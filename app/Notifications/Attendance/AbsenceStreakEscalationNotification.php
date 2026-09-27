<?php

namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversProactiveAttendanceAlert;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Escalation notification for consecutive unauthorized absences.
 * Recipients change by stage: manager → HR + dept head → legal.
 */
class AbsenceStreakEscalationNotification extends Notification implements ShouldQueue
{
    use DeliversProactiveAttendanceAlert, Queueable;

    public function __construct(
        public string $employeeName,
        public int $streakDays,
        public string $stage,
        public string $firstAbsentDate,
    ) {}

    public function typeKey(): string
    {
        return 'attendance.absence_streak_escalation';
    }

    public function toArray(object $notifiable): array
    {
        $stageLabel = match ($this->stage) {
            'notice_sent' => 'HR & Department Head notified',
            'show_cause' => 'Show-cause notice required',
            'deemed_resignation' => 'Deemed resignation threshold reached',
            default => 'Monitoring',
        };

        return [
            'type_key' => $this->typeKey(),
            'title' => "Absence Alert: {$this->employeeName}",
            'body' => "{$this->employeeName} has been absent {$this->streakDays} consecutive working day(s) since {$this->firstAbsentDate}. Stage: {$stageLabel}.",
            'url' => '/hr/offboarding',
            'streak_days' => $this->streakDays,
            'stage' => $this->stage,
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
