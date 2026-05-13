<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_device_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_device_id');
            $table->unsignedBigInteger('user_id');
            $table->string('device_user_id', 50); // enrollment number on the physical device
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['biometric_device_id', 'device_user_id']);
            $table->unique(['biometric_device_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_device_users');
    }
};
