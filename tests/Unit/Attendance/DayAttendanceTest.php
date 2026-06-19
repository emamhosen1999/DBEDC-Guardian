<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayAttendance;
use Carbon\Carbon;
use Tests\TestCase;

class DayAttendanceTest extends TestCase
{
    public function test_to_array_exposes_contract(): void
    {
        $d = new DayAttendance(
            status: DayAttendance::LATE,
            worked_minutes: 470,
            late_minutes: 20,
            early_leave_minutes: 0,
            ot_minutes: 0,
            first_in: Carbon::parse('2026-06-19 09:20:00'),
            last_out: Carbon::parse('2026-06-19 17:30:00'),
            is_complete: true,
            flags: [],
        );

        $arr = $d->toArray();
        $this->assertSame('late', $arr['status']);
        $this->assertSame(470, $arr['worked_minutes']);
        $this->assertSame('2026-06-19 09:20:00', $arr['first_in']);
        $this->assertTrue($arr['is_complete']);
    }
}
