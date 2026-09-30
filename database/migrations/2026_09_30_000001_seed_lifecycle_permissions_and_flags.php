<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Data migration (production does not re-run seeders): splits the financial /
 * asset routes off the read-only `employees.view` permission, and registers the
 * payroll / F&F feature flags in the OFF state.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'hr.assets.view' => 'View company assets and assignments',
        'hr.assets.manage' => 'Create, assign, return and delete company assets',
        'hr.settlement.manage' => 'Create full and final settlement drafts',
        'hr.settlement.approve' => 'Approve full and final settlements',
        'hr.settlement.disburse' => 'Disburse full and final settlements',
        'hr.probation.manage' => 'Confirm employees after probation',
    ];

    private const ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    private const FLAGS = [
        'hr_payroll' => 'Payroll module (generation and payslips). OFF until rebuilt against the payroll schema.',
        'hr_final_settlement' => 'Full and final settlement calculation, approval and disbursal. OFF until rebuilt.',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [];
        foreach (self::PERMISSIONS as $name => $description) {
            $permissions[] = Permission::firstOrCreate(
                ['name' => $name, 'guard_name' => 'web'],
                ['module' => 'hrm', 'description' => $description],
            );
        }

        foreach (self::ROLES as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($permissions);
        }

        if (Schema::hasTable('feature_flags')) {
            foreach (self::FLAGS as $key => $description) {
                $exists = DB::table('feature_flags')->where('key', $key)->whereNull('role')->exists();
                if (! $exists) {
                    DB::table('feature_flags')->insert([
                        'key' => $key,
                        'value' => null,
                        'description' => $description,
                        'is_enabled' => false,
                        'role' => null,
                        'created_at' => now()->format('Y-m-d H:i:s.v'),
                        'updated_at' => now()->format('Y-m-d H:i:s.v'),
                    ]);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(self::PERMISSIONS))->where('guard_name', 'web')->delete();

        if (Schema::hasTable('feature_flags')) {
            DB::table('feature_flags')->whereIn('key', array_keys(self::FLAGS))->whereNull('role')->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
