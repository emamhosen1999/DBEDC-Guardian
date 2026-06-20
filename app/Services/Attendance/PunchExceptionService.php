<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PunchExceptionService
{
    public function __construct(private readonly AttendanceAuditService $audit) {}

    public function pending(): Collection
    {
        return Attendance::where('needs_approval', true)->where('policy_status', 'provisional')
            ->with('user:id,name')->orderByDesc('date')->get();
    }

    public function approve(int $attendanceId, User $approver): array
    {
        return DB::transaction(function () use ($attendanceId, $approver) {
            $att = Attendance::findOrFail($attendanceId);
            $before = $att->only(['policy_status', 'needs_approval']);
            $att->update(['policy_status' => 'accepted', 'needs_approval' => false]);
            $this->audit->record('policy.exception.approve', $att->id, $before, $att->only(['policy_status', 'needs_approval']), 'Punch exception approved by '.$approver->id, null);

            return ['success' => true, 'status' => 'accepted'];
        });
    }

    public function reject(int $attendanceId, User $approver, string $reason): array
    {
        return DB::transaction(function () use ($attendanceId, $approver, $reason) {
            $att = Attendance::findOrFail($attendanceId);
            $before = $att->only(['policy_status', 'needs_approval']);
            $att->update(['policy_status' => 'rejected', 'needs_approval' => false, 'policy_exception_reason' => $reason]);
            $this->audit->record('policy.exception.reject', $att->id, $before, $att->only(['policy_status', 'needs_approval']), $reason, null);

            return ['success' => true, 'status' => 'rejected'];
        });
    }
}
