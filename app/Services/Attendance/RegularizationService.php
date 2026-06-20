<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RegularizationService
{
    public function __construct(
        private readonly AttendanceApprovalService $approvals,
        private readonly AttendanceAuditService $audit,
    ) {}

    public function request(int $userId, array $data): AttendanceRegularization
    {
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
        }

        return $r;
    }

    public function approve(AttendanceRegularization $r, User $approver, ?string $comments = null): array
    {
        $res = $this->approvals->approve($r, $approver, $comments);
        if (($res['status'] ?? null) === 'approved') {
            $this->applyApproved($r->fresh());
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

            $payload = ['user_id' => $r->user_id, 'date' => $r->date->toDateString()];
            if ($r->requested_punchin) {
                $payload['punchin'] = $r->requested_punchin;
            }
            if ($r->requested_punchout) {
                $payload['punchout'] = $r->requested_punchout;
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
