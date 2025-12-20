<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add chainage fields and remove daily_work_id foreign key for many-to-many architecture.
     */
    public function up(): void
    {
        // Add chainage fields if they don't exist
        if (! Schema::hasColumn('rfi_objections', 'chainage_from')) {
            DB::statement('ALTER TABLE rfi_objections ADD COLUMN chainage_from VARCHAR(50) NULL AFTER category');
        }
        if (! Schema::hasColumn('rfi_objections', 'chainage_to')) {
            DB::statement('ALTER TABLE rfi_objections ADD COLUMN chainage_to VARCHAR(50) NULL AFTER chainage_from');
        }

        // Drop the daily_work_id column if it exists
        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            // Check if foreign key exists before dropping
            $foreignKeys = DB::select("
                SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'rfi_objections' 
                AND COLUMN_NAME = 'daily_work_id' 
                AND REFERENCED_TABLE_NAME IS NOT NULL
            ");

            if (! empty($foreignKeys)) {
                DB::statement('ALTER TABLE rfi_objections DROP FOREIGN KEY '.$foreignKeys[0]->CONSTRAINT_NAME);
            }

            DB::statement('ALTER TABLE rfi_objections DROP COLUMN daily_work_id');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore daily_work_id
        if (! Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            DB::statement('ALTER TABLE rfi_objections ADD COLUMN daily_work_id BIGINT UNSIGNED NULL AFTER id');
            DB::statement('ALTER TABLE rfi_objections ADD CONSTRAINT rfi_objections_daily_work_id_foreign FOREIGN KEY (daily_work_id) REFERENCES daily_works(id) ON DELETE CASCADE');
        }

        // Remove chainage fields
        if (Schema::hasColumn('rfi_objections', 'chainage_from')) {
            DB::statement('ALTER TABLE rfi_objections DROP COLUMN chainage_from');
        }
        if (Schema::hasColumn('rfi_objections', 'chainage_to')) {
            DB::statement('ALTER TABLE rfi_objections DROP COLUMN chainage_to');
        }
    }
};
