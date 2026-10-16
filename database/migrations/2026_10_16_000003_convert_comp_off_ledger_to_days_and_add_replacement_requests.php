<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replacement leave (compensatory off, Labour Act s.103 practice) is counted in DAYS, not minutes.
 * comp_off_ledger keeps its history (the minute column stays; there are no rows in production) and gains the day
 * columns: a credit (+days) has an expiry 60 days after the work date and reminder stamps; a use or an expiry is a
 * negative row pointing at the credit it consumed (credit_id), so nothing is ever silently dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('comp_off_ledger') && ! Schema::hasColumn('comp_off_ledger', 'days')) {
            Schema::table('comp_off_ledger', function (Blueprint $table) {
                // The enum could not hold 'expired'; a plain string is the same on every database.
                $table->string('source_type', 24)->change();
            });
            Schema::table('comp_off_ledger', function (Blueprint $table) {
                $table->decimal('days', 4, 1)->default(0);
                $table->date('work_date')->nullable();
                $table->unsignedBigInteger('credit_id')->nullable()->index();
                $table->timestamp('reminded_14_at')->nullable();
                $table->timestamp('reminded_3_at')->nullable();
            });
        }

        if (! Schema::hasTable('replacement_leave_requests')) {
            Schema::create('replacement_leave_requests', function (Blueprint $table) {
                $table->id();
                $table->string('user_id', 50)->index();
                $table->date('replacement_date');
                $table->decimal('days', 3, 1); // 1 or 0.5
                $table->string('reason', 500)->nullable();
                $table->string('status', 16)->default('pending'); // pending | approved | rejected | cancelled
                $table->json('approval_chain')->nullable();
                $table->unsignedInteger('current_approval_level')->default(0);
                $table->string('approved_by', 50)->nullable();
                $table->dateTime('approved_at')->nullable();
                $table->json('allocations'); // [{credit_id, days, work_date}] drawn first-expiring-first
                $table->boolean('roster_applied')->default(false);
                $table->timestamp('reupload_requested_at')->nullable();
                $table->string('reupload_requested_by', 50)->nullable();
                $table->text('reupload_reason')->nullable();
                $table->unsignedSmallInteger('reupload_count')->default(0);
                $table->timestamps();
                $table->index(['user_id', 'replacement_date']);
                $table->index('status');
            });
        }

        // One reminder per employee per unclaimed day.
        if (! Schema::hasTable('overtime_claim_reminders')) {
            Schema::create('overtime_claim_reminders', function (Blueprint $table) {
                $table->id();
                $table->string('user_id', 50);
                $table->date('work_date');
                $table->timestamp('created_at')->nullable();
                $table->unique(['user_id', 'work_date']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overtime_claim_reminders');
        Schema::dropIfExists('replacement_leave_requests');
        if (Schema::hasTable('comp_off_ledger') && Schema::hasColumn('comp_off_ledger', 'days')) {
            Schema::table('comp_off_ledger', fn (Blueprint $t) => $t->dropColumn(['days', 'work_date', 'credit_id', 'reminded_14_at', 'reminded_3_at']));
        }
    }
};
