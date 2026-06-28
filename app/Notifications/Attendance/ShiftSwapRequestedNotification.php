<?php
// app/Notifications/Attendance/ShiftSwapRequestedNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ShiftSwapRequestedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(public int $swapId, public ?string $requesterName = null) {}

    public function typeKey(): string
    {
        return 'attendance.shift_swap_requested';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->requesterName ?? 'A colleague';

        return (new MailMessage)
            ->subject('Shift swap request')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$who} has requested a shift swap that needs your attention.")
            ->action('Review request', url('/attendance.unified'));
    }

    public function toArray(object $notifiable): array
    {
        $who = $this->requesterName ?? 'A colleague';

        return [
            'type_key' => 'attendance.shift_swap_requested',
            'title' => 'Shift swap requested',
            'body' => "{$who} requested a shift swap for your review.",
            'url' => '/attendance.unified',
            'swap_id' => $this->swapId,
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
