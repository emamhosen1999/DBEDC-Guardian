<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Notifications\Attendance\TimeCorrectionDecidedNotification;
use App\Notifications\Attendance\TimeCorrectionRequestedNotification;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SINGLE PIPELINE for time-correction (regularization) requests/decisions. Every
 * side effect — state transition (via {@see AttendanceApprovalService}), applying
 * the approved correction to attendance + audit, the recipient NOTIFICATION
 * (request → the approver; decision → the requester), and the realtime
 * {@see RealtimeSignal} marker — lives here so it fires exactly once whether the
 * call came from the web ({@see \App\Http\Controllers\HRM\RegularizationController})
 * or the mobile ({@see \App\Http\Controllers\Api\V1\AttendanceRequestController})
 * controller. Realtime contract (do NOT change): regularization signals
 * attendance/all; actorId is the ACTING user for self-echo suppression.
 */
class RegularizationService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly AttendanceAuditService $audit,
        private readonly RealtimeSignal $signal,
    ) {}

    public function request(int $userId, array $data): AttendanceRegularization
    {
        $targetDate = \Carbon\Carbon::parse($data['date'])->startOfDay();
        $today = \Carbon\Carbon::today();

        if ($targetDate->greaterThanOrEqualTo($today)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'date' => 'Regularization requests can only be submitted for previous dates.',
            ]);
        }

        $requester = User::find($userId);

        $r = AttendanceRegularization::create([
            'user_id' => $userId,
            'date' => $data['date'],
            'type' => $data['type'],
            'requested_punchin' => $data['requested_punchin'] ?? null,
            'requested_punchout' => $data['requested_punchout'] ?? null,
            'reason' => $data['reason'],
        ]);
        $this->approvals->submit($r);

        // If auto-approved (no chain), apply immediately.
        $r->refresh();
        if ($r->status === 'approved') {
            $this->applyApproved($r);
        } else {
            // Notify the first approver in the chain of the new time correction request
            $firstApprover = collect($r->approval_chain ?? [])->firstWhere('level', 1);
            if ($firstApprover && isset($firstApprover['approver_id'])) {
                $approverUser = User::find($firstApprover['approver_id']);
                if ($approverUser) {
                    try {
                        $approverUser->notify(new TimeCorrectionRequestedNotification($r->id, $requester?->name));
                    } catch (\Throwable $exception) {
                        Log::warning("TimeCorrectionRequestedNotification failed for regularization #{$r->id}", [
                            'error' => $exception->getMessage(),
                        ]);
                    }
                }
            }
        }

        // Living update: the approver's regularization queue subscribes to
        // attendance/all — nudge it so a fresh request surfaces without a refresh.
        $this->signal->touch('attendance', 'all', $userId, 'regularization_apply');

        return $r;
    }

    public function approve(AttendanceRegularization $r, User $approver, ?string $comments = null): array
    {
        $res = $this->approvals->approve($r, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $this->applyApproved($r->fresh());

            // Decision → notify the requester that their time correction was approved.
            $requesterUser = User::find($r->user_id);
            if ($requesterUser) {
                try {
                    $requesterUser->notify(new TimeCorrectionDecidedNotification($r->id, 'approved'));
                } catch (\Throwable $exception) {
                    Log::warning("TimeCorrectionDecidedNotification(approved) failed for regularization #{$r->id}", [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        }

        if ($res['success'] ?? false) {
            // Living update: employee's My Requests + the approver queue watch attendance/all.
            $this->signal->touch('attendance', 'all', $approver->id, 'regularization_approve');
        }

        return $res;
    }

    public function reject(AttendanceRegularization $r, User $approver, string $reason): array
    {
        $res = $this->approvals->reject($r, $approver, $reason);

        if ($res['success'] ?? false) {
            // Decision → notify the requester that their time correction was rejected.
            $requesterUser = User::find($r->user_id);
            if ($requesterUser) {
                try {
                    $requesterUser->notify(new TimeCorrectionDecidedNotification($r->id, 'rejected'));
                } catch (\Throwable $exception) {
                    Log::warning("TimeCorrectionDecidedNotification(rejected) failed for regularization #{$r->id}", [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            // Living update: employee's My Requests + the approver queue watch attendance/all.
            $this->signal->touch('attendance', 'all', $approver->id, 'regularization_reject');
        }

        return $res;
    }

    public function applyApproved(AttendanceRegularization $r): void
    {
        if ($r->status !== 'approved' || $r->applied) {
            return;
        }

        DB::transaction(function () use ($r) {
            $att = Attendance::where('user_id', $r->user_id)->whereDate('date', $r->date->toDateString())->latest()->first();
            $before = $att ? $att->only(['punchin', 'punchout', 'date']) : null;

            $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)
                ->resolve((int) $r->user_id, \Carbon\Carbon::parse($r->date));

            $payload = ['user_id' => $r->user_id, 'date' => $r->date->toDateString()];
            if ($r->type === 'missed_day') {
                $payload['punchin'] = $shift->start;
                $payload['punchout'] = $shift->end;
                $payload['symbol'] = '√';
            } else {
                if ($r->requested_punchin) {
                    $payload['punchin'] = $r->requested_punchin;
                }
                if ($r->requested_punchout) {
                    $payload['punchout'] = $r->requested_punchout;
                }
            }

            if ($att) {
                $att->update($payload);
            } else {
                $att = Attendance::create($payload + ['symbol' => '√']);
            }

            $this->audit->record('regularize', $att->id, $before, $att->only(['punchin', 'punchout', 'date']), 'Regularization #'.$r->id, null);
            $r->update(['applied' => true, 'attendance_id' => $att->id]);
        });
    }
}
