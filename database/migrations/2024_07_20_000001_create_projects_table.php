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
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->id();
                $table->string('project_name');
                $table->unsignedBigInteger('client_id')->nullable();
                $table->date('start_date')->nullable();
                $table->date('end_date')->nullable();
                $table->decimal('rate', 15, 2)->nullable();
                $table->string('rate_type')->nullable();
                $table->string('priority')->nullable();
                $table->unsignedBigInteger('project_leader_id')->nullable();
                $table->unsignedBigInteger('team_leader_id')->nullable();
                $table->text('description')->nullable();
                $table->json('files')->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
