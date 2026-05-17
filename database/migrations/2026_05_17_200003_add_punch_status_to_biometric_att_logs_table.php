<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('biometric_att_logs', function (Blueprint $table) {
            $table->enum('punch_status', ['processed', 'failed', 'duplicate', 'unknown_user', 'wrong_device'])
                ->default('processed')
                ->after('check_type')
                ->index();
            $table->string('punch_status_reason')->nullable()->after('punch_status');
        });
    }

    public function down(): void
    {
        Schema::table('biometric_att_logs', function (Blueprint $table) {
            $table->dropColumn(['punch_status', 'punch_status_reason']);
        });
    }
};
