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
            // Resolve by the row's BUSINESS date, not the punch moment: a night-shift punch-in
            // after midnight belongs to the shift that started the evening before.
            $shift = $schedules->resolve($row->user_id, Carbon::parse($row->date)->startOfDay());
            // Off-day rows (and a punch-in made after the resolved shift already ended) have no
            // scheduled end to anchor on: credit one 8-hour shift from the punch-in. The old
            // endOfDay() fallback produced near-24h phantom rows, and a shift end earlier than
            // the punch-in produced a punch-out BEFORE the punch-in (negative worked time).
            // The row is only closed once that moment has passed, so never in the future and
            // never while someone is still on their off-day shift.
            $end = $shift->isWorkingDay ? $shift->end->copy() : $in->copy()->addHours(8);
            if ($end->lessThanOrEqualTo($in)) {
                $end = $in->copy()->addHours(8);
            }
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
