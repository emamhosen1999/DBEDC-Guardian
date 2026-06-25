<?php

namespace App\Services\Holiday;

use App\Models\HRM\Holiday;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HolidayImportService
{
    /**
     * Clone $fromYear's active holidays into $toYear (shift both dates by the year
     * delta; Feb-29 clamped). Skips a clone whose shifted range overlaps an existing
     * $toYear holiday. Returns the number created.
     */
    public function copyYear(int $fromYear, int $toYear): int
    {
        if ($fromYear === $toYear) {
            return 0;
        }

        $source = Holiday::query()
            ->where('is_active', true)
            ->whereYear('from_date', $fromYear)
            ->orderBy('from_date')
            ->get();

        $created = 0;

        DB::transaction(function () use ($source, $toYear, &$created) {
            foreach ($source as $h) {
                $newFrom = $this->shiftYear(Carbon::parse($h->from_date), $toYear);
                $newTo = $this->shiftYear(Carbon::parse($h->to_date), $toYear);
                if ($newTo->lessThan($newFrom)) {
                    $newTo = $newFrom->copy();
                }

                // Standard interval overlap, date-only (stored values carry a 00:00:00 time):
                // existing.from <= new.to AND existing.to >= new.from.
                $overlaps = Holiday::query()
                    ->whereDate('from_date', '<=', $newTo->toDateString())
                    ->whereDate('to_date', '>=', $newFrom->toDateString())
                    ->exists();

                if ($overlaps) {
                    continue;
                }

                Holiday::create([
                    'title' => $h->title,
                    'description' => $h->description,
                    'from_date' => $newFrom->toDateString(),
                    'to_date' => $newTo->toDateString(),
                    'type' => $h->type,
                    'is_recurring' => $h->is_recurring,
                    'recurrence_pattern' => $h->recurrence_pattern,
                    'is_active' => true,
                    'created_by' => Auth::id(),
                    'updated_by' => Auth::id(),
                ]);
                $created++;
            }
        });

        return $created;
    }

    private function shiftYear(Carbon $date, int $targetYear): Carbon
    {
        $daysInMonth = Carbon::create($targetYear, $date->month, 1)->daysInMonth;
        $day = min($date->day, $daysInMonth); // Feb-29 -> Feb-28 in a non-leap year

        return Carbon::create($targetYear, $date->month, $day)->startOfDay();
    }
}
