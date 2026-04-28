<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add FULLTEXT index for optimized search functionality.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('daily_works')) {
            // Check if FULLTEXT index already exists
            $indexExists = DB::selectOne("
                SELECT COUNT(*) as count 
                FROM INFORMATION_SCHEMA.STATISTICS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'daily_works' 
                AND INDEX_NAME = 'idx_daily_works_search'
            ");

            if ($indexExists && $indexExists->count == 0) {
                DB::statement('
                    ALTER TABLE daily_works 
                    ADD FULLTEXT INDEX idx_daily_works_search 
                    (number, location, description, type, side, inspection_details)
                ');
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql' && Schema::hasTable('daily_works')) {
            DB::statement('
                ALTER TABLE daily_works 
                DROP INDEX idx_daily_works_search
            ');
        }
    }
};