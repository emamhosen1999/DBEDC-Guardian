<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            // Add composite index to attendances table (user_id, date)
            Schema::table('attendances', function (Blueprint $table) {
                $table->index(['user_id', 'date'], 'attendances_user_id_date_index');
            });
        } catch (\Exception $e) {
            // Ignore duplicate key error
            if (!str_contains($e->getMessage(), 'Duplicate key') && !str_contains($e->getMessage(), 'already exists')) throw $e;
        }

        try {
            // Add composite index to leaves table (user_id, from_date, to_date, status)
            Schema::table('leaves', function (Blueprint $table) {
                $table->index(['user_id', 'from_date', 'to_date', 'status'], 'leaves_user_date_status_index');
            });
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key') && !str_contains($e->getMessage(), 'already exists')) throw $e;
        }

        try {
            // Add composite index to petty_cash_loans table (user_id, status)
            Schema::table('petty_cash_loans', function (Blueprint $table) {
                $table->index(['user_id', 'status'], 'loans_user_status_index');
            });
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key') && !str_contains($e->getMessage(), 'already exists')) throw $e;
        }

        try {
            // Add composite indexes to daily_works table for optimization
            Schema::table('daily_works', function (Blueprint $table) {
                $table->index(['incharge', 'status', 'date'], 'works_incharge_status_date_index');
                $table->index(['assigned', 'status', 'date'], 'works_assigned_status_date_index');
            });
        } catch (\Exception $e) {
            if (!str_contains($e->getMessage(), 'Duplicate key') && !str_contains($e->getMessage(), 'already exists')) throw $e;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_works', function (Blueprint $table) {
            $table->dropIndex('works_assigned_status_date_index');
            $table->dropIndex('works_incharge_status_date_index');
        });

        Schema::table('petty_cash_loans', function (Blueprint $table) {
            $table->dropIndex('loans_user_status_index');
        });

        Schema::table('leaves', function (Blueprint $table) {
            $table->dropIndex('leaves_user_date_status_index');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->dropIndex('attendances_user_id_date_index');
        });
    }
};
