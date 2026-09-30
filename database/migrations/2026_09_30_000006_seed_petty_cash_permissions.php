<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration (production does not re-run seeders): replaces the hard-coded
 * role-name checks in PettyCashController with permissions. Legacy roles
 * (Manager, Accountant, Finance Manager) keep working during the transition
 * through a code-level fallback; Finance Manager / Accountant also get the
 * permissions when those roles exist.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'petty-cash.view-all' => 'View other employees\' petty cash loans and transactions',
        'petty-cash.approve' => 'Approve or reject petty cash loans',
        'petty-cash.manage' => 'Record, edit and close transactions on other employees\' petty cash loans',
    ];

    private const ROLES = ['Super Administrator', 'Administrator', 'Finance Manager', 'Accountant'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (self::PERMISSIONS as $name => $description) {
            $permissions[] = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => 'finance', 'description' => $description],
            );
        }

        foreach (self::ROLES as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
