<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('type', ['fixed', 'flexible', 'open'])->default('fixed');
            $table->time('start_time');
            $table->time('end_time');
            $table->boolean('crosses_midnight')->default(false);
            $table->unsignedInteger('break_minutes')->default(0);
            $table->unsignedInteger('grace_in_minutes')->default(0);
            $table->unsignedInteger('grace_out_minutes')->default(0);
            $table->unsignedInteger('full_day_minutes')->default(0);
            $table->unsignedInteger('half_day_minutes')->default(0);
            $table->unsignedInteger('min_present_minutes')->default(0);
            $table->time('core_start_time')->nullable();
            $table->time('core_end_time')->nullable();
            $table->string('color', 16)->default('#3b82f6');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};
