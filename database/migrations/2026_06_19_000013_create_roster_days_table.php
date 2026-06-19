<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roster_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->enum('source', ['pattern', 'rule', 'manual', 'swap'])->default('pattern');
            $table->unsignedBigInteger('assignment_id')->nullable();
            $table->string('note')->nullable();
            $table->boolean('locked')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roster_days');
    }
};
