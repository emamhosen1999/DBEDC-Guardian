<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Admin\UserManagementService;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `Employee` used to carry four FUNCTIONAL permissions (daily works + tasks). The split migration moves
 * them into `Daily Works Contributor`, assigned to every current Employee BEFORE they are revoked from
 * `Employee` — so nobody loses access — and is idempotent: a re-run must not sweep employees created
 * after the split (a department admin's hires are deliberately plain Employees) into the new role.
 */
class EmployeeRoleSplitMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_01_000002_split_daily_works_contributor_from_employee.php';

    private const FUNCTIONAL = ['daily-works.view', 'daily-works.create', 'daily-works.export', 'tasks.view'];

    private const KEPT = ['attendance.own.view', 'leave.own.view', 'profile.own.view', 'hr.selfservice.view', 'hr.selfservice.timeoff.request', 'hr.safety.incidents.create', 'performance-reviews.own.view'];

    private Role $employee;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // The migrations ran when the test database was built (a bare DB: no Employee yet). Rebuild the state
        // production is in BEFORE the split: Employee carries the field-reporting permissions too.
        Role::where('name', 'Daily Works Contributor')->delete();
        foreach (array_merge(self::FUNCTIONAL, self::KEPT) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->employee = Role::create(['name' => 'Employee', 'guard_name' => 'web', 'hierarchy_level' => 60]);
        $this->employee->givePermissionTo(array_merge(self::FUNCTIONAL, self::KEPT));
    }

    private function runMigration(): void
    {
        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);
        $resolve->invoke($migrator, base_path(self::MIGRATION))->up();
    }

    /** @return array<int, string> */
    private function permissionsOf(string $role): array
    {
        $names = Role::findByName($role)->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    private function employeeUser(string ...$extraRoles): User
    {
        $user = User::factory()->create();
        $user->assignRole('Employee', ...$extraRoles);

        return $user;
    }

    public function test_it_creates_the_role_with_exactly_the_four_functional_permissions(): void
    {
        $this->runMigration();

        $role = Role::findByName('Daily Works Contributor');
        $this->assertSame(60, (int) $role->hierarchy_level);
        $this->assertFalse((bool) $role->is_system_role);
        $this->assertSame(['daily-works.create', 'daily-works.export', 'daily-works.view', 'tasks.view'], $this->permissionsOf('Daily Works Contributor'));
    }

    public function test_every_employee_keeps_access_and_employee_keeps_only_self_service(): void
    {
        $teamLead = Role::create(['name' => 'Team Lead', 'guard_name' => 'web', 'hierarchy_level' => 40]);
        $teamLead->givePermissionTo('daily-works.view');
        $admin = Role::findByName('Department Admin'); // created by the department-scope migration

        $plain = $this->employeeUser();
        $lead = $this->employeeUser('Team Lead');
        $leaver = $this->employeeUser();
        $leaver->delete();                                   // a former employee: restoring must keep parity
        $admin_user = User::factory()->create();
        $admin_user->assignRole($admin);                     // no Employee role: must NOT become a contributor
        $this->assertTrue($plain->can('daily-works.create'), 'precondition: Employee carried the permission');

        $this->runMigration();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([$plain, $lead, $leaver] as $holder) {
            $fresh = User::withTrashed()->find($holder->employee_id);
            $this->assertTrue($fresh->hasRole('Daily Works Contributor'), "{$fresh->employee_id} was assigned the contributor role");
            $this->assertTrue($fresh->hasRole('Employee'), 'and keeps Employee');
            foreach (self::FUNCTIONAL as $permission) {
                $this->assertTrue($fresh->hasPermissionTo($permission), "{$fresh->employee_id} still holds {$permission}");
            }
        }
        $this->assertFalse($admin_user->fresh()->hasRole('Daily Works Contributor'));
        $this->assertFalse($admin_user->fresh()->hasPermissionTo('daily-works.view'));

        // Employee gave exactly the four up; safety-incident reporting and every self-service permission stay.
        $employee = $this->permissionsOf('Employee');
        foreach (self::FUNCTIONAL as $permission) {
            $this->assertNotContains($permission, $employee);
        }
        $expectedKept = self::KEPT;
        sort($expectedKept);
        $this->assertSame($expectedKept, $employee);
        $this->assertContains('hr.safety.incidents.create', $employee);
    }

    public function test_it_logs_the_counts(): void
    {
        $this->employeeUser();
        $this->employeeUser();
        $already = $this->employeeUser();
        $already->assignRole(Role::findOrCreate('Daily Works Contributor', 'web')); // an existing contributor is not double-counted

        Log::spy();
        $this->runMigration();

        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context = []) {
            return str_contains($message, 'Daily Works Contributor split')
                && $context['employee_holders'] === 3
                && $context['newly_assigned'] === 2
                && $context['already_contributors'] === 1
                && $context['revoked_from_employee'] === 4;
        })->once();
    }

    public function test_it_is_idempotent_and_never_sweeps_later_hires_into_the_contributor_role(): void
    {
        $early = $this->employeeUser();
        $this->runMigration();
        $afterFirst = $this->permissionsOf('Daily Works Contributor');
        $holdersAfterFirst = DB::table('model_has_roles')->where('role_id', Role::findByName('Daily Works Contributor')->id)->count();

        // A department admin's hire after the split: a plain Employee, deliberately without field reporting.
        $later = $this->employeeUser();
        $this->runMigration();

        $this->assertSame($afterFirst, $this->permissionsOf('Daily Works Contributor'));
        $this->assertSame($holdersAfterFirst, DB::table('model_has_roles')->where('role_id', Role::findByName('Daily Works Contributor')->id)->count());
        $this->assertTrue($early->fresh()->hasRole('Daily Works Contributor'));
        $this->assertFalse($later->fresh()->hasRole('Daily Works Contributor'), 'a re-run does not touch employees created after the split');
        $this->assertFalse($later->fresh()->hasPermissionTo('daily-works.view'));
    }

    public function test_rolling_back_restores_the_old_bundle(): void
    {
        $user = $this->employeeUser();
        $this->runMigration();

        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);
        $resolve->invoke($migrator, base_path(self::MIGRATION))->down();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertNull(Role::where('name', 'Daily Works Contributor')->first());
        $this->assertTrue($user->fresh()->hasPermissionTo('daily-works.view'), 'Employee carries the abilities again');
    }

    public function test_the_seeder_mirrors_the_split(): void
    {
        // A fresh install: no pre-split Employee role lying around (the seeder only ever adds permissions).
        Role::where('name', 'Employee')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(ComprehensiveRolePermissionSeeder::class);

        foreach (self::FUNCTIONAL as $permission) {
            $this->assertNotContains($permission, $this->permissionsOf('Employee'), "seeded Employee does not carry {$permission}");
        }
        $contributor = $this->permissionsOf('Daily Works Contributor');
        $expected = ComprehensiveRolePermissionSeeder::dailyWorksContributorPermissionNames();
        sort($expected);
        $this->assertSame($expected, $contributor);
        $this->assertContains('hr.safety.incidents.create', $this->permissionsOf('Employee'));
    }

    public function test_base_role_helpers_and_the_create_default_keep_working(): void
    {
        $this->runMigration();
        // DWC is a DEPARTMENT default role (Quality Control), no longer a base role of its own.
        $qc = Department::factory()->create(['name' => 'Quality Control']);
        $qc->forceFill(['default_roles' => ['Daily Works Contributor']])->save();
        $both = User::factory()->create();
        $both->assignRole('Employee', 'Daily Works Contributor');
        $this->assertTrue($both->fresh()->hasOnlyBaseRoles(), 'Employee + Daily Works Contributor is still "just an employee"');

        $manager = User::factory()->create();
        $manager->assignRole('Employee', Role::create(['name' => 'Team Lead', 'guard_name' => 'web', 'hierarchy_level' => 40]));
        $this->assertFalse($manager->fresh()->hasOnlyBaseRoles());

        $noEmployee = User::factory()->create();
        $noEmployee->assignRole('Daily Works Contributor');
        $this->assertFalse($noEmployee->fresh()->hasOnlyBaseRoles(), 'the base role is Employee');

        // The employee dashboard follows the same rule.
        Permission::findOrCreate('core.dashboard.view', 'web');
        $both->givePermissionTo('core.dashboard.view');
        $this->actingAs($both)->get(route('dashboard'))->assertRedirect(route('employee-dashboard'));

        // An API-style creation with no roles: Employee + the department's default roles (never DWC for everyone).
        $created = app(UserManagementService::class)->createUser([
            'name' => 'Defaulted', 'email' => 'defaulted@example.com', 'employee_id' => 'DEF-1', 'user_name' => 'defaulted', 'password' => 'Str0ng!Passw0rd#2026',
        ], null);
        $this->assertEqualsCanonicalizing(['Employee'], $created->roles->pluck('name')->all());

        $inQc = app(UserManagementService::class)->createUser([
            'name' => 'Qc Hire', 'email' => 'qchire@example.com', 'employee_id' => 'DEF-2', 'user_name' => 'qchire', 'password' => 'Str0ng!Passw0rd#2026', 'department_id' => $qc->id,
        ], null);
        $this->assertEqualsCanonicalizing(['Employee', 'Daily Works Contributor'], $inQc->roles->pluck('name')->all());
    }
}
