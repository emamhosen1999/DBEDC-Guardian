<?php

namespace App\Services\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Notifications\Attendance\TimeCorrectionDecidedNotification;
use App\Notifications\Attendance\TimeCorrectionRequestedNotification;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Support\Facades\Log;

/**
 * SINGLE PIPELINE for overtime requests/decisions. Every side effect — state
 * transition (via {@see AttendanceApprovalService}), comp-off credit, the
 * recipient NOTIFICATION (request → the approver; decision → the requester), and
 * the realtime {@see RealtimeSignal} marker — lives here so it fires exactly once
 * whether the call came from the web ({@see \App\Http\Controllers\HRM\OvertimeController})
 * or the mobile ({@see \App\Http\Controllers\Api\V1\AttendanceRequestController})
 * controller. Realtime contract (do NOT change): overtime signals attendance/all;
 * actorId is the ACTING user for self-echo suppression.
 */
class OvertimeService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly CompOffService $compOff,
        private readonly RealtimeSignal $signal,
    ) {}

    public function request(int $userId, array $data): OvertimeRequest
    {
        $ot = OvertimeRequest::create([
            'user_id' => $userId, 'date' => $data['date'],
            'requested_minutes' => (int) $data['requested_minutes'], 'reason' => $data['reason'],
        ]);
        $this->approvals->submit($ot);
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

    public function approve(OvertimeRequest $ot, User $approver, ?string $comments, bool $grantCompOff): array
    {
        $res = $this->approvals->approve($ot, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $ot->refresh();
            if ($grantCompOff && ! $ot->comp_off_granted) {
                $this->compOff->credit($ot->user_id, $ot->requested_minutes, 'overtime', $ot->id, 'OT #'.$ot->id);
                $ot->update(['comp_off_granted' => true]);
            }

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
            Log::warning($context, ['error' => $exception->getMessage()]);
        }
    }
}
