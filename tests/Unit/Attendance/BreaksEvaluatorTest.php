<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\Rules\BreaksEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

class BreaksEvaluatorTest extends TestCase
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
            firstIn: Carbon::parse('2026-06-19 09:00'), lastOut: Carbon::parse('2026-06-19 18:00'),
            workedMinutes: $worked, flags: [], shift: $this->shift(), policy: $p,
        );
    }

    public function test_deducts_unpaid_meal_when_worked_exceeds_threshold(): void
    {
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = $this->ctx(540, $p); // 9h worked
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(510, $ctx->workedMinutes);      // 540 - 30
        $this->assertSame(30, $ctx->breakDeductedMinutes);
        $this->assertContains('meal_deducted', $ctx->flags);
    }

    public function test_no_deduction_below_threshold(): void
    {
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = $this->ctx(300, $p); // 5h worked < 6h threshold
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(300, $ctx->workedMinutes);
        $this->assertSame(0, $ctx->breakDeductedMinutes);
    }

    public function test_no_double_deduction_when_break_already_taken(): void
    {
        // GAP I: break_out/break_in punches already EXCLUDE the break from worked minutes
        // (they are out/in punches). A 45-min taken break >= the 30-min unpaid meal, so the
        // auto-deduct must NOT deduct again. Span 09:00->18:00 = 540; worked 495 => 45 break taken.
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:00'), lastOut: Carbon::parse('2026-06-19 18:00'),
            workedMinutes: 495, flags: [], shift: $this->shift(), policy: $p,
        );
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(495, $ctx->workedMinutes);       // unchanged — break already taken
        $this->assertSame(0, $ctx->breakDeductedMinutes);
    }

    public function test_deducts_only_the_shortfall_when_partial_break_taken(): void
    {
        // 15-min taken break, 30-min unpaid meal required => deduct only the 15-min shortfall.
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:00'), lastOut: Carbon::parse('2026-06-19 18:00'),
            workedMinutes: 525, flags: [], shift: $this->shift(), policy: $p, // 540 span - 525 = 15 taken
        );
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(510, $ctx->workedMinutes);       // 525 - 15 shortfall
        $this->assertSame(15, $ctx->breakDeductedMinutes);
    }

    public function test_does_not_support_neutral_policy(): void
    {
        $this->assertFalse((new BreaksEvaluator)->supports(PolicyProfile::neutral()));
    }
}
