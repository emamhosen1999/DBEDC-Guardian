<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\OvertimeRequest;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Decides whether a day can be claimed as overtime and for how much (owner policy, day-based):
 *  - only a day the employee WORKED (punch evidence) that is a holiday (pay only) or a rostered rest day (pay or replacement);
 *  - credit 1 day when at least half the (usual) shift was worked, 0.5 day from 2 h, nothing below;
 *  - a holiday or rest day with a punch-in and no punch-out can still be claimed (the employee declares the end time; the claim is
 *    flagged for the approver). Rest-day overtime PAY on a declared end time is a config switch (default allowed).
 */
class OvertimeEligibilityService
{
    public const WORKING_DAY = 'working_day';

    public const HOLIDAY = 'holiday';

    public const REST_DAY = 'rest_day';

    public function __construct(
        private readonly ScheduleResolver $schedules,
        private readonly HolidayService $holidays,
    ) {}

    /**
     * @return array{
     *   date: string, day_type: string, eligible: bool, code: ?string, message: ?string,
     *   punched_in: bool, punch_out_missing: bool, worked_minutes: int, shift_minutes: int, credit: float,
     *   compensations: list<string>, first_in: ?string, last_out: ?string, claimed: bool
     * }
     */
    public function evaluate(string $userId, CarbonInterface $date, ?CarbonInterface $declaredEnd = null): array
    {
        $date = $date->copy()->startOfDay();
        $schedule = $this->schedules->resolve($userId, $date);
        $holiday = $this->holidays->onDate($date) !== null;
        // OWNER DECISION (2026-10-15): a holiday that falls on the employee's ROSTERED REST DAY is a rest day for claim purposes.
        // Worked: the rest-day rule applies (overtime pay OR replacement leave, the employee chooses). Not worked: the holiday is
        // absorbed by the rest day, so there is no substitute day and nothing is credited (no punch = nothing to claim, no helper
        // entry, no yellow mark). A normal holiday stays "overtime pay only"; a normal rest day "pay or replacement".
        $holidayOnRestDay = $holiday && ! $schedule->isWorkingDay;
        $dayType = $holidayOnRestDay ? self::REST_DAY : ($holiday ? self::HOLIDAY : ($schedule->isWorkingDay ? self::WORKING_DAY : self::REST_DAY));

        $rows = Attendance::query()->where('user_id', $userId)->whereDate('date', $date->toDateString())->whereNotNull('punchin')->orderBy('punchin')->get();
        $punchedIn = $rows->isNotEmpty();
        $closed = $rows->filter(fn ($r) => $r->punchout !== null);
        $punchOutMissing = $punchedIn && $closed->isEmpty();
        $firstIn = $rows->first()?->punchin;
        $lastOut = $closed->sortByDesc('punchout')->first()?->punchout;

        // Verified minutes: closed punch pairs only.
        $worked = (int) $closed->sum(fn ($r) => max(0, $r->punchin->diffInMinutes($r->punchout)));
        $declared = false;
        if ($punchOutMissing && $declaredEnd !== null && $firstIn !== null && $declaredEnd->greaterThan($firstIn)) {
            $worked = (int) $firstIn->diffInMinutes($declaredEnd);
            $declared = true;
        }

        $shift = $this->usualShiftMinutes($userId, $date, $schedule->isWorkingDay ? $schedule->fullDayMinutes : 0);
        $credit = $this->credit($worked, $shift);

        $base = [
            'date' => $date->toDateString(), 'day_type' => $dayType, 'holiday_on_rest_day' => $holidayOnRestDay, 'eligible' => false, 'code' => null, 'message' => null,
            'punched_in' => $punchedIn, 'punch_out_missing' => $punchOutMissing, 'declared' => $declared,
            'worked_minutes' => $worked, 'shift_minutes' => $shift, 'credit' => 0.0, 'compensations' => [],
            'first_in' => $firstIn?->toIso8601String(), 'last_out' => $lastOut?->toIso8601String(),
            'claimed' => $this->existingClaim($userId, $date) !== null,
        ];

        if ($dayType === self::WORKING_DAY) {
            return ['code' => 'working_day', 'message' => __('overtime.refused.working_day')] + $base;
        }
        if (! $punchedIn) {
            return ['code' => 'no_punch', 'message' => __('overtime.refused.no_punch')] + $base;
        }
        if ($base['claimed']) {
            return ['code' => 'already_claimed', 'message' => __('overtime.refused.already_claimed')] + $base;
        }

        // Compensation choices for this kind of day.
        $compensations = $dayType === self::HOLIDAY ? ['payment'] : ['payment', 'replacement'];
        if ($punchOutMissing && $dayType === self::REST_DAY && ! config('overtime_claims.allow_ot_pay_without_punch_out')) {
            // Only when HR switches it off: rest-day pay needs verified hours, replacement can still ride on the declared end time.
            $compensations = array_values(array_diff($compensations, ['payment']));
        }

        if ($punchOutMissing && ! $declared) {
            // Eligible to claim, but the employee still has to declare the end time before a credit can be computed.
            return ['eligible' => true, 'code' => 'declare_end', 'message' => __('overtime.refused.declare_end'), 'compensations' => $compensations] + $base;
        }

        if ($credit <= 0) {
            return ['code' => 'under_minimum', 'message' => __('overtime.refused.under_minimum', ['hours' => round(config('overtime_claims.min_minutes') / 60, 1)])] + $base;
        }

        return ['eligible' => true, 'credit' => $credit, 'compensations' => $compensations] + $base;
    }

    /** 1 day from half the shift, 0.5 day from the minimum (2 h), otherwise nothing. */
    public function credit(int $workedMinutes, int $shiftMinutes): float
    {
        $min = (int) config('overtime_claims.min_minutes');
        if ($workedMinutes < $min) {
            return 0.0;
        }
        $fullAt = max($min, (int) ceil($shiftMinutes * (float) config('overtime_claims.full_day_fraction')));

        return $workedMinutes >= $fullAt ? 1.0 : 0.5;
    }

    /**
     * The employee's usual shift length: the rostered shift of that day, or (rest day / holiday without a shift) the
     * length of the shift on their next working day from the roster pattern.
     */
    public function usualShiftMinutes(string $userId, CarbonInterface $date, int $rostered = 0): int
    {
        if ($rostered > 0) {
            return $rostered;
        }
        for ($i = 1; $i <= 14; $i++) {
            $s = $this->schedules->resolve($userId, $date->copy()->addDays($i));
            if ($s->isWorkingDay && $s->fullDayMinutes > 0) {
                return $s->fullDayMinutes;
            }
        }

        return 480;
    }

    public function existingClaim(string $userId, CarbonInterface $date): ?OvertimeRequest
    {
        return OvertimeRequest::query()->where('user_id', $userId)->whereDate('date', $date->toDateString())
            ->whereNotIn('claim_type', [OvertimeRequest::LEGACY])->whereNotIn('status', ['rejected', 'cancelled'])->first();
    }

    /**
     * Rest-day / holiday work in the claim window that has not been claimed (the "Overtime worked, not claimed" list).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unclaimed(string $userId, ?CarbonInterface $today = null): Collection
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $from = $today->copy()->subDays((int) config('overtime_claims.claim_window_days'));

        $dates = Attendance::query()->where('user_id', $userId)->whereBetween('date', [$from->toDateString(), $today->toDateString()])
            ->whereNotNull('punchin')->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique()->sortDesc()->values();

        return $dates->map(fn ($d) => $this->evaluate($userId, Carbon::parse($d)))
            ->filter(fn ($r) => $r['eligible'] && ! $r['claimed'])
            ->values();
    }
}
