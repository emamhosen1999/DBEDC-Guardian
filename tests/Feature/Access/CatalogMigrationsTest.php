<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditLog;
use App\Models\User;
use App\Services\Access\CatalogPlan;
use App\Services\Access\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The two data migrations of the role catalog, run against a database shaped like production:
 *   A2 (additive): renames by id, creates the new roles, nobody loses anything, idempotent;
 *   A4 (exact):    refuses to run while anybody would lose something outside expected_losses (and then writes NOTHING),
 *                  otherwise trims the roles to the catalog and deletes the retired roles that nobody holds.
 * The RBAC tables are MyISAM in production: both are diff based and a re-run converges.
 */
class CatalogMigrationsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDITIVE = 'database/migrations/2026_10_03_000002_seed_role_catalog_v1_additive.php';

    private const EXACT = 'database/migrations/2026_10_03_000003_sync_role_catalog_v1_exact.php';

    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = CatalogPlan::load()->data['role_sets_before'];
        // start from a database the way production looked: none of the catalog's roles exist yet
        Role::query()->delete();
        DB::table('role_has_permissions')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function migrate(string $path, string $direction = 'up'): void
    {
        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);
        $resolve->invoke($migrator, base_path($path))->{$direction}();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** A role holding exactly $names (permissions created when missing), the way production had it. */
    private function legacyRole(string $name, int $level, array $names): Role
    {
        $role = Role::firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        foreach ($names as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $role->syncPermissions($names);

        return $role;
    }

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

    // ── A2: additive ───────────────────────────────────────────────────────────────────────────────────

    public function test_a2_renames_by_id_creates_the_new_roles_and_removes_nothing_from_anyone(): void
    {
        $teamLead = $this->legacyRole('Team Lead', 40, ['employees.view', 'tasks.view', 'tasks.assign', 'hr.timeoff.approve']);
        $miq = $this->legacyRole('Maintenance Inspector / QC Specialist', 35, $this->before['Maintenance Inspector / QC Specialist']);
        $habibur = User::factory()->create(['employee_id' => '127']);
        $habibur->assignRole($miq);
        // every permission the new sets name exists in production
        foreach (RoleCatalog::mentionedPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->migrate(self::ADDITIVE);

        $this->assertSame($teamLead->id, Role::findByName('Line Manager')->id, 'renamed by id');
        $this->assertSame($miq->id, Role::findByName('Maintenance Inspector')->id);
        $this->assertNull(Role::where('name', 'Team Lead')->first());
        $this->assertNull(Role::where('name', 'Maintenance Inspector / QC Specialist')->first());
        $this->assertSame(50, (int) Role::findByName('Maintenance Inspector')->hierarchy_level);
        $this->assertSame(40, (int) Role::findByName('Line Manager')->hierarchy_level);

        // Line Manager had no holder: it becomes exactly its approved five
        $this->assertSame($this->sorted(RoleCatalog::LINE_MANAGER), $this->held('Line Manager'));
        // Maintenance Inspector keeps ALL 43 permissions until the exact migration, and its holder keeps it
        $this->assertSame($this->sorted($this->before['Maintenance Inspector / QC Specialist']), $this->held('Maintenance Inspector'));
        $this->assertCount(43, $this->held('Maintenance Inspector'));
        $this->assertTrue($habibur->fresh()->hasRole('Maintenance Inspector'));
        $this->assertTrue($habibur->fresh()->can('quality.ncr.delete'));

        foreach (['Quality Manager' => 45, 'Daily Works Manager' => 45, 'Quality Contributor' => 55] as $role => $level) {
            $this->assertSame($level, (int) Role::findByName($role)->hierarchy_level);
            $this->assertSame($this->sorted(RoleCatalog::definitions()[$role]['permissions']), $this->held($role));
        }
        $this->assertSame('om', Permission::where('name', 'monitoring.camera.view')->value('module'));
        $this->assertSame('hrm', Permission::where('name', 'leaves.manage')->value('module'));
    }

    public function test_a2_is_idempotent(): void
    {
        $this->legacyRole('Team Lead', 40, ['employees.view']);
        foreach (RoleCatalog::mentionedPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $this->migrate(self::ADDITIVE);
        $roles = Role::orderBy('id')->get(['id', 'name', 'hierarchy_level'])->toArray();
        $links = DB::table('role_has_permissions')->count();
        $audit = AccessAuditLog::count();

        $this->migrate(self::ADDITIVE);

        $this->assertSame($roles, Role::orderBy('id')->get(['id', 'name', 'hierarchy_level'])->toArray());
        $this->assertSame($links, DB::table('role_has_permissions')->count());
        $this->assertSame($audit, AccessAuditLog::count(), 'nothing changed, nothing logged');
    }

    public function test_a2_skips_a_rename_whose_new_name_is_taken_and_only_adds_to_a_role_that_has_holders(): void
    {
        foreach (RoleCatalog::mentionedPermissions() as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $legacy = $this->legacyRole('Team Lead', 40, ['employees.view']);
        $taken = $this->legacyRole('Line Manager', 40, ['tasks.view']);
        User::factory()->create(['employee_id' => '120'])->assignRole($taken);

        $this->migrate(self::ADDITIVE);

        $this->assertNotNull(Role::where('name', 'Team Lead')->first(), 'the rename is skipped: the name is taken');
        $this->assertSame($legacy->id, Role::where('name', 'Team Lead')->value('id'));
        $this->assertSame($this->sorted([...RoleCatalog::LINE_MANAGER, 'tasks.view']), $this->held('Line Manager'), 'a held role is only added to, never narrowed');
    }

    // ── A4: exact ──────────────────────────────────────────────────────────────────────────────────────

    /** Production before A4: the broad role sets and the people the plan starts from. */
    private function productionBeforeExact(array $holderRoles): void
    {
        foreach ([...RoleCatalog::mentionedPermissions(), ...RoleCatalog::ADMINISTRATOR_EXCLUDED, 'projects.analytics', 'compliance.view', 'hr.timeoff.approve'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        RoleCatalog::seed();

        // the broad sets production holds until A4 (names as renamed by A2)
        $this->legacyRoleSet('Department Manager', 'Department Manager');
        $this->legacyRoleSet('O&M Director', 'O&M Director');
        $this->legacyRoleSet('Maintenance Inspector', 'Maintenance Inspector / QC Specialist');
        $this->legacyRoleSet('TMC Operator', 'TMC Operator');
        // (the plan only embeds the sets of roles its people hold; these two get the catalog set plus what the exact migration removes)
        $this->legacyRole('Highway Patrol Officer', 55, [...RoleCatalog::HIGHWAY_PATROL_OFFICER, 'daily-works.view', 'daily-works.import']);
        $this->legacyRole('HR Manager', 20, [...RoleCatalog::HR_MANAGER, 'employees.access.manage', 'department.scopes.manage', 'users.impersonate']);
        $administrator = Role::findByName('Administrator');
        $administrator->givePermissionTo(['employees.access.manage', 'roles.update']);

        $this->legacyRole('Project Manager', 20, ['daily-works.view', 'tasks.view', 'projects.analytics']);
        $this->legacyRole('Senior Employee', 50, ['tasks.view']);
        $this->legacyRole('Admin', 50, []);

        $bashar = User::factory()->create(['employee_id' => '123']);
        $bashar->syncRoles($holderRoles);
        User::factory()->create(['employee_id' => '306'])->syncRoles(['Employee', 'TMC Operator']);
        User::factory()->create(['employee_id' => '8000'])->syncRoles(['Senior Employee']); // holds a retired role
        User::factory()->create(['employee_id' => '8001'])->syncRoles(['Admin']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function legacyRoleSet(string $role, string $snapshotName): void
    {
        foreach ($this->before[$snapshotName] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        Role::findByName($role)->syncPermissions($this->before[$snapshotName]);
    }

    public function test_a4_refuses_to_run_while_somebody_would_lose_something_and_writes_nothing(): void
    {
        $this->productionBeforeExact(['Department Manager', 'Daily Works Contributor']); // A3 has NOT run: 123 still lacks Quality Manager ...
        $sets = fn () => collect(['Department Manager', 'O&M Director', 'Maintenance Inspector', 'TMC Operator', 'HR Manager', 'Administrator', 'Project Manager'])->mapWithKeys(fn ($r) => [$r => $this->held($r)])->all();
        $before = $sets();

        try {
            $this->migrate(self::EXACT);
            $this->fail('the exact migration must refuse while access:apply-catalog has not moved the people');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('refused', $exception->getMessage());
            $this->assertStringContainsString('123:', $exception->getMessage());
            $this->assertStringContainsString('access:apply-catalog', $exception->getMessage());
        }

        $this->assertSame($before, $sets(), 'the guard runs before any write');
        $this->assertNotNull(Role::where('name', 'Project Manager')->first(), 'no retired role was deleted either');
    }

    public function test_a4_trims_the_roles_to_the_catalog_and_deletes_only_unheld_retired_roles(): void
    {
        $plan = CatalogPlan::load();
        $this->productionBeforeExact($plan->targetRoles('123')); // A3 has run for 123

        $this->migrate(self::EXACT);

        foreach (['Department Manager', 'O&M Director', 'Maintenance Inspector', 'TMC Operator', 'Highway Patrol Officer', 'HR Manager'] as $role) {
            $this->assertSame($this->sorted(RoleCatalog::definitions()[$role]['permissions']), $this->held($role), "{$role} is exact");
        }
        $this->assertContains('tasks.assign', $this->held('Department Manager'), 'Q3: tasks.* stays');
        $this->assertNotContains('daily-works.view', $this->held('TMC Operator'), 'O-5');

        $administrator = $this->held('Administrator');
        $this->assertNotContains('employees.access.manage', $administrator);
        $this->assertNotContains('roles.update', $administrator);
        $this->assertContains('monitoring.camera.view', $administrator);
        $this->assertContains('leaves.manage', $administrator);

        $this->assertNull(Role::where('name', 'Project Manager')->first(), 'no holder: deleted');
        $this->assertNotNull(Role::where('name', 'Senior Employee')->first(), 'a holder: kept');
        $this->assertNotNull(Role::where('name', 'Admin')->first(), 'a holder: kept (the apply command detaches the orphans, then deletes it)');

        // 123 still has everything he used: the roles he got in A3 cover what Department Manager lost
        $bashar = User::find('123');
        foreach (['quality.ncr.delete', 'daily-works.import', 'employees.update', 'tasks.view'] as $permission) {
            $this->assertTrue($bashar->can($permission), "123 keeps {$permission}");
        }
        $this->assertGreaterThan(0, AccessAuditLog::where('action', 'catalog.a4.role.permissions')->count());
    }

    public function test_a4_is_idempotent_and_down_only_ever_adds(): void
    {
        $this->productionBeforeExact(CatalogPlan::load()->targetRoles('123'));
        $this->migrate(self::EXACT);
        $state = DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray();

        $this->migrate(self::EXACT);
        $this->assertEquals($state, DB::table('role_has_permissions')->orderBy('role_id')->orderBy('permission_id')->get()->toArray());

        $this->migrate(self::EXACT, 'down');
        $this->assertGreaterThanOrEqual(count($state), DB::table('role_has_permissions')->count(), 'down restores the previous sets additively');
        $this->assertContains('hr.timeoff.approve', $this->held('Department Manager'));
        $this->assertContains('daily-works.view', $this->held('TMC Operator'));
        $this->assertContains('compliance.view', $this->held('O&M Director'));
    }
}
