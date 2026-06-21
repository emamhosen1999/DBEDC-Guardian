<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Phase 3.1 Task 5: threads BreaksEvaluator + DailyOvertimeEvaluator through
 * AttendanceStatusService via a two-phase RuleEngine pass (rounding -> breaks ->
 * graceTiers -> overtime), while a neutral PolicyProfile stays byte-identical
 * to the Phase 3.0 legacy path.
 */
class PayRulesStatusTest extends TestCase
{
    private function shift(array $over = []): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'),
            end: Carbon::parse('2026-06-19 18:00'),
            crossesMidnight: false,
            graceInMinutes: $over['graceInMinutes'] ?? 15,
            graceOutMinutes: $over['graceOutMinutes'] ?? 0,
            fullDayMinutes: $over['fullDayMinutes'] ?? 480,
            halfDayMinutes: $over['halfDayMinutes'] ?? 0,
            minPresentMinutes: $over['minPresentMinutes'] ?? 0,
            breakMinutes: $over['breakMinutes'] ?? 0,
            isWorkingDay: $over['isWorkingDay'] ?? true,
        );
    }

    public function test_breaks_and_daily_ot_apply_together(): void
    {
        // Worked 09:00-19:00 = 600 min. Breaks: 30 unpaid meal over 360 threshold -> 570.
        // OT: daily threshold 480 -> regular 480, OT 90, double-time 0.
        $policy = new PolicyProfile(
            breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360],
            overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5],
        );
        $shift = $this->shift();
        $punches = collect([(object) [
            'punchin' => Carbon::parse('2026-06-19 09:00'),
            'punchout' => Carbon::parse('2026-06-19 19:00'),
        ]]);

        $r = (new AttendanceStatusService)->resolve($punches, $shift, policy: $policy);

        $this->assertSame(570, $r->worked_minutes);        // meal deducted
        $this->assertSame(30, $r->break_deducted_minutes);
        $this->assertSame(90, $r->ot_minutes);              // 570 - 480
        $this->assertSame(0, $r->double_time_minutes);
        $this->assertSame(480, $r->regular_minutes);
        $this->assertContains('meal_deducted', $r->flags);
    }

    public function test_neutral_policy_pay_rules_back_compat(): void
    {
        $shift = $this->shift();
        $punches = collect([(object) [
            'punchin' => Carbon::parse('2026-06-19 09:00'),
            'punchout' => Carbon::parse('2026-06-19 19:00'),
        ]]);

        $a = (new AttendanceStatusService)->resolve($punches, $shift);
        $b = (new AttendanceStatusService)->resolve($punches, $shift, policy: PolicyProfile::neutral());

        $this->assertSame($a->worked_minutes, $b->worked_minutes); // no meal deduction under neutral
        $this->assertSame($a->ot_minutes, $b->ot_minutes);          // legacy OT preserved
        $this->assertSame(600, $b->worked_minutes);
        $this->assertSame(0, $b->break_deducted_minutes);
        $this->assertSame(0, $b->double_time_minutes);
        $this->assertSame(0, $b->regular_minutes);
        $this->assertSame([], $b->policy_events);
        $this->assertSame(120, $b->ot_minutes); // 600 - 480 legacy calc, unaffected by policy
    }

    public function test_rounding_and_breaks_combined_deducts_from_rounding_adjusted_worked(): void
    {
        // Raw punches: 09:04 -> 19:06 (raw worked = 602).
        // Rounding nearest/15 snaps boundaries to 09:00 / 19:00 -> rounding-adjusted worked = 600.
        // Breaks (30 unpaid meal, threshold 360) must deduct from the ROUNDING-ADJUSTED 600,
        // not the raw 602, producing 570 (not 572). This guards the two-phase engine ordering:
        // rounding recompute happens BEFORE breaks deduct.
        $policy = new PolicyProfile(
            rounding: ['strategy' => 'nearest', 'unit_minutes' => 15, 'direction' => 'nearest'],
            breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360],
        );
        $shift = $this->shift();
        $punches = collect([(object) [
            'punchin' => Carbon::parse('2026-06-19 09:04'),
            'punchout' => Carbon::parse('2026-06-19 19:06'),
        ]]);

        $r = (new AttendanceStatusService)->resolve($punches, $shift, policy: $policy);

        $this->assertSame(570, $r->worked_minutes);
        $this->assertNotSame(572, $r->worked_minutes); // would be 572 if breaks ran against raw worked
        $this->assertSame(30, $r->break_deducted_minutes);
    }

    public function test_overtime_policy_does_not_override_legacy_all_ot_on_off_day(): void
    {
        // Phase 3.1 overtime bucket-split must be PURELY ADDITIVE: on a non-working
        // (off) day the legacy semantics — ALL worked minutes are OT-eligible — must
        // be preserved even when an overtime policy is configured. The bucket-override
        // in AttendanceStatusService must only apply on working days.
        $policy = new PolicyProfile(
            overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5],
        );
        $shift = $this->shift(['isWorkingDay' => false]);
        $punches = collect([(object) [
            'punchin' => Carbon::parse('2026-06-19 09:00'),
            'punchout' => Carbon::parse('2026-06-19 19:00'),
        ]]);

        $r = (new AttendanceStatusService)->resolve($punches, $shift, policy: $policy);

        $this->assertSame(600, $r->worked_minutes);
        $this->assertSame($r->worked_minutes, $r->ot_minutes); // all off-day hours are OT, not threshold-split
        $this->assertSame(0, $r->double_time_minutes);
        $this->assertSame(0, $r->regular_minutes);
        $this->assertContains('worked_on_off_day', $r->flags);
    }
}
