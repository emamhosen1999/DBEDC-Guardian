<?php

namespace App\Services\Attendance;

use App\Models\HRM\RosterDay;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\CarbonInterface;

class RosterScheduleResolver implements ScheduleResolver
{
    public function __construct(
        private readonly RosterService $roster,
        private readonly DefaultScheduleResolver $fallback,
    ) {}

    public function resolve(int $userId, CarbonInterface $date): ShiftSchedule
    {
        $hasRoster = RosterDay::where('user_id', $userId)->whereDate('date', $date->toDateString())->exists();

        // No roster row → check assignment; if neither, preserve legacy behaviour via settings.
        if (! $hasRoster) {
            $assignment = $this->roster->resolveAssignment($userId, $date);
            if (! $assignment) {
                return $this->fallback->resolve($userId, $date);
            }
        }

        $shift = $this->roster->resolveShift($userId, $date);

        if ($shift === null) {
            return ShiftSchedule::nonWorking($date); // off / swap-to-off → never absent
        }

        return $shift->toSchedule($date);
    }
}
