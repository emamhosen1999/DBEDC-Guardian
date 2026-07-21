<?php
// app/Notifications/Attendance/ShiftStartReminderNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversProactiveAttendanceAlert;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired a short while BEFORE an employee's shift starts, nudging them to be
 * ready to punch in. Recipient: the employee.
 */
class ShiftStartReminderNotification extends Notification implements ShouldQueue
{
    use DeliversProactiveAttendanceAlert, Queueable;

    /**
     * @param  string  $shiftCode  e.g. 'MRN'
     * @param  string  $startTime  local shift start, 'H:i' e.g. '07:00'
     * @param  string  $date       business date 'Y-m-d'
     */
    public function __construct(
        public string $shiftCode,
        public string $startTime,
        public string $date,
    ) {}

    public function typeKey(): string
    {
        return 'attendance.shift_start_reminder';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => 'Shift starting soon',
            'body' => "Your {$this->shiftCode} shift starts at {$this->startTime} — don't forget to punch in.",
            'url' => '/mobile/punch',
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
