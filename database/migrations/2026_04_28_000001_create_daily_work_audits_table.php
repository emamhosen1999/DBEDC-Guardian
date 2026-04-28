<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_work_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_work_id')->nullable();
            $table->string('action', 50); // created, updated, deleted, status_changed, etc.
            $table->string('entity_type', 50); // daily_work, objection, file, etc.
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->text('description')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('session_id')->nullable();
            $table->string('request_id')->nullable();
            $table->boolean('is_bulk_operation')->default(false);
            $table->json('bulk_operation_details')->nullable();
            $table->string('source', 50)->default('web'); // web, mobile, api, cli
            $table->timestamp('created_at')->useCurrent();
            
            // Indexes for performance
            $table->index(['daily_work_id']);
            $table->index(['action']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['user_id']);
            $table->index(['created_at']);
            $table->index(['is_bulk_operation']);
            $table->index(['source']);
            
            // Composite indexes for common queries
            $table->index(['daily_work_id', 'action', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['entity_type', 'entity_id', 'action']);
            
            // Note: Foreign key constraints removed due to table structure issues
            // Relationships will be handled at the application level
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_work_audits');
    }
};
