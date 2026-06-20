<?php

namespace App\Services\Attendance;

use App\Models\HRM\AttendanceSetting;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class DefaultScheduleResolver implements ScheduleResolver
{
    private ?AttendanceSetting $settings = null;

    private bool $settingsLoaded = false;

    public function resolve(int $userId, CarbonInterface $date): ShiftSchedule
    {
        $settings = $this->getSettings();

        $startTime = $settings?->office_start_time ?? '09:00';
        $endTime = $settings?->office_end_time ?? '17:00';
        $weekendDays = $settings?->weekend_days ?? ['saturday', 'sunday'];

        $day = $date->copy()->startOfDay();
        $isWorkingDay = ! in_array(strtolower($day->format('l')), array_map('strtolower', $weekendDays), true);

        if (! $isWorkingDay) {
            return ShiftSchedule::nonWorking($day);
        }

        $start = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($startTime)->format('H:i:s'));
        $end = Carbon::parse($day->format('Y-m-d').' '.Carbon::parse($endTime)->format('H:i:s'));
        $crosses = $end->lessThanOrEqualTo($start);
        if ($crosses) {
            $end->addDay();
        }

        $breakMinutes = (int) ($settings?->break_time_duration ?? 0);
        $fullDayMinutes = max(0, $start->diffInMinutes($end) - $breakMinutes);
        $halfDayMinutes = intdiv($fullDayMinutes, 2);

        return new ShiftSchedule(
            start: $start,
            end: $end,
            crossesMidnight: $crosses,
            graceInMinutes: (int) ($settings?->late_mark_after ?? 15),
            graceOutMinutes: (int) ($settings?->early_leave_before ?? 0),
            fullDayMinutes: $fullDayMinutes,
            halfDayMinutes: $halfDayMinutes,
            minPresentMinutes: 0,
            breakMinutes: $breakMinutes,
            isWorkingDay: true,
            isScheduled: false,
        );
    }

    /**
     * Memoize AttendanceSetting::first() on the instance so the daily-overview
     * per-user loop doesn't re-query it for every user/day.
     */
    private function getSettings(): ?AttendanceSetting
    {
        if (! $this->settingsLoaded) {
            $this->settings = AttendanceSetting::first();
            $this->settingsLoaded = true;
        }

        return $this->settings;
    }
}
