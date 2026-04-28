<?php

namespace App\Notifications;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PendingObjectionReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public RfiObjection $objection,
        public DailyWork $dailyWork
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
        $subject = "Pending Objection Reminder: {$this->objection->title}";

        return (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},")
            ->line("This is a reminder about a pending objection that requires your attention.")
            ->line("**Objection Details:**")
            ->line("• Title: {$this->objection->title}")
            ->line("• Category: {$this->objection->category}")
            ->line("• Status: {$this->objection->status}")
            ->line("• Submitted: {$this->objection->created_at->diffForHumans()}")
            ->line("**Related Daily Work:**")
            ->line("• RFI Number: {$this->dailyWork->number}")
            ->line("• Location: {$this->dailyWork->location}")
            ->action('Review Objection', url("/daily-works/{$this->dailyWork->id}/objections/{$this->objection->id}"))
            ->action('View Daily Work', url("/daily-works/{$this->dailyWork->id}"))
            ->line('Please review and resolve this objection.')
            ->salutation('Best regards, Construction Management System');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'pending_objection_reminder',
            'objection_id' => $this->objection->id,
            'objection_title' => $this->objection->title,
            'daily_work_id' => $this->dailyWork->id,
            'daily_work_number' => $this->dailyWork->number,
            'message' => "Objection '{$this->objection->title}' for Daily Work {$this->dailyWork->number} is pending review. Submitted {$this->objection->created_at->diffForHumans()}.",
            'action_url' => "/daily-works/{$this->dailyWork->id}/objections/{$this->objection->id}",
            'action_required' => true,
            'severity' => 'warning',
        ];
    }
}