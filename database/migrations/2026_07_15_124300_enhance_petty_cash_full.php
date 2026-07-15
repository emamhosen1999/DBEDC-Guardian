<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Phase 2: Change category from enum to varchar for custom categories
        // MySQL doesn't let you easily alter enums, so we change the column type
        if (Schema::hasColumn('petty_cash_transactions', 'category')) {
            Schema::table('petty_cash_transactions', function (Blueprint $table) {
                $table->string('category', 100)->nullable()->change();
            });
        }

        // Phase 3: Add fund_name for multiple funds per user
        if (!Schema::hasColumn('petty_cash_loans', 'fund_name')) {
            Schema::table('petty_cash_loans', function (Blueprint $table) {
                $table->string('fund_name', 255)->default('General Fund')->after('user_id');
            });
        }

        // Phase 5: Approval fields
        Schema::table('petty_cash_loans', function (Blueprint $table) {
            if (!Schema::hasColumn('petty_cash_loans', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('notes');
            }
            if (!Schema::hasColumn('petty_cash_loans', 'approval_comment')) {
                $table->text('approval_comment')->nullable()->after('approved_by');
            }
            if (!Schema::hasColumn('petty_cash_loans', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('approval_comment');
            }
            if (!Schema::hasColumn('petty_cash_loans', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
        });

        // Phase 6: Audit trail table
        if (!Schema::hasTable('petty_cash_audit_logs')) {
            Schema::create('petty_cash_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('petty_cash_loan_id');
                $table->unsignedBigInteger('user_id');
                $table->string('action', 50); // created, updated, deleted, approved, rejected, closed
                $table->string('entity_type', 50); // loan, transaction
                $table->unsignedBigInteger('entity_id');
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index('petty_cash_loan_id');
                $table->index('user_id');
                $table->index('action');
                $table->index('created_at');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('petty_cash_audit_logs');

        Schema::table('petty_cash_loans', function (Blueprint $table) {
            $columns = ['approved_by', 'approval_comment', 'approved_at', 'rejected_at', 'fund_name'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('petty_cash_loans', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
