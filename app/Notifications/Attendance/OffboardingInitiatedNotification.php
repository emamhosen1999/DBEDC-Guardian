<?php

namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversProactiveAttendanceAlert;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Sent to HR, managers and IT when an offboarding process is initiated.
 */
class OffboardingInitiatedNotification extends Notification implements ShouldQueue
{
    use DeliversProactiveAttendanceAlert, Queueable;

    public function __construct(
        public string $employeeName,
        public string $reason,
        public string $lastWorkingDate,
        public int $offboardingId,
    ) {}

    public function typeKey(): string
    {
        return 'hr.offboarding_initiated';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => 'Employee Offboarding Initiated',
            'body' => "{$this->employeeName} — {$this->reason}. Last working date: {$this->lastWorkingDate}. Clearance checklist has been created.",
            'url' => '/hr/offboarding',
            'offboarding_id' => $this->offboardingId,
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
