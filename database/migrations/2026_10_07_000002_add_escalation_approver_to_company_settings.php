<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * approvals.escalation_approver_id: who approves requests of people with no usable manager when the
 * organization has no HR Manager. Kept on the existing company_settings row (the app's system-settings
 * store); a users.employee_id value, deliberately without a foreign key (users are soft-deleted).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_settings') && ! Schema::hasColumn('company_settings', 'escalation_approver_id')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->string('escalation_approver_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_settings', 'escalation_approver_id')) {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->dropColumn('escalation_approver_id');
            });
        }
    }
};
