<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\Rules\DailyOvertimeEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

class DailyOvertimeEvaluatorTest extends TestCase
{
    private function shift(): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'), end: Carbon::parse('2026-06-19 18:00'),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    private function ctx(int $worked, PolicyProfile $p): DayContext
    {
        return new DayContext(
            firstIn: null, lastOut: null, workedMinutes: $worked, flags: [],
            shift: $this->shift(), policy: $p,
        );
    }

    public function test_splits_regular_ot_and_double_time(): void
    {
        // daily OT after 8h (480), double-time after 12h (720). Worked 13h (780).
        $p = new PolicyProfile(overtime: [
            'daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5,
            'double_time_threshold_minutes' => 720, 'double_time_multiplier' => 2.0,
        ]);
        $ctx = $this->ctx(780, $p);
        (new DailyOvertimeEvaluator)->evaluate($ctx);
        $this->assertSame(480, $ctx->regularMinutes);     // first 8h
        $this->assertSame(240, $ctx->otMinutes);          // 8h..12h = 4h
        $this->assertSame(60, $ctx->doubleTimeMinutes);   // 12h..13h = 1h
    }

    public function test_no_double_time_threshold_means_all_overage_is_ot(): void
    {
        $p = new PolicyProfile(overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]);
        $ctx = $this->ctx(600, $p); // 10h
        (new DailyOvertimeEvaluator)->evaluate($ctx);
        $this->assertSame(480, $ctx->regularMinutes);
        $this->assertSame(120, $ctx->otMinutes);
        $this->assertSame(0, $ctx->doubleTimeMinutes);
    }

    public function test_under_threshold_is_all_regular(): void
    {
        $p = new PolicyProfile(overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]);
        $ctx = $this->ctx(420, $p); // 7h
        (new DailyOvertimeEvaluator)->evaluate($ctx);
        $this->assertSame(420, $ctx->regularMinutes);
        $this->assertSame(0, $ctx->otMinutes);
    }

    public function test_does_not_support_neutral_policy(): void
    {
        $this->assertFalse((new DailyOvertimeEvaluator)->supports(PolicyProfile::neutral()));
    }
}
