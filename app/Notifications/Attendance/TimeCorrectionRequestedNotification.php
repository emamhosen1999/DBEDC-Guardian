<?php
// app/Notifications/Attendance/TimeCorrectionRequestedNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TimeCorrectionRequestedNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(public int $correctionId, public ?string $requesterName = null) {}

    public function typeKey(): string
    {
        return 'attendance.time_correction_requested';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $who = $this->requesterName ?? 'An employee';

        return (new MailMessage)
            ->subject('Time correction request')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$who} submitted a time correction request for your review.")
            ->action('Review request', url('/attendance.unified'));
    }

    public function toArray(object $notifiable): array
    {
        $who = $this->requesterName ?? 'An employee';

        return [
            'type_key' => 'attendance.time_correction_requested',
            'title' => 'Time correction requested',
            'body' => "{$who} submitted a time correction for your review.",
            'url' => '/attendance.unified',
            'correction_id' => $this->correctionId,
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
