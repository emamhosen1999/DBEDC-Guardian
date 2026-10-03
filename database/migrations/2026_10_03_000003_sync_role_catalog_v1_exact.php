<?php

use App\Services\Access\AccessAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role catalog v1, step A4 (docs/audit/ROLE_CATALOG_2026-10-03.md, section 7.2): the EXACT permission
 * sets of the roles that carry more than the catalog gives them (Department Manager, O&M Director,
 * Maintenance Inspector, TMC Operator, Highway Patrol Officer, Daily Works Contributor, HR Manager,
 * Administrator), and the deletion of the retired roles nobody holds.
 *
 * RUN ONLY AFTER `php artisan access:apply-catalog --apply` HAS BEEN VERIFIED. The people must hold
 * the roles that replace what the trimmed roles no longer carry (Quality Manager, Daily Works
 * Manager, Employee ...). Safety net: before writing anything, this migration works out every active
 * user's permissions before and after, and REFUSES TO RUN when anyone would lose a permission that
 * is not listed in that user's `expected_losses` in database/access-plans/catalog_v1.json.
 * Nothing is written until that check has passed.
 *
 * Owner decisions in the sets: tasks.* stays on every role that holds it today (Q3, 12 hits in 90
 * days); TMC Operator loses daily-works.* (O-5, 0 production rows); HR Manager and Administrator lose
 * access administration (O-15); the retired roles go only while they have no holder.
 *
 * The RBAC tables are MyISAM in production, so there is no rollback to lean on: every write is a diff
 * (attach the missing first, then detach the extras) and re-running converges. The sets below are frozen
 * copies of App\Services\Access\RoleCatalog (RoleCatalogSpecTest fails on drift).
 */
return new class extends Migration
{
    private const PLAN = 'database/access-plans/catalog_v1.json';

    private const ADMINISTRATOR = 'Administrator';

    /** What the catalog does not give an Administrator: destructive, per-person and access administration. */
    private const ADMINISTRATOR_EXCLUDED = [
        'users.impersonate', 'backup.create', 'backup.restore', 'access.self-administration', 'department.admin',
        'employees.access.manage', 'department.scopes.manage', 'roles.create', 'roles.update', 'roles.delete', 'permissions.assign',
    ];

    /** Roles retired in Phase A: deleted only while they have no holder. */
    private const RETIRED = ['Admin', 'Project Manager', 'Senior Employee', 'Contractor', 'Intern'];

    /** @var array<string, array<int, string>> */
    private const SETS = [
        'Department Manager' => [
            'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.restore',
            'employees.placement.update', 'departments.view', 'designations.view', 'attendance.view', 'attendance.create',
            'attendance.update', 'attendance.correct', 'attendance.delete', 'attendance.export', 'attendance.manage',
            'holidays.view', 'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'hr.onboarding.view',
            'hr.onboarding.create', 'hr.onboarding.update', 'hr.offboarding.view', 'hr.offboarding.create',
            'hr.offboarding.update', 'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',
        ],
        'O&M Director' => [
            'om.ai.manage', 'om.analytics.view', 'om.contractors.manage', 'om.contractors.view', 'om.dashboard.view',
            'om.equipment.manage', 'om.equipment.view', 'om.incidents.manage', 'om.incidents.view',
            'om.inspections.manage', 'om.inventory.manage', 'om.maintenance.manage', 'om.maintenance.view',
            'om.patrol.manage', 'om.pm.manage', 'om.research.view', 'om.safety.manage', 'om.safety.view',
            'om.shift.manage', 'om.sla.view', 'om.toll.manage', 'om.toll.view', 'om.tppd.manage', 'om.tppd.view',
            'om.traffic.manage', 'om.traffic.view',
        ],
        'Maintenance Inspector' => [
            'om.dashboard.view', 'om.maintenance.view', 'om.maintenance.manage', 'om.pm.manage', 'om.inspections.manage',
            'om.inventory.manage', 'om.equipment.view', 'om.equipment.manage', 'om.safety.view', 'om.safety.manage',
            'om.sla.view', 'om.research.view', 'om.ai.manage',
        ],
        'TMC Operator' => [
            'om.dashboard.view', 'om.traffic.view', 'om.traffic.manage', 'om.toll.view', 'om.toll.manage',
            'om.incidents.view', 'om.incidents.manage', 'om.equipment.view', 'om.shift.manage', 'om.sla.view',
            'om.safety.view',
        ],
        'Highway Patrol Officer' => [
            'om.dashboard.view', 'om.incidents.view', 'om.incidents.manage', 'om.patrol.manage', 'om.maintenance.view',
            'om.maintenance.manage', 'om.safety.view', 'om.safety.manage', 'om.tppd.view', 'om.tppd.manage',
            'om.shift.manage', 'om.research.view',
        ],
        'Daily Works Contributor' => [
            'daily-works.view', 'daily-works.create', 'daily-works.export', 'tasks.view',
        ],
        'HR Manager' => [
            'attendance.correct', 'attendance.create', 'attendance.delete', 'attendance.export', 'attendance.import',
            'attendance.manage', 'attendance.own.punch', 'attendance.own.view', 'attendance.roster.manage',
            'attendance.settings', 'attendance.update', 'attendance.view', 'communications.own.view', 'company.settings',
            'core.dashboard.view', 'core.stats.view', 'core.updates.view', 'departments.create', 'departments.delete',
            'departments.update', 'departments.view', 'designations.create', 'designations.delete', 'designations.update',
            'designations.view', 'documents.create', 'documents.delete', 'documents.update', 'documents.view',
            'employees.attendance-config.update', 'employees.compensation.update', 'employees.compensation.view',
            'employees.create', 'employees.delete', 'employees.devices.manage', 'employees.export', 'employees.import',
            'employees.password.reset', 'employees.placement.update', 'employees.restore', 'employees.update',
            'employees.view', 'event.registration.manage', 'event.view', 'holidays.create', 'holidays.delete',
            'holidays.update', 'holidays.view', 'hr.analytics.attendance', 'hr.analytics.performance',
            'hr.analytics.recruitment', 'hr.analytics.reports.generate', 'hr.analytics.reports.view',
            'hr.analytics.training', 'hr.analytics.turnover', 'hr.analytics.view', 'hr.assets.manage', 'hr.assets.view',
            'hr.benefits.create', 'hr.benefits.delete', 'hr.benefits.update', 'hr.benefits.view', 'hr.checklists.create',
            'hr.checklists.delete', 'hr.checklists.update', 'hr.checklists.view', 'hr.competencies.create',
            'hr.competencies.delete', 'hr.competencies.update', 'hr.competencies.view', 'hr.documents.categories.create',
            'hr.documents.categories.delete', 'hr.documents.categories.update', 'hr.documents.categories.view',
            'hr.documents.create', 'hr.documents.delete', 'hr.documents.update', 'hr.documents.view',
            'hr.employee.benefits.assign', 'hr.employee.benefits.remove', 'hr.employee.benefits.update',
            'hr.employee.benefits.view', 'hr.employee.documents.create', 'hr.employee.documents.delete',
            'hr.employee.documents.view', 'hr.employee.skills.create', 'hr.employee.skills.delete',
            'hr.employee.skills.update', 'hr.employee.skills.view', 'hr.offboarding.create', 'hr.offboarding.delete',
            'hr.offboarding.update', 'hr.offboarding.view', 'hr.onboarding.create', 'hr.onboarding.delete',
            'hr.onboarding.update', 'hr.onboarding.view', 'hr.payroll.analytics', 'hr.payroll.bulk', 'hr.payroll.create',
            'hr.payroll.delete', 'hr.payroll.process', 'hr.payroll.reports', 'hr.payroll.update', 'hr.payroll.view',
            'hr.payslips.download', 'hr.payslips.email', 'hr.payslips.view', 'hr.probation.manage',
            'hr.safety.incidents.create', 'hr.safety.incidents.update', 'hr.safety.incidents.view',
            'hr.safety.inspections.create', 'hr.safety.inspections.update', 'hr.safety.inspections.view',
            'hr.safety.training.create', 'hr.safety.training.update', 'hr.safety.training.view', 'hr.safety.view',
            'hr.selfservice.benefits.view', 'hr.selfservice.documents.view', 'hr.selfservice.payslips.view',
            'hr.selfservice.performance.view', 'hr.selfservice.profile.update', 'hr.selfservice.profile.view',
            'hr.selfservice.timeoff.request', 'hr.selfservice.timeoff.view', 'hr.selfservice.trainings.view',
            'hr.selfservice.view', 'hr.settlement.approve', 'hr.settlement.disburse', 'hr.settlement.manage',
            'hr.skills.create', 'hr.skills.delete', 'hr.skills.update', 'hr.skills.view', 'hr.timeoff.approve',
            'hr.timeoff.calendar.view', 'hr.timeoff.reject', 'hr.timeoff.reports.view', 'hr.timeoff.settings.update',
            'hr.timeoff.settings.view', 'hr.timeoff.view', 'job-applications.create', 'job-applications.delete',
            'job-applications.update', 'job-applications.view', 'job-hiring-stages.create', 'job-hiring-stages.delete',
            'job-hiring-stages.update', 'job-hiring-stages.view', 'job-interview-feedback.create',
            'job-interview-feedback.update', 'job-interview-feedback.view', 'job-interviews.create',
            'job-interviews.delete', 'job-interviews.update', 'job-interviews.view', 'job-offers.approve',
            'job-offers.create', 'job-offers.delete', 'job-offers.update', 'job-offers.view', 'jobs.create', 'jobs.delete',
            'jobs.update', 'jobs.view', 'jurisdiction.create', 'jurisdiction.delete', 'jurisdiction.update',
            'jurisdiction.view', 'leave-settings.update', 'leave-settings.view', 'leave.own.create', 'leave.own.delete',
            'leave.own.update', 'leave.own.view', 'leaves.analytics', 'leaves.approve', 'leaves.create', 'leaves.delete',
            'leaves.manage', 'leaves.update', 'leaves.view', 'letters.create', 'letters.delete', 'letters.update',
            'letters.view', 'performance-analytics.view', 'performance-reviews.approve', 'performance-reviews.create',
            'performance-reviews.delete', 'performance-reviews.own.create', 'performance-reviews.own.update',
            'performance-reviews.own.view', 'performance-reviews.update', 'performance-reviews.view',
            'performance-templates.create', 'performance-templates.delete', 'performance-templates.update',
            'performance-templates.view', 'profile.own.update', 'profile.own.view', 'profile.password.change',
            'recruitment-analytics.view', 'settings.update', 'settings.view', 'training-analytics.view',
            'training-assignment-submissions.create', 'training-assignment-submissions.grade',
            'training-assignment-submissions.update', 'training-assignment-submissions.view',
            'training-assignments.create', 'training-assignments.delete', 'training-assignments.update',
            'training-assignments.view', 'training-categories.create', 'training-categories.delete',
            'training-categories.update', 'training-categories.view', 'training-enrollments.create',
            'training-enrollments.delete', 'training-enrollments.update', 'training-enrollments.view',
            'training-feedback.create', 'training-feedback.own.create', 'training-feedback.own.view',
            'training-feedback.view', 'training-materials.create', 'training-materials.delete',
            'training-materials.update', 'training-materials.view', 'training-sessions.create', 'training-sessions.delete',
            'training-sessions.update', 'training-sessions.view', 'users.create', 'users.delete', 'users.update',
            'users.view',
        ],
    ];

    /** The sets before this migration (what down() restores). Administrator = every permission but 5. */
    private const PREVIOUS_ADMINISTRATOR_EXCLUDED = ['users.impersonate', 'backup.create', 'backup.restore', 'access.self-administration', 'department.admin'];

    /** @var array<string, array<int, string>> */
    private const PREVIOUS = [
        'Department Manager' => [
            'attendance.correct', 'attendance.create', 'attendance.delete', 'attendance.export', 'attendance.manage',
            'attendance.own.punch', 'attendance.own.view', 'attendance.update', 'attendance.view',
            'communications.own.view', 'core.dashboard.view', 'core.stats.view', 'core.updates.view', 'daily-works.create',
            'daily-works.delete', 'daily-works.export', 'daily-works.import', 'daily-works.update', 'daily-works.view',
            'departments.view', 'designations.view', 'employees.create', 'employees.delete', 'employees.placement.update',
            'employees.restore', 'employees.update', 'employees.view', 'holidays.view', 'hr.analytics.attendance',
            'hr.analytics.performance', 'hr.analytics.view', 'hr.competencies.view', 'hr.documents.view',
            'hr.employee.benefits.view', 'hr.employee.documents.create', 'hr.employee.documents.view',
            'hr.employee.skills.create', 'hr.employee.skills.delete', 'hr.employee.skills.update',
            'hr.employee.skills.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.view',
            'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.view', 'hr.safety.incidents.create',
            'hr.safety.incidents.view', 'hr.safety.inspections.view', 'hr.safety.view', 'hr.skills.view',
            'hr.timeoff.approve', 'hr.timeoff.calendar.view', 'hr.timeoff.reject', 'hr.timeoff.reports.view',
            'hr.timeoff.view', 'leave.own.create', 'leave.own.delete', 'leave.own.update', 'leave.own.view',
            'leaves.approve', 'leaves.create', 'leaves.update', 'leaves.view', 'performance-analytics.view',
            'performance-reviews.approve', 'performance-reviews.create', 'performance-reviews.update',
            'performance-reviews.view', 'profile.own.update', 'profile.own.view', 'profile.password.change',
            'projects.analytics', 'quality.ncr.create', 'quality.ncr.update', 'quality.ncr.view', 'quality.view',
            'reports.create', 'reports.delete', 'reports.update', 'reports.view', 'tasks.assign', 'tasks.create',
            'tasks.delete', 'tasks.update', 'tasks.view',
        ],
        'O&M Director' => [
            'attendance.own.punch', 'attendance.own.view', 'attendance.view', 'compliance.audits.create',
            'compliance.audits.delete', 'compliance.audits.update', 'compliance.audits.view', 'compliance.dashboard.view',
            'compliance.documents.create', 'compliance.documents.delete', 'compliance.documents.update',
            'compliance.documents.view', 'compliance.requirements.create', 'compliance.requirements.delete',
            'compliance.requirements.update', 'compliance.requirements.view', 'compliance.settings', 'compliance.view',
            'core.dashboard.view', 'core.stats.view', 'daily-works.create', 'daily-works.delete', 'daily-works.export',
            'daily-works.import', 'daily-works.update', 'daily-works.view', 'departments.view', 'designations.view',
            'employees.view', 'leave.own.create', 'leave.own.delete', 'leave.own.update', 'leave.own.view', 'leaves.view',
            'om.ai.manage', 'om.analytics.view', 'om.contractors.manage', 'om.contractors.view', 'om.dashboard.view',
            'om.equipment.manage', 'om.equipment.view', 'om.incidents.manage', 'om.incidents.view',
            'om.inspections.manage', 'om.inventory.manage', 'om.maintenance.manage', 'om.maintenance.view',
            'om.patrol.manage', 'om.pm.manage', 'om.research.view', 'om.safety.manage', 'om.safety.view',
            'om.shift.manage', 'om.sla.view', 'om.toll.manage', 'om.toll.view', 'om.tppd.manage', 'om.tppd.view',
            'om.traffic.manage', 'om.traffic.view', 'profile.own.update', 'profile.own.view',
            'quality.calibrations.create', 'quality.calibrations.delete', 'quality.calibrations.update',
            'quality.calibrations.view', 'quality.dashboard.view', 'quality.inspections.create',
            'quality.inspections.delete', 'quality.inspections.update', 'quality.inspections.view', 'quality.ncr.create',
            'quality.ncr.delete', 'quality.ncr.update', 'quality.ncr.view', 'quality.settings', 'quality.view',
        ],
        'Maintenance Inspector' => [
            'attendance.own.punch', 'attendance.own.view', 'core.dashboard.view', 'daily-works.create',
            'daily-works.delete', 'daily-works.export', 'daily-works.import', 'daily-works.update', 'daily-works.view',
            'leave.own.create', 'leave.own.delete', 'leave.own.update', 'leave.own.view', 'om.ai.manage',
            'om.dashboard.view', 'om.equipment.manage', 'om.equipment.view', 'om.inspections.manage',
            'om.inventory.manage', 'om.maintenance.manage', 'om.maintenance.view', 'om.pm.manage', 'om.research.view',
            'om.safety.manage', 'om.safety.view', 'om.sla.view', 'profile.own.update', 'profile.own.view',
            'quality.calibrations.create', 'quality.calibrations.delete', 'quality.calibrations.update',
            'quality.calibrations.view', 'quality.dashboard.view', 'quality.inspections.create',
            'quality.inspections.delete', 'quality.inspections.update', 'quality.inspections.view', 'quality.ncr.create',
            'quality.ncr.delete', 'quality.ncr.update', 'quality.ncr.view', 'quality.settings', 'quality.view',
        ],
        'TMC Operator' => [
            'attendance.own.punch', 'attendance.own.view', 'core.dashboard.view', 'daily-works.create',
            'daily-works.delete', 'daily-works.export', 'daily-works.import', 'daily-works.update', 'daily-works.view',
            'leave.own.create', 'leave.own.delete', 'leave.own.update', 'leave.own.view', 'om.dashboard.view',
            'om.equipment.view', 'om.incidents.manage', 'om.incidents.view', 'om.safety.view', 'om.shift.manage',
            'om.sla.view', 'om.toll.manage', 'om.toll.view', 'om.traffic.manage', 'om.traffic.view', 'profile.own.update',
            'profile.own.view',
        ],
        'Highway Patrol Officer' => [
            'attendance.own.punch', 'attendance.own.view', 'core.dashboard.view', 'daily-works.create',
            'daily-works.delete', 'daily-works.export', 'daily-works.import', 'daily-works.update', 'daily-works.view',
            'leave.own.create', 'leave.own.delete', 'leave.own.update', 'leave.own.view', 'om.dashboard.view',
            'om.incidents.manage', 'om.incidents.view', 'om.maintenance.manage', 'om.maintenance.view', 'om.patrol.manage',
            'om.research.view', 'om.safety.manage', 'om.safety.view', 'om.shift.manage', 'om.tppd.manage', 'om.tppd.view',
            'profile.own.update', 'profile.own.view',
        ],
        'HR Manager' => [
            'attendance.correct', 'attendance.create', 'attendance.delete', 'attendance.export', 'attendance.import',
            'attendance.manage', 'attendance.own.punch', 'attendance.own.view', 'attendance.roster.manage',
            'attendance.settings', 'attendance.update', 'attendance.view', 'communications.own.view', 'company.settings',
            'core.dashboard.view', 'core.stats.view', 'core.updates.view', 'department.scopes.manage',
            'departments.create', 'departments.delete', 'departments.update', 'departments.view', 'designations.create',
            'designations.delete', 'designations.update', 'designations.view', 'documents.create', 'documents.delete',
            'documents.update', 'documents.view', 'employees.access.manage', 'employees.attendance-config.update',
            'employees.compensation.update', 'employees.compensation.view', 'employees.create', 'employees.delete',
            'employees.devices.manage', 'employees.export', 'employees.import', 'employees.password.reset',
            'employees.placement.update', 'employees.restore', 'employees.update', 'employees.view',
            'event.registration.manage', 'event.view', 'holidays.create', 'holidays.delete', 'holidays.update',
            'holidays.view', 'hr.analytics.attendance', 'hr.analytics.performance', 'hr.analytics.recruitment',
            'hr.analytics.reports.generate', 'hr.analytics.reports.view', 'hr.analytics.training', 'hr.analytics.turnover',
            'hr.analytics.view', 'hr.assets.manage', 'hr.assets.view', 'hr.benefits.create', 'hr.benefits.delete',
            'hr.benefits.update', 'hr.benefits.view', 'hr.checklists.create', 'hr.checklists.delete',
            'hr.checklists.update', 'hr.checklists.view', 'hr.competencies.create', 'hr.competencies.delete',
            'hr.competencies.update', 'hr.competencies.view', 'hr.documents.categories.create',
            'hr.documents.categories.delete', 'hr.documents.categories.update', 'hr.documents.categories.view',
            'hr.documents.create', 'hr.documents.delete', 'hr.documents.update', 'hr.documents.view',
            'hr.employee.benefits.assign', 'hr.employee.benefits.remove', 'hr.employee.benefits.update',
            'hr.employee.benefits.view', 'hr.employee.documents.create', 'hr.employee.documents.delete',
            'hr.employee.documents.view', 'hr.employee.skills.create', 'hr.employee.skills.delete',
            'hr.employee.skills.update', 'hr.employee.skills.view', 'hr.offboarding.create', 'hr.offboarding.delete',
            'hr.offboarding.update', 'hr.offboarding.view', 'hr.onboarding.create', 'hr.onboarding.delete',
            'hr.onboarding.update', 'hr.onboarding.view', 'hr.payroll.analytics', 'hr.payroll.bulk', 'hr.payroll.create',
            'hr.payroll.delete', 'hr.payroll.process', 'hr.payroll.reports', 'hr.payroll.update', 'hr.payroll.view',
            'hr.payslips.download', 'hr.payslips.email', 'hr.payslips.view', 'hr.probation.manage',
            'hr.safety.incidents.create', 'hr.safety.incidents.update', 'hr.safety.incidents.view',
            'hr.safety.inspections.create', 'hr.safety.inspections.update', 'hr.safety.inspections.view',
            'hr.safety.training.create', 'hr.safety.training.update', 'hr.safety.training.view', 'hr.safety.view',
            'hr.selfservice.benefits.view', 'hr.selfservice.documents.view', 'hr.selfservice.payslips.view',
            'hr.selfservice.performance.view', 'hr.selfservice.profile.update', 'hr.selfservice.profile.view',
            'hr.selfservice.timeoff.request', 'hr.selfservice.timeoff.view', 'hr.selfservice.trainings.view',
            'hr.selfservice.view', 'hr.settlement.approve', 'hr.settlement.disburse', 'hr.settlement.manage',
            'hr.skills.create', 'hr.skills.delete', 'hr.skills.update', 'hr.skills.view', 'hr.timeoff.approve',
            'hr.timeoff.calendar.view', 'hr.timeoff.reject', 'hr.timeoff.reports.view', 'hr.timeoff.settings.update',
            'hr.timeoff.settings.view', 'hr.timeoff.view', 'job-applications.create', 'job-applications.delete',
            'job-applications.update', 'job-applications.view', 'job-hiring-stages.create', 'job-hiring-stages.delete',
            'job-hiring-stages.update', 'job-hiring-stages.view', 'job-interview-feedback.create',
            'job-interview-feedback.update', 'job-interview-feedback.view', 'job-interviews.create',
            'job-interviews.delete', 'job-interviews.update', 'job-interviews.view', 'job-offers.approve',
            'job-offers.create', 'job-offers.delete', 'job-offers.update', 'job-offers.view', 'jobs.create', 'jobs.delete',
            'jobs.update', 'jobs.view', 'jurisdiction.create', 'jurisdiction.delete', 'jurisdiction.update',
            'jurisdiction.view', 'leave-settings.update', 'leave-settings.view', 'leave.own.create', 'leave.own.delete',
            'leave.own.update', 'leave.own.view', 'leaves.analytics', 'leaves.approve', 'leaves.create', 'leaves.delete',
            'leaves.update', 'leaves.view', 'letters.create', 'letters.delete', 'letters.update', 'letters.view',
            'performance-analytics.view', 'performance-reviews.approve', 'performance-reviews.create',
            'performance-reviews.delete', 'performance-reviews.own.create', 'performance-reviews.own.update',
            'performance-reviews.own.view', 'performance-reviews.update', 'performance-reviews.view',
            'performance-templates.create', 'performance-templates.delete', 'performance-templates.update',
            'performance-templates.view', 'profile.own.update', 'profile.own.view', 'profile.password.change',
            'recruitment-analytics.view', 'settings.update', 'settings.view', 'training-analytics.view',
            'training-assignment-submissions.create', 'training-assignment-submissions.grade',
            'training-assignment-submissions.update', 'training-assignment-submissions.view',
            'training-assignments.create', 'training-assignments.delete', 'training-assignments.update',
            'training-assignments.view', 'training-categories.create', 'training-categories.delete',
            'training-categories.update', 'training-categories.view', 'training-enrollments.create',
            'training-enrollments.delete', 'training-enrollments.update', 'training-enrollments.view',
            'training-feedback.create', 'training-feedback.own.create', 'training-feedback.own.view',
            'training-feedback.view', 'training-materials.create', 'training-materials.delete',
            'training-materials.update', 'training-materials.view', 'training-sessions.create', 'training-sessions.delete',
            'training-sessions.update', 'training-sessions.view', 'users.create', 'users.delete', 'users.impersonate',
            'users.update', 'users.view',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $target = $this->targetSets();

        // 1. The guard: nothing is written before this passes.
        $unexpected = $this->unexpectedLosses($target);
        if ($unexpected !== []) {
            $lines = [];
            foreach ($unexpected as $employee => $lost) {
                $lines[] = "  {$employee}: ".implode(', ', array_slice($lost, 0, 8)).(count($lost) > 8 ? ' (+'.(count($lost) - 8).' more)' : '');
            }
            throw new RuntimeException(
                "Role catalog A4 refused: active users would lose permissions that are not in expected_losses.\n"
                ."Run `php artisan access:apply-catalog --apply` (and verify it) first.\n".implode("\n", $lines)
            );
        }

        // 2. The exact sets, by diff.
        foreach ($target as $roleName => $names) {
            $this->syncRole($roleName, $names, 'catalog.a4.role.permissions');
        }

        // 3. Retired roles go only while empty.
        foreach (self::RETIRED as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            if ($role === null) {
                continue;
            }
            if (DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
                Log::warning("Role catalog A4: retired role {$roleName} still has holders; kept.");

                continue;
            }
            $role->permissions()->detach();
            $role->delete();
            app(AccessAudit::class)->record('catalog.a4.role.deleted', 'role', $roleName, ['retired' => true], null);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $all = DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all();
        $previous = self::PREVIOUS + [self::ADMINISTRATOR => array_values(array_diff($all, self::PREVIOUS_ADMINISTRATOR_EXCLUDED))];
        foreach ($previous as $roleName => $names) {
            // Additive only: restoring must never narrow a role further. Deleted retired roles are not recreated.
            $this->syncRole($roleName, $names, 'catalog.a4.role.restored', false);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<string, array<int, string>> role name => exact permission names (those that exist) */
    private function targetSets(): array
    {
        $all = DB::table('permissions')->where('guard_name', 'web')->pluck('name')->all();
        $sets = self::SETS + [self::ADMINISTRATOR => array_values(array_diff($all, self::ADMINISTRATOR_EXCLUDED))];

        return array_map(fn (array $names) => array_values(array_intersect($names, $all)), $sets);
    }

    /**
     * @param  array<string, array<int, string>>  $target
     * @return array<string, array<int, string>> active employee id => permissions lost and not expected
     */
    private function unexpectedLosses(array $target): array
    {
        $activeIds = DB::table('users')->whereNull('deleted_at')->pluck('employee_id')->map(fn ($id) => (string) $id)->all();
        if ($activeIds === []) {
            return [];
        }

        $plan = is_file(base_path(self::PLAN)) ? json_decode(file_get_contents(base_path(self::PLAN)), true) : null;
        $expected = [];
        foreach (($plan['users'] ?? []) as $employeeId => $entry) {
            $expected[(string) $employeeId] = $entry['expected_losses'] ?? [];
        }

        $rolePermissions = [];
        foreach (DB::table('role_has_permissions as rp')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->select('rp.role_id', 'p.name')->get() as $row) {
            $rolePermissions[$row->role_id][] = $row->name;
        }
        $roleNames = DB::table('roles')->where('guard_name', 'web')->pluck('name', 'id')->all();

        $userRoles = [];
        foreach (DB::table('model_has_roles')->where('model_type', 'App\\Models\\User')->get(['role_id', 'model_id']) as $row) {
            $userRoles[(string) $row->model_id][] = (int) $row->role_id;
        }
        $direct = [];
        foreach (DB::table('model_has_permissions as mp')->join('permissions as p', 'p.id', '=', 'mp.permission_id')->where('mp.model_type', 'App\\Models\\User')->get(['mp.model_id', 'p.name']) as $row) {
            $direct[(string) $row->model_id][] = $row->name;
        }

        $unexpected = [];
        foreach ($activeIds as $employeeId) {
            $before = $direct[$employeeId] ?? [];
            $after = $direct[$employeeId] ?? [];
            foreach ($userRoles[$employeeId] ?? [] as $roleId) {
                $name = $roleNames[$roleId] ?? null;
                $before = array_merge($before, $rolePermissions[$roleId] ?? []);
                $after = array_merge($after, $name !== null && isset($target[$name]) ? $target[$name] : ($rolePermissions[$roleId] ?? []));
            }
            $lost = array_diff(array_unique($before), array_unique($after), $expected[$employeeId] ?? []);
            if ($lost !== []) {
                $unexpected[$employeeId] = array_values($lost);
            }
        }

        return $unexpected;
    }

    /** Attach the missing first, then (when $exact) detach the extras. */
    private function syncRole(string $roleName, array $names, string $action, bool $exact = true): void
    {
        $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
        if ($role === null) {
            return;
        }

        $wanted = DB::table('permissions')->where('guard_name', 'web')->whereIn('name', $names)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $current = DB::table('role_has_permissions')->where('role_id', $role->id)->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
        $add = array_values(array_diff($wanted, $current));
        $remove = $exact ? array_values(array_diff($current, $wanted)) : [];

        if ($add) {
            $role->permissions()->attach($add);
        }
        if ($remove) {
            $role->permissions()->detach($remove);
        }
        if ($add || $remove) {
            app(AccessAudit::class)->record($action, 'role', $roleName, ['count' => count($current)], ['added' => count($add), 'removed' => count($remove)]);
        }
    }
};
