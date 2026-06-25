<?php

namespace App\Services\Leave;

use App\Models\HRM\LeaveAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveAuditService
{
    public function record(
        string $action,
        ?int $leaveId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?Request $request = null
    ): void {
        LeaveAuditLog::create([
            'actor_id' => Auth::id(),
            'leave_id' => $leaveId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip() ?? request()?->ip(),
        ]);
    }
}
