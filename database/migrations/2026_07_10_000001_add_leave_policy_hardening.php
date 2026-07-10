<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. DB-level idempotency for ledger postings (accrual/opening/carry can
        //    otherwise double-post under concurrent command runs).
        if (Schema::hasTable('leave_ledger') && ! Schema::hasColumn('leave_ledger', 'idempotency_key')) {
            Schema::table('leave_ledger', function (Blueprint $table) {
                $table->string('idempotency_key', 80)->nullable()->after('reason');
                $table->unique('idempotency_key', 'leave_ledger_idem_unique');
            });
        }

        // 2. Structured eligibility + encashment cap on leave types.
        if (Schema::hasTable('leave_settings')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                if (! Schema::hasColumn('leave_settings', 'eligible_gender')) {
                    $table->string('eligible_gender', 10)->nullable()->comment('male|female|null=all');
                }
                if (! Schema::hasColumn('leave_settings', 'min_service_months')) {
                    $table->unsignedSmallInteger('min_service_months')->nullable()->comment('Minimum service months before this type may be requested');
                }
                if (! Schema::hasColumn('leave_settings', 'max_encash_days')) {
                    $table->decimal('max_encash_days', 5, 1)->nullable()->comment('Yearly encashment cap in days');
                }
            });
        }

        // 3. Cancellation trail on leaves.
        if (Schema::hasTable('leaves')) {
            Schema::table('leaves', function (Blueprint $table) {
                if (! Schema::hasColumn('leaves', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable();
                }
                if (! Schema::hasColumn('leaves', 'cancelled_by')) {
                    $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('leave_ledger') && Schema::hasColumn('leave_ledger', 'idempotency_key')) {
            Schema::table('leave_ledger', function (Blueprint $table) {
                $table->dropUnique('leave_ledger_idem_unique');
                $table->dropColumn('idempotency_key');
            });
        }

        if (Schema::hasTable('leave_settings')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                foreach (['eligible_gender', 'min_service_months', 'max_encash_days'] as $col) {
                    if (Schema::hasColumn('leave_settings', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        if (Schema::hasTable('leaves')) {
            Schema::table('leaves', function (Blueprint $table) {
                if (Schema::hasColumn('leaves', 'cancelled_by')) {
                    $table->dropConstrainedForeignId('cancelled_by');
                }
                if (Schema::hasColumn('leaves', 'cancelled_at')) {
                    $table->dropColumn('cancelled_at');
                }
            });
        }
    }
};
