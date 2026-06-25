<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zone model: a work location (and an employee override) can restrict the biometric
 * method to a SUBSET of devices. If no subset is chosen, all of the biometric type's
 * devices are valid (pool fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('work_location_biometric_device')) {
            Schema::create('work_location_biometric_device', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_location_id')->constrained('work_locations')->cascadeOnDelete();
                $table->foreignId('biometric_device_id')->constrained('biometric_devices')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['work_location_id', 'biometric_device_id'], 'wlbd_unique');
            });
        }

        if (! Schema::hasTable('user_biometric_device')) {
            Schema::create('user_biometric_device', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('biometric_device_id')->constrained('biometric_devices')->cascadeOnDelete();
                $table->timestamps();
                $table->unique(['user_id', 'biometric_device_id'], 'ubd_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_biometric_device');
        Schema::dropIfExists('work_location_biometric_device');
    }
};
