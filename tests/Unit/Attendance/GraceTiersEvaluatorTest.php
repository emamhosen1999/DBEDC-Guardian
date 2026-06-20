<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Tests\TestCase;

class GraceTiersEvaluatorTest extends TestCase
{
    private function shift(string $start = '09:00'): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse("2026-06-19 $start"), end: Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    private function punch(string $in, string $out): object
    {
        return (object) ['punchin' => Carbon::parse($in), 'punchout' => Carbon::parse($out)];
    }

    public function test_tier_classifies_late_when_in_late_band(): void
    {
        // tiers: 0-10 present, 10-30 late, 30+ half_day. In at 09:20 → 20 min late → 'late'.
        $policy = new PolicyProfile(graceTiers: ['late' => [
            ['upto_minutes' => 10, 'outcome' => 'present'],
            ['upto_minutes' => 30, 'outcome' => 'late'],
            ['upto_minutes' => 9999, 'outcome' => 'half_day'],
        ]]);
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 09:20', '2026-06-19 17:00')]), $this->shift(), policy: $policy,
        );
        $this->assertSame(DayAttendance::LATE, $r->status);
    }

    public function test_tier_half_day_when_beyond_late_band(): void
    {
        $policy = new PolicyProfile(graceTiers: ['late' => [
            ['upto_minutes' => 10, 'outcome' => 'present'],
            ['upto_minutes' => 30, 'outcome' => 'late'],
            ['upto_minutes' => 9999, 'outcome' => 'half_day'],
        ]]);
        $r = (new AttendanceStatusService)->resolve(
            collect([$this->punch('2026-06-19 10:00', '2026-06-19 17:00')]), $this->shift(), policy: $policy,
        );
        $this->assertSame(DayAttendance::HALF_DAY, $r->status);
    }

    public function test_neutral_policy_is_back_compat(): void
    {
        // On-time 09:05 with neutral policy → PRESENT, identical to no-policy.
        $svc = new AttendanceStatusService;
        $punches = collect([$this->punch('2026-06-19 09:05', '2026-06-19 17:30')]);
        $a = $svc->resolve($punches, $this->shift());
        $b = $svc->resolve($punches, $this->shift(), policy: PolicyProfile::neutral());
        $this->assertSame($a->status, $b->status);
        $this->assertSame(DayAttendance::PRESENT, $b->status);
    }

    public function test_rounding_multi_punch_excludes_interior_gap(): void
    {
        // Two punch pairs: 09:07–12:00 (173 min) + 13:00–17:08 (248 min) = 421 min segment sum.
        // Quarter-hour nearest rounding: firstIn 09:07→09:00 (+7 min), lastOut 17:08→17:15 (+7 min).
        // Correct worked_minutes = 421 + 7 + 7 = 435.
        // Buggy span method (firstIn→lastOut after rounding): 09:00→17:15 = 495 — 60-min gap re-counted.
        $policy = new PolicyProfile(rounding: ['strategy' => 'quarter_hour', 'unit_minutes' => 15, 'direction' => 'nearest']);
        $shift = new \App\Services\Attendance\DTO\ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'),
            end: Carbon::parse('2026-06-19 17:00'),
            crossesMidnight: false,
            graceInMinutes: 0,
            graceOutMinutes: 0,
            fullDayMinutes: 0,
            halfDayMinutes: 0,
            minPresentMinutes: 0,
            breakMinutes: 0,
            isWorkingDay: true,
        );
        $punches = collect([
            $this->punch('2026-06-19 09:07', '2026-06-19 12:00'),
            $this->punch('2026-06-19 13:00', '2026-06-19 17:08'),
        ]);
        $r = (new AttendanceStatusService)->resolve($punches, $shift, policy: $policy);
        // Assert boundary-delta fix: 421 + 7 + 7 = 435 (not the buggy span 495).
        $this->assertSame(435, $r->worked_minutes);
    }
}
