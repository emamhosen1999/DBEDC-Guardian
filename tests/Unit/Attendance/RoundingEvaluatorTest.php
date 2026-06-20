<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\Rules\RoundingEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

class RoundingEvaluatorTest extends TestCase
{
    private function shift(): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'), end: Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    public function test_quarter_hour_nearest_rounds_first_in_and_last_out(): void
    {
        $policy = new PolicyProfile(rounding: ['strategy' => 'quarter_hour', 'unit_minutes' => 15, 'direction' => 'nearest']);
        $ctx = new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:07'), lastOut: Carbon::parse('2026-06-19 17:08'),
            workedMinutes: 0, flags: [], shift: $this->shift(), policy: $policy,
        );
        (new RoundingEvaluator)->evaluate($ctx);
        $this->assertSame('09:00', $ctx->firstIn->format('H:i')); // 09:07 → nearest 15 = 09:00
        $this->assertSame('17:15', $ctx->lastOut->format('H:i')); // 17:08 → nearest 15 = 17:15
    }

    public function test_does_not_support_neutral_policy(): void
    {
        $this->assertFalse((new RoundingEvaluator)->supports(PolicyProfile::neutral()));
    }
}
