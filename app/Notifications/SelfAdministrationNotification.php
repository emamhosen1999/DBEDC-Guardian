<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Compensating control: tells the global admins that someone acted on himself under
 * `access.self-administration`. Always delivered in-app (a security control is not opt-out).
 */
class SelfAdministrationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $actorName, public string $actorId, public string $summary, public int $logId) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => 'self_administration',
            'title' => 'Self-administration',
            'body' => "Self-administration: {$this->actorName} {$this->summary}",
            'url' => '/employees',
            'actor_id' => $this->actorId,
            'log_id' => $this->logId,
            'action_required' => false,
        ];
    }
}
