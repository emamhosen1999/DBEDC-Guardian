<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site Instructions (SI) issued by the Independent Engineer to the Project Company.
 * Source of truth: IE "Status of Site Instruction" registers (Dhaka Bypass Expressway PPP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_instructions', function (Blueprint $table) {
            $table->id();
            $table->string('si_number')->unique();          // e.g. SI-04
            $table->string('ie_ref')->nullable();            // e.g. SI/IE/SQCME/004
            $table->string('department')->nullable();        // Quality Control | Pavement | Structure
            $table->string('category')->nullable();          // discipline bucket for analytics
            $table->string('location')->nullable();          // chainage / stretch
            $table->integer('chainage_meters')->nullable();  // parsed chainage for mapping
            $table->text('description')->nullable();
            $table->text('summary')->nullable();
            $table->text('remarks')->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->date('issued_date')->nullable();
            $table->date('closed_date')->nullable();
            $table->timestamps();

            $table->index(['status', 'department']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_instructions');
    }
};
