<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One named permission per action for application documents: viewing them (inside the DepartmentScope) and asking for a
 * re-upload, for leave and for overtime / replacement-leave claims. Granted to every role that approves leave today
 * (leaves.approve): Line Manager, Department Manager, Department Admin, HR Manager, Administrator, Super Administrator.
 * The applicant and the approvers named in the chain can always open the documents of the application itself.
 * Idempotent; grants are a diff of inserts (the RBAC pivot may be MyISAM in production).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'leaves.attachments.view' => 'Open the supporting documents of leave applications inside the own scope',
        'leaves.request_reupload' => 'Return a leave application to the employee for a clearer document',
        'overtime.attachments.view' => 'Open the supporting documents of overtime and replacement-leave claims inside the own scope',
        'overtime.request_reupload' => 'Return an overtime or replacement-leave claim to the employee for a clearer document',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $table = config('permission.table_names.role_has_permissions');

        $approver = Permission::query()->where('name', 'leaves.approve')->where('guard_name', 'web')->first();
        $roleIds = $approver ? DB::table($table)->where('permission_id', $approver->getKey())->pluck('role_id') : collect();
        $roleIds = $roleIds->merge(Role::query()->whereIn('name', ['Super Administrator', 'Administrator'])->where('guard_name', 'web')->pluck('id'))->unique();

        foreach (self::PERMISSIONS as $name => $description) {
            $permission = Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['module' => 'hrm', 'description' => $description]);
            $already = DB::table($table)->where('permission_id', $permission->getKey())->pluck('role_id');
            foreach ($roleIds->diff($already) as $roleId) {
                DB::table($table)->insert(['permission_id' => $permission->getKey(), 'role_id' => $roleId]);
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $name) {
            $permission = Permission::query()->where('name', $name)->where('guard_name', 'web')->first();
            if ($permission !== null) {
                DB::table(config('permission.table_names.role_has_permissions'))->where('permission_id', $permission->getKey())->delete();
                $permission->delete();
            }
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
