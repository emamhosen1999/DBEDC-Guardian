<?php

namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** Reminder (14 and 3 days before expiry) that replacement leave is about to lapse. */
class ReplacementLeaveExpiringNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(public float $days, public string $workDate, public string $expiresOn, public int $daysLeft) {}

    public function typeKey(): string
    {
        return 'attendance.replacement_expiring';
    }

    public function toArray(object $notifiable): array
    {
        $d = rtrim(rtrim(number_format($this->days, 1), '0'), '.');

        return [
            'type_key' => $this->typeKey(),
            'title' => 'Replacement leave expires in '.$this->daysLeft.' days',
            'body' => "{$d} day(s) earned for working on {$this->workDate} expire on {$this->expiresOn}. Request your replacement day off before then.",
            'url' => '/attendance-employee',
            'expires_on' => $this->expiresOn,
            'days_left' => $this->daysLeft,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $d = $this->toArray($notifiable);

        return new PushMessage($d['title'], $d['body'], ['type_key' => $d['type_key'], 'url' => $d['url'], 'days_left' => (string) $this->daysLeft]);
    }
}
