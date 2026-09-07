<?php

namespace App\Services\Project;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\RfiObjection;
use App\Models\RfiSubmissionOverrideLog;
use App\Models\User;
use App\Services\Concurrency\VersionGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DailyWorkService
{
    /**
     * Update status, inspection result, and times of a DailyWork.
     */
    public function updateStatus(
        DailyWork $dailyWork,
        string $status,
        ?string $inspectionResult = null,
        bool $updateSubmissionTime = true,
        ?int $expectedVersion = null
    ): DailyWork {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($status, $inspectionResult, $updateSubmissionTime): void {
            $updateData = ['status' => $status];

            if ($inspectionResult !== null || $status === DailyWork::STATUS_NEW) {
                $updateData['inspection_result'] = ($status === DailyWork::STATUS_NEW) ? null : $inspectionResult;
            }

            if ($status === DailyWork::STATUS_COMPLETED) {
                $updateData['completion_time'] = $locked->completion_time ?? now();
                if ($updateSubmissionTime) {
                    $updateData['submission_time'] = $locked->submission_time ?? now();
                }
            }

            if ($status === DailyWork::STATUS_NEW) {
                $updateData['completion_time'] = null;
                if ($updateSubmissionTime) {
                    $updateData['submission_time'] = null;
                }
                $updateData['inspection_result'] = null;
            }

            $locked->fill($updateData);
        });
    }

    /**
     * Update the completion time of a DailyWork.
     */
    public function updateCompletionTime(DailyWork $dailyWork, string $completionTime, ?int $expectedVersion = null): DailyWork
    {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($completionTime): void {
            $locked->completion_time = $completionTime;
        });
    }

    /**
     * Update RFI submission date, logging an override if active objections exist.
     */
    public function updateSubmissionTime(
        DailyWork $dailyWork,
        string $submissionDate,
        string $userId,
        ?string $overrideReason = null,
        ?int $expectedVersion = null
    ): DailyWork {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($submissionDate, $userId, $overrideReason): void {
            $activeObjectionsCount = $locked->objections()
                ->whereIn('status', ['draft', 'submitted', 'under_review'])
                ->count();

            if ($activeObjectionsCount > 0 && $overrideReason) {
                RfiSubmissionOverrideLog::logOverride(
                    dailyWorkId: $locked->id,
                    oldDate: $locked->rfi_submission_date?->format('Y-m-d'),
                    newDate: $submissionDate,
                    activeObjectionsCount: $activeObjectionsCount,
                    reason: $overrideReason,
                    userId: $userId
                );
            }

            $locked->rfi_submission_date = $submissionDate;
        });
    }

    /**
     * Update an RFI response under the same row lock/version boundary used by
     * interactive mutations. Imports pass the version captured during parsing,
     * so a concurrent edit is reported instead of silently overwritten.
     */
    public function updateResponseStatus(
        DailyWork $dailyWork,
        string $responseStatus,
        string $responseDate,
        string $userId,
        ?string $overrideReason = null,
        ?int $expectedVersion = null
    ): DailyWork {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($responseStatus, $responseDate, $userId, $overrideReason): void {
            $activeObjectionsCount = $locked->objections()
                ->whereIn('status', ['draft', 'submitted', 'under_review'])
                ->count();

            if ($activeObjectionsCount > 0 && $overrideReason) {
                RfiSubmissionOverrideLog::logOverride(
                    dailyWorkId: $locked->id,
                    oldDate: $locked->rfi_response_date?->format('Y-m-d'),
                    newDate: $responseDate,
                    activeObjectionsCount: $activeObjectionsCount,
                    reason: $overrideReason,
                    userId: $userId
                );
            }

            $locked->rfi_response_status = $responseStatus;
            $locked->rfi_response_date = $responseDate;
        });
    }

    /**
     * Bulk submit RFIs.
     */
    public function bulkSubmit(
        array $ids,
        array $versions,
        string $submissionDate,
        string $userId,
        bool $skipObjected = false,
        bool $overrideObjected = false,
        ?string $overrideReason = null,
        ?callable $authorizeCallback = null
    ): array {
        return DB::transaction(function () use ($ids, $versions, $submissionDate, $userId, $skipObjected, $overrideObjected, $overrideReason, $authorizeCallback): array {
            $dailyWorks = $this->lockDailyWorks($ids, $versions, true);

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
                    $work->rfi_submission_date = $submissionDate;
                    $work->lock_version = VersionGuard::next($work);
                    $work->save();
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

                        $work->rfi_submission_date = $submissionDate;
                        $work->lock_version = VersionGuard::next($work);
                        $work->save();
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
        });
    }

    /**
     * Bulk update RFI response status.
     */
    public function bulkResponseStatusUpdate(
        array $ids,
        array $versions,
        string $responseStatus,
        string $responseDate,
        string $userId,
        bool $skipObjected = false,
        bool $overrideObjected = false,
        ?string $overrideReason = null,
        ?callable $authorizeCallback = null
    ): array {
        return DB::transaction(function () use ($ids, $versions, $responseStatus, $responseDate, $userId, $skipObjected, $overrideObjected, $overrideReason, $authorizeCallback): array {
            $dailyWorks = $this->lockDailyWorks($ids, $versions, true);

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
                    if ($authorizeCallback) {
                        $authorizeCallback($work);
                    }
                    $work->rfi_response_status = $responseStatus;
                    $work->rfi_response_date = $responseDate;
                    $work->lock_version = VersionGuard::next($work);
                    $work->save();
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
                        if ($authorizeCallback) {
                            $authorizeCallback($work);
                        }
                        // Log the override
                        RfiSubmissionOverrideLog::logOverride(
                            dailyWorkId: $work->id,
                            oldDate: $work->rfi_response_date?->format('Y-m-d'),
                            newDate: $responseDate,
                            activeObjectionsCount: $work->active_objections_count,
                            reason: $overrideReason.' (Bulk response status: '.$responseStatus.')',
                            userId: $userId
                        );

                        $work->rfi_response_status = $responseStatus;
                        $work->rfi_response_date = $responseDate;
                        $work->lock_version = VersionGuard::next($work);
                        $work->save();
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
        });
    }

    /**
     * Update a set of daily works under one ordered lock/version boundary.
     *
     * @return array<int, DailyWork>
     */
    public function bulkUpdateIncharge(array $ids, array $versions, ?string $inchargeId, callable $authorizeCallback): array
    {
        return $this->bulkMutate($ids, $versions, $authorizeCallback, function (DailyWork $work) use ($inchargeId): void {
            $work->incharge = $inchargeId;
        });
    }

    /** @return array<int, DailyWork> */
    public function bulkUpdateStatus(array $ids, array $versions, string $status, callable $authorizeCallback): array
    {
        return $this->bulkMutate($ids, $versions, $authorizeCallback, function (DailyWork $work) use ($status): void {
            $work->status = $status;

            if ($status === DailyWork::STATUS_COMPLETED) {
                $work->completion_time ??= now();
            } elseif ($status === DailyWork::STATUS_NEW) {
                $work->completion_time = null;
                $work->inspection_result = null;
            }
        });
    }

    /** @return array<int, DailyWork> */
    public function bulkUpdateCompletionDate(array $ids, array $versions, ?string $completionDate, callable $authorizeCallback): array
    {
        return $this->bulkMutate($ids, $versions, $authorizeCallback, function (DailyWork $work) use ($completionDate): void {
            $work->completion_time = $completionDate;
        });
    }

    /** @return array<int, array{id:int,number:string|null}> */
    public function bulkDelete(array $ids, array $versions, callable $authorizeCallback): array
    {
        return DB::transaction(function () use ($ids, $versions, $authorizeCallback): array {
            $works = $this->lockDailyWorks($ids, $versions);

            foreach ($works as $work) {
                $authorizeCallback($work);
            }

            return $works->map(function (DailyWork $work): array {
                $deleted = ['id' => (int) $work->id, 'number' => $work->number];
                $work->delete();

                return $deleted;
            })->all();
        });
    }

    /**
     * Update the incharge user of a DailyWork.
     */
    public function updateIncharge(DailyWork $dailyWork, ?string $inchargeId, ?int $expectedVersion = null): DailyWork
    {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($inchargeId): void {
            $locked->incharge = $inchargeId;
        });
    }

    /**
     * Update the assigned user of a DailyWork.
     */
    public function updateAssigned(DailyWork $dailyWork, ?string $assignedId, ?int $expectedVersion = null): DailyWork
    {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($assignedId): void {
            $locked->assigned = $assignedId;
        });
    }

    public function updateInspectionDetails(DailyWork $dailyWork, ?string $inspectionDetails, ?int $expectedVersion = null): DailyWork
    {
        return $this->mutateDailyWork($dailyWork, $expectedVersion, function (DailyWork $locked) use ($inspectionDetails): void {
            $locked->inspection_details = $inspectionDetails;
        });
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
            $objection->created_by = (string) $user->id;

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

            $objection->statusLogs()->create([
                'from_status' => null,
                'to_status' => $objection->status,
                'notes' => 'Objection created',
                'changed_by' => (string) $user->id,
                'changed_at' => now(),
            ]);

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

            return $objection->fresh(['createdBy:employee_id,name']) ?? $objection;
        });
    }

    /**
     * Submit an RFI objection.
     */
    public function submitObjection(RfiObjection $objection, ?int $expectedVersion = null, ?string $actorId = null): RfiObjection
    {
        return $this->transitionObjection($objection, $expectedVersion, fn (RfiObjection $locked) => $locked->submit('Submitted for review', $actorId));
    }

    /**
     * Start reviewing an RFI objection.
     */
    public function startReviewObjection(RfiObjection $objection, ?int $expectedVersion = null, ?string $actorId = null): RfiObjection
    {
        return $this->transitionObjection($objection, $expectedVersion, fn (RfiObjection $locked) => $locked->startReview('Review started', $actorId));
    }

    /**
     * Resolve an RFI objection.
     */
    public function resolveObjection(RfiObjection $objection, ?string $resolutionNotes, ?int $expectedVersion = null, ?string $actorId = null): RfiObjection
    {
        return $this->transitionObjection($objection, $expectedVersion, fn (RfiObjection $locked) => $locked->resolve((string) $resolutionNotes, $actorId));
    }

    /**
     * Reject an RFI objection.
     */
    public function rejectObjection(RfiObjection $objection, ?string $rejectionReason, ?int $expectedVersion = null, ?string $actorId = null): RfiObjection
    {
        return $this->transitionObjection($objection, $expectedVersion, fn (RfiObjection $locked) => $locked->reject((string) $rejectionReason, $actorId));
    }

    private function mutateDailyWork(DailyWork $dailyWork, ?int $expectedVersion, callable $mutation): DailyWork
    {
        return DB::transaction(function () use ($dailyWork, $expectedVersion, $mutation): DailyWork {
            $locked = DailyWork::query()->lockForUpdate()->findOrFail($dailyWork->getKey());
            VersionGuard::assertMatches($locked, $expectedVersion);

            $mutation($locked);
            $locked->lock_version = VersionGuard::next($locked);
            $locked->save();

            return $locked->fresh(['inchargeUser:employee_id,name', 'assignedUser:employee_id,name']) ?? $locked;
        });
    }

    /**
     * @return array<int, DailyWork>
     */
    private function bulkMutate(array $ids, array $versions, callable $authorizeCallback, callable $mutation): array
    {
        return DB::transaction(function () use ($ids, $versions, $authorizeCallback, $mutation): array {
            $works = $this->lockDailyWorks($ids, $versions);

            foreach ($works as $work) {
                $authorizeCallback($work);
            }

            return $works->map(function (DailyWork $work) use ($mutation): DailyWork {
                $mutation($work);
                $work->lock_version = VersionGuard::next($work);
                $work->save();

                return $work->fresh(['inchargeUser:employee_id,name', 'assignedUser:employee_id,name']) ?? $work;
            })->all();
        });
    }

    /**
     * Acquire locks in a stable order and reject the whole batch if any row is
     * missing or has changed since the selection was rendered.
     *
     * @return Collection<int, DailyWork>
     */
    private function lockDailyWorks(array $ids, array $versions, bool $withObjectionCounts = false): Collection
    {
        $normalizedIds = collect($ids)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values();

        $missingVersions = $normalizedIds->filter(fn (int $id): bool => ! array_key_exists($id, $versions));
        if ($missingVersions->isNotEmpty()) {
            throw ValidationException::withMessages([
                'versions' => 'Refresh the list and retry; one or more selected records are missing a version token.',
            ]);
        }

        $query = DailyWork::query()
            ->whereIn('id', $normalizedIds->all())
            ->orderBy('id')
            ->lockForUpdate();

        if ($withObjectionCounts) {
            $query->withCount(['objections as active_objections_count' => function ($objectionQuery) {
                $objectionQuery->whereIn('status', ['draft', 'submitted', 'under_review']);
            }]);
        }

        /** @var Collection<int, DailyWork> $works */
        $works = $query->get();

        if ($works->count() !== $normalizedIds->count()) {
            throw ValidationException::withMessages([
                'ids' => 'One or more selected daily works no longer exist. Refresh the list and retry.',
            ]);
        }

        foreach ($works as $work) {
            VersionGuard::assertMatches($work, (int) $versions[$work->id]);
        }

        return $works;
    }

    private function transitionObjection(RfiObjection $objection, ?int $expectedVersion, callable $transition): RfiObjection
    {
        return DB::transaction(function () use ($objection, $expectedVersion, $transition): RfiObjection {
            $locked = RfiObjection::query()->lockForUpdate()->findOrFail($objection->getKey());
            VersionGuard::assertMatches($locked, $expectedVersion);
            $locked->lock_version = VersionGuard::next($locked);
            $transition($locked);

            return $locked->fresh(['createdBy:employee_id,name']) ?? $locked;
        });
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
        } elseif ($user->hasRole('Department Manager') && $user->department_id !== null) {
            $query->where(function ($q) use ($user) {
                $q->whereHas('inchargeUser', function ($uq) use ($user) {
                    $uq->where('department_id', $user->department_id);
                })->orWhereHas('assignedUser', function ($uq) use ($user) {
                    $uq->where('department_id', $user->department_id);
                });
            });
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

        $uid = (string) ($user->employee_id ?? $user->getKey());

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::where('incharge', $uid)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can view works where they are incharge
                return (string) $dailyWork->incharge === $uid;
            } else {
                // Employee has no jurisdiction: can view works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can view own works
                return (string) $dailyWork->incharge === $uid;
            }
        }

        // For other roles (non-employee, non-admin): can view if incharge/assigned OR manager is incharge
        if ((string) $dailyWork->incharge === $uid
            || (string) $dailyWork->assigned === $uid) {
            return true;
        }

        if ($user->report_to && (string) $dailyWork->incharge === (string) $user->report_to) {
            return true;
        }

        return false;
    }

    /**
     * Check if user can submit an objection.
     */
    public function canSubmitObjection(User $user, RfiObjection $objection): bool
    {
        $uid = (string) ($user->employee_id ?? $user->getKey());

        return (string) $objection->created_by === $uid || $this->isPrivilegedUser($user);
    }

    /**
     * Check if user can view objection files.
     */
    public function canViewObjectionFiles(User $user, DailyWork $dailyWork, RfiObjection $objection): bool
    {
        $uid = (string) ($user->employee_id ?? $user->getKey());

        return $this->isPrivilegedUser($user)
            || (string) $objection->created_by === $uid
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

        $uid = (string) ($user->employee_id ?? $user->getKey());

        return $this->isPrivilegedUser($user)
            || (string) $objection->created_by === $uid
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
            ->with(['createdBy:employee_id,name'])
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
