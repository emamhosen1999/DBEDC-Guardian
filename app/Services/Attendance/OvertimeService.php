<?php

namespace App\Services\Attendance;

use App\Http\Controllers\Api\V1\AttendanceRequestController;
use App\Http\Controllers\HRM\OvertimeController;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Notifications\Attendance\TimeCorrectionDecidedNotification;
use App\Notifications\Attendance\TimeCorrectionRequestedNotification;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentService;
use App\Services\Realtime\RealtimeSignal;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * SINGLE PIPELINE for overtime claims/decisions. Every side effect — state
 * transition (via {@see AttendanceApprovalService}), replacement-leave credit, the
 * recipient NOTIFICATION (request → the approver; decision → the requester), and
 * the realtime {@see RealtimeSignal} marker — lives here so it fires exactly once
 * whether the call came from the web ({@see OvertimeController})
 * or the mobile ({@see AttendanceRequestController})
 * controller. Realtime contract (do NOT change): overtime signals attendance/all;
 * actorId is the ACTING user for self-echo suppression.
 *
 * Claims are DAY-based (owner policy): only work on a holiday (pay) or a rostered rest day (pay or replacement leave),
 * credited 1 / 0.5 day from the punches, always with a clear supporting document. Minute-based requests are legacy history.
 */
class OvertimeService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly CompOffService $compOff,
        private readonly RealtimeSignal $signal,
        private readonly OvertimeEligibilityService $eligibility,
        private readonly AttachmentService $attachments,
    ) {}

    /**
     * @param  array{date: string, compensation?: ?string, declared_end_time?: ?string, reason?: ?string, attachment_tokens?: list<string>}  $data
     *
     * @throws ValidationException when the day cannot be claimed
     * @throws AttachmentRejected when the document is missing or not clear
     */
    public function request(string $userId, array $data): OvertimeRequest
    {
        $date = Carbon::parse($data['date'])->startOfDay();

        $declaredEnd = null;
        if (! empty($data['declared_end_time'])) {
            $declaredEnd = Carbon::parse($date->toDateString().' '.$data['declared_end_time']);
        }

        $check = $this->eligibility->evaluate($userId, $date, $declaredEnd);
        if (! $check['eligible'] || $check['credit'] <= 0) {
            throw ValidationException::withMessages([$check['code'] === 'declare_end' ? 'declared_end_time' : 'date' => [$check['message'] ?? __('overtime.refused.working_day')]]);
        }
        if ($check['punch_out_missing'] && $declaredEnd === null) {
            throw ValidationException::withMessages(['declared_end_time' => [__('overtime.refused.declare_end')]]);
        }
        if ($check['punch_out_missing'] && $declaredEnd !== null && ! $check['declared']) {
            throw ValidationException::withMessages(['declared_end_time' => [__('overtime.refused.declared_end_invalid')]]);
        }

        $compensation = $data['compensation'] ?? null;
        if ($compensation === null || $compensation === '') {
            throw ValidationException::withMessages(['compensation' => [__('overtime.refused.compensation_required')]]);
        }
        if (! in_array($compensation, $check['compensations'], true)) {
            $msg = match (true) {
                $check['day_type'] === OvertimeEligibilityService::HOLIDAY => __('overtime.refused.holiday_pay_only'),
                $compensation === 'payment' && $check['punch_out_missing'] => __('overtime.refused.pay_needs_punch_out'),
                default => __('overtime.refused.compensation_required'),
            };
            throw ValidationException::withMessages(['compensation' => [$msg]]);
        }

        // Every claim carries a clear application image or document.
        $staged = $this->attachments->resolve((array) ($data['attachment_tokens'] ?? []), $userId);
        if ($staged->isEmpty()) {
            throw new AttachmentRejected(__('attachments.errors.required'), 'required');
        }

        $ot = DB::transaction(function () use ($userId, $data, $date, $check, $compensation, $declaredEnd, $staged) {
            $ot = OvertimeRequest::create([
                'user_id' => $userId, 'date' => $date->toDateString(),
                'requested_minutes' => (int) $check['worked_minutes'],
                'reason' => $data['reason'] ?? ($check['day_type'] === OvertimeEligibilityService::HOLIDAY ? 'Worked on a holiday' : 'Worked on a rest day'),
                'claim_type' => $check['day_type'], 'compensation' => $compensation,
                'day_credit' => $check['credit'], 'original_day_credit' => $check['credit'],
                'end_time_declared' => (bool) $check['declared'], 'declared_end_at' => $check['declared'] ? $declaredEnd : null,
            ]);
            $this->approvals->submit($ot);
            $this->attachments->attach($ot, $staged, $userId);

            return $ot;
        });
        $ot->refresh();

        // Request → notify the first approver in the chain (skip when auto-approved:
        // an empty chain means no manager/HR on record, nobody to notify).
        if ($ot->status !== 'approved') {
            $firstApprover = collect($ot->approval_chain ?? [])->firstWhere('level', 1);
            if ($firstApprover && isset($firstApprover['approver_id'])) {
                $approver = User::find($firstApprover['approver_id']);
                if ($approver) {
                    $this->notify(
                        $approver,
                        new TimeCorrectionRequestedNotification($ot->id, User::find($userId)?->name),
                        "TimeCorrectionRequestedNotification failed for overtime #{$ot->id}"
                    );
                }
            }
        }

        // Living update: the approver's overtime queue subscribes to attendance/all.
        $this->signal->touch('attendance', 'all', $userId, 'overtime_apply');

        return $ot;
    }

    /**
     * @param  ?string  $compensation  the approver's confirmation (defaults to what the employee chose)
     * @param  ?float  $adjustedCredit  1 or 0.5, only with a reason (declared end times the approver does not fully accept)
     */
    public function approve(OvertimeRequest $ot, User $approver, ?string $comments, bool $grantCompOff = false, ?string $compensation = null, ?float $adjustedCredit = null, ?string $adjustReason = null): array
    {
        if (! $ot->isLegacy()) {
            if ($compensation !== null && $compensation !== $ot->compensation) {
                $allowed = $ot->claim_type === OvertimeEligibilityService::HOLIDAY ? ['payment'] : ['payment', 'replacement'];
                if (! in_array($compensation, $allowed, true)) {
                    return ['success' => false, 'message' => __('overtime.refused.holiday_pay_only')];
                }
                if ($compensation === 'payment' && $ot->end_time_declared && $ot->claim_type === OvertimeEligibilityService::REST_DAY && ! config('overtime_claims.allow_ot_pay_without_punch_out')) {
                    return ['success' => false, 'message' => __('overtime.refused.pay_needs_punch_out')];
                }
            }
            if ($adjustedCredit !== null && abs($adjustedCredit - (float) $ot->day_credit) > 0.001) {
                if (! in_array($adjustedCredit, [0.5, 1.0], true)) {
                    return ['success' => false, 'message' => 'The credit can only be a full or a half day.'];
                }
                if (trim((string) $adjustReason) === '') {
                    return ['success' => false, 'message' => 'Say why the credit is changed.'];
                }
            }
        }

        $res = $this->approvals->approve($ot, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $ot->refresh();
            if (! $ot->isLegacy()) {
                $this->settle($ot, $approver, $compensation, $adjustedCredit, $adjustReason);
            }
            // Legacy minute-based requests are read-only history: no credit is created for them any more.

            // Decision → notify the requester their overtime was approved.
            $requester = User::find($ot->user_id);
            if ($requester) {
                $this->notify(
                    $requester,
                    new TimeCorrectionDecidedNotification($ot->id, 'approved'),
                    "TimeCorrectionDecidedNotification(approved) failed for overtime #{$ot->id}"
                );
            }
        }

        if ($res['success'] ?? false) {
            // Living update: employee's My Requests + the approver queue watch attendance/all.
            $this->signal->touch('attendance', 'all', $approver->id, 'overtime_approve');
        }

        return $res;
    }

    /** Final approval of a day-based claim: confirmed compensation, adjusted credit, and the replacement credit itself. */
    private function settle(OvertimeRequest $ot, User $approver, ?string $compensation, ?float $adjustedCredit, ?string $adjustReason): void
    {
        $updates = [];
        if ($compensation !== null && $compensation !== $ot->compensation) {
            $updates['compensation'] = $compensation;
        }
        if ($adjustedCredit !== null && abs($adjustedCredit - (float) $ot->day_credit) > 0.001) {
            $updates += [
                'day_credit' => $adjustedCredit, 'credit_adjusted_by' => (string) $approver->employee_id,
                'credit_adjusted_at' => now(), 'credit_adjust_reason' => trim((string) $adjustReason),
            ];
        }
        if ($updates) {
            $ot->update($updates);
        }

        if ($ot->compensation === 'replacement' && ! $ot->comp_off_granted) {
            $this->compOff->credit((string) $ot->user_id, (float) $ot->day_credit, 'overtime', $ot->id, 'Rest day worked '.$ot->date->toDateString(), $ot->date);
            $ot->update(['comp_off_granted' => true]);
        }
    }

    public function reject(OvertimeRequest $ot, User $approver, string $reason): array
    {
        $res = $this->approvals->reject($ot, $approver, $reason);

        if ($res['success'] ?? false) {
            // Decision → notify the requester their overtime was rejected.
            $requester = User::find($ot->user_id);
            if ($requester) {
                $this->notify(
                    $requester,
                    new TimeCorrectionDecidedNotification($ot->id, 'rejected'),
                    "TimeCorrectionDecidedNotification(rejected) failed for overtime #{$ot->id}"
                );
            }

            // Living update: employee's My Requests + the approver queue watch attendance/all.
            $this->signal->touch('attendance', 'all', $approver->id, 'overtime_reject');
        }

        return $res;
    }

    private function notify(User $user, $notification, string $context): void
    {
        try {
            $user->notify($notification);
        } catch (\Throwable $exception) {
            Log::error($context, ['error' => $exception->getMessage()]);
        }
    }
}
