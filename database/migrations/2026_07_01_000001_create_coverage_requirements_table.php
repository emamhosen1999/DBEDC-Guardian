<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('coverage_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_location_id')->constrained('work_locations')->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained('shifts')->cascadeOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained('designations')->nullOnDelete();
            $table->unsignedInteger('required_headcount');
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->date('date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['work_location_id', 'shift_id', 'date']);
            $table->index(['work_location_id', 'shift_id', 'weekday']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('coverage_requirements');
    }
};
