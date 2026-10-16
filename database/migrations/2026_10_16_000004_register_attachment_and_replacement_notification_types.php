<?php

use App\Models\NotificationType;
use Illuminate\Database\Migrations\Migration;

/** Notification types are registry rows: a type that is not registered sends nothing, so the three new ones are inserted here. */
return new class extends Migration
{
    private const TYPES = [
        ['key' => 'attachments.reupload_requested', 'category' => 'attachments', 'label' => 'Document returned for re-upload', 'recipient_roles' => ['Employee']],
        ['key' => 'attendance.replacement_expiring', 'category' => 'attendance', 'label' => 'Replacement leave expiring', 'recipient_roles' => ['Employee']],
        ['key' => 'attendance.overtime_unclaimed', 'category' => 'attendance', 'label' => 'Rest-day / holiday work not claimed', 'recipient_roles' => ['Employee']],
    ];

    public function up(): void
    {
        foreach (self::TYPES as $t) {
            NotificationType::updateOrCreate(['key' => $t['key']], $t + ['default_channels' => ['database', 'push'], 'locked_channels' => ['database'], 'is_active' => true]);
        }
    }

    public function down(): void
    {
        NotificationType::whereIn('key', array_column(self::TYPES, 'key'))->delete();
    }
};
