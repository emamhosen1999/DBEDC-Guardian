<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendanceAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AttendanceAuditService
{
    public function record(
        string $action,
        ?int $attendanceId,
        ?array $before,
        ?array $after,
        ?string $reason,
        ?Request $request = null
    ): void {
        AttendanceAuditLog::create([
            'actor_id' => Auth::id(),
            'attendance_id' => $attendanceId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip(),
        ]);
    }
}
