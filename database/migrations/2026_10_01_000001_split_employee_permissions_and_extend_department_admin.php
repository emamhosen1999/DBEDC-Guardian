<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration (production does not re-run seeders): split the coarse `users.update` /
 * `users.delete` / `users.create` gates on employee administration into granular, named
 * permissions — one per action — so each role can be given exactly the capabilities it needs
 * (a department admin gets everything but access management).
 *
 *   employees.update                    profile (identity, personal, department)   [exists]
 *   employees.placement.update          designation / reports-to / work location
 *   employees.attendance-config.update  attendance method / biometric device rules
 *   employees.compensation.view|update  salary and statutory details
 *   employees.password.reset            reset another employee's password
 *   employees.devices.manage            device lock, device history, sessions
 *   employees.access.manage             roles and direct permissions (global actors only)
 *   employees.delete / employees.restore  deactivate / reinstate (soft delete)
 *   employees.import / employees.export                                             [exist]
 *
 * NOBODY LOSES ANYTHING: every role that held the coarse permission a new one replaces is granted
 * the new one (GRANTS below). Salary used to be gated by role NAME, so the three HR roles get
 * the two compensation permissions. The role `Department Admin` is then synced — exact, never
 * additive — to its new list. Mirrors ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames().
 */
return new class extends Migration
{
    /** @var array<string, string> permission => description (all in module "hrm") */
    private const NEW_PERMISSIONS = [
        'employees.placement.update' => "Change employees' designation, reporting line and work location",
        'employees.attendance-config.update' => "Change employees' attendance method and biometric device rules",
        'employees.compensation.view' => "View employees' salary and statutory details",
        'employees.compensation.update' => "Change employees' salary and statutory details",
        'employees.password.reset' => "Reset employees' passwords",
        'employees.devices.manage' => "Manage employees' device lock, registered devices and sessions",
        'employees.access.manage' => "Manage employees' roles and direct permissions",
        'employees.restore' => 'Restore deactivated employees',
    ];

    /**
     * Permissions this migration builds on. They exist in production already (seeded earlier);
     * created here only so a fresh database that skipped the seeder still migrates.
     *
     * @var array<string, string>
     */
    private const BASE_PERMISSIONS = [
        'employees.view' => 'View employee records',
        'employees.create' => 'Create employee records',
        'employees.update' => 'Update employee records',
        'employees.delete' => 'Delete employee records',
        'employees.import' => 'Import employee data',
        'employees.export' => 'Export employee data',
        'designations.view' => 'View designations/positions',
        'designations.create' => 'Create designations',
        'designations.update' => 'Update designations',
        'designations.delete' => 'Delete designations',
    ];

    /**
     * Old (coarse) permission => the granular permissions every role holding it receives.
     *
     * @var array<string, array<int, string>>
     */
    private const GRANTS = [
        'users.create' => ['employees.create'],
        'users.update' => [
            'employees.update', 'employees.placement.update', 'employees.attendance-config.update',
            'employees.password.reset', 'employees.devices.manage', 'employees.access.manage',
        ],
        // The work-location route was gated by employees.update; the inline designation one by designations.update.
        'employees.update' => ['employees.placement.update'],
        'designations.update' => ['employees.placement.update'],
        'users.delete' => ['employees.delete', 'employees.restore'],
        'employees.delete' => ['employees.restore'],
    ];

    /** Salary was gated by role NAME ("Only HR may change salary"), not by a permission. */
    private const COMPENSATION_ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    private const ROLE = 'Department Admin';

    /** The 40-permission set migration 2026_09_30_000005 defined (what rollback restores). */
    private const PREVIOUS_DEPARTMENT_ADMIN_PERMISSIONS = [
        'department.admin',
        'core.dashboard.view', 'core.stats.view', 'core.updates.view',
        'attendance.own.view', 'attendance.own.punch',
        'leave.own.view', 'leave.own.create', 'leave.own.update', 'leave.own.delete',
        'communications.own.view',
        'profile.own.view', 'profile.own.update', 'profile.password.change',
        'employees.view', 'employees.create', 'employees.update',
        'users.create', 'users.update',
        'attendance.view', 'attendance.create', 'attendance.update', 'attendance.correct', 'attendance.export',
        'attendance.roster.manage',
        'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'leaves.delete',
        'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.delete',
        'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.delete',
        'hr.assets.view', 'hr.assets.manage',
    ];

    /**
     * Department Admin = a DELEGATED department administrator (the Entra administrative-unit /
     * Google Workspace OU-admin model): full people administration inside his department(s) —
     * the previous 40 + delete/restore, designation CRUD for his own department (scoped by
     * DepartmentScope, parent designations included) and every granular employee permission
     * EXCEPT access management. Company-wide configuration (departments, settings, roles,
     * payroll, ...) stays global.
     */
    private const DEPARTMENT_ADMIN_ADDITIONS = [
        'employees.delete', 'employees.restore',
        'designations.view', 'designations.create', 'designations.update', 'designations.delete',
        'employees.placement.update', 'employees.attendance-config.update',
        'employees.compensation.view', 'employees.compensation.update',
        'employees.password.reset', 'employees.devices.manage',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([...self::BASE_PERMISSIONS, ...self::NEW_PERMISSIONS] as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => 'hrm', 'description' => $description],
            );
        }

        // Nobody loses anything: whoever held the coarse permission gets the granular one.
        // (permissions eager-loaded: givePermissionTo() would otherwise lazy-load them per role,
        // which throws wherever lazy loading is prevented — dev and test.)
        foreach (self::GRANTS as $coarse => $granular) {
            Role::query()
                ->where('guard_name', 'web')
                ->whereHas('permissions', fn ($query) => $query->where('name', $coarse))
                ->with('permissions')
                ->get()
                ->each(fn (Role $role) => $role->givePermissionTo($granular));
        }

        Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', self::COMPENSATION_ROLES)
            ->with('permissions')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(['employees.compensation.view', 'employees.compensation.update']));

        $role = Role::firstOrCreate(
            ['name' => self::ROLE, 'guard_name' => 'web'],
            [
                'description' => 'Delegated department administrator: full people administration (employees, designations, attendance, leave, onboarding, offboarding, assets) inside the assigned department(s)',
                'hierarchy_level' => 25,
                'is_system_role' => false,
            ],
        );

        // syncPermissions, not givePermissionTo: the set is EXACT.
        $role->syncPermissions(
            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', [...self::PREVIOUS_DEPARTMENT_ADMIN_PERMISSIONS, ...self::DEPARTMENT_ADMIN_ADDITIONS])
                ->get()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', 'web')->first();
        $role?->syncPermissions(
            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', self::PREVIOUS_DEPARTMENT_ADMIN_PERMISSIONS)
                ->get()
        );

        Permission::whereIn('name', array_keys(self::NEW_PERMISSIONS))->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
