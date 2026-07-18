<?php

namespace App\Events\Domain;

use Carbon\CarbonImmutable;

/**
 * A leave request cleared an approval level.
 *
 * Dispatched from App\Services\Leave\LeaveApprovalService::approve() for BOTH
 * outcomes of that method:
 *   $final = false → approved at this level, forwarded to the next approver
 *   $final = true  → last level cleared, leave is now status=approved
 *
 * Consumers that only care about a fully-approved leave must check
 * payload()['final'] — the event name describes the act of approving, and the
 * service genuinely performs it once per level.
 */
class LeaveApproved extends DomainEventBase
{
    public function __construct(
        ?int $approverId,
        ?int $leaveId,
        public readonly int $level,
        public readonly bool $final,
        public readonly ?int $employeeId = null,
        ?CarbonImmutable $occurredAt = null,
    ) {
        parent::__construct($approverId, $leaveId, [
            'level' => $level,
            'final' => $final,
            'employee_id' => $employeeId,
        ], $occurredAt);
    }

    public function eventName(): string
    {
        return 'leave.approved';
    }

    public function realtimeEntity(): ?string
    {
        return 'leave';
    }

    public function realtimeAction(): string
    {
        return 'approve';
    }
}
