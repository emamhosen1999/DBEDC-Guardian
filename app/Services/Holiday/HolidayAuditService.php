<?php

namespace App\Services\Holiday;

use App\Models\HRM\HolidayAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HolidayAuditService
{
    public function record(
        string $action,
        ?int $holidayId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
        ?Request $request = null
    ): void {
        HolidayAuditLog::create([
            'actor_id' => Auth::id(),
            'holiday_id' => $holidayId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $request?->ip() ?? request()?->ip(),
        ]);
    }
}
