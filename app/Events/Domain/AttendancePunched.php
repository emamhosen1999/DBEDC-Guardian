<?php

namespace App\Events\Domain;

use Carbon\CarbonImmutable;

/**
 * A user's attendance row was opened (punch in) or closed (punch out).
 *
 * Dispatched from App\Services\Attendance\AttendancePunchService — the single
 * place BOTH the web and mobile punch paths funnel through.
 *
 * Realtime: the attendance family is bucketed by BUSINESS DATE (Y-m-d), which
 * for an overnight shift is the date the row is filed under, not necessarily
 * the wall-clock date of the punch. The bucket is therefore taken from the
 * attendance row, never from now().
 */
class AttendancePunched extends DomainEventBase
{
    public const ACTION_IN = 'punch_in';

    public const ACTION_OUT = 'punch_out';

    public function __construct(
        ?int $actorId,
        ?int $attendanceId,
        public readonly string $action,
        public readonly string $businessDate,
        array $payload = [],
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($actorId, $attendanceId, $payload + [
            'action' => $action,
            'business_date' => $businessDate,
        ], $occurredAt);
    }

    public function eventName(): string
    {
        return 'attendance.punched';
    }

    public function realtimeEntity(): ?string
    {
        return 'attendance';
    }

    public function realtimeBucket(): string
    {
        return $this->businessDate;
    }

    public function realtimeAction(): string
    {
        return 'punch';
    }
}
