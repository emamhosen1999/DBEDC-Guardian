<?php

namespace App\Notifications;

use App\Models\HRM\Leave;
use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveRejectedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(
        public Leave $leave,
        public string $reason
    ) {}

    public function typeKey(): string
    {
        return 'leave.rejected';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $leaveType = $this->leave->leaveSetting?->leave_type ?? 'Leave';
        $rejectedBy = $this->leave->rejectedBy?->name ?? 'Manager';

        return (new MailMessage)
            ->subject('Leave Request Rejected')
            ->greeting("Hello {$notifiable->name},")
            ->line("Your {$leaveType} request has been rejected by {$rejectedBy}.")
            ->line('**Leave Details:**')
            ->line('From: '.\Illuminate\Support\Carbon::parse($this->leave->from_date)->format('d M Y'))
            ->line('To: '.\Illuminate\Support\Carbon::parse($this->leave->to_date)->format('d M Y'))
            ->line("Duration: {$this->leave->no_of_days} day(s)")
            ->line('**Rejection Reason:**')
            ->line($this->reason)
            ->action('View Leave Details', url("/leaves?view={$this->leave->id}"))
            ->line('You may contact your manager for further clarification.');
    }

    public function toArray(object $notifiable): array
    {
        $leaveType = $this->leave->leaveSetting?->leave_type ?? 'Leave';

        return [
            'type_key' => 'leave.rejected',
            'title' => 'Leave rejected',
            'body' => "Your {$leaveType} request was rejected. Reason: {$this->reason}",
            'url' => "/leaves-employee?view={$this->leave->id}",
            'leave_id' => $this->leave->id,
            'leave_type' => $leaveType,
            'from_date' => $this->leave->from_date,
            'to_date' => $this->leave->to_date,
            'no_of_days' => $this->leave->no_of_days,
            'status' => 'rejected',
            'rejection_reason' => $this->reason,
            'rejected_by' => $this->leave->rejected_by,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage($data['title'], $data['body'], [
            'type_key' => $data['type_key'],
            'leave_id' => (string) $this->leave->id,
            'url' => $data['url'],
        ]);
    }
}
