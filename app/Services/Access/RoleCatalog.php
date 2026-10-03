<?php

namespace App\Services\Access;

use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The role catalog (docs/audit/ROLE_CATALOG_2026-10-03.md, section 4, Phase A): ONE definition of every
 * role - its level, its description and its EXACT permission set - read by the seeders, the
 * `access:apply-catalog` command and the tests.
 *
 * Production never re-runs seeders: migrations 2026_10_03_000002 (additive) and 2026_10_03_000003
 * (exact) carry frozen copies of these sets, and tests/Feature/Access/RoleCatalogSpecTest fails when a
 * copy drifts from this class.
 *
 * Two roles are defined by a rule instead of a list, so a permission added later needs no edit here:
 *   - Super Administrator: the whole catalog except the two per-person permissions;
 *   - Administrator: the whole catalog except the destructive, per-person and access-administration ones.
 */
final class RoleCatalog
{
    public const VERSION = 1;

    /** Permissions created by this catalog (used in routes / nav but never created before). */
    public const NEW_PERMISSIONS = [
        'monitoring.camera.view' => ['module' => 'om', 'description' => 'View the CCTV / camera monitoring console'],
        'leaves.manage' => ['module' => 'hrm', 'description' => 'Administer leave: override, adjust balances and records of any employee'],
    ];

    /** Old role name => new role name (renamed by id, so holders and grants follow). */
    public const RENAMES = [
        'Team Lead' => 'Line Manager',
        'Maintenance Inspector / QC Specialist' => 'Maintenance Inspector',
    ];

    /** Roles retired in Phase A: deleted only while they have no holder. */
    public const RETIRED = ['Admin', 'Project Manager', 'Senior Employee', 'Contractor', 'Intern'];

    /** Held by one person each, directly, by design (never revoked by the catalog). */
    public const PER_PERSON_PERMISSIONS = ['access.self-administration', 'department.admin'];

    /** The destructive permissions no Administrator carries. */
    public const ADMINISTRATOR_EXCLUDED = [
        'users.impersonate', 'backup.create', 'backup.restore',
        'access.self-administration', 'department.admin',
        // Access administration is the Super Administrator's alone (owner decision O-15).
        'employees.access.manage', 'department.scopes.manage', 'roles.create', 'roles.update', 'roles.delete', 'permissions.assign',
    ];

    /** Access administration stripped from HR Manager (O-15); `users.impersonate` is dead and goes with it. */
    public const HR_MANAGER_REMOVED = ['employees.access.manage', 'department.scopes.manage', 'users.impersonate'];

    /**
     * Segregation of duties: no non-global account may hold both permissions of a pair.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    public const SSD_PAIRS = [
        ['hr.settlement.approve', 'hr.settlement.disburse', 'S5 final settlement: approve and disburse'],
        ['petty-cash.approve', 'petty-cash.manage', 'S4 petty cash: approval and custody'],
    ];

    /** Role names that bypass the SSD check: Gate::before already lets them through. */
    public const SSD_EXEMPT_ROLES = ['Super Administrator'];

    /**
     * name => [level, description, system role, permissions (null = a rule, see permissionsFor())].
     *
     * @return array<string, array{level: int, description: string, system: bool, permissions: array<int, string>|null}>
     */
    public static function definitions(): array
    {
        $roles = ['Super Administrator' => ['level' => 1, 'description' => 'Full system access with all privileges', 'system' => true, 'permissions' => null],            'Administrator' => ['level' => 10, 'description' => 'Administrative access to most system functions (not access administration)', 'system' => true, 'permissions' => null],            'O&M Director' => ['level' => 15, 'description' => 'Executive director of operations, maintenance and traffic management (O&M records)', 'system' => false, 'permissions' => self::OM_DIRECTOR],            'HR Manager' => ['level' => 20, 'description' => 'Human resources management and employee operations (not access administration)', 'system' => false, 'permissions' => self::HR_MANAGER],            'Department Admin' => ['level' => 25, 'description' => 'Delegated department administrator: full people administration inside the assigned department(s)', 'system' => false, 'permissions' => ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames()],            'Department Manager' => ['level' => 30, 'description' => 'Departmental management: people administration, time and leave inside the own department', 'system' => false, 'permissions' => self::DEPARTMENT_MANAGER],            'Line Manager' => ['level' => 40, 'description' => 'Line management: view the team, approve its leave (reporting subtree)', 'system' => false, 'permissions' => self::LINE_MANAGER],            'Quality Manager' => ['level' => 45, 'description' => 'Quality management: the whole quality module (NCR register, inspections, calibrations, settings)', 'system' => false, 'permissions' => self::QUALITY_MANAGER],            'Daily Works Manager' => ['level' => 45, 'description' => 'Daily works management: the full daily works module including import', 'system' => false, 'permissions' => self::DAILY_WORKS_MANAGER],            'Maintenance Inspector' => ['level' => 50, 'description' => 'Field maintenance inspector and defect verification specialist (O&M records)', 'system' => false, 'permissions' => self::MAINTENANCE_INSPECTOR],            'TMC Operator' => ['level' => 45, 'description' => 'Traffic Management Center control room operator, CCTV/VMS controller, shift logger', 'system' => false, 'permissions' => self::TMC_OPERATOR],            'Highway Patrol Officer' => ['level' => 55, 'description' => 'Highway patrol officer, emergency responder and crash damage assessor', 'system' => false, 'permissions' => self::HIGHWAY_PATROL_OFFICER],            'Quality Contributor' => ['level' => 55, 'description' => 'Quality field work: raise and update NCRs, view inspections and calibrations', 'system' => false, 'permissions' => self::QUALITY_CONTRIBUTOR],            'Employee' => ['level' => 60, 'description' => 'Standard employee access to self-service functions', 'system' => false, 'permissions' => self::EMPLOYEE],            'Daily Works Contributor' => ['level' => 60, 'description' => 'Field reporting: daily works and tasks. Held next to the base Employee role by staff who file daily works.', 'system' => false, 'permissions' => self::DAILY_WORKS_CONTRIBUTOR]];

        return $roles;
    }

    /**
     * The exact permission names a role holds, out of every permission name that exists.
     *
     * @param  array<int, string>  $allPermissionNames
     * @return array<int, string>
     */
    public static function permissionsFor(string $role, array $allPermissionNames): array
    {
        $definition = self::definitions()[$role] ?? null;
        if ($definition === null) {
            return [];
        }
        if (is_array($definition['permissions'])) {
            return array_values(array_unique($definition['permissions']));
        }

        $excluded = $role === 'Super Administrator' ? self::PER_PERSON_PERMISSIONS : self::ADMINISTRATOR_EXCLUDED;

        return array_values(array_diff($allPermissionNames, $excluded));
    }

    /** @return array<int, string> every permission named by a listed (not rule based) role set */
    public static function mentionedPermissions(): array
    {
        $names = [];
        foreach (self::definitions() as $definition) {
            $names = array_merge($names, $definition['permissions'] ?? []);
        }

        return array_values(array_unique($names));
    }

    /** @return array<int, string> every role name of the catalog */
    public static function roleNames(): array
    {
        return array_keys(self::definitions());
    }

    /**
     * Seeder path: create what is missing and bring every catalog role to its exact set. Diff based
     * (never detach-all-then-attach), so an interrupted run leaves no role empty.
     *
     * @return array<string, array{added: int, removed: int}>
     */
    public static function seed(): array
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name => $meta) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], $meta);
        }
        // A permission a catalog set names but no seeder has created yet (a fresh database) is created here, so
        // a role is never silently short of a permission the catalog gives it. Existing ones are left as they are.
        foreach (self::mentionedPermissions() as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], [
                'module' => explode('.', $name)[0],
                'description' => ucfirst(str_replace(['.', '-'], ' ', $name)),
            ]);
        }
        $all = Permission::query()->where('guard_name', 'web')->pluck('id', 'name')->all();

        $report = [];
        foreach (self::definitions() as $name => $definition) {
            $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], [
                'description' => $definition['description'],
                'hierarchy_level' => $definition['level'],
                'is_system_role' => $definition['system'],
            ]);
            $wanted = array_values(array_intersect_key($all, array_flip(self::permissionsFor($name, array_keys($all)))));
            $report[$name] = self::syncByDiff($role, $wanted);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $report;
    }

    /**
     * Bring a role's permission pivot to exactly $permissionIds: attach the missing first, then detach
     * the extras. The RBAC tables are MyISAM in production, so a half-finished run must never leave a
     * role with fewer permissions than before AND than after.
     *
     * @param  array<int, int>  $permissionIds
     * @return array{added: int, removed: int}
     */
    public static function syncByDiff(Role $role, array $permissionIds): array
    {
        $current = DB::table(config('permission.table_names.role_has_permissions'))
            ->where('role_id', $role->getKey())->pluck('permission_id')->map(fn ($id) => (int) $id)->all();
        $add = array_values(array_diff($permissionIds, $current));
        $remove = array_values(array_diff($current, $permissionIds));

        if ($add !== []) {
            $role->permissions()->attach($add);
        }
        if ($remove !== []) {
            $role->permissions()->detach($remove);
        }

        return ['added' => count($add), 'removed' => count($remove)];
    }

    /** Employee: 28 permissions. */
    public const EMPLOYEE = [
        'attendance.own.punch', 'attendance.own.view', 'communications.own.view', 'core.dashboard.view',
        'core.stats.view', 'core.updates.view', 'hr.safety.incidents.create', 'hr.selfservice.benefits.view',
        'hr.selfservice.documents.view', 'hr.selfservice.payslips.view', 'hr.selfservice.performance.view',
        'hr.selfservice.profile.update', 'hr.selfservice.profile.view', 'hr.selfservice.timeoff.request',
        'hr.selfservice.timeoff.view', 'hr.selfservice.trainings.view', 'hr.selfservice.view', 'leave.own.create',
        'leave.own.delete', 'leave.own.update', 'leave.own.view', 'performance-reviews.own.view', 'profile.own.update',
        'profile.own.view', 'profile.password.change', 'training-assignment-submissions.create',
        'training-feedback.own.create', 'training-feedback.own.view',
    ];

    /** Daily Works Contributor: 4 permissions. */
    public const DAILY_WORKS_CONTRIBUTOR = [
        'daily-works.view', 'daily-works.create', 'daily-works.export', 'tasks.view',
    ];

    /** Daily Works Manager: 6 permissions. */
    public const DAILY_WORKS_MANAGER = [
        'daily-works.view', 'daily-works.create', 'daily-works.update', 'daily-works.delete', 'daily-works.import',
        'daily-works.export',
    ];

    /** Quality Contributor: 6 permissions. */
    public const QUALITY_CONTRIBUTOR = [
        'quality.view', 'quality.ncr.view', 'quality.ncr.create', 'quality.ncr.update', 'quality.inspections.view',
        'quality.calibrations.view',
    ];

    /** Quality Manager: 15 permissions. */
    public const QUALITY_MANAGER = [
        'quality.calibrations.create', 'quality.calibrations.delete', 'quality.calibrations.update',
        'quality.calibrations.view', 'quality.dashboard.view', 'quality.inspections.create',
        'quality.inspections.delete', 'quality.inspections.update', 'quality.inspections.view', 'quality.ncr.create',
        'quality.ncr.delete', 'quality.ncr.update', 'quality.ncr.view', 'quality.settings', 'quality.view',
    ];

    /** O&M Director: 26 permissions. */
    public const OM_DIRECTOR = [
        'om.ai.manage', 'om.analytics.view', 'om.contractors.manage', 'om.contractors.view', 'om.dashboard.view',
        'om.equipment.manage', 'om.equipment.view', 'om.incidents.manage', 'om.incidents.view',
        'om.inspections.manage', 'om.inventory.manage', 'om.maintenance.manage', 'om.maintenance.view',
        'om.patrol.manage', 'om.pm.manage', 'om.research.view', 'om.safety.manage', 'om.safety.view',
        'om.shift.manage', 'om.sla.view', 'om.toll.manage', 'om.toll.view', 'om.tppd.manage', 'om.tppd.view',
        'om.traffic.manage', 'om.traffic.view',
    ];

    /** Maintenance Inspector: 13 permissions. */
    public const MAINTENANCE_INSPECTOR = [
        'om.dashboard.view', 'om.maintenance.view', 'om.maintenance.manage', 'om.pm.manage', 'om.inspections.manage',
        'om.inventory.manage', 'om.equipment.view', 'om.equipment.manage', 'om.safety.view', 'om.safety.manage',
        'om.sla.view', 'om.research.view', 'om.ai.manage',
    ];

    /** TMC Operator: 11 permissions. */
    public const TMC_OPERATOR = [
        'om.dashboard.view', 'om.traffic.view', 'om.traffic.manage', 'om.toll.view', 'om.toll.manage',
        'om.incidents.view', 'om.incidents.manage', 'om.equipment.view', 'om.shift.manage', 'om.sla.view',
        'om.safety.view',
    ];

    /** Highway Patrol Officer: 12 permissions. */
    public const HIGHWAY_PATROL_OFFICER = [
        'om.dashboard.view', 'om.incidents.view', 'om.incidents.manage', 'om.patrol.manage', 'om.maintenance.view',
        'om.maintenance.manage', 'om.safety.view', 'om.safety.manage', 'om.tppd.view', 'om.tppd.manage',
        'om.shift.manage', 'om.research.view',
    ];

    /** Line Manager: 5 permissions. */
    public const LINE_MANAGER = [
        'employees.view', 'attendance.view', 'leaves.view', 'leaves.approve', 'holidays.view',
    ];

    /** Department Manager: 31 permissions. */
    public const DEPARTMENT_MANAGER = [
        'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.restore',
        'employees.placement.update', 'departments.view', 'designations.view', 'attendance.view', 'attendance.create',
        'attendance.update', 'attendance.correct', 'attendance.delete', 'attendance.export', 'attendance.manage',
        'holidays.view', 'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'hr.onboarding.view',
        'hr.onboarding.create', 'hr.onboarding.update', 'hr.offboarding.view', 'hr.offboarding.create',
        'hr.offboarding.update', 'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',
    ];

    /** HR Manager: 240 permissions. */
    public const HR_MANAGER = [
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
    ];
}
