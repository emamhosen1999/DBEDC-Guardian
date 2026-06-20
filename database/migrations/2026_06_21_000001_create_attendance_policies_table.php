<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_policies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->enum('scope_type', ['org', 'department', 'designation', 'user']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->integer('priority')->default(0);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->unsignedBigInteger('version_group_id');
            $table->integer('version')->default(1);
            $table->enum('status', ['draft', 'active', 'archived'])->default('draft');
            $table->enum('punch_strictness', ['warn', 'flag', 'restrict'])->default('warn');
            $table->integer('outside_window_minutes')->default(120);
            $table->json('grace_tiers')->nullable();
            $table->json('rounding')->nullable();
            $table->json('rule_overrides')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->index(['scope_type', 'scope_id', 'status']);
            $table->index(['version_group_id', 'version']);
            $table->index(['effective_from', 'effective_to']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_policies');
    }
};
