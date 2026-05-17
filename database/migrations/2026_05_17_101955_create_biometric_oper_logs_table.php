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
        Schema::create('biometric_oper_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('biometric_device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('serial_number')->index();
            $table->text('raw_data');
            $table->string('operation_type')->nullable();
            $table->string('user_pin')->nullable();
            $table->text('context')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['serial_number', 'occurred_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_oper_logs');
    }
};
