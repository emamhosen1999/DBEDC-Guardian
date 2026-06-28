<?php

namespace App\Services\Notification;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\InvalidMessage;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification;

class FcmNotificationService
{
    protected $messaging;

    public function __construct()
    {
        // Resolve Firebase lazily (see messaging()). Resolving here would throw
        // when credentials are absent, which — because this service is constructed
        // during push-channel resolution — would break the ENTIRE notification
        // (including the always-on database channel). Lazy resolution lets the
        // send methods' catch blocks degrade gracefully instead.
    }

    /**
     * Lazily resolve the Firebase messaging client. Throws if Firebase is not
     * configured; callers invoke this inside their try/catch so a missing
     * credentials file logs and returns a graceful result rather than bubbling.
     */
    protected function messaging()
    {
        if ($this->messaging === null) {
            $this->messaging = app('firebase.messaging');
        }

        return $this->messaging;
    }

    /**
     * Send a notification to a specific device
     *
     * @param  string  $deviceToken
     * @param  string  $title
     * @param  string  $body
     * @param  array  $data
     * @return bool
     */
    public function sendNotification($deviceToken, $title, $body, $data = [])
    {
        Log::info('Sending FCM notification', [
            'device_token' => $deviceToken,
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        if (empty($deviceToken)) {
            Log::error('FCM Notification Error: Device token is empty');

            return false;
        }

        try {
            // Create notification
            $notification = Notification::create($title, $body);

            // Create message
            $message = CloudMessage::withTarget('token', $deviceToken)
                ->withNotification($notification)
                ->withData($data)
                ->withDefaultSounds()
                ->withHighPriority()
                ->withApnsConfig([
                    'headers' => [
                        'apns-priority' => '10',
                    ],
                    'payload' => [
                        'aps' => [
                            'content-available' => 1,
                        ],
                    ],
                ]);

            // Send the message
            $response = $this->messaging()->send($message);

            Log::debug('FCM Notification Sent', [
                'message_id' => $response,
                'fcm_token' => $deviceToken,
            ]);

            return true;

        } catch (NotFound $e) {
            // Handle invalid/expired tokens
            $this->handleInvalidToken($deviceToken);
            Log::error('FCM Token not found or invalid', [
                'fcm_token' => $deviceToken,
                'error' => $e->getMessage(),
            ]);

            return false;

        } catch (InvalidMessage $e) {
            Log::error('FCM Invalid Message', [
                'fcm_token' => $deviceToken,
                'error' => $e->getMessage(),
            ]);

            return false;

        } catch (\Throwable $e) {
            // \Throwable (not just \Exception): a missing Firebase credentials file
            // surfaces as an Error, and push must never break notification delivery.
            Log::error('FCM Notification Error', [
                'fcm_token' => $deviceToken,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return false;
        }
    }

    /**
     * Send a multicast notification to multiple devices
     */
    public function sendMulticastNotification(array $deviceTokens, string $title, string $body, array $data = []): array
    {
        if (empty($deviceTokens)) {
            return [];
        }

        try {
            $notification = Notification::create($title, $body);

            $message = CloudMessage::new()
                ->withNotification($notification)
                ->withData($data)
                ->withDefaultSounds()
                ->withHighPriority();

            $report = $this->messaging()->sendMulticast($message, $deviceTokens);

            return [
                'successful' => $report->successes()->count(),
                'failed' => $report->failures()->count(),
                'invalid_tokens' => $report->invalidTokens(),
            ];

        } catch (\Throwable $e) {
            // \Throwable (not just \Exception): a missing Firebase credentials file
            // surfaces as an Error, and push must never break notification delivery.
            Log::error('FCM Multicast Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'successful' => 0,
                'failed' => count($deviceTokens),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle invalid/expired FCM tokens
     *
     * @param  string  $deviceToken
     * @return void
     */
    protected function handleInvalidToken($deviceToken)
    {
        Log::warning('Invalid FCM token detected', [
            'fcm_token' => $deviceToken,
            'action' => 'Token removed from notification_tokens',
        ]);

        \App\Models\NotificationToken::where('token', $deviceToken)->delete();
    }
}
