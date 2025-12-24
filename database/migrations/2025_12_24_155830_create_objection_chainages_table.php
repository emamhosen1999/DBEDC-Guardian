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
        Schema::create('objection_chainages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('objection_id')
                ->constrained('rfi_objections')
                ->cascadeOnDelete();
            $table->string('chainage', 50)->comment('Display format e.g., K35+897');
            $table->unsignedInteger('chainage_meters')->comment('Numeric value in meters for comparison');
            $table->enum('entry_type', ['specific', 'range_start', 'range_end'])
                ->default('specific')
                ->comment('specific = single point, range_start/range_end = range endpoints');
            $table->timestamps();

            // Index for fast lookups
            $table->index(['objection_id', 'entry_type']);
            $table->index('chainage_meters');

            // Prevent duplicate chainages for same objection and type
            $table->unique(['objection_id', 'chainage', 'entry_type'], 'unique_objection_chainage');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('objection_chainages');
    }
};
