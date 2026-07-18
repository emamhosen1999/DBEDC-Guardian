<?php

namespace App\Events\Domain;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Base for all domain events. Carries the common shape and — critically —
 * implements ShouldDispatchAfterCommit.
 *
 * AFTER-COMMIT GUARANTEE: when the dispatch happens inside an open database
 * transaction, Laravel defers delivery until that transaction commits and
 * DISCARDS it if the transaction rolls back. A punch/approval/status change
 * that never made it to disk therefore never emits an event, so no listener
 * can publish a realtime marker for a write that does not exist. Outside a
 * transaction the event fires immediately, as normal.
 *
 * Payloads are ID-ONLY by convention: these events feed a realtime marker
 * writer that must never carry names or other PII off the server.
 */
abstract class DomainEventBase implements DomainEvent, ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    public readonly CarbonImmutable $occurredAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly ?int $actorId = null,
        public readonly ?int $subjectId = null,
        public readonly array $payload = [],
        ?CarbonImmutable $occurredAt = null,
    ) {
        $this->occurredAt = $occurredAt ?? CarbonImmutable::now();
    }

    public function occurredAt(): CarbonImmutable
    {
        return $this->occurredAt;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    public function subjectId(): ?int
    {
        return $this->subjectId;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'event' => $this->eventName(),
            'occurred_at' => $this->occurredAt->toIso8601String(),
            'actor_id' => $this->actorId,
            'subject_id' => $this->subjectId,
            'payload' => $this->payload,
        ];
    }

    /** Default: not routed to realtime. Events that are routed override this. */
    public function realtimeEntity(): ?string
    {
        return null;
    }

    /** Team-wide bucket by default; date/month-bucketed events override. */
    public function realtimeBucket(): string
    {
        return 'all';
    }

    public function realtimeAction(): string
    {
        return 'update';
    }
}
