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
        public readonly int $double_time_minutes = 0,
        public readonly int $regular_minutes = 0,
        public readonly int $break_deducted_minutes = 0,
        public readonly array $policy_events = [],
        public readonly float $leave_fraction = 0.0,
        public readonly ?string $leave_session = null,
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
            'double_time_minutes' => $this->double_time_minutes,
            'regular_minutes' => $this->regular_minutes,
            'break_deducted_minutes' => $this->break_deducted_minutes,
            'policy_events' => $this->policy_events,
            'leave_fraction' => $this->leave_fraction,
            'leave_session' => $this->leave_session,
        ];
    }
}
