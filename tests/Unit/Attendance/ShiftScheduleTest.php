<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class ShiftScheduleTest extends TestCase
{
    public function test_holds_window_and_flags(): void
    {
        $s = new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00:00'),
            end: Carbon::parse('2026-06-19 17:30:00'),
            crossesMidnight: false,
            graceInMinutes: 15,
            graceOutMinutes: 10,
            fullDayMinutes: 480,
            halfDayMinutes: 240,
            minPresentMinutes: 60,
            breakMinutes: 30,
            isWorkingDay: true,
        );

        $this->assertSame(15, $s->graceInMinutes);
        $this->assertTrue($s->isWorkingDay);
        $this->assertSame('2026-06-19 17:30:00', $s->end->format('Y-m-d H:i:s'));
    }

    public function test_non_working_day_factory(): void
    {
        $s = ShiftSchedule::nonWorking(Carbon::parse('2026-06-20'));
        $this->assertFalse($s->isWorkingDay);
    }
}
