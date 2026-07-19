<?php

namespace App\Listeners\Domain;

use App\Events\Domain\DomainEvent;
use App\Services\Realtime\RealtimeSignal;

/**
 * The domain bus's realtime consumer: turns any DomainEvent that declares a
 * signal family into a marker at signals/{ns}/{entity}/{bucket}.
 *
 * Registered ONCE, against the DomainEvent INTERFACE (EventServiceProvider),
 * so every present and future domain event is covered without touching the
 * provider again.
 *
 * ADDITIVE BY DESIGN — the pre-existing direct RealtimeSignal::touch() calls in
 * the controllers (LeaveController, DailyWorkController, Api\V1\AttendanceController,
 * …) are deliberately LEFT IN PLACE. Two reasons:
 *   1. those controllers cover paths this bus does not dispatch from yet
 *      (leave apply/cancel/reject, bulk submit, objections, roster), so ripping
 *      the calls out would silently drop signals;
 *   2. touch() is an idempotent SET of a "last-change" marker — writing the same
 *      bucket twice for one request just overwrites it with the same shape.
 * The duplicate write is therefore harmless, and removing the direct calls can
 * be done later, per-path, once each path has a domain event.
 *
 * Fail-open: RealtimeSignal::touch() already swallows and reports its own
 * errors, so a realtime outage can never break the write that emitted the event.
 */
class EmitRealtimeSignal
{
    public function __construct(private readonly RealtimeSignal $signal) {}

    public function handle(DomainEvent $event): void
    {
        $entity = $event->realtimeEntity();

        if ($entity === null) {
            return; // event has no place in the fixed signal vocabulary
        }

        $bucket = trim($event->realtimeBucket());

        if ($bucket === '') {
            return; // never write to signals/{ns}/{entity}/ with an empty key
        }

        $this->signal->touch($entity, $bucket, $event->actorId(), $event->realtimeAction());
    }
}
