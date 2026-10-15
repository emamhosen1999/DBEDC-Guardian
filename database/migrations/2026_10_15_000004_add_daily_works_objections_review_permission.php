<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reviewing (and resolving / rejecting) RFI objections raised by others gets its own permission. The policy used to
 * decide it by role NAMES that do not exist ('Super Admin', 'Admin', 'Project Manager', 'Consultant'), so only HR Manager
 * could review; the Daily Works Manager - whose catalog role is the full daily works module - could not.
 * Granted to Daily Works Manager and the global admin roles; the global roles keep reviewing through DepartmentScope.
 * Idempotent; the RBAC pivot may be MyISAM in production, so grants are a diff of inserts.
 */
return new class extends Migration
{
    private const PERMISSION = 'daily-works.objections.review';

    private const ROLES = ['Daily Works Manager', 'Administrator', 'Super Administrator'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $permission = Permission::firstOrCreate(
            ['name' => self::PERMISSION, 'guard_name' => 'web'],
            ['module' => 'daily-works', 'description' => 'Review, resolve and reject RFI objections raised by others'],
        );

        $table = config('permission.table_names.role_has_permissions');
        $already = DB::table($table)->where('permission_id', $permission->getKey())->pluck('role_id')->all();
        foreach (Role::query()->whereIn('name', self::ROLES)->where('guard_name', 'web')->pluck('id')->diff($already) as $roleId) {
            DB::table($table)->insert(['permission_id' => $permission->getKey(), 'role_id' => $roleId]);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', self::PERMISSION)->where('guard_name', 'web')->first();
        if ($permission !== null) {
            DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $permission->getKey())->delete();
            $permission->delete();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
