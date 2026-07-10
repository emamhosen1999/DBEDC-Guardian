<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leave_settings')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_settings', 'requires_attachment_days')) {
                // Attachment (e.g. medical certificate) becomes mandatory when the
                // request exceeds this many days. NULL = never required.
                $table->decimal('requires_attachment_days', 5, 1)->nullable()
                    ->comment('Attachment required when requested days exceed this; null = never');
            }
            if (! Schema::hasColumn('leave_settings', 'is_comp_off')) {
                // Marks the type that banks compensatory-off grants (worked on
                // off-day/holiday/approved leave). At most one type should be flagged.
                $table->boolean('is_comp_off')->default(false)
                    ->comment('Compensatory-off bank type (receives comp_off ledger grants)');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('leave_settings')) {
            return;
        }

        Schema::table('leave_settings', function (Blueprint $table) {
            foreach (['requires_attachment_days', 'is_comp_off'] as $col) {
                if (Schema::hasColumn('leave_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
