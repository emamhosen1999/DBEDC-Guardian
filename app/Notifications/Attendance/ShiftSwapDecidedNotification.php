<?php
// app/Notifications/Attendance/ShiftSwapDecidedNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ShiftSwapDecidedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    /** @param  string  $decision  'approved' | 'rejected' */
    public function __construct(public int $swapId, public string $decision) {}

    public function typeKey(): string
    {
        return 'attendance.shift_swap_decided';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => 'attendance.shift_swap_decided',
            'title' => 'Shift swap '.$this->decision,
            'body' => "Your shift swap request was {$this->decision}.",
            'url' => '/attendance-employee',
            'swap_id' => $this->swapId,
            'decision' => $this->decision,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage($data['title'], $data['body'], [
            'type_key' => $data['type_key'],
            'swap_id' => (string) $this->swapId,
            'url' => $data['url'],
        ]);
    }
}
