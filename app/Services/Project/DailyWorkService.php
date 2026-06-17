<?php

namespace App\Services\Project;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\RfiObjection;
use App\Models\RfiSubmissionOverrideLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DailyWorkService
{
    /**
     * Update status, inspection result, and times of a DailyWork.
     */
    public function updateStatus(DailyWork $dailyWork, string $status, ?string $inspectionResult = null, bool $updateSubmissionTime = true): DailyWork
    {
        $updateData = [
            'status' => $status,
        ];

        // Add inspection result if provided or reset it for new status
        if ($inspectionResult !== null || $status === DailyWork::STATUS_NEW) {
            $updateData['inspection_result'] = ($status === DailyWork::STATUS_NEW) ? null : $inspectionResult;
        }

        // Auto-set completion and submission times for completed status
        if ($status === DailyWork::STATUS_COMPLETED) {
            $updateData['completion_time'] = $dailyWork->completion_time ?? now();
            if ($updateSubmissionTime) {
                $updateData['submission_time'] = $dailyWork->submission_time ?? now();
            }
        }

        // Reset times for new status
        if ($status === DailyWork::STATUS_NEW) {
            $updateData['completion_time'] = null;
            if ($updateSubmissionTime) {
                $updateData['submission_time'] = null;
            }
            $updateData['inspection_result'] = null;
        }

        $dailyWork->update($updateData);

        return $dailyWork;
    }

    /**
     * Update the completion time of a DailyWork.
     */
    public function updateCompletionTime(DailyWork $dailyWork, string $completionTime): DailyWork
    {
        $dailyWork->update(['completion_time' => $completionTime]);

        return $dailyWork;
    }

    /**
     * Update RFI submission date, logging an override if active objections exist.
     */
    public function updateSubmissionTime(DailyWork $dailyWork, string $submissionDate, int $userId, ?string $overrideReason = null): DailyWork
    {
        $activeObjectionsCount = $dailyWork->objections()
            ->whereIn('status', ['draft', 'submitted', 'under_review'])
            ->count();

        if ($activeObjectionsCount > 0 && $overrideReason) {
            RfiSubmissionOverrideLog::logOverride(
                dailyWorkId: $dailyWork->id,
                oldDate: $dailyWork->rfi_submission_date?->format('Y-m-d'),
                newDate: $submissionDate,
                activeObjectionsCount: $activeObjectionsCount,
                reason: $overrideReason,
                userId: $userId
            );
        }

        $dailyWork->update(['rfi_submission_date' => $submissionDate]);

        return $dailyWork;
    }

    /**
     * Bulk submit RFIs.
     */
    public function bulkSubmit(
        array $ids,
        string $submissionDate,
        int $userId,
        bool $skipObjected = false,
        bool $overrideObjected = false,
        ?string $overrideReason = null,
        ?callable $authorizeCallback = null
    ): array {
        // Get all daily works with their objection counts
        $dailyWorks = DailyWork::whereIn('id', $ids)
            ->withCount(['objections as active_objections_count' => function ($query) {
                $query->whereIn('status', ['draft', 'submitted', 'under_review']);
            }])
            ->get();

        // Separate works with and without active objections
        $worksWithObjections = $dailyWorks->filter(fn ($w) => $w->active_objections_count > 0);
        $worksWithoutObjections = $dailyWorks->filter(fn ($w) => $w->active_objections_count === 0);

        // Check if there are works with objections and user hasn't made a decision
        if ($worksWithObjections->count() > 0 && ! $skipObjected && ! $overrideObjected) {
            return [
                'requires_decision' => true,
                'total_count' => $dailyWorks->count(),
                'objected_count' => $worksWithObjections->count(),
                'clean_count' => $worksWithoutObjections->count(),
                'objected_works' => $worksWithObjections->map(fn ($w) => [
                    'id' => $w->id,
                    'number' => $w->number,
                    'location' => $w->location,
                    'active_objections_count' => $w->active_objections_count,
                ])->values()->toArray(),
            ];
        }

        $submitted = [];
        $skipped = [];
        $failed = [];

        // Process works without objections
        foreach ($worksWithoutObjections as $work) {
            try {
                if ($authorizeCallback) {
                    $authorizeCallback($work);
                }
                $work->update(['rfi_submission_date' => $submissionDate]);
                $submitted[] = [
                    'id' => $work->id,
                    'number' => $work->number,
                    'dailyWork' => $work->fresh(['inchargeUser', 'assignedUser']),
                ];
            } catch (\Exception $e) {
                $failed[] = [
                    'id' => $work->id,
                    'number' => $work->number,
                    'error' => 'Permission denied',
                ];
            }
        }

        // Process works with objections based on user decision
        foreach ($worksWithObjections as $work) {
            if ($skipObjected) {
                $skipped[] = [
                    'id' => $work->id,
                    'number' => $work->number,
                    'active_objections_count' => $work->active_objections_count,
                ];

                continue;
            }

            if ($overrideObjected) {
                try {
                    if ($authorizeCallback) {
                        $authorizeCallback($work);
                    }

                    // Log the override
                    RfiSubmissionOverrideLog::logOverride(
                        dailyWorkId: $work->id,
                        oldDate: $work->rfi_submission_date?->format('Y-m-d'),
                        newDate: $submissionDate,
                        activeObjectionsCount: $work->active_objections_count,
                        reason: $overrideReason.' (Bulk submission)',
                        userId: $userId
                    );

                    $work->update(['rfi_submission_date' => $submissionDate]);
                    $submitted[] = [
                        'id' => $work->id,
                        'number' => $work->number,
                        'override_logged' => true,
                        'dailyWork' => $work->fresh(['inchargeUser', 'assignedUser']),
                    ];
                } catch (\Exception $e) {
                    $failed[] = [
                        'id' => $work->id,
                        'number' => $work->number,
                        'error' => 'Permission denied',
                    ];
                }
            }
        }

        return [
            'requires_decision' => false,
            'submitted' => $submitted,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Bulk update RFI response status.
     */
    public function bulkResponseStatusUpdate(
        array $ids,
        string $responseStatus,
        string $responseDate,
        int $userId,
        bool $skipObjected = false,
        bool $overrideObjected = false,
        ?string $overrideReason = null
    ): array {
        // Get all daily works with their objection counts
        $dailyWorks = DailyWork::whereIn('id', $ids)
            ->withCount(['objections as active_objections_count' => function ($query) {
                $query->whereIn('status', ['draft', 'submitted', 'under_review']);
            }])
            ->get();

        // Separate works with and without active objections
        $worksWithObjections = $dailyWorks->filter(fn ($w) => $w->active_objections_count > 0);
        $worksWithoutObjections = $dailyWorks->filter(fn ($w) => $w->active_objections_count === 0);

        // Check if there are works with objections and user hasn't made a decision
        if ($worksWithObjections->count() > 0 && ! $skipObjected && ! $overrideObjected) {
            return [
                'requires_decision' => true,
                'total_count' => $dailyWorks->count(),
                'objected_count' => $worksWithObjections->count(),
                'clean_count' => $worksWithoutObjections->count(),
                'objected_works' => $worksWithObjections->map(fn ($w) => [
                    'id' => $w->id,
                    'number' => $w->number,
                    'location' => $w->location,
                    'active_objections_count' => $w->active_objections_count,
                ])->values()->toArray(),
            ];
        }

        $updated = [];
        $skipped = [];
        $failed = [];

        // Process works without objections
        foreach ($worksWithoutObjections as $work) {
            try {
                $work->update([
                    'rfi_response_status' => $responseStatus,
                    'rfi_response_date' => $responseDate,
                ]);
                $updated[] = [
                    'id' => $work->id,
                    'number' => $work->number,
                    'dailyWork' => $work->fresh(['inchargeUser', 'assignedUser']),
                ];
            } catch (\Exception $e) {
                $failed[] = [
                    'id' => $work->id,
                    'number' => $work->number,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Process works with objections based on user decision
        foreach ($worksWithObjections as $work) {
            if ($skipObjected) {
                $skipped[] = [
                    'id' => $work->id,
                    'number' => $work->number,
                    'active_objections_count' => $work->active_objections_count,
                ];

                continue;
            }

            if ($overrideObjected) {
                try {
                    // Log the override
                    RfiSubmissionOverrideLog::logOverride(
                        dailyWorkId: $work->id,
                        oldDate: $work->rfi_response_date?->format('Y-m-d'),
                        newDate: $responseDate,
                        activeObjectionsCount: $work->active_objections_count,
                        reason: $overrideReason.' (Bulk response status: '.$responseStatus.')',
                        userId: $userId
                    );

                    $work->update([
                        'rfi_response_status' => $responseStatus,
                        'rfi_response_date' => $responseDate,
                    ]);
                    $updated[] = [
                        'id' => $work->id,
                        'number' => $work->number,
                        'override_logged' => true,
                        'dailyWork' => $work->fresh(['inchargeUser', 'assignedUser']),
                    ];
                } catch (\Exception $e) {
                    $failed[] = [
                        'id' => $work->id,
                        'number' => $work->number,
                        'error' => $e->getMessage(),
                    ];
                }
            }
        }

        return [
            'requires_decision' => false,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Update the incharge user of a DailyWork.
     */
    public function updateIncharge(DailyWork $dailyWork, ?int $inchargeId): DailyWork
    {
        $dailyWork->update(['incharge' => $inchargeId]);

        return $dailyWork;
    }

    /**
     * Update the assigned user of a DailyWork.
     */
    public function updateAssigned(DailyWork $dailyWork, ?int $assignedId): DailyWork
    {
        $dailyWork->update(['assigned' => $assignedId]);

        return $dailyWork;
    }

    /**
     * Store a new RFI objection.
     */
    public function storeObjection(DailyWork $dailyWork, array $data, User $user): RfiObjection
    {
        return DB::transaction(function () use ($dailyWork, $data, $user) {
            $rangeFrom = $data['chainage_range_from'] ?? $data['chainage_from'] ?? null;
            $rangeTo = $data['chainage_range_to'] ?? $data['chainage_to'] ?? null;

            $objection = new RfiObjection;
            $objection->title = $data['title'];
            $objection->category = $data['category'] ?? RfiObjection::CATEGORY_OTHER;
            $objection->description = $data['description'] ?? null;
            $objection->reason = $data['reason'] ?? null;
            $objection->status = $data['status'] ?? RfiObjection::STATUS_DRAFT;
            $objection->created_by = (int) $user->id;

            if (Schema::hasColumn('rfi_objections', 'type')) {
                $objection->type = $data['type'] ?? null;
            }

            if (Schema::hasColumn('rfi_objections', 'chainage_from')) {
                $objection->chainage_from = $rangeFrom;
            }

            if (Schema::hasColumn('rfi_objections', 'chainage_to')) {
                $objection->chainage_to = $rangeTo;
            }

            if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
                $objection->setAttribute('daily_work_id', $dailyWork->id);
            }

            $objection->save();

            if (Schema::hasTable('daily_work_objection')) {
                $objection->dailyWorks()->syncWithoutDetaching([
                    $dailyWork->id => [
                        'attached_by' => $user->id,
                        'attached_at' => now(),
                        'attachment_notes' => $data['attachment_notes'] ?? null,
                    ],
                ]);
            }

            if (Schema::hasTable('objection_chainages')) {
                $specificChainages = array_values(array_filter(
                    array_map('trim', preg_split('/\s*,\s*/', (string) ($data['specific_chainages'] ?? ''))),
                    fn (string $chainage): bool => $chainage !== ''
                ));

                $objection->syncChainages($specificChainages, $rangeFrom, $rangeTo);
            }

            return $objection->fresh(['createdBy:id,name']) ?? $objection;
        });
    }

    /**
     * Submit an RFI objection.
     */
    public function submitObjection(RfiObjection $objection): RfiObjection
    {
        $objection->submit('Submitted for review');

        return $objection->fresh(['createdBy:id,name']) ?? $objection;
    }

    /**
     * Start reviewing an RFI objection.
     */
    public function startReviewObjection(RfiObjection $objection): RfiObjection
    {
        $objection->startReview('Review started');

        return $objection->fresh(['createdBy:id,name']) ?? $objection;
    }

    /**
     * Resolve an RFI objection.
     */
    public function resolveObjection(RfiObjection $objection, ?string $resolutionNotes): RfiObjection
    {
        $objection->resolve($resolutionNotes);

        return $objection->fresh(['createdBy:id,name']) ?? $objection;
    }

    /**
     * Reject an RFI objection.
     */
    public function rejectObjection(RfiObjection $objection, ?string $rejectionReason): RfiObjection
    {
        $objection->reject($rejectionReason);

        return $objection->fresh(['createdBy:id,name']) ?? $objection;
    }

    /**
     * Upload files to an objection.
     */
    public function uploadObjectionFiles(RfiObjection $objection, array $files): array
    {
        $uploadedFiles = [];
        foreach ($files as $file) {
            $media = $objection
                ->addMedia($file)
                ->usingFileName($this->generateUniqueMediaFileName($file))
                ->toMediaCollection('objection_files');

            $uploadedFiles[] = $media;
        }

        return $uploadedFiles;
    }

    /**
     * Generate unique media file name.
     */
    public function generateUniqueMediaFileName($file): string
    {
        $extension = $file->getClientOriginalExtension();
        $baseName = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $baseName);

        return substr((string) $baseName, 0, 100).'_'.time().'_'.uniqid().'.'.$extension;
    }

    /**
     * Build filtered query for DailyWorks.
     */
    public function buildFilteredDailyWorksQuery(User $user, array $filters): Builder
    {
        $query = DailyWork::query();
        $userDesignationTitle = $this->getUserDesignationTitle($user);

        \Log::info('buildFilteredDailyWorksQuery from Service', [
            'user_id' => $user->id,
            'user_name' => $user->name,
            'report_to' => $user->report_to,
            'designation' => $userDesignationTitle,
            'is_privileged' => $this->isPrivilegedUser($user),
        ]);

        if ($this->isPrivilegedUser($user)) {
            // Managers and admin roles can access all daily works.
        } else {
            if ($userDesignationTitle === 'Supervision Engineer') {
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id);
                    if ($user->report_to) {
                        $q->orWhere('incharge', $user->report_to);
                    }
                });
            } elseif (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'])) {
                if ($user->report_to) {
                    $query->where('incharge', $user->report_to);
                } else {
                    $query->where('assigned', $user->id);
                }
            } elseif ($user->hasRole('Employee')) {
                \Log::info('Employee visibility filter applied', [
                    'user_id' => $user->id,
                    'report_to' => $user->report_to,
                ]);
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);

                    if ($user->report_to) {
                        $q->orWhere('incharge', $user->report_to);
                    }
                });
            } elseif ($user->report_to) {
                // For other roles (non-employee, non-admin) with a manager: apply report_to visibility
                \Log::info('User with manager - applying universal filter', [
                    'user_id' => $user->id,
                    'report_to' => $user->report_to,
                ]);
                $query->where(function ($dailyWorkQuery) use ($user) {
                    $dailyWorkQuery
                        ->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id)
                        ->orWhere('incharge', $user->report_to);
                });
            } else {
                // Otherwise, show only own works (incharge or assigned)
                $query->where(function ($dailyWorkQuery) use ($user) {
                    $dailyWorkQuery
                        ->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);
                });
            }
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
            $query->whereBetween('date', [$filters['date_from'], $filters['date_to']]);
        } elseif (! empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        } elseif (! empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (! empty($filters['search'])) {
            $search = (string) $filters['search'];
            $words = array_values(array_filter(explode(' ', $search)));

            if (count($words) > 0) {
                $query->where(function ($searchQuery) use ($words) {
                    foreach ($words as $word) {
                        $searchQuery->where(function ($subQuery) use ($word) {
                            $subQuery->where('number', 'like', "%{$word}%")
                                ->orWhere('description', 'like', "%{$word}%")
                                ->orWhere('location', 'like', "%{$word}%")
                                ->orWhere('type', 'like', "%{$word}%")
                                ->orWhere('inspection_details', 'like', "%{$word}%");
                        });
                    }
                });
            }
        }

        if (! empty($filters['only_with_objections']) && $filters['only_with_objections'] === true) {
            $query->whereHas('objections', function ($objectionQuery) {
                $objectionQuery->whereIn('rfi_objections.status', RfiObjection::$activeStatuses);
            });
        }

        return $query;
    }

    /**
     * Check if user is privileged.
     */
    public function isPrivilegedUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'Daily Work Manager',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    /**
     * Get user designation title.
     */
    public function getUserDesignationTitle(User $user): string
    {
        if (! Schema::hasColumn('users', 'designation_id') || ! Schema::hasTable('designations')) {
            return '';
        }

        if (! $user->relationLoaded('designation')) {
            $user->load('designation:id,title');
        }

        return trim((string) ($user->designation?->title ?? ''));
    }

    /**
     * Check if user can access a daily work.
     */
    public function canAccessDailyWork(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can view works where they are incharge
                return (int) $dailyWork->incharge === (int) $user->id;
            } else {
                // Employee has no jurisdiction: can view works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (int) $dailyWork->incharge === (int) $user->report_to;
                }

                // No jurisdiction and no manager: can view own works
                return (int) $dailyWork->incharge === (int) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): can view if incharge/assigned OR manager is incharge
        if ((int) $dailyWork->incharge === (int) $user->id
            || (int) $dailyWork->assigned === (int) $user->id) {
            return true;
        }

        if ($user->report_to && (int) $dailyWork->incharge === (int) $user->report_to) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can submit an objection.
     */
    public function canSubmitObjection(User $user, RfiObjection $objection): bool
    {
        return (int) $objection->created_by === (int) $user->id || $this->isPrivilegedUser($user);
    }

    /**
     * Check if user can view objection files.
     */
    public function canViewObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        return $this->isPrivilegedUser($user)
            || (int) $objection->created_by === (int) $user->id
            || $this->canAccessDailyWork($user, $dailyWork);
    }

    /**
     * Check if user can manage objection files.
     */
    public function canManageObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        if (! in_array($objection->status, [RfiObjection::STATUS_DRAFT, RfiObjection::STATUS_SUBMITTED], true)) {
            return false;
        }

        return $this->isPrivilegedUser($user)
            || (int) $objection->created_by === (int) $user->id
            || $this->canAccessDailyWork($user, $dailyWork);
    }

    /**
     * Check if user can review objections.
     */
    public function canReviewObjection(User $user): bool
    {
        return $this->isPrivilegedUser($user);
    }

    /**
     * Find an objection associated with a specific daily work.
     */
    public function findObjectionForDailyWork(int $dailyWorkId, int $objectionId): ?RfiObjection
    {
        return RfiObjection::query()
            ->with(['createdBy:id,name'])
            ->where('id', $objectionId)
            ->where(function ($objectionQuery) use ($dailyWorkId) {
                $objectionQuery->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWorkId) {
                    $dailyWorkQuery->where('daily_works.id', $dailyWorkId);
                });

                if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
                    $objectionQuery->orWhere('daily_work_id', $dailyWorkId);
                }
            })
            ->first();
    }
}
