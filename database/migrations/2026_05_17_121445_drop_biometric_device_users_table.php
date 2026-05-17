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
        Schema::dropIfExists('biometric_device_users');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::create('biometric_device_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('biometric_device_id');
            $table->unsignedBigInteger('user_id');
            $table->integer('employee_id')->nullable();
            $table->string('device_user_id', 50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['biometric_device_id', 'device_user_id']);
            $table->unique(['biometric_device_id', 'user_id']);
            $table->index('user_id');
            $table->index('employee_id');
        });
    }
};
