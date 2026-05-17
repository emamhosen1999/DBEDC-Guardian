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
        Schema::create('biometric_att_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->string('serial_number')->index();
            $table->string('user_pin')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->timestamp('punch_time')->index();
            $table->string('check_type')->default('in'); // in, out, break_in, break_out, ot_in, ot_out
            $table->string('verify_code')->nullable();
            $table->string('work_code')->nullable();
            $table->text('raw_data')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_att_logs');
    }
};
