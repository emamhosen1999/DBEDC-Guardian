<?php

namespace App\Http\Controllers;

use App\Models\Letter;
use App\Services\GmailService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class LettersController extends Controller
{
    protected $gmailService;

    public function __construct(GmailService $gmailService)
    {
        $this->gmailService = $gmailService;
    }

    /**
     * Display a listing of letters with advanced filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = Letter::with(['dealtBy', 'assignedTo', 'forwardedTo']);

        // Apply filters
        if ($request->has('status') && $request->status !== '') {
            $query->byStatus($request->status);
        }

        if ($request->has('priority') && $request->priority !== '') {
            $query->byPriority($request->priority);
        }

        if ($request->has('category') && $request->category !== '') {
            $query->byCategory($request->category);
        }

        if ($request->has('search') && $request->search !== '') {
            $query->search($request->search);
        }

        if ($request->boolean('unread_only')) {
            $query->unread();
        }

        if ($request->boolean('urgent_only')) {
            $query->urgent();
        }

        if ($request->boolean('needing_reply')) {
            $query->needingReply();
        }

        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        if ($request->has('assigned_to') && $request->assigned_to !== '') {
            $query->where('assigned_to', $request->assigned_to);
        }

        if ($request->has('date_from')) {
            $query->where('received_date', '>=', $request->date_from);
        }

        if ($request->has('date_to')) {
            $query->where('received_date', '<=', $request->date_to);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'received_date');
        $sortDirection = $request->get('sort_direction', 'desc');

        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 15);
        $letters = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $letters,
            'stats' => $this->getStats()
        ]);
    }

    /**
     * Store a newly created letter
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from' => 'required|string|max:255',
            'sender_name' => 'nullable|string|max:255',
            'sender_email' => 'nullable|email|max:255',
            'sender_address' => 'nullable|string|max:500',
            'sender_phone' => 'nullable|string|max:50',
            'recipient' => 'nullable|string|max:255',
            'subject' => 'required|string|max:500',
            'content' => 'nullable|string',
            'priority' => 'required|in:low,normal,high,urgent',
            'category' => 'required|in:general,official,personal,legal,financial',
            'received_date' => 'required|date',
            'due_date' => 'nullable|date|after:received_date',
            'need_reply' => 'boolean',
            'need_forward' => 'boolean',
            'confidential' => 'boolean',
            'attachments.*' => 'file|max:10240', // 10MB max
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $request->all();
            $data['status'] = 'unread';
            $data['reference_number'] = $this->generateReferenceNumber();
            $data['source'] = 'manual';

            // Handle attachments
            if ($request->hasFile('attachments')) {
                $data['attachments'] = $this->processUploadedAttachments($request->file('attachments'));
            }

            $letter = Letter::create($data);

            Log::info('Letter created manually', ['letter_id' => $letter->id]);

            return response()->json([
                'success' => true,
                'message' => 'Letter created successfully',
                'data' => $letter->load(['dealtBy', 'assignedTo'])
            ], 201);

        } catch (\Exception $e) {
            Log::error('Failed to create letter: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to create letter'
            ], 500);
        }
    }

    /**
     * Display the specified letter
     */
    public function show(Letter $letter): JsonResponse
    {
        // Mark as read if unread
        if ($letter->status === 'unread') {
            $letter->update(['status' => 'read']);
        }

        return response()->json([
            'success' => true,
            'data' => $letter->load(['dealtBy', 'assignedTo', 'forwardedTo'])
        ]);
    }

    /**
     * Update the specified letter
     */
    public function update(Request $request, Letter $letter): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'status' => 'sometimes|in:unread,read,processed,archived,urgent',
            'priority' => 'sometimes|in:low,normal,high,urgent',
            'category' => 'sometimes|in:general,official,personal,legal,financial',
            'handling_memo' => 'nullable|string|max:1000',
            'action_taken' => 'nullable|string|max:1000',
            'handling_status' => 'nullable|string|max:255',
            'need_reply' => 'boolean',
            'replied_status' => 'boolean',
            'reply_content' => 'nullable|string',
            'need_forward' => 'boolean',
            'forwarded_status' => 'boolean',
            'forwarded_to' => 'nullable|exists:users,id',
            'assigned_to' => 'nullable|exists:users,id',
            'due_date' => 'nullable|date',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $letter->update($request->all());

            Log::info('Letter updated', ['letter_id' => $letter->id, 'changes' => $request->all()]);

            return response()->json([
                'success' => true,
                'message' => 'Letter updated successfully',
                'data' => $letter->load(['dealtBy', 'assignedTo', 'forwardedTo'])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to update letter: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update letter'
            ], 500);
        }
    }

    /**
     * Remove the specified letter
     */
    public function destroy(Letter $letter): JsonResponse
    {
        try {
            // Delete attachments
            if ($letter->attachments) {
                foreach ($letter->attachments as $attachment) {
                    Storage::delete($attachment['path']);
                }
            }

            $letter->delete();

            Log::info('Letter deleted', ['letter_id' => $letter->id]);

            return response()->json([
                'success' => true,
                'message' => 'Letter deleted successfully'
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to delete letter: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete letter'
            ], 500);
        }
    }

    /**
     * Sync incoming emails
     */
    public function syncEmails(): JsonResponse
    {
        try {
            $processed = $this->gmailService->syncIncomingEmails();

            return response()->json([
                'success' => true,
                'message' => "Successfully processed {$processed} emails",
                'processed' => $processed
            ]);

        } catch (\Exception $e) {
            Log::error('Email sync failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Email synchronization failed'
            ], 500);
        }
    }

    /**
     * Send reply to letter
     */
    public function sendReply(Request $request, Letter $letter): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reply_content' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $this->gmailService->sendReply($letter, $request->reply_content);

            return response()->json([
                'success' => true,
                'message' => 'Reply sent successfully',
                'data' => $letter->fresh(['dealtBy', 'assignedTo'])
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send reply: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to send reply'
            ], 500);
        }
    }

    /**
     * Bulk update letters
     */
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'letter_ids' => 'required|array',
            'letter_ids.*' => 'exists:letters,id',
            'action' => 'required|in:mark_read,mark_unread,archive,assign,change_priority,change_category',
            'value' => 'required_unless:action,mark_read,mark_unread,archive'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $letterIds = $request->letter_ids;
            $action = $request->action;
            $value = $request->value;

            $updates = [];
            switch ($action) {
                case 'mark_read':
                    $updates['status'] = 'read';
                    break;
                case 'mark_unread':
                    $updates['status'] = 'unread';
                    break;
                case 'archive':
                    $updates['status'] = 'archived';
                    break;
                case 'assign':
                    $updates['assigned_to'] = $value;
                    break;
                case 'change_priority':
                    $updates['priority'] = $value;
                    break;
                case 'change_category':
                    $updates['category'] = $value;
                    break;
            }

            Letter::whereIn('id', $letterIds)->update($updates);

            Log::info('Bulk update performed', ['action' => $action, 'count' => count($letterIds)]);

            return response()->json([
                'success' => true,
                'message' => 'Bulk update completed successfully',
                'updated_count' => count($letterIds)
            ]);

        } catch (\Exception $e) {
            Log::error('Bulk update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Bulk update failed'
            ], 500);
        }
    }

    /**
     * Download attachment
     */
    public function downloadAttachment(Letter $letter, $attachmentIndex)
    {
        if (!$letter->attachments || !isset($letter->attachments[$attachmentIndex])) {
            abort(404, 'Attachment not found');
        }

        $attachment = $letter->attachments[$attachmentIndex];

        if (!Storage::exists($attachment['path'])) {
            abort(404, 'Attachment file not found');
        }

        return Storage::download($attachment['path'], $attachment['filename']);
    }

    /**
     * Get dashboard statistics
     */
    protected function getStats()
    {
        return [
            'total' => Letter::count(),
            'unread' => Letter::unread()->count(),
            'urgent' => Letter::urgent()->count(),
            'needing_reply' => Letter::needingReply()->count(),
            'overdue' => Letter::overdue()->count(),
            'by_status' => Letter::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'by_priority' => Letter::selectRaw('priority, count(*) as count')
                ->groupBy('priority')
                ->pluck('count', 'priority')
                ->toArray(),
            'by_category' => Letter::selectRaw('category, count(*) as count')
                ->groupBy('category')
                ->pluck('count', 'category')
                ->toArray(),
        ];
    }

    /**
     * Process uploaded attachments
     */
    protected function processUploadedAttachments(array $files)
    {
        $attachments = [];

        foreach ($files as $file) {
            $filename = $file->getClientOriginalName();
            $path = $file->store('letters/attachments');

            $attachments[] = [
                'filename' => $filename,
                'path' => $path,
                'size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ];
        }

        return $attachments;
    }

    /**
     * Generate reference number
     */
    protected function generateReferenceNumber()
    {
        return 'LTR-' . date('Y') . '-' . str_pad(Letter::count() + 1, 6, '0', STR_PAD_LEFT);
    }
}