<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayAttendance;
use Tests\TestCase;

class DayAttendanceShapeTest extends TestCase
{
    public function test_new_fields_default_to_back_compat_values(): void
    {
        $d = new DayAttendance(
            status: DayAttendance::PRESENT, worked_minutes: 480, late_minutes: 0,
            early_leave_minutes: 0, ot_minutes: 0, first_in: null, last_out: null,
            is_complete: true, flags: [],
        );
        $this->assertSame(0, $d->double_time_minutes);
        $this->assertSame(0, $d->break_deducted_minutes);
        $this->assertSame([], $d->policy_events);
        $arr = $d->toArray();
        $this->assertSame(0, $arr['double_time_minutes']);
        $this->assertArrayHasKey('policy_events', $arr);
    }
}
