<?php
// app/Notifications/Attendance/MissingPunchInNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversProactiveAttendanceAlert;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired a while AFTER a shift has started when the employee still has no
 * punch-in for the shift's business date. Recipient: the employee (a chance to
 * punch in before it escalates to an absence). Distinct from the reactive
 * end-of-window 'attendance.missed_punch_in'.
 */
class MissingPunchInNotification extends Notification implements ShouldQueue
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
        return 'attendance.shift_punch_in_overdue';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => 'You have not punched in',
            'body' => "Your {$this->shiftCode} shift started at {$this->startTime} and you haven't punched in yet. Punch in now to avoid being marked absent.",
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
