<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Notifications\RfiObjectionNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RfiObjectionController extends Controller
{
    /**
     * Get all objections for a specific RFI.
     */
    public function index(DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);

        $objections = $dailyWork->objections()
            ->with(['createdBy:id,name,email', 'resolvedBy:id,name,email'])
            ->get()
            ->map(function ($objection) {
                return array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]);
            });

        return response()->json([
            'objections' => $objections,
            'total' => $objections->count(),
            'active_count' => $objections->where('is_active', true)->count(),
        ]);
    }

    /**
     * Get a specific objection with details.
     */
    public function show(DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        // Ensure objection is attached to the daily work (many-to-many)
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('view', $objection);

        $objection->load([
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'resolvedBy:id,name,email',
            'statusLogs.changedBy:id,name',
        ]);

        return response()->json([
            'objection' => array_merge($objection->toArray(), [
                'files' => $objection->files,
            ]),
        ]);
    }

    /**
     * Create a new objection for an RFI.
     */
    public function store(Request $request, DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('create', [RfiObjection::class, $dailyWork]);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'category' => 'nullable|string|in:'.implode(',', RfiObjection::$categories),
            'description' => 'required|string|max:5000',
            'reason' => 'required|string|max:5000',
            'status' => 'nullable|string|in:draft,submitted',
        ]);

        try {
            DB::beginTransaction();

            $objection = new RfiObjection([
                'title' => $validated['title'],
                'category' => $validated['category'] ?? RfiObjection::CATEGORY_OTHER,
                'description' => $validated['description'],
                'reason' => $validated['reason'],
                'status' => $validated['status'] ?? RfiObjection::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ]);

            $objection->save();

            // Attach to the daily work (many-to-many)
            $objection->attachToRfis([$dailyWork->id]);

            // Log initial status
            $objection->statusLogs()->create([
                'from_status' => null,
                'to_status' => $objection->status,
                'notes' => 'Objection created',
                'changed_by' => auth()->id(),
                'changed_at' => now(),
            ]);

            // If submitted immediately, send notifications
            if ($objection->status === RfiObjection::STATUS_SUBMITTED) {
                $this->notifyStakeholders($objection, 'submitted');
            }

            DB::commit();

            $objection->load(['createdBy:id,name,email']);

            return response()->json([
                'message' => 'Objection created successfully.',
                'objection' => array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to create objection: '.$e->getMessage()], 500);
        }
    }

    /**
     * Update an existing objection.
     */
    public function update(Request $request, DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('update', $objection);

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'category' => 'nullable|string|in:'.implode(',', RfiObjection::$categories),
            'description' => 'sometimes|required|string|max:5000',
            'reason' => 'sometimes|required|string|max:5000',
        ]);

        try {
            $objection->update($validated);

            $objection->load(['createdBy:id,name,email']);

            return response()->json([
                'message' => 'Objection updated successfully.',
                'objection' => array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to update objection: '.$e->getMessage()], 500);
        }
    }

    /**
     * Delete an objection.
     */
    public function destroy(DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('delete', $objection);

        try {
            // Clear media files
            $objection->clearMediaCollection('objection_files');

            $objection->delete();

            return response()->json([
                'message' => 'Objection deleted successfully.',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete objection: '.$e->getMessage()], 500);
        }
    }

    /**
     * Submit an objection for review.
     */
    public function submit(DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('submit', $objection);

        try {
            $objection->submit('Submitted for review');

            // Send notifications
            $this->notifyStakeholders($objection, 'submitted');

            $objection->load(['createdBy:id,name,email']);

            return response()->json([
                'message' => 'Objection submitted for review.',
                'objection' => array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to submit objection: '.$e->getMessage()], 500);
        }
    }

    /**
     * Start reviewing an objection.
     */
    public function startReview(DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('review', $objection);

        try {
            $objection->startReview('Review started');

            $objection->load(['createdBy:id,name,email']);

            return response()->json([
                'message' => 'Objection is now under review.',
                'objection' => array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to start review: '.$e->getMessage()], 500);
        }
    }

    /**
     * Resolve an objection.
     */
    public function resolve(Request $request, DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('review', $objection);

        $validated = $request->validate([
            'resolution_notes' => 'required|string|max:5000',
        ]);

        try {
            $objection->resolve($validated['resolution_notes']);

            // Notify the objection creator
            $this->notifyStakeholders($objection, 'resolved');

            $objection->load(['createdBy:id,name,email', 'resolvedBy:id,name,email']);

            return response()->json([
                'message' => 'Objection resolved successfully.',
                'objection' => array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to resolve objection: '.$e->getMessage()], 500);
        }
    }

    /**
     * Reject an objection.
     */
    public function reject(Request $request, DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('review', $objection);

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:5000',
        ]);

        try {
            $objection->reject($validated['rejection_reason']);

            // Notify the objection creator
            $this->notifyStakeholders($objection, 'rejected');

            $objection->load(['createdBy:id,name,email', 'resolvedBy:id,name,email']);

            return response()->json([
                'message' => 'Objection rejected.',
                'objection' => array_merge($objection->toArray(), [
                    'files' => $objection->files,
                ]),
            ]);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reject objection: '.$e->getMessage()], 500);
        }
    }

    /**
     * Upload files to an objection.
     */
    public function uploadFiles(Request $request, DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('uploadFiles', $objection);

        $request->validate([
            'files' => 'required|array|min:1|max:10',
            'files.*' => 'file|mimes:jpeg,jpg,png,webp,gif,pdf,doc,docx,xls,xlsx|max:10240',
        ]);

        $uploadedFiles = [];
        $errors = [];

        foreach ($request->file('files') as $file) {
            try {
                $media = $objection
                    ->addMedia($file)
                    ->usingFileName($this->generateUniqueFileName($file))
                    ->toMediaCollection('objection_files');

                $uploadedFiles[] = [
                    'id' => $media->id,
                    'name' => $media->file_name,
                    'url' => $media->getUrl(),
                    'thumb_url' => $media->hasGeneratedConversion('thumb') ? $media->getUrl('thumb') : null,
                    'mime_type' => $media->mime_type,
                    'size' => $media->size,
                    'is_image' => str_starts_with($media->mime_type, 'image/'),
                    'is_pdf' => $media->mime_type === 'application/pdf',
                ];
            } catch (\Exception $e) {
                $errors[] = [
                    'file' => $file->getClientOriginalName(),
                    'error' => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => count($uploadedFiles).' file(s) uploaded successfully.',
            'files' => $uploadedFiles,
            'errors' => $errors,
            'total_files' => $objection->getMedia('objection_files')->count(),
        ]);
    }

    /**
     * Get files for an objection.
     */
    public function getFiles(DailyWork $dailyWork, RfiObjection $objection): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('view', $objection);

        return response()->json([
            'files' => $objection->files,
            'total' => $objection->files_count,
        ]);
    }

    /**
     * Delete a file from an objection.
     */
    public function deleteFile(DailyWork $dailyWork, RfiObjection $objection, int $mediaId): JsonResponse
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('deleteFiles', $objection);

        $media = $objection->getMedia('objection_files')->where('id', $mediaId)->first();

        if (! $media) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        try {
            $media->delete();

            return response()->json([
                'message' => 'File deleted successfully.',
                'total_files' => $objection->getMedia('objection_files')->count(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete file: '.$e->getMessage()], 500);
        }
    }

    /**
     * Download a file from an objection.
     */
    public function downloadFile(DailyWork $dailyWork, RfiObjection $objection, int $mediaId)
    {
        if (! $dailyWork->objections()->where('rfi_objections.id', $objection->id)->exists()) {
            return response()->json(['error' => 'Objection not found for this RFI.'], 404);
        }

        $this->authorize('view', $objection);

        $media = $objection->getMedia('objection_files')->where('id', $mediaId)->first();

        if (! $media) {
            return response()->json(['error' => 'File not found.'], 404);
        }

        return response()->download($media->getPath(), $media->file_name);
    }

    /**
     * Get available objections that can be attached to this RFI.
     * This returns objections not already attached to this daily work,
     * filtered by chainage matching with the RFI's location.
     */
    public function available(DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);

        // Get IDs of objections already attached to this daily work
        $attachedIds = $dailyWork->objections()->pluck('rfi_objections.id')->toArray();

        // Get all objections not already attached, excluding resolved/rejected ones
        $query = RfiObjection::query()
            ->whereNotIn('id', $attachedIds)
            ->whereIn('status', [RfiObjection::STATUS_DRAFT, RfiObjection::STATUS_SUBMITTED, RfiObjection::STATUS_UNDER_REVIEW])
            ->with(['createdBy:id,name,email', 'chainages'])
            ->orderBy('created_at', 'desc')
            ->limit(200); // Fetch more to filter

        $objections = $query->get();

        // Filter by chainage matching if the RFI has a location
        $rfiLocation = $dailyWork->location;
        $matchedObjections = collect();
        $unmatchedObjections = collect();

        foreach ($objections as $objection) {
            // Check if objection has chainages defined
            $hasChainages = $objection->chainages->isNotEmpty()
                || ! empty($objection->chainage_from);

            if (! $hasChainages) {
                // No chainages - include but mark as unmatched
                $unmatchedObjections->push($objection);

                continue;
            }

            // Check if objection matches the RFI location
            if (! empty($rfiLocation) && $objection->matchesRfiLocation($rfiLocation)) {
                $matchedObjections->push($objection);
            } else {
                $unmatchedObjections->push($objection);
            }
        }

        // Prioritize matched objections, then include unmatched ones
        $sortedObjections = $matchedObjections->merge($unmatchedObjections)->take(50);

        $availableObjections = $sortedObjections->map(function ($objection) use ($matchedObjections) {
            $chainageSummary = $objection->getChainageSummary();

            return [
                'id' => $objection->id,
                'title' => $objection->title,
                'category' => $objection->category,
                'category_label' => $objection->category_label,
                'chainage_from' => $objection->chainage_from,
                'chainage_to' => $objection->chainage_to,
                'specific_chainages' => $chainageSummary['specific'],
                'chainage_range' => $chainageSummary['range'],
                'description' => $objection->description,
                'reason' => $objection->reason,
                'status' => $objection->status,
                'status_label' => $objection->status_label,
                'created_by' => $objection->createdBy,
                'created_at' => $objection->created_at,
                'is_chainage_match' => $matchedObjections->contains('id', $objection->id),
            ];
        });

        return response()->json([
            'objections' => $availableObjections,
            'count' => $availableObjections->count(),
            'matched_count' => $matchedObjections->count(),
            'rfi_location' => $rfiLocation,
        ]);
    }

    /**
     * Attach existing objections to this RFI (daily work).
     */
    public function attach(Request $request, DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);

        $validated = $request->validate([
            'objection_ids' => 'required|array|min:1',
            'objection_ids.*' => 'exists:rfi_objections,id',
            'attachment_notes' => 'nullable|string|max:1000',
        ]);

        try {
            $attachedCount = 0;
            $alreadyAttachedCount = 0;

            foreach ($validated['objection_ids'] as $objectionId) {
                $objection = RfiObjection::find($objectionId);

                if ($objection) {
                    // Check if already attached
                    if (! $dailyWork->objections()->where('rfi_objections.id', $objectionId)->exists()) {
                        // Use the model method to attach
                        $objection->attachToRfis([$dailyWork->id], $validated['attachment_notes'] ?? null);
                        $attachedCount++;
                    } else {
                        $alreadyAttachedCount++;
                    }
                }
            }

            $message = "Successfully attached {$attachedCount} objection(s) to this RFI.";
            if ($alreadyAttachedCount > 0) {
                $message .= " {$alreadyAttachedCount} objection(s) were already attached.";
            }

            // Calculate the new active objections count
            $activeObjectionsCount = $dailyWork->objections()
                ->whereIn('status', ['draft', 'submitted', 'under_review'])
                ->count();

            return response()->json([
                'message' => $message,
                'attached_count' => $attachedCount,
                'already_attached_count' => $alreadyAttachedCount,
                'active_objections_count' => $activeObjectionsCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to attach objections.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Detach objections from this RFI (daily work).
     */
    public function detach(Request $request, DailyWork $dailyWork): JsonResponse
    {
        $this->authorize('viewAny', RfiObjection::class);

        $validated = $request->validate([
            'objection_ids' => 'required|array|min:1',
            'objection_ids.*' => 'exists:rfi_objections,id',
        ]);

        try {
            $detachedCount = 0;

            foreach ($validated['objection_ids'] as $objectionId) {
                $objection = RfiObjection::find($objectionId);

                if ($objection) {
                    // Check if attached
                    if ($dailyWork->objections()->where('rfi_objections.id', $objectionId)->exists()) {
                        // Detach from this daily work
                        $objection->detachFromRfis([$dailyWork->id]);
                        $detachedCount++;
                    }
                }
            }

            // Calculate the new active objections count
            $activeObjectionsCount = $dailyWork->objections()
                ->whereIn('status', ['draft', 'submitted', 'under_review'])
                ->count();

            return response()->json([
                'message' => "Successfully detached {$detachedCount} objection(s) from this RFI.",
                'detached_count' => $detachedCount,
                'active_objections_count' => $activeObjectionsCount,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to detach objections.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get objection categories and statuses for form dropdowns.
     */
    public function getMetadata(): JsonResponse
    {
        return response()->json([
            'categories' => collect(RfiObjection::$categories)->map(fn ($cat) => [
                'value' => $cat,
                'label' => RfiObjection::$categoryLabels[$cat] ?? ucfirst(str_replace('_', ' ', $cat)),
            ]),
            'statuses' => collect(RfiObjection::$statuses)->map(fn ($status) => [
                'value' => $status,
                'label' => RfiObjection::$statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
            ]),
            'active_statuses' => RfiObjection::$activeStatuses,
        ]);
    }

    /**
     * Generate a unique filename for uploaded files.
     */
    protected function generateUniqueFileName($file): string
    {
        $extension = $file->getClientOriginalExtension();
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);

        return substr($baseName, 0, 100).'_'.time().'_'.uniqid().'.'.$extension;
    }

    /**
     * Notify relevant stakeholders about objection events.
     */
    protected function notifyStakeholders(RfiObjection $objection, string $event): void
    {
        try {
            $dailyWork = $objection->dailyWork;
            $usersToNotify = collect();

            // Get incharge user
            if ($dailyWork->incharge && $dailyWork->inchargeUser) {
                $usersToNotify->push($dailyWork->inchargeUser);
            }

            // Get assigned user
            if ($dailyWork->assigned && $dailyWork->assignedUser && $dailyWork->assigned !== $dailyWork->incharge) {
                $usersToNotify->push($dailyWork->assignedUser);
            }

            // For submitted events, also notify managers/admins
            if ($event === 'submitted') {
                $managers = \App\Models\User::role(['Super Admin', 'Admin', 'Project Manager', 'Consultant'])
                    ->where('active', true)
                    ->get();
                $usersToNotify = $usersToNotify->merge($managers);
            }

            // For resolved/rejected events, notify the objection creator
            if (in_array($event, ['resolved', 'rejected']) && $objection->createdBy) {
                $usersToNotify->push($objection->createdBy);
            }

            // Remove duplicates and the current user
            $usersToNotify = $usersToNotify
                ->unique('id')
                ->filter(fn ($user) => $user->id !== auth()->id());

            foreach ($usersToNotify as $user) {
                $user->notify(new RfiObjectionNotification($objection, $event));
            }
        } catch (\Exception $e) {
            \Log::error('Failed to send objection notifications', [
                'objection_id' => $objection->id,
                'event' => $event,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
