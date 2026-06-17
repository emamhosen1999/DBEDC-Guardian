<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Add 'downloaded' to punch_status ENUM
            DB::statement("ALTER TABLE `biometric_att_logs` MODIFY COLUMN `punch_status` ENUM('processed', 'failed', 'duplicate', 'unknown_user', 'wrong_device', 'downloaded') NOT NULL DEFAULT 'processed'");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            // Revert punch_status ENUM
            DB::statement("ALTER TABLE `biometric_att_logs` MODIFY COLUMN `punch_status` ENUM('processed', 'failed', 'duplicate', 'unknown_user', 'wrong_device') NOT NULL DEFAULT 'processed'");
        }
    }
};
