<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - leave_settings.attachment_rule: an explicit, HR-configurable rule (always / beyond N days / optional); existing
 *   types keep their behaviour (a requires_attachment_days value means "beyond N days").
 * - overtime_requests: day-based claims (holiday / rest day) with the compensation chosen, the day credit, the
 *   declared end time (no punch-out) and the approver's credit adjustment. Every existing row is the legacy
 *   minute-based kind and stays untouched.
 * - leaves / overtime_requests: the "returned for re-upload" state. The status column is NOT changed: the application
 *   stays pending at the same approval step and these columns carry the request.
 */
return new class extends Migration
{
    private const REUPLOAD = ['reupload_requested_at', 'reupload_requested_by', 'reupload_reason', 'reupload_count'];

    public function up(): void
    {
        if (Schema::hasTable('leave_settings') && ! Schema::hasColumn('leave_settings', 'attachment_rule')) {
            Schema::table('leave_settings', function (Blueprint $table) {
                // NULL = not chosen yet: derived from requires_attachment_days (a value there means "beyond N days", none means optional).
                $table->string('attachment_rule', 16)->nullable();
            });
            DB::table('leave_settings')->whereNotNull('requires_attachment_days')->update(['attachment_rule' => 'beyond_days']);
            DB::table('leave_settings')->whereNull('requires_attachment_days')->update(['attachment_rule' => 'optional']);
        }

        foreach (['leaves', 'overtime_requests'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'reupload_requested_at')) {
                Schema::table($name, function (Blueprint $table) {
                    $table->timestamp('reupload_requested_at')->nullable();
                    $table->string('reupload_requested_by', 50)->nullable();
                    $table->text('reupload_reason')->nullable();
                    $table->unsignedSmallInteger('reupload_count')->default(0);
                });
            }
        }

        if (Schema::hasTable('overtime_requests') && ! Schema::hasColumn('overtime_requests', 'claim_type')) {
            Schema::table('overtime_requests', function (Blueprint $table) {
                $table->string('claim_type', 16)->default('legacy_hours'); // legacy_hours | holiday | rest_day
                $table->string('compensation', 16)->nullable(); // payment | replacement
                $table->decimal('day_credit', 3, 1)->nullable();
                $table->decimal('original_day_credit', 3, 1)->nullable();
                $table->boolean('end_time_declared')->default(false); // punched in, no punch-out: the employee declared the end
                $table->dateTime('declared_end_at')->nullable();
                $table->string('credit_adjusted_by', 50)->nullable();
                $table->timestamp('credit_adjusted_at')->nullable();
                $table->string('credit_adjust_reason', 500)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('overtime_requests') && Schema::hasColumn('overtime_requests', 'claim_type')) {
            Schema::table('overtime_requests', fn (Blueprint $t) => $t->dropColumn(['claim_type', 'compensation', 'day_credit', 'original_day_credit', 'end_time_declared', 'declared_end_at', 'credit_adjusted_by', 'credit_adjusted_at', 'credit_adjust_reason']));
        }
        foreach (['leaves', 'overtime_requests'] as $name) {
            if (Schema::hasTable($name) && Schema::hasColumn($name, 'reupload_requested_at')) {
                Schema::table($name, fn (Blueprint $t) => $t->dropColumn(self::REUPLOAD));
            }
        }
        if (Schema::hasTable('leave_settings') && Schema::hasColumn('leave_settings', 'attachment_rule')) {
            Schema::table('leave_settings', fn (Blueprint $t) => $t->dropColumn('attachment_rule'));
        }
    }
};
