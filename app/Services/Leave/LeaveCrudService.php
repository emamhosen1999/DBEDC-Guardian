<?php

namespace App\Services\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
     * Request-time eligibility gate: gender restriction and minimum service.
     *
     * @throws \RuntimeException 422 when the employee is not eligible
     */
    private function assertEligible(?LeaveSetting $setting, int $userId, Carbon $fromDate): void
    {
        if (! $setting) {
            return;
        }

        $user = User::find($userId);
        if (! $user) {
            return;
        }

        if ($setting->eligible_gender
            && strcasecmp((string) $user->gender, (string) $setting->eligible_gender) !== 0) {
            throw new \RuntimeException(
                "\"{$setting->type}\" is only available to {$setting->eligible_gender} employees.", 422
            );
        }

        if ($setting->min_service_months) {
            $join = $user->date_of_joining ? Carbon::parse($user->date_of_joining) : null;
            $serviceMonths = $join ? (int) $join->diffInMonths($fromDate) : 0;
            if ($serviceMonths < (int) $setting->min_service_months) {
                throw new \RuntimeException(
                    "\"{$setting->type}\" requires {$setting->min_service_months} months of service.", 422
                );
            }
        }
    }

    /**
     * Attachment gate: types with requires_attachment_days demand a supporting
     * document (e.g. medical certificate) once the request exceeds the threshold.
     *
     * @throws \RuntimeException 422 when a required document is missing
     */
    private function assertAttachmentRequirement(?LeaveSetting $setting, float $days, bool $hasAttachment): void
    {
        if (! $setting || $setting->requires_attachment_days === null) {
            return;
        }
        if ($days > (float) $setting->requires_attachment_days && ! $hasAttachment) {
            throw new \RuntimeException(
                "\"{$setting->type}\" requests over {$setting->requires_attachment_days} day(s) require a supporting document.", 422
            );
        }
    }

    /**
     * Balance-enforcement gate. If the (user, type, year) balance is untracked,
     * lazily seed it from the accrual policy so enforcement is never silently
     * dormant; a type that still has no ledger (no accrual config) is logged.
     *
     * @throws \RuntimeException 422 on insufficient balance
     */
    private function assertSufficientBalance(?LeaveSetting $setting, int $userId, int $leaveTypeId, Carbon $fromDate, float $days): void
    {
        if (! $setting || $setting->allow_negative) {
            return;
        }

        $year = (int) $fromDate->year;

        if (! $this->ledger->isTracked($userId, $leaveTypeId, $year)
            && config('leave.auto_seed_ledger', true)) {
            app(LeaveAccrualService::class)->seedFor($userId, $year);
        }

        if (! $this->ledger->isTracked($userId, $leaveTypeId, $year)) {
            // Still untracked (type has no accrual policy): enforcement stays
            // dormant, but never silently — surface the exposure.
            Log::warning('Leave balance untracked — enforcement bypassed', [
                'user_id' => $userId, 'leave_type' => $leaveTypeId, 'year' => $year,
            ]);

            return;
        }

        $available = $this->ledger->available($userId, $leaveTypeId, $fromDate);
        if ($days > $available) {
            throw new \RuntimeException('Insufficient leave balance for selected leave type.', 422);
        }
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

            // Request-time eligibility (gender / minimum service).
            $this->assertEligible($leaveSetting, (int) $data['user_id'], $fromDate);

            // Supporting-document gate (e.g. medical certificate for long sick leave).
            $this->assertAttachmentRequirement($leaveSetting, $serverDays, (bool) ($data['hasAttachment'] ?? false));

            // Server-side balance enforcement via the ledger (lazily seeded from the
            // accrual policy when untracked) unless the type allows negative.
            $this->assertSufficientBalance($leaveSetting, (int) $data['user_id'], (int) $leaveTypeId, $fromDate, $serverDays);

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

            // Request-time eligibility (gender / minimum service).
            $this->assertEligible($leaveSetting, (int) $data['user_id'], $fromDate);

            // Supporting-document gate (counts existing attachments on the record).
            $hasAttachment = (bool) ($data['hasAttachment'] ?? false) || $leave->getMedia('attachments')->isNotEmpty();
            $this->assertAttachmentRequirement($leaveSetting, $serverDays, $hasAttachment);

            // Server-side balance enforcement via the ledger (lazily seeded when
            // untracked) unless the type allows negative.
            $this->assertSufficientBalance($leaveSetting, (int) $data['user_id'], (int) $leaveTypeId, $fromDate, $serverDays);

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
     * Cancel a leave request (employee-initiated withdrawal or admin cancel).
     *
     * Rules:
     *  - only the owner or a user with leaves.approve / leaves.manage may cancel;
     *  - pending leave: cancellable any time;
     *  - approved leave: owner may withdraw only BEFORE it starts; an
     *    approver/manager may cancel any approved leave (full reversal);
     *  - rejected/cancelled leave: nothing to cancel.
     *
     * @throws \RuntimeException 403|422
     */
    public function cancelLeave(int $leaveId, User $actor, ?string $reason = null): Leave
    {
        return DB::transaction(function () use ($leaveId, $actor, $reason) {
            $leave = Leave::lockForUpdate()->findOrFail($leaveId);
            $before = $leave->toArray();

            $isOwner = (int) $leave->user_id === (int) $actor->id;
            $isManager = $actor->can('leaves.approve') || $actor->can('leaves.manage');

            if (! $isOwner && ! $isManager) {
                throw new \RuntimeException('You are not authorized to cancel this leave request.', 403);
            }

            $status = strtolower((string) $leave->status);

            if (in_array($status, ['cancelled', 'rejected'], true)) {
                throw new \RuntimeException('This leave request can no longer be cancelled.', 422);
            }

            if ($status === 'approved' && ! $isManager
                && Carbon::parse($leave->from_date)->startOfDay()->lte(now()->startOfDay())) {
                throw new \RuntimeException(
                    'An approved leave that has already started can only be cancelled by an approver.', 422
                );
            }

            $wasApproved = $status === 'approved';

            $leave->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            // Cancelling an approved leave frees the consumed balance.
            if ($wasApproved) {
                $this->ledger->reverseConsumption($leaveId, $reason ?? 'Leave cancelled');
            }

            $this->audit->record('cancel', $leaveId, $before, $leave->fresh()->toArray(), $reason);

            return $leave->fresh();
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
