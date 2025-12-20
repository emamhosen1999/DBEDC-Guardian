<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Add standalone date index for faster date range queries.
     * The existing composite indexes (status,date) and (incharge,date)
     * are less efficient for date-first filtering.
     */
    public function up(): void
    {
        Schema::table('daily_works', function (Blueprint $table) {
            // Add standalone date index for date range queries (most common filter)
            $table->index('date', 'daily_works_date_index');

            // Add composite index for date + type (common filter combination)
            $table->index(['date', 'type'], 'daily_works_date_type_index');

            // Add composite index for date descending ordering optimization
            // MySQL will use this for ORDER BY date DESC queries
            $table->index(['date', 'id'], 'daily_works_date_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_works', function (Blueprint $table) {
            $table->dropIndex('daily_works_date_index');
            $table->dropIndex('daily_works_date_type_index');
            $table->dropIndex('daily_works_date_id_index');
        });
    }
};
