<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Manual entry point for the employee-facing attendance reminders. There is ONE code path: the
 * notifications of `attendance:shift-alerts`, delivered through PushChannel and the user's
 * preferences, only to employees rostered to work today (holidays, approved leave, off days and
 * offboarded staff excluded) and, for the overdue phase, who have not punched in. Its once-per-
 * (user, date, shift, phase) markers make a manual run safe next to the 5-minute schedule.
 *
 * It used to dispatch a per-user FCM job AND a MissedPunchNotification to every user at 22:17
 * regardless of roster or punches; that job and its schedule entry were removed.
 */
class SendAttendanceReminders extends Command
{
    protected $signature = 'attendance:reminders
                            {--lead=30 : Minutes BEFORE shift start for the start reminder}';

    protected $description = 'Re-run the shift-start and overdue punch-in reminders (alias of attendance:shift-alerts, employee phases).';

    public function handle(): int
    {
        $lead = (int) $this->option('lead');

        $reminder = $this->call('attendance:shift-alerts', ['--phase' => 'reminder', '--lead' => $lead]);
        $overdue = $this->call('attendance:shift-alerts', ['--phase' => 'overdue']);

        return $reminder === self::SUCCESS && $overdue === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }
}
