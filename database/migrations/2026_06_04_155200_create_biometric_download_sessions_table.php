<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_download_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->constrained('biometric_devices')->onDelete('cascade');
            $table->enum('trigger_type', ['manual', 'scheduled', 'reconnect', 'bulk']);
            $table->enum('status', ['pending', 'in_progress', 'completed', 'failed', 'partial'])->default('pending');
            $table->integer('total_records')->default(0);
            $table->integer('processed_count')->default(0);
            $table->integer('duplicate_count')->default(0);
            $table->integer('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->foreignId('command_id')->nullable()->constrained('biometric_device_commands')->onDelete('set null');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();

            // Indexes for fast lookup
            $table->index(['biometric_device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_download_sessions');
    }
};
