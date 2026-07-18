<?php

namespace App\Events\Domain;

use Carbon\CarbonImmutable;

/**
 * Contract for every DOMAIN event this application emits.
 *
 * WHY AN INTERFACE (and not just a base class): Laravel's event dispatcher
 * resolves listeners registered against an event's INTERFACES
 * (Illuminate\Events\Dispatcher::addInterfaceListeners) but NOT against its
 * parent classes. Registering one listener on this interface therefore wires
 * it to every domain event at once — see App\Providers\EventServiceProvider.
 *
 * SHAPE: every domain event answers the same four questions —
 *   occurredAt() — when the transition happened (server clock, immutable)
 *   actorId()    — the user who caused it (ID only, never a name/PII)
 *   subjectId()  — the primary key of the record that changed
 *   payload()    — bounded, ID-only extra context specific to the event
 *
 * DELIVERY: implementations extend DomainEventBase, which also implements
 * Illuminate\Contracts\Events\ShouldDispatchAfterCommit — a transition that
 * gets rolled back must never be announced to the outside world.
 */
interface DomainEvent
{
    /** Stable dotted name for logging/telemetry, e.g. "attendance.punched". */
    public function eventName(): string;

    public function occurredAt(): CarbonImmutable;

    public function actorId(): ?int;

    public function subjectId(): ?int;

    /** @return array<string, mixed> */
    public function payload(): array;

    /** @return array<string, mixed> */
    public function toArray(): array;

    /**
     * Realtime routing for App\Services\Realtime\RealtimeSignal.
     *
     * Return null from realtimeEntity() when this event has NO place in the
     * fixed signal vocabulary (attendance|dailywork|leave|objection|roster).
     * The listener then skips it rather than inventing a new Firebase path.
     */
    public function realtimeEntity(): ?string;

    public function realtimeBucket(): string;

    public function realtimeAction(): string;
}
