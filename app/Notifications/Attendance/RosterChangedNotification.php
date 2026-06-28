<?php
// app/Notifications/Attendance/RosterChangedNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RosterChangedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(public string $date) {}

    public function typeKey(): string
    {
        return 'attendance.roster_changed';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => 'attendance.roster_changed',
            'title' => 'Roster updated',
            'body' => "Your shift for {$this->date} was changed.",
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
