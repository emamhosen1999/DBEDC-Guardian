<?php

namespace App\Notifications\Approvals;

use App\Notifications\Concerns\DeliversProactiveAttendanceAlert;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to Super Administrators when a request had to be routed to them as the last resort because the
 * requester has no manager and no HR Manager or configured escalation approver exists.
 */
class ApprovalRoutingIncompleteNotification extends Notification implements ShouldQueue
{
    use DeliversProactiveAttendanceAlert, Queueable;

    public function __construct(
        public string $requesterName,
        public string $requesterId,
        public string $routedToName,
    ) {}

    public function typeKey(): string
    {
        return 'approvals.routing_incomplete';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => 'Approval routing incomplete',
            'body' => "{$this->requesterName} ({$this->requesterId}) has no reporting manager and no HR Manager or escalation approver is set, so the request was routed to {$this->routedToName}. Assign an HR Manager or set an escalation approver.",
            'url' => '/company-settings',
            'requester_id' => $this->requesterId,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage($data['title'], $data['body'], [
            'type_key' => $data['type_key'],
            'url' => $data['url'],
        ]);
    }
}
