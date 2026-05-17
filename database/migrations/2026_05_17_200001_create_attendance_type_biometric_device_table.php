<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_type_biometric_device', function (Blueprint $table) {
            $table->foreignId('attendance_type_id')
                ->constrained('attendance_types')
                ->cascadeOnDelete();
            $table->foreignId('biometric_device_id')
                ->constrained('biometric_devices')
                ->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['attendance_type_id', 'biometric_device_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_type_biometric_device');
    }
};
