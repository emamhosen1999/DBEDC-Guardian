<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_regularizations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->enum('type', ['missing_punchin', 'missing_punchout', 'wrong_time', 'missed_day', 'other']);
            $table->dateTime('requested_punchin')->nullable();
            $table->dateTime('requested_punchout')->nullable();
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected', 'cancelled'])->default('pending');
            $table->json('approval_chain')->nullable();
            $table->integer('current_approval_level')->default(0);
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at')->nullable();
            $table->boolean('applied')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'date']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_regularizations');
    }
};
