<?php

namespace App\Events\Domain;

use Carbon\CarbonImmutable;

/**
 * An administrator killed one device's session: its access tokens, tracked
 * user_sessions row, refresh-token chain and device record are all dead.
 *
 * Dispatched from App\Http\Controllers\Admin\DeviceSessionController::revoke()
 * AFTER the revocation transaction commits — a rolled-back revoke must never
 * announce that a device was cut off when it is in fact still live.
 *
 * NOT routed to realtime: the signal vocabulary is a fixed, deliberate set
 * (attendance|dailywork|leave|objection|roster) and there is no device family
 * in it. realtimeEntity() therefore stays null (inherited) and the realtime
 * listener skips this event instead of inventing a new Firebase path.
 */
class DeviceSessionRevoked extends DomainEventBase
{
    public function __construct(
        ?int $actorId,
        ?int $userDeviceId,
        public readonly ?int $ownerId,
        public readonly int $accessTokensRevoked = 0,
        public readonly int $refreshTokensRevoked = 0,
        public readonly bool $unboundCurrentDevice = false,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($actorId, $userDeviceId, [
            'owner_id' => $ownerId,
            'access_tokens_revoked' => $accessTokensRevoked,
            'refresh_tokens_revoked' => $refreshTokensRevoked,
            'unbound_current_device' => $unboundCurrentDevice,
        ], $occurredAt);
    }

    public function eventName(): string
    {
        return 'device.session_revoked';
    }
}
