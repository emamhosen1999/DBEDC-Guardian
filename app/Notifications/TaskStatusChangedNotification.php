<?php

namespace App\Notifications;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A daily-work task changed status (completion and resubmission are statuses of the same type). */
class TaskStatusChangedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(
        public int|string $taskId,
        public ?string $taskNumber,
        public ?string $oldStatus,
        public string $newStatus,
        public string $actorName,
        public ?string $actorId = null,
        public ?int $resubmissionCount = null,
    ) {}

    public function typeKey(): string
    {
        return 'task.status_changed';
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body())
            ->action('View tasks', url('/daily-works'));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => $this->title(),
            'body' => $this->body(),
            'url' => '/daily-works',
            'task_id' => $this->taskId,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'updated_by' => $this->actorId,
            'resubmission_count' => $this->resubmissionCount,
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

    private function title(): string
    {
        return match ($this->newStatus) {
            'completed' => 'Task completed',
            'resubmission' => 'Task resubmission',
            default => 'Task status updated',
        };
    }

    private function body(): string
    {
        return match ($this->newStatus) {
            'completed' => "Task #{$this->taskNumber} has been completed by {$this->actorName}",
            'resubmission' => $this->resubmissionCount
                ? "Task #{$this->taskNumber} has been resubmitted for the {$this->ordinal($this->resubmissionCount)} time"
                : "Task #{$this->taskNumber} has been resubmitted",
            default => "Task #{$this->taskNumber} status changed from {$this->oldStatus} to {$this->newStatus}",
        };
    }

    private function ordinal(int $number): string
    {
        if (! in_array($number % 100, [11, 12, 13], true)) {
            switch ($number % 10) {
                case 1: return $number.'st';
                case 2: return $number.'nd';
                case 3: return $number.'rd';
            }
        }

        return $number.'th';
    }
}
