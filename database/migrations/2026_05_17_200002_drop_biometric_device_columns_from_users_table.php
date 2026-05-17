<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'biometric_device_id')) {
                $table->dropForeign(['biometric_device_id']);
                $table->dropIndex(['biometric_device_id']);
                $table->dropColumn('biometric_device_id');
            }
            if (Schema::hasColumn('users', 'biometric_device_name')) {
                $table->dropColumn('biometric_device_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('biometric_device_id')->nullable()->after('locale')->index();
            $table->string('biometric_device_name')->nullable()->after('biometric_device_id');
            $table->foreign('biometric_device_id')
                ->references('id')
                ->on('biometric_devices')
                ->nullOnDelete();
        });
    }
};
