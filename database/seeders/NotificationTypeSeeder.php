<?php
// database/seeders/NotificationTypeSeeder.php
namespace Database\Seeders;

use App\Models\NotificationType;
use Illuminate\Database\Seeder;

class NotificationTypeSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            // Leave
            ['key' => 'leave.requested', 'category' => 'leave', 'label' => 'Leave request submitted', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager', 'Super Administrator']],
            ['key' => 'leave.approved', 'category' => 'leave', 'label' => 'Leave approved', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'leave.rejected', 'category' => 'leave', 'label' => 'Leave rejected', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'leave.cancelled', 'category' => 'leave', 'label' => 'Leave cancelled', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            // Attendance
            ['key' => 'attendance.missed_punch_in', 'category' => 'attendance', 'label' => 'Missed punch-in', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.missed_punch_out', 'category' => 'attendance', 'label' => 'Missed punch-out', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.roster_changed', 'category' => 'attendance', 'label' => 'Roster/shift changed', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.shift_swap_requested', 'category' => 'attendance', 'label' => 'Shift swap requested', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee', 'Manager']],
            ['key' => 'attendance.shift_swap_decided', 'category' => 'attendance', 'label' => 'Shift swap decision', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
            ['key' => 'attendance.time_correction_requested', 'category' => 'attendance', 'label' => 'Time correction requested', 'default_channels' => ['database', 'push', 'mail'], 'locked_channels' => ['database'], 'recipient_roles' => ['Manager']],
            ['key' => 'attendance.time_correction_decided', 'category' => 'attendance', 'label' => 'Time correction decision', 'default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'recipient_roles' => ['Employee']],
        ];

        foreach ($types as $t) {
            NotificationType::updateOrCreate(['key' => $t['key']], array_merge($t, ['is_active' => true, 'description' => $t['description'] ?? null]));
        }
    }
}
