<?php

use App\Models\NotificationType;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Registers notification types added after the original seed (task.*, om.alert, hr.offboarding_initiated,
 * attendance.absence_streak_escalation, rfi.objection) on deploy. Insert-only (firstOrCreate): an admin's
 * channel customisation of an existing type is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notification_types')) {
            return;
        }

        foreach (NotificationTypeSeeder::types() as $type) {
            NotificationType::firstOrCreate(
                ['key' => $type['key']],
                array_merge($type, ['is_active' => true, 'description' => $type['description'] ?? null]),
            );
        }
    }

    public function down(): void
    {
        // Rows are configuration; nothing to undo.
    }
};
