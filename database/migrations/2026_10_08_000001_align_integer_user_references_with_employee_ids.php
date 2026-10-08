<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entity-relationship audit 2026-10-08 (docs/audit/ENTITY_RELATIONSHIP_AUDIT_2026-10-08.md).
 *
 * users.employee_id is a string. Columns that reference an employee but are still unsigned
 * integers silently cast a non-numeric id to 0 and never join; the earlier alignment
 * migration (2026_09_02_000002) missed the ones below (they were added later or have a
 * non-conventional table name). Two repairs, both idempotent:
 *
 *  1. convertColumns(): integer user-reference column -> nullable varchar(50), MySQL only
 *     (SQLite stores strings in any column; the test suite does not rebuild these tables).
 *  2. remapLegacyActors(): audit/ledger rows written before the primary-key conversion hold
 *     the old numeric users.id. Such a value is translated through the same legacy map as the
 *     earlier migration - but only when no employee already owns that id, so a value that is
 *     already a real employee_id is never rewritten.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const USER_COLUMNS = [
        'activity_log' => ['causer_id'],
        'leave_audit_logs' => ['actor_id'],
        'leave_ledger' => ['actor_id'],
        'job_application_stage_history' => ['moved_by'],
        'jobs_recruitment' => ['hiring_manager_id'],
        'om_environmental_logs' => ['reported_by'],
        'om_inspection_templates' => ['created_by'],
        'om_inspections' => ['inspector_id', 'reviewed_by'],
        'om_lookups' => ['created_by', 'updated_by'],
        'om_preventive_schedules' => ['created_by'],
        'om_safety_incidents' => ['reported_by', 'investigated_by', 'closed_by'],
        'om_sla_breaches' => ['acknowledged_by'],
        'om_toolbox_talks' => ['supervisor_id'],
        'om_tppd_claims' => ['created_by_user_id'],
        // Dormant modules (empty tables) - aligned now so enabling them cannot store 0 for an alphanumeric id.
        'sessions' => ['user_id'],
        'compliance_audits' => ['lead_auditor_id'],
        'controlled_documents' => ['approver_id'],
        'courses' => ['instructor_id'],
        'daily_work_objection' => ['attached_by'],
        'dms_document_approvals' => ['approver_id'],
        'dms_document_folders' => ['added_by'],
        'dms_document_shares' => ['shared_by'],
        'document_revisions' => ['revised_by'],
        'event_registrations' => ['payment_verified_by'],
        'knowledge_base_articles' => ['last_updated_by'],
        'procurement_requests' => ['approver_id'],
        'purchase_orders' => ['approver_id'],
        'return_requests' => ['approver_id'],
        'safety_incidents' => ['reported_by'],
        'safety_inspections' => ['inspector_id'],
    ];

    /** Columns whose existing values are legacy numeric users.id (everything else is empty or already converted). */
    private const LEGACY_VALUE_COLUMNS = [
        'activity_log' => ['causer_id', 'causer_type'],
        'leave_audit_logs' => ['actor_id', null],
        'leave_ledger' => ['actor_id', null],
    ];

    /** @var array<int, string> same snapshot as 2026_09_02_000002_align_late_user_references_with_employee_ids */
    private const LEGACY_USER_ID_MAP = [
        1 => '123',
        3 => '120',
        4 => '126',
        5 => '127',
        6 => '159',
        7 => '7',
        8 => '131',
        9 => '143',
        10 => '1231',
        11 => '1261',
        12 => '142',
        13 => '145',
        14 => '272',
        16 => '356',
        17 => '170',
        18 => '151',
        19 => '152',
        20 => '153',
        21 => '1232',
        22 => '29',
        23 => '122',
        24 => '24',
        25 => '1536',
        26 => '169',
        91 => '130',
        95 => '149',
        96 => '896',
        97 => '97',
        98 => '538',
        99 => '154',
        100 => '155',
        101 => '301',
        102 => '302',
        103 => '304',
        104 => '305',
        105 => '306',
        106 => '397',
        107 => '308',
        108 => '309',
        133 => '310',
        134 => '307',
    ];

    public function up(): void
    {
        $this->convertColumns();
        $this->remapLegacyActors();
    }

    public function convertColumns(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        foreach (self::USER_COLUMNS as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($tableName, $column)) {
                    continue;
                }

                $metadata = collect(Schema::getColumns($tableName))->firstWhere('name', $column);
                if (! str_contains(strtolower((string) ($metadata['type_name'] ?? $metadata['type'] ?? '')), 'int')) {
                    continue; // already converted
                }

                $nullable = (bool) ($metadata['nullable'] ?? true);

                Schema::table($tableName, function (Blueprint $table) use ($column, $nullable) {
                    $definition = $table->string($column, 50);
                    if ($nullable) {
                        $definition->nullable();
                    }
                    $definition->change();
                });
            }
        }
    }

    public function remapLegacyActors(): void
    {
        foreach (self::LEGACY_VALUE_COLUMNS as $tableName => [$column, $typeColumn]) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, $column) || ! Schema::hasTable('users')) {
                continue;
            }

            foreach (self::LEGACY_USER_ID_MAP as $legacyId => $employeeId) {
                if ((string) $legacyId === $employeeId) {
                    continue;
                }

                // A value somebody already owns as an employee_id is a real reference, not a legacy one.
                if (DB::table('users')->where('employee_id', (string) $legacyId)->exists()) {
                    continue;
                }

                $query = DB::table($tableName)->where($column, (string) $legacyId);
                if ($typeColumn !== null && Schema::hasColumn($tableName, $typeColumn)) {
                    $query->where($typeColumn, 'App\\Models\\User');
                }

                $query->update([$column => $employeeId]);
            }
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: converting employee ids back to integers would truncate identity data.
    }
};
