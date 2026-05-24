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
        Schema::create('petty_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('petty_cash_loan_id')->constrained()->onDelete('cascade');
            $table->enum('type', ['loan_taken', 'expense', 'reimbursement', 'repayment']);
            $table->enum('category', ['office_supplies', 'meeting_supplies', 'office_maintenance', 'services'])->nullable();
            $table->decimal('amount', 10, 2);
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->timestamps();
            
            $table->index('petty_cash_loan_id');
            $table->index('type');
            $table->index('category');
            $table->index('transaction_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('petty_cash_transactions');
    }
};
