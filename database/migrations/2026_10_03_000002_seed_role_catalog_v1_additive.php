<?php

use App\Services\Access\AccessAudit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role catalog v1, step A2 (docs/audit/ROLE_CATALOG_2026-10-03.md, section 7.2): ADDITIVE ONLY, so no
 * current holder loses anything.
 *
 *   - creates `monitoring.camera.view` and `leaves.manage` (used by routes / nav, never created);
 *   - renames by id (holders and grants follow): Team Lead -> Line Manager,
 *     Maintenance Inspector / QC Specialist -> Maintenance Inspector (level 35 -> 50); a rename is
 *     skipped when the new name is already taken;
 *   - creates Quality Manager, Daily Works Manager and Quality Contributor with their exact sets;
 *   - gives Line Manager its 5-permission set while it still has no holder.
 *
 * The role sets are frozen copies of App\Services\Access\RoleCatalog (RoleCatalogSpecTest fails on drift).
 * The exact trimming of the existing roles is migration 2026_10_03_000003 (A4), after
 * `access:apply-catalog` has moved the people. The RBAC tables are MyISAM in production: every write
 * here is idempotent and diff based, so a failed run is finished by running it again.
 */
return new class extends Migration
{
    /** @var array<string, array{module: string, description: string}> */
    private const NEW_PERMISSIONS = [
        'monitoring.camera.view' => ['module' => 'om', 'description' => 'View the CCTV / camera monitoring console'],
        'leaves.manage' => ['module' => 'hrm', 'description' => 'Administer leave: override, adjust balances and records of any employee'],
    ];

    /** old name => [new name, new level (null = unchanged)] */
    private const RENAMES = [
        'Team Lead' => ['Line Manager', null],
        'Maintenance Inspector / QC Specialist' => ['Maintenance Inspector', 50],
    ];

    /** @var array<string, array{level: int, description: string}> */
    private const ROLES = [
        'Quality Manager' => ['level' => 45, 'description' => 'Quality management: the whole quality module (NCR register, inspections, calibrations, settings)'],
        'Daily Works Manager' => ['level' => 45, 'description' => 'Daily works management: the full daily works module including import'],
        'Quality Contributor' => ['level' => 55, 'description' => 'Quality field work: raise and update NCRs, view inspections and calibrations'],
        'Line Manager' => ['level' => 40, 'description' => 'Line management: view the team, approve its leave (reporting subtree)'],
    ];

    /** @var array<string, array<int, string>> */
    private const SETS = [
        'Quality Manager' => [
            'quality.calibrations.create', 'quality.calibrations.delete', 'quality.calibrations.update',
            'quality.calibrations.view', 'quality.dashboard.view', 'quality.inspections.create',
            'quality.inspections.delete', 'quality.inspections.update', 'quality.inspections.view', 'quality.ncr.create',
            'quality.ncr.delete', 'quality.ncr.update', 'quality.ncr.view', 'quality.settings', 'quality.view',
        ],
        'Daily Works Manager' => [
            'daily-works.view', 'daily-works.create', 'daily-works.update', 'daily-works.delete', 'daily-works.import',
            'daily-works.export',
        ],
        'Quality Contributor' => [
            'quality.view', 'quality.ncr.view', 'quality.ncr.create', 'quality.ncr.update', 'quality.inspections.view',
            'quality.calibrations.view',
        ],
        'Line Manager' => [
            'employees.view', 'attendance.view', 'leaves.view', 'leaves.approve', 'holidays.view',
        ],
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::NEW_PERMISSIONS as $name => $meta) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], $meta);
        }

        foreach (self::RENAMES as $old => [$new, $level]) {
            $role = Role::where('name', $old)->where('guard_name', 'web')->first();
            if ($role === null || Role::where('name', $new)->where('guard_name', 'web')->exists()) {
                continue;
            }
            $role->update(array_filter(['name' => $new, 'hierarchy_level' => $level], fn ($v) => $v !== null));
            app(AccessAudit::class)->record('catalog.role.renamed', 'role', $new, ['name' => $old], ['name' => $new, 'hierarchy_level' => $level]);
        }

        $ids = Permission::where('guard_name', 'web')->pluck('id', 'name')->all();

        foreach (self::ROLES as $name => $meta) {
            $role = Role::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['description' => $meta['description'], 'hierarchy_level' => $meta['level'], 'is_system_role' => false],
            );

            // A role with holders (a Line Manager somebody already assigned) is only ever added to here.
            $holders = DB::table('model_has_roles')->where('role_id', $role->id)->count();
            $exact = $holders === 0 || $role->wasRecentlyCreated;
            $wanted = array_values(array_intersect_key($ids, array_flip(self::SETS[$name])));

            $this->sync($role, $wanted, $exact);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::RENAMES as $old => [$new, $level]) {
            $role = Role::where('name', $new)->where('guard_name', 'web')->first();
            if ($role !== null && ! Role::where('name', $old)->where('guard_name', 'web')->exists()) {
                $role->update(['name' => $old] + ($level !== null ? ['hierarchy_level' => 35] : []));
            }
        }

        // Only roles nobody holds are removed; a role with holders is left in place.
        foreach (['Quality Manager', 'Daily Works Manager', 'Quality Contributor'] as $name) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();
            if ($role !== null && ! DB::table('model_has_roles')->where('role_id', $role->id)->exists()) {
                $role->permissions()->detach();
                $role->delete();
            }
        }

        Permission::whereIn('name', array_keys(self::NEW_PERMISSIONS))->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** Attach the missing first, then (only when $exact) detach the extras. */
    private function sync(Role $role, array $wanted, bool $exact): void
    {
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
            app(AccessAudit::class)->record('catalog.role.permissions', 'role', $role->name, ['count' => count($current)], ['added' => count($add), 'removed' => count($remove)]);
        }
    }
};
