<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Notifications\Attendance\TimeCorrectionDecidedNotification;
use App\Notifications\Attendance\TimeCorrectionRequestedNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RegularizationService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly AttendanceAuditService $audit,
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

        return $r;
    }

    public function approve(AttendanceRegularization $r, User $approver, ?string $comments = null): array
    {
        $res = $this->approvals->approve($r, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $this->applyApproved($r->fresh());

            // Notify the requester that their time correction was approved
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
