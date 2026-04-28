<?php

namespace App\Notifications;

use App\Models\DailyWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class DailyWorkStatusChangeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public DailyWork $dailyWork,
        public string $oldStatus,
        public string $newStatus,
        public string $reason
    ) {}

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = "Daily Work Status Changed: {$this->dailyWork->number}";
        $message = $this->buildMessage();

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line($message)
            ->action('View Daily Work', url("/daily-works/{$this->dailyWork->id}"))
            ->line('This is an automated status change notification.')
            ->salutation('Best regards, Construction Management System');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'daily_work_status_change',
            'daily_work_id' => $this->dailyWork->id,
            'daily_work_number' => $this->dailyWork->number,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'reason' => $this->reason,
            'message' => $this->buildMessage(),
            'action_url' => "/daily-works/{$this->dailyWork->id}",
            'action_required' => $this->isActionRequired(),
        ];
    }

    /**
     * Build the notification message.
     */
    private function buildMessage(): string
    {
        $statusMap = [
            DailyWork::STATUS_COMPLETED => 'Completed',
            DailyWork::STATUS_PENDING => 'Pending',
            DailyWork::STATUS_RESUBMISSION => 'Requires Resubmission',
            DailyWork::STATUS_EMERGENCY => 'Emergency/Escalated',
            DailyWork::STATUS_IN_PROGRESS => 'In Progress',
            DailyWork::STATUS_NEW => 'New',
            DailyWork::STATUS_REJECTED => 'Rejected',
        ];

        $oldStatusText = $statusMap[$this->oldStatus] ?? ucfirst($this->oldStatus);
        $newStatusText = $statusMap[$this->newStatus] ?? ucfirst($this->newStatus);

        return "Daily Work {$this->dailyWork->number} status has changed from '{$oldStatusText}' to '{$newStatusText}'. " .
               "Reason: {$this->reason}. " .
               "Location: {$this->dailyWork->location} ({$this->dailyWork->type})";
    }

    /**
     * Check if this notification requires user action.
     */
    private function isActionRequired(): bool
    {
        return in_array($this->newStatus, [
            DailyWork::STATUS_RESUBMISSION,
            DailyWork::STATUS_EMERGENCY,
            DailyWork::STATUS_PENDING,
        ], true);
    }
}