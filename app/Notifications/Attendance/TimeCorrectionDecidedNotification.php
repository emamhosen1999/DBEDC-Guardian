<?php
// app/Notifications/Attendance/TimeCorrectionDecidedNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class TimeCorrectionDecidedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    /** @param  string  $decision  'approved' | 'rejected' */
    public function __construct(public int $correctionId, public string $decision) {}

    public function typeKey(): string
    {
        return 'attendance.time_correction_decided';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => 'attendance.time_correction_decided',
            'title' => 'Time correction '.$this->decision,
            'body' => "Your time correction request was {$this->decision}.",
            'url' => '/attendance-employee',
            'correction_id' => $this->correctionId,
            'decision' => $this->decision,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage($data['title'], $data['body'], [
            'type_key' => $data['type_key'],
            'correction_id' => (string) $this->correctionId,
            'url' => $data['url'],
        ]);
    }
}
