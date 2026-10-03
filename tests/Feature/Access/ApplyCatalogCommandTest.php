<?php

namespace Tests\Feature\Access;

use App\Models\AccessAuditLog;
use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\CatalogApplier;
use App\Services\Access\CatalogPlan;
use App\Services\Admin\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Access\Concerns\BuildsCatalogWorld;
use Tests\TestCase;

/**
 * `php artisan access:apply-catalog`: the RBAC tables are MyISAM in production, so nothing may rely on a rollback.
 * What is proved here instead: dry-run writes nothing; apply refuses without a backup of the SAME plan hash (and without
 * a Super Administrator, a reason or the matching hash); apply converges, is idempotent and finishes a half-done run;
 * every pivot write attaches before it detaches; the result is verified and drift exits non-zero; a backup restores.
 */
class ApplyCatalogCommandTest extends TestCase
{
    use BuildsCatalogWorld;
    use RefreshDatabase;

    private string $backups;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCatalogWorld('current');

        // production as the plan found it: only Daily Works Contributor is a Quality Control default
        Department::whereKey(11)->update(['default_roles' => json_encode(['Daily Works Contributor'])]);
        // the 31 orphan role rows (here: three of them) and the empty `Admin` role they keep alive
        $admin = Role::create(['name' => 'Admin', 'guard_name' => 'web', 'hierarchy_level' => 50]);
        foreach (['8001', '8002'] as $id) {
            $ghost = User::factory()->create(['employee_id' => $id, 'department_id' => 11]);
            $ghost->syncRoles(['Employee', 'Daily Works Contributor', $id === '8001' ? 'Admin' : 'TMC Operator']);
            $ghost->delete();
        }
        DB::table('model_has_roles')->insert(['role_id' => $admin->id, 'model_type' => User::class, 'model_id' => '8999']); // a user that no longer exists at all
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->backups = storage_path('app/access-backups');
        File::deleteDirectory($this->backups);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->backups);
        parent::tearDown();
    }

    private function hash(): string
    {
        return CatalogPlan::load()->hash();
    }

    private function rolesOf(string $id): array
    {
        $roles = User::find($id)->roles()->pluck('name')->all();
        sort($roles);

        return $roles;
    }

    private function targetOf(string $id): array
    {
        $roles = CatalogPlan::load()->targetRoles($id);
        sort($roles);

        return $roles;
    }

    private function apply(array $extra = []): PendingCommand
    {
        return $this->artisan('access:apply-catalog', array_merge(['--apply' => true, '--actor' => '151', '--reason' => 'catalog v1 rollout', '--plan-hash' => $this->hash()], $extra));
    }

    private function snapshotOfAccess(): array
    {
        return json_decode(json_encode([
            DB::table('model_has_roles')->orderBy('role_id')->orderBy('model_id')->get(),
            DB::table('model_has_permissions')->orderBy('permission_id')->orderBy('model_id')->get(),
            DB::table('roles')->orderBy('id')->get(['id', 'name', 'hierarchy_level']),
            DB::table('departments')->orderBy('id')->pluck('default_roles', 'id'),
        ]), true);
    }

    public function test_the_dry_run_is_the_default_prints_the_hash_and_writes_nothing(): void
    {
        $before = $this->snapshotOfAccess();

        $this->artisan('access:apply-catalog')
            ->expectsOutputToContain('Plan hash: '.$this->hash())
            ->expectsOutputToContain('Dry-run only')
            ->assertSuccessful();
        $this->artisan('access:apply-catalog', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame($before, $this->snapshotOfAccess());
        $this->assertDirectoryDoesNotExist($this->backups);
        $this->assertSame(0, AccessAuditLog::where('action', 'catalog.apply')->count());
    }

    public function test_apply_refuses_without_a_backup_of_the_same_plan_hash(): void
    {
        $before = $this->snapshotOfAccess();

        $this->apply()->expectsOutputToContain('no complete backup')->assertFailed();
        $this->assertSame($before, $this->snapshotOfAccess());

        // a backup of ANOTHER plan hash does not count
        File::ensureDirectoryExists($this->backups.'/20200101_000000_deadbeef');
        File::put($this->backups.'/20200101_000000_deadbeef/manifest.json', json_encode(['plan_hash' => 'deadbeef', 'complete' => true]));
        $this->apply()->assertFailed();

        // ...and neither does an unfinished one (no manifest, or complete = false)
        File::ensureDirectoryExists($this->backups.'/20260101_000000_'.substr($this->hash(), 0, 8));
        File::put($this->backups.'/20260101_000000_'.substr($this->hash(), 0, 8).'/manifest.json', json_encode(['plan_hash' => $this->hash(), 'complete' => false]));
        $this->apply()->assertFailed();
        $this->assertSame($before, $this->snapshotOfAccess());
    }

    public function test_apply_refuses_a_wrong_hash_actor_or_missing_reason(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        $before = $this->snapshotOfAccess();

        $this->apply(['--plan-hash' => str_repeat('0', 64)])->expectsOutputToContain('does not match the plan')->assertFailed();
        $this->apply(['--actor' => '120'])->expectsOutputToContain('Super Administrator')->assertFailed(); // an ordinary employee
        $this->apply(['--actor' => '9999'])->assertFailed();
        $this->apply(['--reason' => ''])->assertExitCode(2);

        $this->assertSame($before, $this->snapshotOfAccess());
    }

    public function test_the_backup_is_written_before_anything_else_and_carries_the_plan_hash(): void
    {
        $before = $this->snapshotOfAccess();

        $this->artisan('access:apply-catalog', ['--backup' => true])->expectsOutputToContain('Backup written')->assertSuccessful();

        $this->assertSame($before, $this->snapshotOfAccess(), 'a backup run writes no access change');
        $directories = glob($this->backups.'/*', GLOB_ONLYDIR);
        $this->assertCount(1, $directories);
        $manifest = json_decode(file_get_contents($directories[0].'/manifest.json'), true);
        $this->assertSame($this->hash(), $manifest['plan_hash']);
        $this->assertTrue($manifest['complete']);
        foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'departments'] as $table) {
            $this->assertFileExists("{$directories[0]}/{$table}.json");
        }
        $this->assertCount(DB::table('model_has_roles')->count(), json_decode(file_get_contents($directories[0].'/model_has_roles.json'), true));
        $this->assertSame('["Daily Works Contributor"]', collect(json_decode(file_get_contents($directories[0].'/departments.json'), true))->firstWhere('id', 11)['default_roles']);
    }

    public function test_backup_then_apply_converges_to_the_plan_and_verifies(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        $epoch = (int) DB::table('users')->where('employee_id', '127')->value('sync_epoch');
        $untouchedEpoch = (int) DB::table('users')->where('employee_id', '154')->value('sync_epoch');

        $this->apply()->expectsOutputToContain('Applied and verified')->assertSuccessful();

        foreach (CatalogPlan::load()->users() as $id => $entry) {
            $this->assertSame($this->targetOf((string) $id), $this->rolesOf((string) $id), "{$id}: roles equal the plan");
        }
        $this->assertSame(['access.self-administration'], User::find('1537')->permissions()->pluck('name')->all(), 'Mahdi keeps his documented exception');
        $this->assertSame([], User::find('120')->permissions()->pluck('name')->all(), 'the redundant direct leave.own.view is gone');
        $this->assertSame([], User::find('169')->permissions()->pluck('name')->all());
        $this->assertSame(['Daily Works Contributor', 'Quality Contributor'], Department::find(11)->default_roles);
        $this->assertSame(0, DB::table('model_has_roles')->where('model_type', User::class)->whereNotIn('model_id', User::pluck('employee_id')->all())->count(), 'no role row on a deleted or missing user');
        $this->assertNull(Role::where('name', 'Admin')->first(), 'Admin is deleted once nobody holds it');
        $this->assertGreaterThan($epoch, (int) DB::table('users')->where('employee_id', '127')->value('sync_epoch'), 'a role change bumps the mobile sync epoch');
        $this->assertSame($untouchedEpoch, (int) DB::table('users')->where('employee_id', '154')->value('sync_epoch'), 'an unchanged user is not bumped');
        $this->assertContains('Super Administrator', $this->rolesOf('151'));
    }

    public function test_the_apply_is_audited_with_actor_reason_and_plan_hash(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        $this->apply()->assertSuccessful();

        $row = AccessAuditLog::where('action', 'catalog.apply')->where('subject_id', '127')->firstOrFail();
        $this->assertSame('151', $row->actor_id);
        $this->assertSame('catalog v1 rollout', $row->reason);
        $this->assertSame($this->hash(), $row->plan_hash);
        $this->assertContains('Maintenance Inspector', $row->before['roles']);
        $this->assertContains('Quality Manager', $row->after['roles']);
        $this->assertContains('leave.own.view', $row->before['direct_permissions']);
        $this->assertSame([], $row->after['direct_permissions']);

        // the Spatie events feed the same ledger, with the same context
        $attached = AccessAuditLog::where('action', 'role.attached')->where('subject_id', '127')->whereNotNull('plan_hash')->first();
        $this->assertNotNull($attached);
        $this->assertSame('151', $attached->actor_id);
        $this->assertSame($this->hash(), $attached->plan_hash);
        $this->assertContains('Quality Manager', $attached->after['roles']);

        $this->assertSame(0, AccessAuditLog::where('action', 'catalog.apply')->where('subject_id', '154')->count(), 'an unchanged user writes no row');
        $this->assertSame(1, AccessAuditLog::where('action', 'catalog.apply.default_roles')->count());
        $this->assertSame(1, AccessAuditLog::where('action', 'catalog.apply.role_deleted')->count());
    }

    public function test_a_second_apply_changes_nothing(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        $this->apply()->assertSuccessful();
        $state = $this->snapshotOfAccess();
        $audit = AccessAuditLog::count();
        $epoch = (int) DB::table('users')->where('employee_id', '127')->value('sync_epoch');

        $this->apply()->expectsOutputToContain('Applied and verified')->assertSuccessful();

        $this->assertSame($state, $this->snapshotOfAccess());
        $this->assertSame($audit, AccessAuditLog::count(), 'idempotent: no new audit rows');
        $this->assertSame($epoch, (int) DB::table('users')->where('employee_id', '127')->value('sync_epoch'));
    }

    public function test_an_interrupted_run_is_finished_by_running_it_again(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();

        // a run that died halfway: some people converted, one with only part of his target, the defaults and orphans untouched
        $service = app(UserManagementService::class);
        $service->reconcileRoles(User::find('123'), $this->targetOf('123'));
        User::find('127')->assignRole('Quality Manager');
        $service->reconcileRoles(User::find('169'), ['Employee', 'Department Admin', 'Department Manager', 'Daily Works Contributor', 'Quality Manager']);

        $this->apply()->assertSuccessful();

        foreach (CatalogPlan::load()->users() as $id => $entry) {
            $this->assertSame($this->targetOf((string) $id), $this->rolesOf((string) $id), "{$id}");
        }
        $this->assertSame([], app(CatalogApplier::class)->verify(CatalogPlan::load()));
    }

    public function test_roles_are_attached_before_any_are_detached_so_nobody_is_ever_left_without(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            if (str_contains($query->sql, 'model_has_roles')) {
                $statements[] = strtok(ltrim($query->sql), ' ');
            }
        });

        app(UserManagementService::class)->reconcileRoles(User::find('127'), ['Employee', 'Daily Works Contributor', 'Quality Manager']); // swaps MI for QM

        $insert = array_search('insert', $statements, true);
        $delete = array_search('delete', $statements, true);
        $this->assertNotFalse($insert);
        $this->assertNotFalse($delete);
        $this->assertLessThan($delete, $insert, 'attach first, detach second');
        $this->assertSame(['Daily Works Contributor', 'Employee', 'Quality Manager'], $this->rolesOf('127'));
    }

    public function test_verification_drift_exits_non_zero_and_is_reported(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        // somebody else changes access while the run is in flight: after the last changed user (1536) is written,
        // a role lands on 7, who was verified-correct a moment ago
        $role = Role::findByName('Quality Manager');
        Event::listen(RoleAttached::class, function (RoleAttached $event) use ($role) {
            if ((string) $event->model->getKey() === '1536') {
                DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => User::class, 'model_id' => '7']);
            }
        });

        $this->apply()->expectsOutputToContain('DRIFT: 7: roles are')->expectsOutputToContain('Verification FAILED')->assertFailed();

        $this->assertContains('Quality Manager', $this->rolesOf('7'), 'the drift is really in the database, and was reported rather than hidden');
    }

    public function test_verify_finds_real_drift(): void
    {
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        $this->apply()->assertSuccessful();
        $applier = app(CatalogApplier::class);
        $this->assertSame([], $applier->verify(CatalogPlan::load()));

        User::find('123')->removeRole('Quality Contributor');
        User::find('120')->givePermissionTo('quality.view'); // covered by the Quality Contributor role: not a loss when revoked
        Department::whereKey(11)->update(['default_roles' => json_encode(['Daily Works Contributor'])]);

        $drift = implode("\n", $applier->verify(CatalogPlan::load()));
        $this->assertStringContainsString('123: roles are', $drift);
        $this->assertStringContainsString('120: direct permissions are', $drift);
        $this->assertStringContainsString('department 11: default_roles', $drift);

        $this->apply()->assertSuccessful(); // converges again
        $this->assertSame([], $applier->verify(CatalogPlan::load()));
    }

    public function test_a_plan_that_would_take_something_in_use_aborts_the_dry_run_and_writes_nothing(): void
    {
        $edited = CatalogPlan::load()->data;
        $edited['users']['123']['target']['roles'] = ['Employee', 'Daily Works Contributor']; // quietly drops Department Manager, Quality Manager ...
        $path = $this->tempPlan($edited);
        $before = $this->snapshotOfAccess();

        $this->artisan('access:apply-catalog', ['--plan' => $path])->expectsOutputToContain('nothing was written')->assertFailed();
        $this->artisan('access:apply-catalog', ['--plan' => $path, '--backup' => true])->assertFailed();

        $this->assertSame($before, $this->snapshotOfAccess());
        $report = app(CatalogApplier::class)->plan(CatalogPlan::load($path));
        $this->assertStringContainsString('would lose permissions not in expected_losses', implode("\n", $report['errors']));
    }

    public function test_the_dry_run_aborts_on_a_missing_user_a_missing_role_and_a_user_without_employee(): void
    {
        $edited = CatalogPlan::load()->data;
        User::withTrashed()->where('employee_id', '154')->forceDelete();
        Role::findByName('Quality Contributor')->delete();
        $edited['users']['155']['target']['roles'] = ['Line Manager'];
        $errors = implode("\n", app(CatalogApplier::class)->plan(CatalogPlan::load($this->tempPlan($edited)))['errors']);

        $this->assertStringContainsString('154 (Shajahan Ali): user not found', $errors);
        $this->assertStringContainsString("target role 'Quality Contributor' does not exist", $errors);
        $this->assertStringContainsString('155: every active user must keep the Employee role', $errors);
        $this->assertStringContainsString('155 (Razib Mia): would not hold the Employee role', $errors);
    }

    public function test_an_active_user_outside_the_plan_without_employee_aborts_and_one_with_it_only_warns(): void
    {
        User::factory()->create(['employee_id' => '9100', 'department_id' => 30])->syncRoles(['Employee']);
        User::factory()->create(['employee_id' => '9101', 'department_id' => 30])->syncRoles(['TMC Operator']);

        $report = app(CatalogApplier::class)->plan(CatalogPlan::load());

        $this->assertStringContainsString('9101: active user is not in the plan and lacks the Employee role', implode("\n", $report['errors']));
        $this->assertContains('9100: active user is not in the plan; left unchanged', $report['warnings']);
    }

    public function test_segregation_of_duties_is_checked_before_anything_is_written(): void
    {
        $approve = Role::create(['name' => 'Settlement Approver', 'guard_name' => 'web', 'hierarchy_level' => 50]);
        $approve->givePermissionTo(Permission::findOrCreate('hr.settlement.approve', 'web'));
        $disburse = Role::create(['name' => 'Settlement Disburser', 'guard_name' => 'web', 'hierarchy_level' => 50]);
        $disburse->givePermissionTo(Permission::findOrCreate('hr.settlement.disburse', 'web'));
        $edited = CatalogPlan::load()->data;
        $edited['users']['154']['target']['roles'] = ['Employee', 'Settlement Approver', 'Settlement Disburser'];

        $errors = implode("\n", app(CatalogApplier::class)->plan(CatalogPlan::load($this->tempPlan($edited)))['errors']);

        $this->assertStringContainsString('154 (Shajahan Ali): segregation of duties violated (S5 final settlement', $errors);
    }

    public function test_a_soft_deleted_planned_user_is_skipped_not_resurrected(): void
    {
        User::find('154')->delete();
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();

        $this->apply()->assertSuccessful();

        $this->assertTrue(User::withTrashed()->find('154')->trashed(), 'never resurrected');
        $this->assertSame([], User::withTrashed()->find('154')->roles()->pluck('name')->all(), 'a deleted user holds no role row once the orphans are detached');
    }

    public function test_restore_puts_a_backup_back_through_the_same_path(): void
    {
        $before = $this->snapshotOfAccess();
        $this->artisan('access:apply-catalog', ['--backup' => true])->assertSuccessful();
        $directory = glob($this->backups.'/*', GLOB_ONLYDIR)[0];
        $this->apply()->assertSuccessful();
        $this->assertNotSame($before, $this->snapshotOfAccess());

        $this->artisan('access:apply-catalog', ['--restore' => $directory, '--actor' => '151', '--reason' => 'rollback of catalog v1'])->assertSuccessful();

        $this->assertSame($before, $this->snapshotOfAccess(), 'roles, holders, permissions and default roles are all back');
        $this->assertNotNull(Role::where('name', 'Admin')->first(), 'the deleted Admin role returns with its holders');
        $this->assertSame(1, AccessAuditLog::where('action', 'catalog.restore.role')->count());
        $this->assertSame('rollback of catalog v1', AccessAuditLog::where('action', 'catalog.restore.default_roles')->firstOrFail()->reason);
    }

    public function test_restore_refuses_an_incomplete_backup_and_a_non_super_administrator(): void
    {
        File::ensureDirectoryExists($this->backups.'/half');
        $this->artisan('access:apply-catalog', ['--restore' => $this->backups.'/half', '--actor' => '151', '--reason' => 'x'])->assertFailed();
        $this->artisan('access:apply-catalog', ['--restore' => $this->backups.'/half', '--actor' => '120', '--reason' => 'x'])->assertFailed();
    }

    /** @param array<string, mixed> $plan */
    private function tempPlan(array $plan): string
    {
        $path = tempnam(sys_get_temp_dir(), 'plan');
        file_put_contents($path, json_encode($plan));

        return $path;
    }
}
