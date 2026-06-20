<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AttendanceStatusServiceTest extends TestCase
{
    private function shift(string $date, string $start = '09:00', string $end = '17:00', array $over = []): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse("$date $start"),
            end: Carbon::parse("$date $end"),
            crossesMidnight: $over['crossesMidnight'] ?? false,
            graceInMinutes: $over['graceInMinutes'] ?? 15,
            graceOutMinutes: $over['graceOutMinutes'] ?? 0,
            fullDayMinutes: $over['fullDayMinutes'] ?? 0,
            halfDayMinutes: $over['halfDayMinutes'] ?? 0,
            minPresentMinutes: $over['minPresentMinutes'] ?? 0,
            breakMinutes: $over['breakMinutes'] ?? 0,
            isWorkingDay: $over['isWorkingDay'] ?? true,
        );
    }

    private function punch(?string $in, ?string $out): object
    {
        return (object) [
            'punchin' => $in ? Carbon::parse($in) : null,
            'punchout' => $out ? Carbon::parse($out) : null,
        ];
    }

    public function test_absent_when_working_day_and_no_punches(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            new Collection,
            $this->shift('2026-06-19'),
        );
        $this->assertSame(DayAttendance::ABSENT, $r->status);
    }

    public function test_weekend_when_non_working_and_no_punches(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            new Collection,
            ShiftSchedule::nonWorking(Carbon::parse('2026-06-20')),
        );
        $this->assertSame(DayAttendance::WEEKEND, $r->status);
    }

    public function test_holiday_and_leave_take_priority_when_no_punches(): void
    {
        $svc = new AttendanceStatusService;
        $shift = $this->shift('2026-06-19');

        $this->assertSame(DayAttendance::HOLIDAY,
            $svc->resolve(new Collection, $shift, isHoliday: true)->status);
        $this->assertSame(DayAttendance::ON_LEAVE,
            $svc->resolve(new Collection, $shift, isOnLeave: true)->status);
    }

    public function test_present_on_time(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:05', '2026-06-19 17:30')]),
            $this->shift('2026-06-19'),
        );
        $this->assertSame(DayAttendance::PRESENT, $r->status);
        $this->assertSame(0, $r->late_minutes);
        $this->assertSame(505, $r->worked_minutes); // 8h25m
    }

    public function test_late_uses_shift_start_plus_grace_not_hardcoded_9(): void
    {
        // Shift starts 10:00, grace 15 → late threshold 10:15. Punch 10:30 = 15 late.
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 10:30', '2026-06-19 18:00')]),
            $this->shift('2026-06-19', '10:00', '18:00'),
        );
        $this->assertSame(DayAttendance::LATE, $r->status);
        $this->assertSame(15, $r->late_minutes);
    }

    public function test_night_shift_spanning_midnight_computes_hours(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 20:00', '2026-06-20 04:00')]),
            $this->shift('2026-06-19', '20:00', '04:00', ['crossesMidnight' => true]),
        );
        $this->assertSame(480, $r->worked_minutes); // 8h across midnight
    }

    public function test_missing_punch_out_flagged_and_incomplete(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', null)]),
            $this->shift('2026-06-19'),
        );
        $this->assertFalse($r->is_complete);
        $this->assertContains('missing_punch_out', $r->flags);
    }

    public function test_half_day_when_threshold_configured(): void
    {
        // full=480, half=240; worked 3h = 180 < half → half_day
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', '2026-06-19 12:00')]),
            $this->shift('2026-06-19', '09:00', '17:00', ['fullDayMinutes' => 480, 'halfDayMinutes' => 240]),
        );
        $this->assertSame(DayAttendance::HALF_DAY, $r->status);
    }

    public function test_overtime_minutes_beyond_full_day(): void
    {
        // full=480; worked 9h = 540 → 60 OT
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', '2026-06-19 18:00')]),
            $this->shift('2026-06-19', '09:00', '17:00', ['fullDayMinutes' => 480]),
        );
        $this->assertSame(60, $r->ot_minutes);
    }

    public function test_present_on_non_working_day_is_not_absent(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-20 10:00', '2026-06-20 14:00')]),
            ShiftSchedule::nonWorking(Carbon::parse('2026-06-20')),
        );
        $this->assertSame(DayAttendance::PRESENT, $r->status);
    }

    public function test_early_leave_minutes_when_punch_out_before_shift_end(): void
    {
        // Shift 09:00-17:00, grace-out 0 → leaving at 16:00 is 60 minutes early.
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:00', '2026-06-19 16:00')]),
            $this->shift('2026-06-19', '09:00', '17:00', ['graceOutMinutes' => 0]),
        );
        $this->assertSame(60, $r->early_leave_minutes);
    }

    public function test_off_day_work_is_flagged_and_all_overtime(): void
    {
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-20 10:00', '2026-06-20 16:00')]),     // 6h on a day off
            ShiftSchedule::nonWorking(Carbon::parse('2026-06-20')),
        );
        $this->assertSame(DayAttendance::PRESENT, $r->status);
        $this->assertContains('worked_on_off_day', $r->flags);
        $this->assertSame(360, $r->ot_minutes); // all 6h are OT on an off day
    }

    public function test_unscheduled_flag_when_schedule_not_explicit(): void
    {
        // a working window but not explicitly rostered/assigned (isScheduled=false)
        $shift = new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'), end: Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0, fullDayMinutes: 0, halfDayMinutes: 0,
            minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true, isScheduled: false,
        );
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:05', '2026-06-19 17:30')]), $shift,
        );
        $this->assertContains('unscheduled', $r->flags);
    }

    public function test_outside_shift_window_flag(): void
    {
        // shift 09:00-17:00; punched in 06:30 (> 120 min early)
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 06:30', '2026-06-19 17:00')]),
            $this->shift('2026-06-19', '09:00', '17:00'),
        );
        $this->assertContains('outside_shift_window', $r->flags);
    }
}
