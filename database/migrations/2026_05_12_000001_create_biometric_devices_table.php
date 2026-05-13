<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('serial_number', 191)->unique();
            $table->string('ip_address')->nullable();
            $table->string('location')->nullable();
            $table->string('model')->nullable();
            $table->string('auth_token', 64)->unique();
            $table->boolean('is_active')->default(true);
            $table->json('config')->nullable();
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
