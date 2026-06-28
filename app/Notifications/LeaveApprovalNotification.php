<?php

namespace App\Notifications;

use App\Models\HRM\Leave;
use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LeaveApprovalNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(
        public Leave $leave
    ) {}

    public function typeKey(): string
    {
        return 'leave.requested';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->leave->user;
        $leaveType = $this->leave->leaveSetting?->leave_type ?? 'Leave';

        return (new MailMessage)
            ->subject('Leave Approval Required')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$employee->name} has requested {$leaveType} for approval.")
            ->line('**Leave Details:**')
            ->line('From: '.\Illuminate\Support\Carbon::parse($this->leave->from_date)->format('d M Y'))
            ->line('To: '.\Illuminate\Support\Carbon::parse($this->leave->to_date)->format('d M Y'))
            ->line("Duration: {$this->leave->no_of_days} day(s)")
            ->when($this->leave->reason, function ($message) {
                return $message->line("Reason: {$this->leave->reason}");
            })
            ->action('Review Leave Request', url("/leaves?approve={$this->leave->id}"))
            ->line('Please review and take action on this leave request.');
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->leave->user->name;
        $leaveType = $this->leave->leaveSetting?->leave_type ?? 'Leave';

        return [
            'type_key' => 'leave.requested',
            'title' => 'Leave request to review',
            'body' => "{$employee} requested {$leaveType} ({$this->leave->no_of_days} day(s)).",
            'url' => "/leaves?approve={$this->leave->id}",
            'leave_id' => $this->leave->id,
            'employee_name' => $employee,
            'leave_type' => $leaveType,
            'from_date' => $this->leave->from_date,
            'to_date' => $this->leave->to_date,
            'no_of_days' => $this->leave->no_of_days,
            'action_required' => true,
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
