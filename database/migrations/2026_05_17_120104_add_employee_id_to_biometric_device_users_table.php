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
        Schema::table('biometric_device_users', function (Blueprint $table) {
            $table->integer('employee_id')->nullable()->after('user_id')->index();
        });

        // Populate employee_id from users table for existing records
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('
                UPDATE biometric_device_users
                SET employee_id = (SELECT employee_id FROM users WHERE users.id = biometric_device_users.user_id)
            ');
        } else {
            DB::statement('
                UPDATE biometric_device_users bdu
                JOIN users u ON bdu.user_id = u.id
                SET bdu.employee_id = u.employee_id
            ');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }
        Schema::table('biometric_device_users', function (Blueprint $table) {
            $table->dropColumn('employee_id');
        });
    }
};
