<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration (production does not re-run seeders) for department scoping:
 *   - `department.scopes.manage` — grant/revoke user_department_scopes (global HR roles);
 *   - `department.admin`         — administer one's OWN department (like Department Manager);
 *   - `attendance.roster.manage` — assign shifts / edit the roster and decide shift swaps,
 *     WITHOUT the company-wide attendance configuration that `attendance.settings` unlocks
 *     (shift definitions, rotation patterns, attendance policies, devices, coverage rules).
 *     Every role that holds `attendance.settings` keeps this ability;
 *   - role `Department Admin` (level 25): a department-only HR operator. Its permission
 *     set is EXACT (synced, never additive) — see DEPARTMENT_ADMIN_PERMISSIONS.
 * Mirrors ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames().
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'department.scopes.manage' => 'Grant and revoke department admin / acting scopes',
        'department.admin' => 'Administer own department (employees, attendance, leave, lifecycle)',
        'attendance.roster.manage' => 'Assign shifts, edit the roster and decide shift swaps (no company-wide attendance settings)',
    ];

    private const SCOPE_MANAGER_ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    private const ROLE = 'Department Admin';

    /**
     * Department Admin = department-only HR operator: dashboard, own self-service and
     * ONLY Employees, Attendance (+ roster/shifts inside it), Leave, Onboarding,
     * Offboarding and Asset Management — all confined by DepartmentScope. Never
     * payroll / F&F, delete, roles, settings, feature flags, projects, quality, etc.
     */
    private const DEPARTMENT_ADMIN_PERMISSIONS = [
        'department.admin',
        // Dashboard + the same self-service base every Employee gets (modules core + self-service).
        'core.dashboard.view', 'core.stats.view', 'core.updates.view',
        'attendance.own.view', 'attendance.own.punch',
        'leave.own.view', 'leave.own.create', 'leave.own.update', 'leave.own.delete',
        'communications.own.view',
        'profile.own.view', 'profile.own.update', 'profile.password.change',
        // Workforce -> Employees (no delete: exit goes through Offboarding).
        'employees.view', 'employees.create', 'employees.update',
        'users.create', 'users.update',
        // Time/Attendance -> Attendances (+ roster / shift assignment / swaps), not settings.
        'attendance.view', 'attendance.create', 'attendance.update', 'attendance.correct', 'attendance.export',
        'attendance.roster.manage',
        // Leave Management.
        'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'leaves.delete',
        // Lifecycle.
        'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.delete',
        'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.delete',
        'hr.assets.view', 'hr.assets.manage',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $name => $description) {
            Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => 'admin', 'description' => $description],
            );
        }

        foreach (self::SCOPE_MANAGER_ROLES as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()
                ?->givePermissionTo('department.scopes.manage');
        }

        // Whoever could already configure attendance keeps the roster/shift-assignment
        // abilities that used to ride on the same permission.
        // (permissions eager-loaded: givePermissionTo() would otherwise lazy-load them per role,
        // which throws wherever lazy loading is prevented — dev and test.)
        Permission::where('name', 'attendance.settings')->where('guard_name', 'web')->first()
            ?->roles()->with('permissions')->get()
            ->each(fn (Role $role) => $role->givePermissionTo('attendance.roster.manage'));

        $role = Role::firstOrCreate(
            ['name' => self::ROLE, 'guard_name' => 'web'],
            [
                'description' => 'Department-scoped HR operator: employees, attendance, leave, onboarding, offboarding and assets within assigned department(s)',
                'hierarchy_level' => 25,
                'is_system_role' => false,
            ],
        );

        // syncPermissions, not givePermissionTo: a role that already exists (from an
        // earlier, broader definition) is narrowed to exactly this list.
        $role->syncPermissions(
            Permission::query()
                ->where('guard_name', 'web')
                ->whereIn('name', self::DEPARTMENT_ADMIN_PERMISSIONS)
                ->get()
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Role::where('name', self::ROLE)->where('guard_name', 'web')->delete();
        Permission::whereIn('name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
