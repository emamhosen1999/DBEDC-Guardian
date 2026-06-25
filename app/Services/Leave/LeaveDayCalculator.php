<?php

namespace App\Services\Leave;

use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Server-authoritative leave day-count.
 *
 * Counts the employee's roster WORKING days in [from,to] (excludes their
 * weekly-off via ScheduleResolver and holidays via HolidayService). A half-day
 * leave (single date) is 0.5 when that date is a working day, else 0.
 *
 * The client-supplied daysCount is no longer trusted — this is the source.
 */
class LeaveDayCalculator
{
    public function __construct(
        private ScheduleResolver $scheduleResolver,
        private HolidayService $holidayService,
    ) {}

    public function compute(int $userId, CarbonInterface $from, CarbonInterface $to, bool $isHalfDay = false): float
    {
        $start = $from->copy()->startOfDay();
        $end = $to->copy()->startOfDay();
        if ($end->lessThan($start)) {
            return 0.0;
        }

        $holidays = $this->holidayService->forRange($start, $end->copy()->endOfDay());

        $workingDays = 0;
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            if ($this->isHoliday($date, $holidays)) {
                continue;
            }
            if (! $this->scheduleResolver->resolve($userId, $date)->isWorkingDay) {
                continue;
            }
            $workingDays++;
        }

        if ($isHalfDay) {
            // Half-day only applies to a single-date request; 0.5 if that day counts, else 0.
            return $workingDays > 0 ? 0.5 : 0.0;
        }

        return (float) $workingDays;
    }

    private function isHoliday(CarbonInterface $date, $holidays): bool
    {
        return $holidays->contains(fn ($h) => $date->between(
            Carbon::parse($h->from_date)->startOfDay(),
            Carbon::parse($h->to_date)->endOfDay()
        ));
    }
}
