<?php

namespace App\Services\Attendance\DTO;

use Carbon\Carbon;

final class DayAttendance
{
    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    public const LATE = 'late';
    public const HALF_DAY = 'half_day';
    public const SHORT = 'short';
    public const ON_LEAVE = 'on_leave';
    public const HOLIDAY = 'holiday';
    public const WEEKEND = 'weekend';
    public const DAY_OFF = 'day_off';

    public function __construct(
        public readonly string $status,
        public readonly int $worked_minutes,
        public readonly int $late_minutes,
        public readonly int $early_leave_minutes,
        public readonly int $ot_minutes,
        public readonly ?Carbon $first_in,
        public readonly ?Carbon $last_out,
        public readonly bool $is_complete,
        public readonly array $flags,
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'worked_minutes' => $this->worked_minutes,
            'late_minutes' => $this->late_minutes,
            'early_leave_minutes' => $this->early_leave_minutes,
            'ot_minutes' => $this->ot_minutes,
            'first_in' => $this->first_in?->format('Y-m-d H:i:s'),
            'last_out' => $this->last_out?->format('Y-m-d H:i:s'),
            'is_complete' => $this->is_complete,
            'flags' => $this->flags,
        ];
    }
}
