<?php

namespace Tests\Feature\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EnginePrecedenceTest extends TestCase
{
    private function offDay(): ShiftSchedule
    {
        return ShiftSchedule::nonWorking(Carbon::create(2026, 6, 13)); // a Saturday
    }

    public function test_leave_on_a_rest_day_resolves_to_weekend_not_leave(): void
    {
        $result = app(AttendanceStatusService::class)->resolve(
            new Collection(), // no punches
            $this->offDay(),
            isHoliday: false,
            isOnLeave: true,
        );

        $this->assertSame(DayAttendance::WEEKEND, $result->status);
    }

    public function test_holiday_still_outranks_everything_on_a_rest_day(): void
    {
        $result = app(AttendanceStatusService::class)->resolve(
            new Collection(),
            $this->offDay(),
            isHoliday: true,
            isOnLeave: true,
        );

        $this->assertSame(DayAttendance::HOLIDAY, $result->status);
    }

    public function test_leave_on_a_working_day_with_no_punch_still_on_leave(): void
    {
        $working = new ShiftSchedule(
            start: Carbon::create(2026, 6, 10, 9), end: Carbon::create(2026, 6, 10, 17),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0,
            fullDayMinutes: 420, halfDayMinutes: 210, minPresentMinutes: 60,
            breakMinutes: 60, isWorkingDay: true,
        );

        $result = app(AttendanceStatusService::class)->resolve(
            new Collection(), $working, isHoliday: false, isOnLeave: true,
        );

        $this->assertSame(DayAttendance::ON_LEAVE, $result->status);
    }
}
