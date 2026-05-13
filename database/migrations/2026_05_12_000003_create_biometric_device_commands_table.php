<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_device_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->constrained('biometric_devices')->onDelete('cascade');
            $table->string('command_type'); // 'REBOOT', 'SET_TIME', 'ADD_USER', 'DELETE_USER', 'CLEAR_LOG'
            $table->json('payload')->nullable(); // Command parameters
            $table->enum('status', ['pending', 'sent', 'executed', 'failed'])->default('pending');
            $table->integer('retry_count')->default(0);
            $table->string('return_code')->nullable(); // Device's return code
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            // Index for efficient querying
            $table->index(['biometric_device_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_device_commands');
    }
};
