<?php
// app/Notifications/Attendance/ShiftAbsenceNotification.php
namespace App\Notifications\Attendance;

use App\Notifications\Concerns\DeliversProactiveAttendanceAlert;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Fired well into a shift when an employee still has no punch-in — a likely
 * absence. Recipient: the employee's MANAGER (report_to, else department
 * manager), so they can follow up / arrange cover.
 */
class ShiftAbsenceNotification extends Notification implements ShouldQueue
{
    use DeliversProactiveAttendanceAlert, Queueable;

    /**
     * @param  string  $employeeName  the absent employee's display name
     * @param  string  $shiftCode     e.g. 'MRN'
     * @param  string  $startTime     local shift start, 'H:i' e.g. '07:00'
     * @param  string  $date          business date 'Y-m-d'
     */
    public function __construct(
        public string $employeeName,
        public string $shiftCode,
        public string $startTime,
        public string $date,
    ) {}

    public function typeKey(): string
    {
        return 'attendance.shift_absence';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'title' => 'Possible absence',
            'body' => "{$this->employeeName} has not punched in for the {$this->shiftCode} shift (started {$this->startTime}) and may be absent.",
            'url' => '/mobile/team-attendance',
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
