<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_settings') || Schema::hasColumn('leave_settings', 'is_paid')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            $table->boolean('is_paid')->default(true)->after('is_earned');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_settings') && Schema::hasColumn('leave_settings', 'is_paid')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                $table->dropColumn('is_paid');
            });
        }
    }
};
