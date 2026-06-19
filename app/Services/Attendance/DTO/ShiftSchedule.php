<?php

namespace App\Services\Attendance\DTO;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class ShiftSchedule
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
        public readonly bool $crossesMidnight,
        public readonly int $graceInMinutes,
        public readonly int $graceOutMinutes,
        public readonly int $fullDayMinutes,
        public readonly int $halfDayMinutes,
        public readonly int $minPresentMinutes,
        public readonly int $breakMinutes,
        public readonly bool $isWorkingDay,
    ) {}

    public static function nonWorking(CarbonInterface $date): self
    {
        $d = $date->copy()->startOfDay();

        return new self(
            start: $d->copy(),
            end: $d->copy()->endOfDay(),
            crossesMidnight: false,
            graceInMinutes: 0,
            graceOutMinutes: 0,
            fullDayMinutes: 0,
            halfDayMinutes: 0,
            minPresentMinutes: 0,
            breakMinutes: 0,
            isWorkingDay: false,
        );
    }
}
