<?php

namespace App\Services\Attendance\DTO;

use Carbon\Carbon;

final class DayContext
{
    public function __construct(
        public ?Carbon $firstIn,
        public ?Carbon $lastOut,
        public int $workedMinutes,
        public array $flags,
        public ShiftSchedule $shift,
        public PolicyProfile $policy,
        public int $regularMinutes = 0,
        public int $otMinutes = 0,
        public int $doubleTimeMinutes = 0,
        public int $breakDeductedMinutes = 0,
        public array $policyEvents = [],
    ) {}
}
