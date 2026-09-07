<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * User references missed by the primary-key conversion, plus references
     * introduced later as unsigned integers.
     *
     * @var array<string, list<string>>
     */
    private const USER_COLUMNS = [
        'aeon_conversations' => ['user_id'],
        'attendance_policies' => ['scope_id'],
        'onboardings' => ['employee_id'],
        'offboardings' => ['employee_id'],
        'performance_reviews' => ['employee_id', 'reviewer_id'],
        'safety_incident_participants' => ['employee_id'],
        'safety_training_participants' => ['employee_id'],
        'shift_assignments' => ['scope_id', 'assigned_by'],
        'daily_works' => ['incharge', 'assigned'],
        'jurisdictions' => ['incharge'],
        'daily_work_summaries' => ['incharge'],
        'daily_summaries' => ['incharge'],
        'letters' => ['dealt_by'],
        'client_error_logs' => ['resolved_by'],
        'attendance_audit_logs' => ['actor_id'],
        'holiday_audit_logs' => ['actor_id'],
        'jobs' => ['hiring_manager_id'],
        'projects' => ['project_leader_id', 'team_leader_id'],
        'project_issues' => ['reported_by'],
        'job_application_stage_histories' => ['moved_by'],
        'job_applications' => ['applicant_id', 'referrer_id'],
        'quality_calibrations' => ['performed_by'],
        'quality_inspections' => ['inspector_id'],
        'kpis' => ['responsible_user_id'],
        'job_interviews' => ['scheduled_by'],
        'leaves' => ['rejected_by', 'cancelled_by'],
        'rfi_submission_override_logs' => ['overridden_by'],
        'rfi_objection_status_logs' => ['changed_by'],
        'rfi_objections' => ['resolved_by', 'overridden_by'],
        'training_sessions' => ['instructor_id'],
        'payrolls' => ['processed_by'],
        'trainings' => ['instructor_id'],
        'training_assignment_submissions' => ['graded_by'],
        'shift_swap_requests' => ['requester_id', 'counterparty_id'],
        'quality_ncrs' => ['reported_by', 'closed_by'],
        'om_incidents' => ['reported_by', 'escalated_by'],
        'om_work_orders' => ['reported_by', 'assigned_by', 'approved_by', 'verified_by'],
        'om_equipment_status' => ['reported_by'],
        'om_incident_photos' => ['uploaded_by'],
        'om_work_order_photos' => ['uploaded_by'],
        'om_incident_escalations' => ['escalated_by'],
        'om_activity_logs' => ['user_id'],
        'om_asset_condition_surveys' => ['inspector_id'],
        'om_patrol_shifts' => ['lead_officer_id'],
        'om_defects' => ['reported_by', 'verified_by'],
        'om_lane_closure_permits' => ['requested_by', 'approved_by'],
        'om_toll_shift_audits' => ['auditor_id', 'shift_supervisor_id'],
        'om_shift_logs' => ['operator_id', 'incoming_operator_id', 'acknowledged_by_user_id'],
    ];

    /**
     * Snapshot used by the original employee-ID standardization migration.
     * The subsequent primary-key migration dropped users.id, so this is the
     * only deterministic way to reconcile non-conventionally named columns
     * which that migration did not discover.
     *
     * @var array<int, string>
     */
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
        // SQLite accepts string values in integer-affinity columns and rebuilding
        // these heavily indexed tables during the test suite is unnecessary.
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

                $columnMetadata = collect(Schema::getColumns($tableName))->firstWhere('name', $column);
                $isNullable = (bool) ($columnMetadata['nullable'] ?? true);

                Schema::table($tableName, function (Blueprint $table) use ($column, $isNullable) {
                    $definition = $table->string($column, 50);

                    if ($isNullable) {
                        $definition->nullable();
                    }

                    $definition->change();
                });

                $this->reconcileLegacyValues($tableName, $column);
            }
        }
    }

    private function reconcileLegacyValues(string $tableName, string $column): void
    {
        foreach (self::LEGACY_USER_ID_MAP as $legacyId => $employeeId) {
            $query = DB::table($tableName)->where($column, (string) $legacyId);

            // scope_id is polymorphic. Department and designation IDs are
            // legitimate numeric values and must never be translated as users.
            if ($column === 'scope_id' && Schema::hasColumn($tableName, 'scope_type')) {
                $query->where('scope_type', 'user');
            }

            $query->update([$column => $employeeId]);
        }
    }

    public function down(): void
    {
        // Intentionally irreversible: converting EMP-* identifiers back to
        // integers would truncate production identity data.
    }
};
