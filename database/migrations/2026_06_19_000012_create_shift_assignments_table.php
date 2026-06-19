<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->enum('scope_type', ['user', 'department', 'designation', 'org']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('rotation_pattern_id')->nullable()->constrained('shift_rotation_patterns')->nullOnDelete();
            $table->date('anchor_date');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->integer('priority')->default(0);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};
