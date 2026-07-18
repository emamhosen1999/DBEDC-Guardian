<?php

namespace App\Events\Domain;

use Carbon\CarbonImmutable;

/**
 * A daily-work (RFI) record moved between statuses.
 *
 * Dispatched from App\Services\DailyWork\DailyWorkCrudService::update() and
 * only when the status VALUE actually changed — an edit that rewrites the
 * description while leaving status alone is not a transition and emits nothing.
 */
class DailyWorkStatusChanged extends DomainEventBase
{
    public function __construct(
        ?int $actorId,
        ?int $dailyWorkId,
        public readonly ?string $from,
        public readonly string $to,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($actorId, $dailyWorkId, [
            'from' => $from,
            'to' => $to,
        ], $occurredAt);
    }

    public function eventName(): string
    {
        return 'dailywork.status_changed';
    }

    public function realtimeEntity(): ?string
    {
        return 'dailywork';
    }

    public function realtimeAction(): string
    {
        return 'status';
    }
}
