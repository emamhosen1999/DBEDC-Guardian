<?php

namespace App\Services;

use App\Models\Letter;
use Google\Client;
use Google\Service\Gmail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class GmailService
{
    protected $client;
    protected $service;

    public function __construct()
    {
        $this->client = new Client();
        $this->client->setApplicationName(config('app.name'));
        $this->client->setScopes([Gmail::GMAIL_READONLY, Gmail::GMAIL_MODIFY]);
        $this->client->setAuthConfig(config('services.gmail.credentials_path'));
        $this->client->setAccessType('offline');

        $this->service = new Gmail($this->client);
    }

    /**
     * Sync incoming emails and create letter records
     */
    public function syncIncomingEmails($maxResults = 50)
    {
        try {
            // Get unread messages
            $messages = $this->service->users_messages->listUsersMessages('me', [
                'q' => 'is:unread',
                'maxResults' => $maxResults
            ]);

            $processed = 0;
            foreach ($messages->getMessages() as $message) {
                $this->processEmailMessage($message->getId());
                $processed++;
            }

            Log::info("Processed {$processed} incoming emails");
            return $processed;

        } catch (\Exception $e) {
            Log::error('Failed to sync incoming emails: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Process a single email message
     */
    protected function processEmailMessage($messageId)
    {
        try {
            $message = $this->service->users_messages->get('me', $messageId, [
                'format' => 'full'
            ]);

            $headers = $this->parseHeaders($message->getPayload()->getHeaders());

            // Extract metadata
            $metadata = $this->extractMetadata($message);

            // Create letter record
            $letter = Letter::create([
                'from' => $headers['From'] ?? '',
                'sender_name' => $this->extractSenderName($headers['From'] ?? ''),
                'sender_email' => $this->extractEmailAddress($headers['From'] ?? ''),
                'recipient' => $headers['To'] ?? '',
                'subject' => $headers['Subject'] ?? 'No Subject',
                'content' => $this->extractBody($message),
                'received_date' => Carbon::createFromTimestamp($message->getInternalDate() / 1000),
                'status' => 'unread',
                'priority' => $this->determinePriority($headers),
                'category' => $this->categorizeEmail($headers, $metadata),
                'source' => 'email',
                'attachments' => $this->processAttachments($message),
                'metadata' => $metadata,
                'reference_number' => $this->generateReferenceNumber(),
            ]);

            // Mark as read in Gmail
            $this->service->users_messages->modify('me', $messageId, new Gmail\ModifyMessageRequest([
                'removeLabelIds' => ['UNREAD']
            ]));

            return $letter;

        } catch (\Exception $e) {
            Log::error('Failed to process email message: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Parse email headers
     */
    protected function parseHeaders($headers)
    {
        $parsed = [];
        foreach ($headers as $header) {
            $parsed[$header->getName()] = $header->getValue();
        }
        return $parsed;
    }

    /**
     * Extract sender name from From header
     */
    protected function extractSenderName($from)
    {
        // Extract name from "Name <email>" format
        if (preg_match('/^([^<]+)</', $from, $matches)) {
            return trim($matches[1]);
        }
        return '';
    }

    /**
     * Extract email address from From header
     */
    protected function extractEmailAddress($from)
    {
        if (preg_match('/<([^>]+)>/', $from, $matches)) {
            return $matches[1];
        }
        return $from;
    }

    /**
     * Extract email body
     */
    protected function extractBody($message)
    {
        $payload = $message->getPayload();

        if ($payload->getBody()->getData()) {
            return base64_decode(str_replace(['-', '_'], ['+', '/'], $payload->getBody()->getData()));
        }

        // Handle multipart messages
        foreach ($payload->getParts() as $part) {
            if ($part->getMimeType() === 'text/plain' && $part->getBody()->getData()) {
                return base64_decode(str_replace(['-', '_'], ['+', '/'], $part->getBody()->getData()));
            }
        }

        return '';
    }

    /**
     * Process email attachments
     */
    protected function processAttachments($message)
    {
        $attachments = [];
        $payload = $message->getPayload();

        $this->extractAttachmentsFromParts($payload->getParts(), $attachments);

        return $attachments;
    }

    /**
     * Recursively extract attachments from message parts
     */
    protected function extractAttachmentsFromParts($parts, &$attachments)
    {
        if (!$parts) return;

        foreach ($parts as $part) {
            if ($part->getFilename() && $part->getBody()->getAttachmentId()) {
                $attachment = $this->service->users_messages_attachments->get(
                    'me',
                    $part->getBody()->getAttachmentId()
                );

                $fileData = base64_decode(str_replace(['-', '_'], ['+', '/'], $attachment->getData()));

                // Store attachment
                $filename = $part->getFilename();
                $path = 'letters/attachments/' . uniqid() . '_' . $filename;
                Storage::put($path, $fileData);

                $attachments[] = [
                    'filename' => $filename,
                    'path' => $path,
                    'size' => strlen($fileData),
                    'mime_type' => $part->getMimeType(),
                ];
            }

            // Recursively check nested parts
            if ($part->getParts()) {
                $this->extractAttachmentsFromParts($part->getParts(), $attachments);
            }
        }
    }

    /**
     * Extract metadata from email
     */
    protected function extractMetadata($message)
    {
        $metadata = [];
        $payload = $message->getPayload();

        // Extract any additional data
        $metadata['message_id'] = $message->getId();
        $metadata['thread_id'] = $message->getThreadId();
        $metadata['size'] = $message->getSizeEstimate();

        return $metadata;
    }

    /**
     * Determine priority based on headers
     */
    protected function determinePriority($headers)
    {
        if (isset($headers['X-Priority']) && $headers['X-Priority'] >= 3) {
            return 'high';
        }

        if (isset($headers['Importance']) && strtoupper($headers['Importance']) === 'HIGH') {
            return 'high';
        }

        // Check for urgent keywords in subject
        if (isset($headers['Subject']) &&
            preg_match('/urgent|important|asap|emergency/i', $headers['Subject'])) {
            return 'urgent';
        }

        return 'normal';
    }

    /**
     * Categorize email based on content
     */
    protected function categorizeEmail($headers, $metadata)
    {
        $subject = strtolower($headers['Subject'] ?? '');
        $from = strtolower($headers['From'] ?? '');

        // Official correspondence
        if (preg_match('/official|government|ministry|department/i', $subject . ' ' . $from)) {
            return 'official';
        }

        // Legal matters
        if (preg_match('/legal|court|lawyer|contract/i', $subject)) {
            return 'legal';
        }

        // Financial
        if (preg_match('/invoice|payment|financial|budget/i', $subject)) {
            return 'financial';
        }

        return 'general';
    }

    /**
     * Generate unique reference number
     */
    protected function generateReferenceNumber()
    {
        return 'LTR-' . date('Y') . '-' . str_pad(Letter::count() + 1, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Send reply via Gmail
     */
    public function sendReply(Letter $letter, $replyContent)
    {
        try {
            // Implementation for sending reply
            // This would use Gmail API to send a reply

            $letter->update([
                'replied_status' => true,
                'reply_date' => now(),
                'reply_content' => $replyContent,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send reply: ' . $e->getMessage());
            throw $e;
        }
    }
}