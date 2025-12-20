<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Add primary key to daily_works table id column.
     */
    public function up(): void
    {
        // Check if primary key already exists
        $indexes = DB::select("SHOW INDEX FROM daily_works WHERE Key_name = 'PRIMARY'");

        if (empty($indexes)) {
            // Use raw SQL to add primary key
            DB::statement('ALTER TABLE daily_works ADD PRIMARY KEY (id)');

            // Make it auto-increment
            DB::statement('ALTER TABLE daily_works MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Drop the primary key
        Schema::table('daily_works', function (Blueprint $table) {
            $table->dropPrimary('PRIMARY');
        });
    }
};
