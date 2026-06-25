<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('leave_ledger')) {
            return;
        }

        Schema::create('leave_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('leave_type'); // -> leave_settings.id
            $table->smallInteger('period_year');
            $table->string('txn_type', 24); // opening|accrual|consumption|consumption_reversal|carry_forward|carry_expiry|encashment|adjustment
            $table->decimal('amount', 6, 2);          // signed
            $table->decimal('balance_after', 6, 2);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('leave_type')->references('id')->on('leave_settings')->cascadeOnDelete();
            $table->index(['user_id', 'leave_type', 'period_year']);
            $table->index(['txn_type', 'created_at']);
            $table->index(['source_type', 'source_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_ledger');
    }
};
