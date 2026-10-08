<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entity-relationship audit 2026-10-08 (docs/audit/ENTITY_RELATIONSHIP_AUDIT_2026-10-08.md).
 *
 *  - quality_ncrs.inspection_id: QualityNCR/QualityInspection relate through it and it is mass-assignable, but the
 *    column was never created (the 2024 migration put inspection_id on quality_checkpoints only).
 *  - deleted_at on the three training tables whose models use SoftDeletes (every query on them failed).
 *  - indexes on relationship keys that are filtered or joined on without one. Guarded with hasIndex and named
 *    explicitly, so it is safe to re-run and to apply on MyISAM or InnoDB; only fixed-width / short keys are indexed
 *    (a varchar(255) utf8mb4 key exceeds MyISAM's 1000-byte limit).
 *
 * Existing foreign keys and morph indexes are untouched.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> table => relationship columns that lack a leading index */
    private const INDEXES = [
        'departments' => ['manager_id'],
        'designations' => ['parent_id'],
        'roster_days' => ['assignment_id'],
        'shift_assignments' => ['scope_id', 'assigned_by'],
        'shift_swap_requests' => ['approved_by'],
        'attendance_regularizations' => ['attendance_id', 'approved_by'],
        'overtime_requests' => ['approved_by'],
        'petty_cash_loans' => ['approved_by'],
        'absence_cases' => ['offboarding_id'],
        'refresh_tokens' => ['replaced_by'],
        'client_error_logs' => ['resolved_by'],
        'leave_ledger' => ['actor_id'],
        'leave_audit_logs' => ['actor_id'],
        'om_defects' => ['asset_id', 'patrol_shift_id', 'reported_by', 'verified_by'],
        'om_incidents' => ['reported_by', 'escalated_by'],
        'om_work_orders' => ['defect_id', 'asset_id', 'preventive_schedule_id', 'inspection_id', 'reported_by', 'assigned_by', 'approved_by', 'verified_by'],
        'om_inspections' => ['preventive_schedule_id', 'inspector_id', 'reviewed_by'],
        'om_preventive_schedules' => ['asset_id', 'created_by'],
        'om_lane_closure_permits' => ['work_order_id', 'requested_by', 'approved_by'],
        'om_toolbox_talks' => ['work_order_id', 'supervisor_id'],
        'om_shift_logs' => ['operator_id', 'incoming_operator_id', 'acknowledged_by_user_id'],
        'om_patrol_shifts' => ['lead_officer_id'],
        'om_toll_shift_audits' => ['auditor_id', 'shift_supervisor_id'],
        'om_work_order_materials' => ['issued_from_inventory_id'],
        'return_requests' => ['requested_by'],
        'stock_movements' => ['user_id'],
        'quality_ncrs' => ['inspection_id'],
    ];

    /** @var list<string> */
    private const SOFT_DELETE_TABLES = ['training_enrollments', 'training_feedback', 'training_assignment_submissions'];

    public function up(): void
    {
        if (Schema::hasTable('quality_ncrs') && ! Schema::hasColumn('quality_ncrs', 'inspection_id')) {
            Schema::table('quality_ncrs', function (Blueprint $table) {
                $table->unsignedBigInteger('inspection_id')->nullable()->after('department_id');
            });
        }

        foreach (self::SOFT_DELETE_TABLES as $tableName) {
            if (Schema::hasTable($tableName) && ! Schema::hasColumn($tableName, 'deleted_at')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->softDeletes();
                });
            }
        }

        foreach (self::INDEXES as $tableName => $columns) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($tableName, $column) || $this->isIndexed($tableName, $column)) {
                    continue;
                }

                $name = substr("idx_{$tableName}_{$column}", 0, 64);
                Schema::table($tableName, function (Blueprint $table) use ($column, $name) {
                    $table->index($column, $name);
                });
            }
        }
    }

    /** True when an index already starts with the column (a longer composite counts: it serves the same lookups). */
    private function isIndexed(string $tableName, string $column): bool
    {
        foreach (Schema::getIndexes($tableName) as $index) {
            if (($index['columns'][0] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }

    public function down(): void
    {
        // Additive and harmless: indexes and nullable columns are left in place.
    }
};
