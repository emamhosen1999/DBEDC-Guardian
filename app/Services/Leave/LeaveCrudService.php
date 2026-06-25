<?php

namespace App\Services\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveCrudService
{
    protected LeaveApprovalService $approvalService;

    protected LeaveOverlapService $overlapService;

    protected LeaveDayCalculator $dayCalculator;

    protected LeaveAuditService $audit;

    public function __construct(
        LeaveApprovalService $approvalService,
        LeaveOverlapService $overlapService,
        LeaveDayCalculator $dayCalculator,
        LeaveAuditService $audit
    ) {
        $this->approvalService = $approvalService;
        $this->overlapService = $overlapService;
        $this->dayCalculator = $dayCalculator;
        $this->audit = $audit;
    }

    /**
     * Create a new leave record
     */
    public function createLeave(array $data): Leave
    {
        return DB::transaction(function () use ($data) {
            $fromDate = Carbon::parse($data['fromDate']);
            $toDate = Carbon::parse($data['toDate']);

            $leaveTypeId = LeaveSetting::where('type', $data['leaveType'])->value('id');
            $leaveSetting = LeaveSetting::find($leaveTypeId);

            // Server-side overlap enforcement
            $overlapError = $this->overlapService->getOverlapErrorMessage(
                (int) $data['user_id'], $fromDate, $toDate
            );

            if ($overlapError) {
                throw new \RuntimeException($overlapError, 422);
            }

            // Server-authoritative day-count: working days in range minus weekly-off
            // and holidays, halved for a half-day leave. Client daysCount is ignored.
            $isHalfDay = (bool) ($data['isHalfDay'] ?? false);
            $halfDaySession = $isHalfDay ? ($data['halfDaySession'] ?? 'first_half') : null;
            $serverDays = $this->dayCalculator->compute(
                (int) $data['user_id'], $fromDate, $toDate, $isHalfDay
            );

            // Server-side balance enforcement: if leave type has an allocation, ensure user has enough remaining days
            if ($leaveSetting && is_numeric($leaveSetting->days)) {
                $year = (int) $fromDate->year;
                $requested = $serverDays;
                $remaining = $this->getRemainingDays((int) $data['user_id'], (int) $leaveTypeId, $year);

                if ($requested > $remaining) {
                    throw new \RuntimeException('Insufficient leave balance for selected leave type.', 422);
                }
            }

            $leave = Leave::create([
                'user_id' => $data['user_id'],
                'leave_type' => $leaveTypeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'no_of_days' => $serverDays,
                'is_half_day' => $isHalfDay,
                'half_day_session' => $halfDaySession,
                'reason' => $data['leaveReason'],
                'status' => 'pending',
            ]);

            // Check if approval is required or auto-approve is enabled
            if ($leaveSetting && (! $leaveSetting->requires_approval || $leaveSetting->auto_approve)) {
                // Auto-approve the leave
                $leave->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                ]);
            } else {
                // Build and submit approval chain
                $this->approvalService->submitForApproval($leave);
            }

            $this->audit->record('create', $leave->id, null, $leave->fresh()->toArray());

            return $leave->fresh(); // Ensure we return the latest data
        });
    }

    /**
     * Update an existing leave record
     */
    public function updateLeave(int $leaveId, array $data): Leave
    {
        return DB::transaction(function () use ($leaveId, $data) {
            $leave = Leave::lockForUpdate()->findOrFail($leaveId);
            $before = $leave->toArray();

            // Parse dates correctly for consistent format
            $fromDate = Carbon::parse($data['fromDate']);
            $toDate = Carbon::parse($data['toDate']);

            // Get leave type ID
            $leaveTypeId = LeaveSetting::where('type', $data['leaveType'])->value('id');
            if (! $leaveTypeId) {
                // Fallback to current leave_type if not found
                $leaveTypeId = $leave->leave_type;
            }

            $leaveSetting = LeaveSetting::find($leaveTypeId);

            // Server-side overlap enforcement for updates
            $overlapError = $this->overlapService->getOverlapErrorMessage(
                (int) $data['user_id'], $fromDate, $toDate, $leaveId
            );

            if ($overlapError) {
                throw new \RuntimeException($overlapError, 422);
            }

            // Server-authoritative day-count (see createLeave).
            $isHalfDay = (bool) ($data['isHalfDay'] ?? false);
            $halfDaySession = $isHalfDay ? ($data['halfDaySession'] ?? 'first_half') : null;
            $serverDays = $this->dayCalculator->compute(
                (int) $data['user_id'], $fromDate, $toDate, $isHalfDay
            );

            // Server-side balance enforcement on update (exclude current leave from calculation)
            if ($leaveSetting && is_numeric($leaveSetting->days)) {
                $year = (int) $fromDate->year;
                $requested = $serverDays;
                $remaining = $this->getRemainingDays((int) $data['user_id'], (int) $leaveTypeId, $year, $leaveId);

                if ($requested > $remaining) {
                    throw new \RuntimeException('Insufficient leave balance for selected leave type.', 422);
                }
            }

            $leave->update([
                'user_id' => $data['user_id'],
                'leave_type' => $leaveTypeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'no_of_days' => $serverDays,
                'is_half_day' => $isHalfDay,
                'half_day_session' => $halfDaySession,
                'reason' => $data['leaveReason'],
                'status' => $data['status'] ?? $leave->status,
            ]);

            $this->audit->record('update', $leave->id, $before, $leave->fresh()->toArray());

            return $leave->fresh(); // Ensure we return the latest data with relationships
        });
    }

    /**
     * Compute remaining days for a user's leave type for a year.
     * Excludes an optional leave id from the aggregation (useful for updates).
     */
    private function getRemainingDays(int $userId, int $leaveTypeId, int $year, ?int $excludeLeaveId = null): float
    {
        $leaveSetting = LeaveSetting::find($leaveTypeId);

        if (! $leaveSetting || ! is_numeric($leaveSetting->days)) {
            // Treat as unlimited if no allocation is configured
            return (float) PHP_INT_MAX;
        }

        $allocated = (int) $leaveSetting->days;

        $query = DB::table('leaves')
            ->where('user_id', $userId)
            ->where('leave_type', $leaveTypeId)
            ->whereYear('from_date', $year)
            ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'pending']);

        if ($excludeLeaveId) {
            $query->where('id', '<>', $excludeLeaveId);
        }

        // Lock relevant rows to avoid races; aggregate in PHP after selecting rows
        $rows = $query->lockForUpdate()->get(['no_of_days']);

        // Sum as float so half-day (0.5) usage is not truncated in the balance check.
        $used = (float) $rows->sum('no_of_days');

        return max(0, $allocated - $used);
    }

    /**
     * Update leave status
     */
    public function updateLeaveStatus(int $leaveId, string $status, int $approvedBy): array
    {
        return DB::transaction(function () use ($leaveId, $status, $approvedBy) {
            $leave = Leave::lockForUpdate()->findOrFail($leaveId);
            $before = $leave->toArray();

            $normalized = strtolower((string) $status);

            if ($leave->status !== $normalized) {
                $leave->update([
                    'status' => $normalized,
                    'approved_by' => $approvedBy,
                ]);

                $this->audit->record('status_change', $leave->id, $before, $leave->fresh()->toArray(), "status -> {$normalized}");

                return [
                    'success' => true,
                    'updated' => true,
                    'message' => 'Leave application status updated to '.$normalized,
                ];
            }

            return [
                'success' => false,
                'updated' => false,
                'message' => 'Leave status remains unchanged.',
            ];
        });
    }

    /**
     * Delete a leave record
     */
    public function deleteLeave(int $leaveId): bool
    {
        return DB::transaction(function () use ($leaveId) {
            $leave = Leave::lockForUpdate()->findOrFail($leaveId);
            $before = $leave->toArray();

            $deleted = $leave->delete();

            $this->audit->record('delete', $leaveId, $before, null);

            return $deleted;
        });
    }

    /**
     * Find leave by ID
     */
    public function findLeave(int $leaveId): Leave
    {
        return Leave::findOrFail($leaveId);
    }
}
