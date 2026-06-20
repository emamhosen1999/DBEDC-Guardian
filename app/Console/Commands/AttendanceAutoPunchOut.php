<?php

namespace App\Console\Commands;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AttendanceAutoPunchOut extends Command
{
    protected $signature = 'attendance:auto-punch-out';

    protected $description = 'Close forgotten open punches at their resolved shift end when auto_punch_out is enabled.';

    public function handle(ScheduleResolver $schedules, AttendanceAuditService $audit): int
    {
        $settings = AttendanceSetting::first();
        if (! $settings || ! $settings->auto_punch_out) {
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $rows = Attendance::whereNull('punchout')
            ->whereNotNull('punchin')
            ->whereDate('date', '>=', $now->copy()->subDays(2)->toDateString())
            ->get();

        $closed = 0;
        foreach ($rows as $row) {
            $in = Carbon::parse($row->punchin);
            $shift = $schedules->resolve($row->user_id, $in);
            $end = $shift->isWorkingDay ? $shift->end->copy() : $in->copy()->endOfDay();
            if ($now->lessThan($end)) {
                continue; // still on shift
            }
            $before = $row->only(['punchout']);
            $row->update(['punchout' => $end]);
            $audit->record('attendance.auto_punch_out', $row->id, $before, $row->only(['punchout']), 'auto punched out at shift end', null);
            $closed++;
        }

        $this->info("Auto-punched-out {$closed} open attendance row(s).");

        return self::SUCCESS;
    }
}
