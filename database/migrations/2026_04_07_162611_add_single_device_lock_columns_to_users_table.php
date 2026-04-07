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
        if (! Schema::hasColumn('users', 'single_device_login_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('single_device_login_enabled')->default(false);
            });
        }

        if (! Schema::hasColumn('users', 'device_reset_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('device_reset_at')->nullable();
            });
        }

        if (! Schema::hasColumn('users', 'device_reset_reason')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('device_reset_reason')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'device_reset_reason')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('device_reset_reason');
            });
        }

        if (Schema::hasColumn('users', 'device_reset_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('device_reset_at');
            });
        }

        if (Schema::hasColumn('users', 'single_device_login_enabled')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('single_device_login_enabled');
            });
        }
    }
};
