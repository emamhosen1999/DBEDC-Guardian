<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A daily-work task was assigned to the notifiable. Scalars only: nothing to re-hydrate on the queue. */
class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(
        public int|string $taskId,
        public ?string $taskNumber,
        public string $assignedByName,
        public ?string $assignedById = null,
    ) {}

    public function typeKey(): string
    {
        return 'task.assigned';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New task assignment')
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('View tasks', url('/daily-works'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => 'New task assignment',
            'body' => $this->body(),
            'url' => '/daily-works',
            'task_id' => $this->taskId,
            'assigned_by' => $this->assignedById,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage($data['title'], $data['body'], [
            'type_key' => $data['type_key'],
            'task_id' => (string) $this->taskId,
            'url' => $data['url'],
        ]);
    }

    private function body(): string
    {
        return "You have been assigned task #{$this->taskNumber} by {$this->assignedByName}";
    }
}
