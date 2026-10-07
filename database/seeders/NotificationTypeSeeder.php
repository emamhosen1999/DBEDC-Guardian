<?php

// database/seeders/NotificationTypeSeeder.php

namespace Database\Seeders;

use App\Models\NotificationType;
use Illuminate\Database\Seeder;

class NotificationTypeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::types() as $t) {
            NotificationType::updateOrCreate(['key' => $t['key']], array_merge($t, ['is_active' => true, 'description' => $t['description'] ?? null]));
        }
    }

    /**
     * The registry. Every typeKey() in app/Notifications must appear here
     * (guarded by NotificationTypeRegistryTest).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function types(): array
    {
        return [
            // Leave
            ['key' => 'leave.requested', 'category' => 'leave', 'label' => 'Leave request submitted', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager', 'Super Administrator']],
            ['key' => 'leave.approved', 'category' => 'leave', 'label' => 'Leave approved', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'leave.rejected', 'category' => 'leave', 'label' => 'Leave rejected', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'leave.cancelled', 'category' => 'leave', 'label' => 'Leave cancelled', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            // Tasks (daily work; legacy /task/* endpoints)
            ['key' => 'task.assigned', 'category' => 'task', 'label' => 'Task assigned', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'task.status_changed', 'category' => 'task', 'label' => 'Task status changed', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee', 'Manager']],
            // Attendance
            ['key' => 'attendance.missed_punch_in', 'category' => 'attendance', 'label' => 'Missed punch-in', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.missed_punch_out', 'category' => 'attendance', 'label' => 'Missed punch-out', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.roster_changed', 'category' => 'attendance', 'label' => 'Roster/shift changed', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.shift_swap_requested', 'category' => 'attendance', 'label' => 'Shift swap requested', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee', 'Manager']],
            ['key' => 'attendance.shift_swap_decided', 'category' => 'attendance', 'label' => 'Shift swap decision', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.time_correction_requested', 'category' => 'attendance', 'label' => 'Time correction requested', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager']],
            ['key' => 'attendance.time_correction_decided', 'category' => 'attendance', 'label' => 'Time correction decision', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            // Proactive shift-lifecycle alerts (scheduled: attendance:shift-alerts)
            ['key' => 'attendance.shift_start_reminder', 'category' => 'attendance', 'label' => 'Shift start reminder', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.shift_punch_in_overdue', 'category' => 'attendance', 'label' => 'Punch-in overdue', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.shift_absence', 'category' => 'attendance', 'label' => 'Possible absence (manager)', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager', 'Super Administrator']],
            // Previously unregistered (silently fell back to hardcoded channels, or sent nothing)
            ['key' => 'attendance.absence_streak_escalation', 'category' => 'attendance', 'label' => 'Absence streak escalation', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager']],
            ['key' => 'hr.offboarding_initiated', 'category' => 'hr', 'label' => 'Offboarding initiated', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager']],
            ['key' => 'om.alert', 'category' => 'om', 'label' => 'O&M operational alert', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager']],
            ['key' => 'rfi.objection', 'category' => 'rfi', 'label' => 'RFI objection updates', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager', 'Employee']],
            ['key' => 'approvals.routing_incomplete', 'category' => 'hr', 'label' => 'Approval routing incomplete', 'description' => 'A request had to be routed to a Super Administrator because the requester has no manager and no HR Manager or escalation approver exists.', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Super Administrator']],
            // Biometric infrastructure alerts (scheduled: biometric:device-health-alert)
            // Own category, not 'attendance': preferences are stored per CATEGORY
            // (notification_preferences.user_id+category+channel), so filing this under
            // 'attendance' would let an admin who mutes routine attendance push also mute
            // the terminal-down alert without ever intending to. It also keeps the
            // established key-prefix == category convention every other row follows.
            // 'mail' is on by default here and nowhere else in the proactive alerts: this
            // one fires out of hours, at people who may have no push token registered, and
            // e-mail is the only channel that still lands when the admin is not carrying
            // the app. 'database' stays locked, so no combination of user preferences can
            // take this alert to zero channels.
            ['key' => 'biometric.device_silent', 'category' => 'biometric', 'label' => 'Biometric device silent', 'description' => 'A terminal has stopped sending heartbeats — its punches are not reaching attendance. Recipients are everyone holding the attendance.settings permission, not a fixed role list.', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Super Administrator', 'Administrator']],
        ];
    }
}
