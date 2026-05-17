<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_attendance_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->unsignedBigInteger('attendance_type_id')->nullable();
            $table->unsignedBigInteger('biometric_device_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('attendance_type_id')->references('id')->on('attendance_types')->onDelete('set null');
            $table->foreign('biometric_device_id')->references('id')->on('biometric_devices')->onDelete('set null');
        });

        // Seed existing assignments from users.attendance_type_id
        DB::statement('
            INSERT INTO employee_attendance_types (user_id, attendance_type_id, created_at, updated_at)
            SELECT id, attendance_type_id, NOW(), NOW()
            FROM users
            WHERE attendance_type_id IS NOT NULL
        ');

        // Drop attendance_config from users (config now lives here)
        if (Schema::hasColumn('users', 'attendance_config')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('attendance_config');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('attendance_config')->nullable()->after('attendance_type_id');
        });

        Schema::dropIfExists('employee_attendance_types');
    }
};
