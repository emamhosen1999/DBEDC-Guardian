<?php

namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Next-morning nudge: a rest day or holiday was worked and not yet claimed. */
class OvertimeUnclaimedReminderNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(public string $workDate, public float $credit, public bool $punchOutMissing = false) {}

    public function typeKey(): string
    {
        return 'attendance.overtime_unclaimed';
    }

    public function toArray(object $notifiable): array
    {
        $body = $this->punchOutMissing
            ? "You worked on {$this->workDate} (rest day) but there is no punch-out. Claim replacement leave on the end time you declare."
            : 'You worked on '.$this->workDate.'. You can claim '.rtrim(rtrim(number_format($this->credit, 1), '0'), '.').' day(s) as overtime pay or replacement leave.';

        return [
            'type_key' => $this->typeKey(),
            'title' => 'Work on '.$this->workDate.' not claimed yet',
            'body' => $body,
            'url' => '/attendance-employee?claim='.$this->workDate,
            'work_date' => $this->workDate,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $d = $this->toArray($notifiable);

        return new PushMessage($d['title'], $d['body'], ['type_key' => $d['type_key'], 'url' => $d['url'], 'work_date' => $this->workDate]);
    }
}
