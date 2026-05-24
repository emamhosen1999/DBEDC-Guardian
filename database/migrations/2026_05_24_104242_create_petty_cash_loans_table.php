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
        Schema::create('petty_cash_loans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->decimal('loan_amount', 10, 2);
            $table->decimal('original_amount', 10, 2);
            $table->decimal('current_balance', 10, 2)->default(0);
            $table->enum('status', ['active', 'closed', 'settled'])->default('active');
            $table->date('loan_date');
            $table->date('closed_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            
            $table->index('user_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_loans');
    }
};
