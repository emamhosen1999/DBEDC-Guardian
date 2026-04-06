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
        if (Schema::hasTable('mobile_sync_mutations')) {
            return;
        }

        Schema::create('mobile_sync_mutations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('idempotency_key', 191);
            $table->string('module', 50);
            $table->string('action', 50);
            $table->string('status', 20);
            $table->json('result')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key'], 'mobile_sync_mutations_user_idempotency_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mobile_sync_mutations');
    }
};
