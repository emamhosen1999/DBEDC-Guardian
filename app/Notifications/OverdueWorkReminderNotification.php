<?php

namespace App\Notifications;

use App\Models\DailyWork;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OverdueWorkReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public DailyWork $dailyWork) {}

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
        $subject = "Overdue Daily Work Reminder: {$this->dailyWork->number}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line("This is a reminder that Daily Work {$this->dailyWork->number} is overdue.")
            ->line("**Work Details:**")
            ->line("• Location: {$this->dailyWork->location}")
            ->line("• Type: {$this->dailyWork->type}")
            ->line("• Planned Time: {$this->dailyWork->planned_time}")
            ->line("• Status: {$this->dailyWork->status}")
            ->action('View Daily Work', url("/daily-works/{$this->dailyWork->id}"))
            ->line('Please review and update the status as needed.')
            ->salutation('Best regards, Construction Management System');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'overdue_work_reminder',
            'daily_work_id' => $this->dailyWork->id,
            'daily_work_number' => $this->dailyWork->number,
            'message' => "Daily Work {$this->dailyWork->number} at {$this->dailyWork->location} is overdue. Planned completion: {$this->dailyWork->planned_time}",
            'action_url' => "/daily-works/{$this->dailyWork->id}",
            'action_required' => true,
            'severity' => 'warning',
        ];
    }
}