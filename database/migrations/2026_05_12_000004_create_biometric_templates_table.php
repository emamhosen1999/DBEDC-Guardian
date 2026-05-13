<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('biometric_device_id')->constrained('biometric_devices')->onDelete('cascade');
            $table->string('device_user_id');
            $table->enum('template_type', ['fingerprint', 'face', 'palm']);
            $table->integer('finger_index')->nullable(); // For fingerprint (0-9)
            $table->text('template_data'); // Base64 encoded template
            $table->integer('template_size')->nullable();
            $table->string('template_version')->default('v10');
            $table->timestamps();

            $table->index(['user_id', 'biometric_device_id']);
            $table->index('device_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_templates');
    }
};
