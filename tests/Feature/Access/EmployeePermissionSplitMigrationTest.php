<?php

namespace Tests\Feature\Access;

use App\Services\Access\SelfAdministration;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Production does not re-run seeders, so the split of the coarse `users.*` gates into granular
 * employee permissions ships as a data migration. It must (1) create the permissions, (2) give each
 * to every role that held the coarse permission it replaces — nobody loses anything — (3) sync the
 * Department Admin role to its exact new list and (4) clear the permission cache.
 */
class EmployeePermissionSplitMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_01_000001_split_employee_permissions_and_extend_department_admin.php';

    private const SELF_ADMINISTRATION_MIGRATION = 'database/migrations/2026_10_02_000003_add_self_administration_permission_and_log.php';

    private const GRANULAR = [
        'employees.placement.update', 'employees.attendance-config.update', 'employees.compensation.view',
        'employees.compensation.update', 'employees.password.reset', 'employees.devices.manage',
        'employees.access.manage', 'employees.restore',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Production already holds every permission the role is synced to (the seeder created them);
        // a bare test database does not, so create them or the sync would silently select fewer.
        $existing = ['users.view', 'users.create', 'users.update', 'users.delete', 'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'designations.update', 'roles.view', 'attendance.settings'];
        foreach (array_merge($existing, ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames()) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function run_(string $migration = self::MIGRATION): void
    {
        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);
        $resolve->invoke($migrator, base_path($migration))->up();
    }

    private function role(string $name, array $permissions, int $level = 50): Role
    {
        $role = Role::create(['name' => $name, 'guard_name' => 'web', 'hierarchy_level' => $level]);
        $role->givePermissionTo($permissions);

        return $role;
    }

    /** @return array<int, string> */
    private function held(string $role): array
    {
        $names = Role::findByName($role)->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    public function test_it_creates_every_granular_permission(): void
    {
        $this->run_();

        foreach (self::GRANULAR as $name) {
            $this->assertNotNull(Permission::where('name', $name)->where('guard_name', 'web')->first(), "{$name} is created");
        }
    }

    public function test_every_holder_of_a_coarse_permission_receives_what_it_replaces(): void
    {
        $this->role('HR Manager', ['users.view', 'users.create', 'users.update', 'users.delete', 'employees.view'], 20);
        $this->role('Profile Editor', ['employees.view', 'employees.update']);   // employees.update used to open the work-location route
        $this->role('Designation Clerk', ['designations.update']);               // designations.update used to open inline designation
        $this->role('Leaver Desk', ['employees.delete']);                        // could delete -> may now restore
        $this->role('Creator', ['users.create']);
        $this->role('Auditor', ['users.view', 'employees.view']);                // read-only: must gain nothing

        $this->run_();

        // users.update -> everything the old gate opened, access management included
        $hr = $this->held('HR Manager');
        foreach (['employees.create', 'employees.update', 'employees.placement.update', 'employees.attendance-config.update', 'employees.password.reset', 'employees.devices.manage', 'employees.access.manage', 'employees.delete', 'employees.restore'] as $name) {
            $this->assertContains($name, $hr, "HR Manager keeps {$name}");
        }
        // salary used to be gated by role NAME: the three HR roles get the compensation pair
        $this->assertContains('employees.compensation.view', $hr);
        $this->assertContains('employees.compensation.update', $hr);

        $editor = $this->held('Profile Editor');
        $this->assertContains('employees.placement.update', $editor);
        $this->assertNotContains('employees.password.reset', $editor);
        $this->assertNotContains('employees.devices.manage', $editor);
        $this->assertNotContains('employees.access.manage', $editor);

        $this->assertContains('employees.placement.update', $this->held('Designation Clerk'));
        $this->assertContains('employees.restore', $this->held('Leaver Desk'));
        $this->assertContains('employees.create', $this->held('Creator'));
        $this->assertSame(['employees.view', 'users.view'], $this->held('Auditor'), 'a read-only role gains nothing');
    }

    public function test_compensation_goes_to_the_three_hr_roles_only(): void
    {
        foreach (['Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20, 'Team Lead' => 40] as $name => $level) {
            $this->role($name, ['employees.view'], $level);
        }

        $this->run_();

        foreach (['Super Administrator', 'Administrator', 'HR Manager'] as $name) {
            $this->assertContains('employees.compensation.update', $this->held($name));
        }
        $this->assertNotContains('employees.compensation.view', $this->held('Team Lead'));
    }

    public function test_department_admin_is_synced_to_its_exact_delegated_list_without_access_management(): void
    {
        // The role already exists (earlier migrations create it); an earlier, broader definition is narrowed.
        Role::findByName('Department Admin')->syncPermissions(['roles.view', 'attendance.settings', 'users.delete', 'users.update']);

        $this->run_();

        $expected = ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames();
        sort($expected);
        $this->assertCount(52, $expected);
        $this->assertSame($expected, $this->held('Department Admin'));
        $this->assertNotContains('employees.access.manage', $this->held('Department Admin'));
        $this->assertNotContains('roles.view', $this->held('Department Admin'));

        // Later migrations keep it there: self-administration is a per-person exception, never a role grant.
        $this->run_(self::SELF_ADMINISTRATION_MIGRATION);
        $this->assertSame($expected, $this->held('Department Admin'));
        $this->assertNotContains(SelfAdministration::PERMISSION, $this->held('Department Admin'));
    }

    public function test_the_seeder_creates_the_same_permissions_the_migration_does(): void
    {
        $this->seed(ComprehensiveRolePermissionSeeder::class);

        foreach (self::GRANULAR as $name) {
            $this->assertNotNull(Permission::where('name', $name)->first(), "seeder defines {$name}");
        }
        $seeded = $this->held('Department Admin');
        $expected = ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames();
        sort($expected);
        $this->assertSame($expected, $seeded);
        // Owner decision O-15 (role catalog v1): access administration belongs to the Super Administrator alone.
        $this->assertNotContains('employees.access.manage', $this->held('HR Manager'), 'HR no longer administers access');
    }

    public function test_it_is_idempotent_and_clears_the_permission_cache(): void
    {
        $hr = $this->role('HR Manager', ['users.update'], 20);
        // Warm the cache with the pre-migration state.
        $this->assertFalse($hr->hasPermissionTo('users.create'));

        $this->run_();
        $first = $this->held('HR Manager');
        $this->run_();

        $this->assertSame($first, $this->held('HR Manager'), 'running twice changes nothing');
        $this->assertTrue(Role::findByName('HR Manager')->hasPermissionTo('employees.access.manage'), 'the cache was cleared');
    }
}
