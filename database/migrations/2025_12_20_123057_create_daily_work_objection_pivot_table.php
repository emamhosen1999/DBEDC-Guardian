<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Pivot table for many-to-many relationship between objections and RFIs (daily works).
     * One objection can affect multiple RFIs (e.g., across chainages).
     */
    public function up(): void
    {
        if (! Schema::hasTable('daily_work_objection')) {
            Schema::create('daily_work_objection', function (Blueprint $table) {
                $table->id();
                $table->foreignId('daily_work_id')->constrained('daily_works')->onDelete('cascade');
                $table->foreignId('rfi_objection_id')->constrained('rfi_objections')->onDelete('cascade');
                $table->foreignId('attached_by')->nullable()->constrained('users')->onDelete('set null');
                $table->timestamp('attached_at')->useCurrent();
                $table->text('attachment_notes')->nullable();
                $table->timestamps();

                // Indexes for performance
                $table->index('daily_work_id');
                $table->index('rfi_objection_id');
                $table->index(['daily_work_id', 'rfi_objection_id'], 'work_objection_idx');

                // Ensure unique combination
                $table->unique(['daily_work_id', 'rfi_objection_id'], 'work_objection_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('daily_work_objection');
    }
};
