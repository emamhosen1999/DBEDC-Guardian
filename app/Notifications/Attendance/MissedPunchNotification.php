<?php
// app/Notifications/Attendance/MissedPunchNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class MissedPunchNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    /** @param  string  $direction  'in' | 'out' */
    public function __construct(public string $direction, public string $date) {}

    public function typeKey(): string
    {
        return $this->direction === 'in' ? 'attendance.missed_punch_in' : 'attendance.missed_punch_out';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => $this->direction === 'in' ? 'Missed punch-in' : 'Missed punch-out',
            'body' => "You have a missed punch-{$this->direction} for {$this->date}.",
            'url' => '/attendance-employee',
            'date' => $this->date,
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
