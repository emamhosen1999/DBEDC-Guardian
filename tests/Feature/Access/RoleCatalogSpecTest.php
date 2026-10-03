<?php

namespace Tests\Feature\Access;

use App\Models\User;
use App\Services\Access\RoleCatalog;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Database\Seeders\OmRbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The role catalog (docs/audit/ROLE_CATALOG_2026-10-03.md, section 4.1 and 4.2, owner-approved) as LITERALS: every
 * role's exact permission set and level after the seeders run, the retired roles gone, and the frozen copies
 * in the catalog migrations equal to App\Services\Access\RoleCatalog (production never re-runs seeders).
 *
 * The expectations below are written out by hand on purpose: a test that read them from RoleCatalog would
 * pass whatever RoleCatalog said.
 */
class RoleCatalogSpecTest extends TestCase
{
    use RefreshDatabase;

    private const ADDITIVE_MIGRATION = 'database/migrations/2026_10_03_000002_seed_role_catalog_v1_additive.php';

    private const EXACT_MIGRATION = 'database/migrations/2026_10_03_000003_sync_role_catalog_v1_exact.php';

    /** role => [level, exact permissions]. Roles defined by a rule (SA, Administrator) and big lists are checked separately. */
    private const SPEC = [
        'Employee' => [60, [
            'attendance.own.punch', 'attendance.own.view', 'leave.own.view', 'leave.own.create', 'leave.own.update', 'leave.own.delete',
            'profile.own.view', 'profile.own.update', 'profile.password.change', 'core.dashboard.view', 'core.stats.view', 'core.updates.view',
            'communications.own.view', 'hr.selfservice.view', 'hr.selfservice.profile.view', 'hr.selfservice.profile.update',
            'hr.selfservice.documents.view', 'hr.selfservice.benefits.view', 'hr.selfservice.timeoff.view', 'hr.selfservice.timeoff.request',
            'hr.selfservice.trainings.view', 'hr.selfservice.payslips.view', 'hr.selfservice.performance.view',
            'performance-reviews.own.view', 'training-feedback.own.view', 'training-feedback.own.create',
            'training-assignment-submissions.create', 'hr.safety.incidents.create',
        ]],
        // tasks.view stays (Q3: the legacy task endpoints had 12 hits in 90 days)
        'Daily Works Contributor' => [60, ['daily-works.view', 'daily-works.create', 'daily-works.export', 'tasks.view']],
        'Daily Works Manager' => [45, ['daily-works.view', 'daily-works.create', 'daily-works.update', 'daily-works.delete', 'daily-works.import', 'daily-works.export']],
        'Quality Contributor' => [55, ['quality.view', 'quality.ncr.view', 'quality.ncr.create', 'quality.ncr.update', 'quality.inspections.view', 'quality.calibrations.view']],
        'Quality Manager' => [45, [
            'quality.view', 'quality.dashboard.view', 'quality.settings',
            'quality.ncr.view', 'quality.ncr.create', 'quality.ncr.update', 'quality.ncr.delete',
            'quality.inspections.view', 'quality.inspections.create', 'quality.inspections.update', 'quality.inspections.delete',
            'quality.calibrations.view', 'quality.calibrations.create', 'quality.calibrations.update', 'quality.calibrations.delete',
        ]],
        'Maintenance Inspector' => [50, [
            'om.dashboard.view', 'om.maintenance.view', 'om.maintenance.manage', 'om.pm.manage', 'om.inspections.manage', 'om.inventory.manage',
            'om.equipment.view', 'om.equipment.manage', 'om.safety.view', 'om.safety.manage', 'om.sla.view', 'om.research.view', 'om.ai.manage',
        ]],
        // O-5: no daily-works.* (production had 0 TMC daily-works rows)
        'TMC Operator' => [45, [
            'om.dashboard.view', 'om.traffic.view', 'om.traffic.manage', 'om.toll.view', 'om.toll.manage', 'om.incidents.view', 'om.incidents.manage',
            'om.equipment.view', 'om.shift.manage', 'om.sla.view', 'om.safety.view',
        ]],
        'Highway Patrol Officer' => [55, [
            'om.dashboard.view', 'om.incidents.view', 'om.incidents.manage', 'om.patrol.manage', 'om.maintenance.view', 'om.maintenance.manage',
            'om.safety.view', 'om.safety.manage', 'om.tppd.view', 'om.tppd.manage', 'om.shift.manage', 'om.research.view',
        ]],
        // approved 5; its tasks.* are not carried over: the role has no holder, so nobody loses a thing (Q3 keeps tasks.* where it is held)
        'Line Manager' => [40, ['employees.view', 'attendance.view', 'leaves.view', 'leaves.approve', 'holidays.view']],
        // tasks.* kept (Q3), so 26 + 5
        'Department Manager' => [30, [
            'employees.view', 'employees.create', 'employees.update', 'employees.delete', 'employees.restore', 'employees.placement.update',
            'departments.view', 'designations.view',
            'attendance.view', 'attendance.create', 'attendance.update', 'attendance.correct', 'attendance.delete', 'attendance.export', 'attendance.manage',
            'holidays.view', 'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve',
            'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign',
        ]],
    ];

    private const LEVELS = [
        'Super Administrator' => 1, 'Administrator' => 10, 'O&M Director' => 15, 'HR Manager' => 20, 'Department Admin' => 25,
        'Department Manager' => 30, 'Line Manager' => 40, 'Quality Manager' => 45, 'Daily Works Manager' => 45,
        'TMC Operator' => 45, 'Maintenance Inspector' => 50, 'Highway Patrol Officer' => 55, 'Quality Contributor' => 55,
        'Employee' => 60, 'Daily Works Contributor' => 60,
    ];

    private const RETIRED = ['Admin', 'Project Manager', 'Senior Employee', 'Contractor', 'Intern', 'Team Lead', 'Maintenance Inspector / QC Specialist'];

    /** O-15: access administration is the Super Administrator's alone. */
    private const ACCESS_ADMINISTRATION = ['employees.access.manage', 'department.scopes.manage', 'roles.create', 'roles.update', 'roles.delete', 'permissions.assign'];

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->seed(ComprehensiveRolePermissionSeeder::class);
        $this->seed(OmRbacSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return array<int, string> */
    private function held(string $role): array
    {
        $names = Role::findByName($role)->permissions()->pluck('name')->all();
        sort($names);

        return $names;
    }

    private function sorted(array $names): array
    {
        sort($names);

        return $names;
    }

    public function test_every_role_holds_exactly_its_catalog_permissions_and_level(): void
    {
        foreach (self::SPEC as $role => [$level, $permissions]) {
            $this->assertSame($this->sorted($permissions), $this->held($role), "{$role}: exact permission set");
            $this->assertSame($level, (int) Role::findByName($role)->hierarchy_level, "{$role}: level");
        }
    }

    public function test_the_set_sizes_are_the_approved_ones(): void
    {
        $sizes = ['Employee' => 28, 'Daily Works Contributor' => 4, 'Daily Works Manager' => 6, 'Quality Contributor' => 6, 'Quality Manager' => 15,
            'O&M Director' => 26, 'Maintenance Inspector' => 13, 'TMC Operator' => 11, 'Highway Patrol Officer' => 12, 'Line Manager' => 5,
            'Department Manager' => 31, 'Department Admin' => 52, 'HR Manager' => 240];
        foreach ($sizes as $role => $size) {
            $this->assertCount($size, $this->held($role), "{$role} holds {$size} permissions");
        }
    }

    public function test_every_catalog_role_exists_with_its_level_and_nothing_retired_remains(): void
    {
        $this->assertSame(self::LEVELS, collect(self::LEVELS)->keys()->mapWithKeys(fn ($r) => [$r => (int) Role::findByName($r)->hierarchy_level])->all());
        $this->assertEqualsCanonicalizing(array_keys(self::LEVELS), RoleCatalog::roleNames(), 'the catalog defines exactly these roles');

        foreach (self::RETIRED as $name) {
            $this->assertNull(Role::where('name', $name)->first(), "{$name} is retired / renamed and must not be re-created by a seeder");
        }
        $this->assertSame(RoleCatalog::RETIRED, ['Admin', 'Project Manager', 'Senior Employee', 'Contractor', 'Intern']);
    }

    public function test_o_and_m_director_holds_exactly_the_twenty_six_om_permissions(): void
    {
        $om = Permission::where('name', 'like', 'om.%')->pluck('name')->all();
        $this->assertCount(26, $om);
        $this->assertSame($this->sorted($om), $this->held('O&M Director'));
    }

    public function test_super_administrator_holds_the_whole_catalog_except_the_two_per_person_permissions(): void
    {
        $all = Permission::pluck('name')->all();
        $expected = array_values(array_diff($all, ['access.self-administration', 'department.admin']));

        $this->assertSame($this->sorted($expected), $this->held('Super Administrator'));
        $this->assertContains('monitoring.camera.view', $this->held('Super Administrator'));
        $this->assertContains('leaves.manage', $this->held('Super Administrator'));
    }

    public function test_administrator_and_hr_manager_no_longer_administer_access(): void
    {
        $all = Permission::pluck('name')->all();
        $administrator = $this->held('Administrator');
        $hr = $this->held('HR Manager');

        // all but: users.impersonate, backup.create/restore, the two per-person permissions (5), plus access administration (6)
        $this->assertSame($this->sorted(array_values(array_diff($all, [
            'users.impersonate', 'backup.create', 'backup.restore', 'access.self-administration', 'department.admin', ...self::ACCESS_ADMINISTRATION,
        ]))), $administrator);
        $this->assertCount(count($all) - 11, $administrator);

        foreach (self::ACCESS_ADMINISTRATION as $permission) {
            $this->assertNotContains($permission, $administrator, "Administrator must not hold {$permission}");
            $this->assertNotContains($permission, $hr, "HR Manager must not hold {$permission}");
        }
        $this->assertNotContains('users.impersonate', $hr);
        $this->assertContains('roles.view', $administrator, 'read access to the role list stays');

        foreach (['monitoring.camera.view', 'leaves.manage'] as $new) {
            $this->assertContains($new, $administrator);
        }
        $this->assertContains('leaves.manage', $hr);
        $this->assertNotContains('monitoring.camera.view', $hr);
    }

    public function test_the_two_new_permissions_exist(): void
    {
        $this->assertSame('om', Permission::where('name', 'monitoring.camera.view')->value('module'));
        $this->assertSame('hrm', Permission::where('name', 'leaves.manage')->value('module'));
    }

    public function test_tasks_permissions_stay_wherever_they_are_held_today(): void
    {
        // Q3: the legacy task endpoints had 12 hits in 90 days, so no tasks.* permission is taken from a holder.
        $this->assertContains('tasks.view', $this->held('Daily Works Contributor'));
        foreach (['tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete', 'tasks.assign'] as $permission) {
            $this->assertContains($permission, $this->held('Department Manager'));
        }
    }

    public function test_re_seeding_is_idempotent_and_repairs_drift(): void
    {
        $before = collect(self::LEVELS)->keys()->mapWithKeys(fn ($r) => [$r => $this->held($r)])->all();

        Role::findByName('Quality Contributor')->givePermissionTo('quality.settings');
        Role::findByName('TMC Operator')->revokePermissionTo('om.toll.view');
        $this->seed(ComprehensiveRolePermissionSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $after = collect(self::LEVELS)->keys()->mapWithKeys(fn ($r) => [$r => $this->held($r)])->all();
        $this->assertSame($before, $after, 'the seeder syncs, it never only adds');

        foreach (RoleCatalog::seed() as $role => $report) {
            $this->assertSame(['added' => 0, 'removed' => 0], $report, "{$role}: nothing left to change");
        }
    }

    public function test_the_default_department_roles_seeded_for_quality_control(): void
    {
        DB::table('departments')->insert(['name' => 'Quality Control', 'code' => 'QC', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->seed(ComprehensiveRolePermissionSeeder::class);

        $this->assertSame(['Daily Works Contributor', 'Quality Contributor'], json_decode(DB::table('departments')->where('name', 'Quality Control')->value('default_roles'), true));
    }

    public function test_the_additive_migration_carries_the_catalog_definitions(): void
    {
        $constants = $this->constants(self::ADDITIVE_MIGRATION);

        foreach (['Quality Manager', 'Daily Works Manager', 'Quality Contributor', 'Line Manager'] as $role) {
            $this->assertSame($this->sorted(RoleCatalog::definitions()[$role]['permissions']), $this->sorted($constants['SETS'][$role]), "{$role}: migration set mirrors the catalog");
            $this->assertSame(RoleCatalog::definitions()[$role]['level'], $constants['ROLES'][$role]['level'], "{$role}: migration level mirrors the catalog");
            $this->assertSame(RoleCatalog::definitions()[$role]['description'], $constants['ROLES'][$role]['description']);
        }
        $this->assertSame(RoleCatalog::NEW_PERMISSIONS, $constants['NEW_PERMISSIONS']);
        $this->assertSame(['Team Lead' => ['Line Manager', null], 'Maintenance Inspector / QC Specialist' => ['Maintenance Inspector', 50]], $constants['RENAMES']);
        $this->assertSame(array_keys(RoleCatalog::RENAMES), array_keys($constants['RENAMES']));
    }

    public function test_the_exact_migration_carries_the_catalog_definitions(): void
    {
        $constants = $this->constants(self::EXACT_MIGRATION);

        foreach (['Department Manager', 'O&M Director', 'Maintenance Inspector', 'TMC Operator', 'Highway Patrol Officer', 'Daily Works Contributor', 'HR Manager'] as $role) {
            $this->assertSame($this->sorted(RoleCatalog::definitions()[$role]['permissions']), $this->sorted($constants['SETS'][$role]), "{$role}: migration set mirrors the catalog");
        }
        $this->assertSame($this->sorted(RoleCatalog::ADMINISTRATOR_EXCLUDED), $this->sorted($constants['ADMINISTRATOR_EXCLUDED']));
        $this->assertSame(RoleCatalog::RETIRED, $constants['RETIRED']);
        $this->assertSame(
            ComprehensiveRolePermissionSeeder::dailyWorksContributorPermissionNames(),
            $constants['SETS']['Daily Works Contributor'],
            'DWC: the seeder helper the earlier migration mirrors still agrees',
        );
    }

    public function test_department_admin_is_still_exactly_its_52_permissions(): void
    {
        $this->assertSame($this->sorted(ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames()), $this->held('Department Admin'));
        $this->assertCount(52, $this->held('Department Admin'));
    }

    public function test_a_catalog_user_resolves_to_the_catalog_through_the_role_model(): void
    {
        $user = User::factory()->create();
        $user->assignRole(['Employee', 'Quality Manager']);
        $this->assertTrue($user->can('quality.ncr.delete'));
        $this->assertFalse($user->can('daily-works.import'));
    }

    /** @return array<string, mixed> the private constants of a migration's anonymous class */
    private function constants(string $migration): array
    {
        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);

        return (new \ReflectionClass($resolve->invoke($migrator, base_path($migration))))->getConstants();
    }
}
