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
        if (! Schema::hasTable('leave_settings')) {
            Schema::create('leave_settings', function (Blueprint $table) {
                $table->id();
                $table->string('type')->unique();
                $table->string('symbol', 10)->nullable();
                $table->unsignedInteger('days')->default(0);
                $table->text('eligibility')->nullable();
                $table->boolean('carry_forward')->default(false);
                $table->boolean('earned_leave')->default(false);
                $table->boolean('is_earned')->default(false);
                $table->boolean('requires_approval')->default(true);
                $table->boolean('auto_approve')->default(false);
                $table->text('special_conditions')->nullable();
                $table->timestamps();
            });

            return;
        }

        if (! Schema::hasColumn('leave_settings', 'type')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->string('type')->nullable();
            });
        }

        if (! Schema::hasColumn('leave_settings', 'symbol')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->string('symbol', 10)->nullable();
            });
        }

        if (! Schema::hasColumn('leave_settings', 'days')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->unsignedInteger('days')->default(0);
            });
        }

        if (! Schema::hasColumn('leave_settings', 'eligibility')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->text('eligibility')->nullable();
            });
        }

        if (! Schema::hasColumn('leave_settings', 'carry_forward')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->boolean('carry_forward')->default(false);
            });
        }

        if (! Schema::hasColumn('leave_settings', 'earned_leave')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->boolean('earned_leave')->default(false);
            });
        }

        if (! Schema::hasColumn('leave_settings', 'is_earned')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->boolean('is_earned')->default(false);
            });
        }

        if (! Schema::hasColumn('leave_settings', 'requires_approval')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->boolean('requires_approval')->default(true);
            });
        }

        if (! Schema::hasColumn('leave_settings', 'auto_approve')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->boolean('auto_approve')->default(false);
            });
        }

        if (! Schema::hasColumn('leave_settings', 'special_conditions')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->text('special_conditions')->nullable();
            });
        }

        if (! Schema::hasColumn('leave_settings', 'created_at') && ! Schema::hasColumn('leave_settings', 'updated_at')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Intentionally left blank to avoid destructive schema rollback in existing environments.
    }
};
