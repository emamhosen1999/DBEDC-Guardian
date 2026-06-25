<?php

namespace Tests\Feature\Leave;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class LeaveFractionEngineTest extends TestCase
{
    private function workingShift(string $date): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse("$date 09:00"), end: Carbon::parse("$date 17:00"),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0,
            fullDayMinutes: 480, halfDayMinutes: 240, minPresentMinutes: 0,
            breakMinutes: 0, isWorkingDay: true, isScheduled: true,
        );
    }

    /** @test */
    public function full_day_leave_no_punch_is_on_leave_fraction_one(): void
    {
        $r = app(AttendanceStatusService::class)->resolve(
            collect(), $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 1.0, leaveSession: null,
        );
        $this->assertSame(DayAttendance::ON_LEAVE, $r->status);
        $this->assertSame(1.0, $r->leave_fraction);
    }

    /** @test */
    public function half_day_leave_no_punch_carries_half_fraction_and_unworked_flag(): void
    {
        $r = app(AttendanceStatusService::class)->resolve(
            collect(), $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 0.5, leaveSession: 'first_half',
        );
        // Display stays ON_LEAVE; counts split (0.5 leave + 0.5 absent) handled in the report layer.
        $this->assertSame(DayAttendance::ON_LEAVE, $r->status);
        $this->assertSame(0.5, $r->leave_fraction);
        $this->assertContains('half_day_leave_unworked', $r->flags);
    }

    /** @test */
    public function half_day_leave_with_afternoon_punch_is_present_with_half_fraction(): void
    {
        $punches = new Collection([
            (object) ['punchin' => '2026-03-02 13:00:00', 'punchout' => '2026-03-02 17:00:00'],
        ]);

        $r = app(AttendanceStatusService::class)->resolve(
            $punches, $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 0.5, leaveSession: 'first_half',
        );
        $this->assertSame(0.5, $r->leave_fraction);
        $this->assertSame(240, $r->worked_minutes);
        $this->assertNotContains('worked_on_leave', $r->flags); // half-day worked is NOT a conflict
    }

    /** @test */
    public function full_day_leave_with_a_punch_flags_worked_on_leave(): void
    {
        $punches = new Collection([
            (object) ['punchin' => '2026-03-02 09:00:00', 'punchout' => '2026-03-02 17:00:00'],
        ]);

        $r = app(AttendanceStatusService::class)->resolve(
            $punches, $this->workingShift('2026-03-02'),
            isHoliday: false, isOnLeave: false, now: null, policy: null,
            leaveFraction: 1.0, leaveSession: null,
        );
        $this->assertContains('worked_on_leave', $r->flags);
    }
}
