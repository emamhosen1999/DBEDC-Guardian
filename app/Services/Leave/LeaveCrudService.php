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

    protected LeaveLedgerService $ledger;

    public function __construct(
        LeaveApprovalService $approvalService,
        LeaveOverlapService $overlapService,
        LeaveDayCalculator $dayCalculator,
        LeaveAuditService $audit,
        LeaveLedgerService $ledger
    ) {
        $this->approvalService = $approvalService;
        $this->overlapService = $overlapService;
        $this->dayCalculator = $dayCalculator;
        $this->audit = $audit;
        $this->ledger = $ledger;
    }

    /**
     * Post a consumption transaction for an approved leave (net-idempotent).
     */
    private function postConsumption(Leave $leave): void
    {
        $this->ledger->consume($leave);
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

            // Server-side balance enforcement via the ledger (only once the balance is
            // tracked/seeded; dormant for un-onboarded types) unless the type allows negative.
            if ($leaveSetting && ! $leaveSetting->allow_negative
                && $this->ledger->isTracked((int) $data['user_id'], (int) $leaveTypeId, (int) $fromDate->year)) {
                $available = $this->ledger->available((int) $data['user_id'], (int) $leaveTypeId, $fromDate);
                if ($serverDays > $available) {
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

                // An approved leave immediately consumes balance.
                $this->postConsumption($leave->fresh());
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

            // If the existing leave was approved, free its consumed days before the
            // balance check (so editing an approved leave doesn't double-count it).
            $wasApproved = $leave->status === 'approved';
            if ($wasApproved) {
                $this->ledger->reverseConsumption($leaveId, 'Leave edited');
            }

            // Server-side balance enforcement via the ledger (tracked types only) unless allow_negative.
            if ($leaveSetting && ! $leaveSetting->allow_negative
                && $this->ledger->isTracked((int) $data['user_id'], (int) $leaveTypeId, (int) $fromDate->year)) {
                $available = $this->ledger->available((int) $data['user_id'], (int) $leaveTypeId, $fromDate);
                if ($serverDays > $available) {
                    throw new \RuntimeException('Insufficient leave balance for selected leave type.', 422);
                }
            }

            $newStatus = $data['status'] ?? $leave->status;
            $leave->update([
                'user_id' => $data['user_id'],
                'leave_type' => $leaveTypeId,
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'no_of_days' => $serverDays,
                'is_half_day' => $isHalfDay,
                'half_day_session' => $halfDaySession,
                'reason' => $data['leaveReason'],
                'status' => $newStatus,
            ]);

            // Re-post consumption for the new amount if the leave is (still) approved.
            if ($newStatus === 'approved') {
                $this->postConsumption($leave->fresh());
            }

            $this->audit->record('update', $leave->id, $before, $leave->fresh()->toArray());

            return $leave->fresh(); // Ensure we return the latest data with relationships
        });
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
                $wasApproved = $leave->status === 'approved';

                $leave->update([
                    'status' => $normalized,
                    'approved_by' => $approvedBy,
                ]);

                // Ledger: approving consumes; leaving an approved state reverses.
                if ($normalized === 'approved') {
                    $this->postConsumption($leave->fresh());
                } elseif ($wasApproved) {
                    $this->ledger->reverseConsumption($leaveId, "status -> {$normalized}");
                }

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

            // Free any consumed balance before removing the leave.
            if ($leave->status === 'approved') {
                $this->ledger->reverseConsumption($leaveId, 'Leave deleted');
            }

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
