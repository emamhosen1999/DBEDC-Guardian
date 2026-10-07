<?php

namespace App\Services\Attendance;

use App\Models\HRM\Holiday;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of holiday occurrences for attendance.
 *
 * Honors is_active everywhere and expands annual_fixed recurrence (same
 * Gregorian month-day each year). Lunar/announced holidays are one-off rows
 * HR enters per year and are never auto-expanded.
 */
class HolidayService
{
    /** @var array<string, ?Holiday> per-instance memo: date => the holiday covering it (or null) */
    private array $dayCache = [];

    /**
     * The active company holiday covering this calendar day, if any. Memoised per instance so a
     * partition over hundreds of employees asks the database once.
     */
    public function onDate(CarbonInterface $date): ?Holiday
    {
        $key = $date->toDateString();
        if (! array_key_exists($key, $this->dayCache)) {
            $this->dayCache[$key] = $this->forRange($date, $date)->first();
        }

        return $this->dayCache[$key];
    }

    public function isHoliday(CarbonInterface $date): bool
    {
        return $this->onDate($date) !== null;
    }

    public function forRange(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $active = Holiday::query()->where('is_active', true)->get();

        $occurrences = collect();

        foreach ($active as $holiday) {
            $isRecurring = $holiday->is_recurring || $holiday->recurrence_pattern === 'annual_fixed';

            if (! $isRecurring) {
                $hFrom = Carbon::parse($holiday->from_date)->startOfDay();
                $hTo = Carbon::parse($holiday->to_date)->endOfDay();
                if ($hFrom->lte($to) && $hTo->gte($from)) {
                    $occurrences->push($holiday);
                }

                continue;
            }

            // annual_fixed: project the holiday's month-day into each year spanned by [from,to].
            $baseFrom = Carbon::parse($holiday->from_date);
            $baseTo = Carbon::parse($holiday->to_date);
            $spanDays = $baseFrom->copy()->startOfDay()->diffInDays($baseTo->copy()->startOfDay());

            // Start one year earlier so a December-anchored span can still be
            // caught when it extends into January of the queried range.
            for ($year = $from->year - 1; $year <= $to->year; $year++) {
                $daysInMonth = Carbon::create($year, $baseFrom->month, 1)->daysInMonth;
                $day = min($baseFrom->day, $daysInMonth);
                $occFrom = Carbon::create($year, $baseFrom->month, $day)->startOfDay();
                $occTo = $occFrom->copy()->addDays($spanDays)->endOfDay();
                if ($occFrom->lte($to) && $occTo->gte($from)) {
                    $clone = $holiday->replicate();
                    $clone->id = $holiday->id; // keep identity for callers that read it
                    $clone->from_date = $occFrom->toDateString();
                    $clone->to_date = $occTo->toDateString();
                    $occurrences->push($clone);
                }
            }
        }

        return $occurrences->values();
    }
}
