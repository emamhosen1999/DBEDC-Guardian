<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comp_off_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('minutes'); // + credit, - debit
            $table->enum('source_type', ['overtime', 'holiday_work', 'manual', 'used']);
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('note')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comp_off_ledger');
    }
};
