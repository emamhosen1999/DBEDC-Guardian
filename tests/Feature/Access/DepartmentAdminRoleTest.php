<?php

namespace Tests\Feature\Access;

use App\Jobs\ExportAttendanceReport;
use App\Models\FeatureFlag;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\CoverageRequirement;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Models\UserDevice;
use App\Models\WorkLocation;
use App\Services\Access\DepartmentScope;
use App\Services\Attendance\AttendanceReportService;
use App\Services\FeatureFlagService;
use Carbon\Carbon;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Department Admin = a department-only HR operator. The ROLE defines what he may do; his HOME
 * DEPARTMENT defines on whom — user_department_scopes grants stay optional (exceptions only).
 *
 * D1 = his department, D2 = somebody else's. Everything below proves a D1 admin sees and edits
 * D1 only, that the modules outside his menu are closed, and that the routes his `users.update`
 * permission opens are safe for a scoped actor.
 */
class DepartmentAdminRoleTest extends TestCase
{
    use RefreshDatabase;

    /** Migrations that define the role, in order: the original 40, then the delegated-administrator split. */
    private const MIGRATIONS = [
        'database/migrations/2026_09_30_000005_seed_department_scope_permissions_and_role.php',
        'database/migrations/2026_10_01_000001_split_employee_permissions_and_extend_department_admin.php',
    ];

    /** The 40 permissions the role held before the delegated-administrator split. */
    private const PREVIOUS_SPEC = [
        'department.admin',
        'core.dashboard.view', 'core.stats.view', 'core.updates.view',
        'attendance.own.view', 'attendance.own.punch',
        'leave.own.view', 'leave.own.create', 'leave.own.update', 'leave.own.delete',
        'communications.own.view',
        'profile.own.view', 'profile.own.update', 'profile.password.change',
        'employees.view', 'employees.create', 'employees.update',
        'users.create', 'users.update',
        'attendance.view', 'attendance.create', 'attendance.update', 'attendance.correct', 'attendance.export',
        'attendance.roster.manage',
        'leaves.view', 'leaves.create', 'leaves.update', 'leaves.approve', 'leaves.delete',
        'hr.onboarding.view', 'hr.onboarding.create', 'hr.onboarding.update', 'hr.onboarding.delete',
        'hr.offboarding.view', 'hr.offboarding.create', 'hr.offboarding.update', 'hr.offboarding.delete',
        'hr.assets.view', 'hr.assets.manage',
    ];

    /**
     * What the delegated department administrator gained: delete/restore, designation CRUD for his own
     * department and every granular employee permission EXCEPT access (roles) management.
     */
    private const ADDITIONS = [
        'employees.delete', 'employees.restore',
        'designations.view', 'designations.create', 'designations.update', 'designations.delete',
        'employees.placement.update', 'employees.attendance-config.update',
        'employees.compensation.view', 'employees.compensation.update',
        'employees.password.reset', 'employees.devices.manage',
    ];

    /** Real permissions the role must NOT hold (they exist in the DB, so a leak would show). */
    private const EXCLUDED = [
        'attendance.settings', 'attendance.delete', 'attendance.import',
        'users.view', 'users.delete', 'employees.export', 'employees.import', 'employees.access.manage',
        'departments.view', 'departments.create', 'departments.update', 'departments.delete', 'jurisdiction.view',
        'roles.view', 'roles.update', 'permissions.assign', 'department.scopes.manage',
        'daily-works.view', 'daily-works.own.view', 'quality.ncr.view',
        'holidays.view', 'holidays.create',
        'hr.payroll.view', 'hr.payroll.process', 'hr.settlement.manage', 'hr.settlement.approve', 'hr.settlement.disburse',
        'hr.probation.manage', 'performance-reviews.view', 'hr.skills.view', 'hr.benefits.view',
        'hr.safety.view', 'hr.documents.view', 'hr.analytics.view', 'leaves.analytics',
        'settings.view', 'company.settings', 'leave-settings.update', 'notifications.settings',
        'om.dashboard.view', 'monitoring.camera.view', 'petty-cash.approve',
    ];

    private const DAY = '2026-06-03'; // a Wednesday, and "today" for the whole test

    private Department $d1;

    private Department $d2;

    private User $admin;      // Department Admin of D1 — role + home department ONLY, no grants

    private User $peer;       // another Department Admin in D1 (equal rank)

    private User $hrInD1;     // an HR Manager who happens to sit in D1 (outranks the admin)

    private User $hr;         // global HR

    private User $e1;         // D1 employee (punched today)

    private User $e1b;        // D1 employee (no punch: absent)

    private User $e2;         // D2 employee (punched today)

    private User $e2b;        // D2 employee (no punch: absent)

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse(self::DAY.' 20:00:00'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20, 'Department Admin' => 25, 'Department Manager' => 30, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (array_merge(self::PREVIOUS_SPEC, self::ADDITIONS, self::EXCLUDED) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        // Global roles hold everything, so any denial below is about SCOPE or a missing permission.
        foreach (['HR Manager', 'Administrator'] as $globalRole) {
            Role::findByName($globalRole)->syncPermissions(Permission::all());
        }

        // The production data migration syncs the role to the spec list.
        $this->runMigration();

        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['saturday', 'sunday'], 'auto_punch_out' => false,
        ]);

        [$this->d1, $this->d2] = [Department::factory()->create(), Department::factory()->create()];

        $this->admin = $this->makeUser('Department Admin', $this->d1, 'Dee One Admin');
        $this->peer = $this->makeUser('Department Admin', $this->d1, 'Peer Admin');
        $this->hrInD1 = $this->makeUser('HR Manager', $this->d1, 'Hr Inside DeptOne');
        $this->hr = $this->makeUser('HR Manager', null, 'Global Hr');
        $this->e1 = $this->makeUser('Employee', $this->d1, 'Scopetarget One');
        $this->e1b = $this->makeUser('Employee', $this->d1, 'Scopetarget OneB');
        $this->e2 = $this->makeUser('Employee', $this->d2, 'Scopetarget Two');
        $this->e2b = $this->makeUser('Employee', $this->d2, 'Scopetarget TwoB');

        $this->punch($this->e1);
        $this->punch($this->e2);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── fixtures ───────────────────────────────────────────────────────────────

    /** Run the role migrations again (idempotent) through the migrator's own path cache. */
    private function runMigration(): void
    {
        $migrator = app('migrator');
        $resolve = new \ReflectionMethod($migrator, 'resolvePath');
        $resolve->setAccessible(true);
        foreach (self::MIGRATIONS as $migration) {
            $resolve->invoke($migrator, base_path($migration))->up();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The exact permission set of the role today. */
    private function spec(): array
    {
        return array_merge(self::PREVIOUS_SPEC, self::ADDITIONS);
    }

    private function makeUser(string $role, ?Department $department, string $name): User
    {
        $designation = Designation::factory()->create();
        $user = User::factory()->create(['department_id' => $department?->id, 'designation_id' => $designation->id, 'name' => $name]);
        $user->assignRole($role);

        return $user;
    }

    private function punch(User $user, string $date = self::DAY, array $extra = []): Attendance
    {
        return Attendance::factory()->for($user)->create(array_merge([
            'date' => $date, 'punchin' => "{$date} 09:00:00", 'punchout' => "{$date} 17:00:00",
        ], $extra));
    }

    private function makeDevice(User $user): UserDevice
    {
        return UserDevice::create([
            'user_id' => $this->id($user), 'device_id' => 'dev-'.$this->id($user), 'device_token' => 'tok-'.$this->id($user), 'device_name' => 'Test Phone',
            'device_type' => 'mobile', 'platform' => 'android', 'is_active' => true, 'last_used_at' => now(),
        ]);
    }

    private function id(User $user): string
    {
        return (string) $user->employee_id;
    }

    /** @param  array<int, string>  $expected */
    private function assertPermissionSet(array $expected, Role $role): void
    {
        $actual = $role->permissions()->pluck('name')->all();
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    /** The response mentions the visible person and never the hidden one. */
    private function assertOnly(string $body, User $visible, User $hidden, string $label = ''): void
    {
        $this->assertStringContainsString($this->id($visible), $body, "{$label}: should show {$visible->name}");
        $this->assertStringNotContainsString($this->id($hidden), $body, "{$label}: must not show {$hidden->name}");
        $this->assertStringNotContainsString($hidden->name, $body, "{$label}: must not show {$hidden->name}");
    }

    private function as(User $user): self
    {
        return $this->actingAs($user);
    }

    private function newUserPayload(?int $departmentId, array $extra = []): array
    {
        static $n = 0;
        $n++;

        return array_merge([
            'name' => "New Hire {$n}", 'user_name' => "newhire{$n}", 'email' => "new.hire{$n}@example.com",
            'employee_id' => "NEW-{$n}", 'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
            'department_id' => $departmentId, 'attendance_type_ids' => [AttendanceType::query()->where('is_active', true)->value('id') ?? AttendanceType::factory()->create(['is_active' => true])->id],
        ], $extra);
    }

    // ── 1. the role is EXACTLY the spec ───────────────────────────────────────

    public function test_migration_leaves_the_role_with_exactly_the_spec_permissions(): void
    {
        $this->assertCount(40, self::PREVIOUS_SPEC);
        $this->assertCount(12, self::ADDITIONS);
        $this->assertCount(52, $this->spec());
        $this->assertPermissionSet($this->spec(), Role::findByName('Department Admin'));
    }

    public function test_the_seeder_definition_mirrors_the_migration(): void
    {
        $seeded = ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames();
        sort($seeded);
        $spec = $this->spec();
        sort($spec);

        $this->assertSame($spec, $seeded);
    }

    public function test_none_of_the_excluded_permissions_is_held(): void
    {
        foreach (self::EXCLUDED as $permission) {
            $this->assertFalse($this->admin->can($permission), "Department Admin must not hold {$permission}");
        }
        $this->assertTrue($this->admin->hasRole('Department Admin'));
    }

    public function test_rerunning_the_migration_narrows_a_broader_role_and_settings_holders_gain_roster_manage(): void
    {
        $role = Role::findByName('Department Admin');
        $role->givePermissionTo(['attendance.settings', 'users.delete', 'roles.view', 'employees.access.manage']);
        $officer = Role::create(['name' => 'Attendance Officer', 'guard_name' => 'web', 'hierarchy_level' => 40]);
        $officer->givePermissionTo('attendance.settings');
        $this->assertFalse($officer->hasPermissionTo('attendance.roster.manage'));

        $this->runMigration();

        $this->assertPermissionSet($this->spec(), $role->fresh());
        $this->assertTrue($officer->fresh()->hasPermissionTo('attendance.roster.manage'));
    }

    // ── 2. role + home department alone is sufficient ─────────────────────────

    public function test_role_plus_home_department_alone_scopes_to_that_department(): void
    {
        $this->assertSame(0, UserDepartmentScope::count(), 'no grants exist anywhere');
        $scope = app(DepartmentScope::class);

        $this->assertSame([$this->d1->id], $scope->managedDepartmentIds($this->admin));

        $d1Members = User::where('department_id', $this->d1->id)->pluck('employee_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
        $visible = collect($scope->visibleEmployeeIds($this->admin))->sort()->values()->all();
        $this->assertSame($d1Members, $visible);
        $this->assertTrue($scope->canActOn($this->admin, $this->e1));
        $this->assertFalse($scope->canActOn($this->admin, $this->e2));
        $this->assertFalse($scope->isGlobal($this->admin));
    }

    public function test_a_department_admin_without_a_home_department_fails_closed(): void
    {
        $homeless = $this->makeUser('Department Admin', null, 'Homeless Admin');
        $scope = app(DepartmentScope::class);

        $this->assertSame([], $scope->managedDepartmentIds($homeless));
        $this->assertSame([$this->id($homeless)], $scope->visibleEmployeeIds($homeless));
        $this->assertFalse($scope->canActOn($homeless, $this->e1));
    }

    public function test_hr_creating_a_department_admin_with_role_and_department_is_sufficient(): void
    {
        $created = $this->as($this->hr)->postJson(route('users.store'), $this->newUserPayload($this->d1->id, ['roles' => ['Department Admin']]))
            ->assertCreated();
        $newAdmin = User::where('employee_id', $created->json('user.employee_id'))->firstOrFail();

        $this->assertTrue($newAdmin->hasRole('Department Admin'));
        $this->assertSame(0, UserDepartmentScope::count(), 'role + department, no grant rows');

        $this->assertTrue((bool) $newAdmin->must_change_password, 'an admin-set password must be replaced at first sign-in');
        $newAdmin->forceFill(['must_change_password' => false])->save();

        $body = $this->as($newAdmin)->getJson(route('employees.paginate', ['perPage' => 50]))->assertOk()->getContent();
        $this->assertOnly($body, $this->e1, $this->e2, 'new department admin');
    }

    public function test_hr_cannot_mint_a_role_equal_or_above_their_own(): void
    {
        foreach (['Administrator', 'Super Administrator', 'HR Manager'] as $role) {
            $this->as($this->hr)->postJson(route('users.store'), $this->newUserPayload($this->d1->id, ['roles' => [$role]]))
                ->assertForbidden();
        }
    }

    // ── 3. employees: view / create / update, never delete ───────────────────

    public function test_the_employee_directory_is_limited_to_the_home_department(): void
    {
        $body = $this->as($this->admin)->getJson(route('employees.paginate', ['perPage' => 50]))->assertOk()->getContent();
        $this->assertOnly($body, $this->e1, $this->e2, 'directory');

        $this->as($this->admin)->getJson(route('employees.show', $this->id($this->e1)))->assertOk();
        $this->as($this->admin)->getJson(route('employees.show', $this->id($this->e2)))->assertNotFound();
    }

    public function test_a_department_admin_creates_updates_and_deactivates_inside_his_department(): void
    {
        // Asking for a privileged role is ignored: every employee he creates is a plain base-role Employee.
        $created = $this->as($this->admin)->postJson(route('users.store'), $this->newUserPayload($this->d1->id, ['roles' => ['Department Admin']]))
            ->assertCreated();
        $newcomer = User::where('employee_id', $created->json('user.employee_id'))->firstOrFail();
        $this->assertSame(['Employee'], $newcomer->roles->pluck('name')->all());
        $this->assertSame($this->d1->id, (int) $newcomer->department_id);

        $this->as($this->admin)->postJson(route('users.store'), $this->newUserPayload($this->d2->id))->assertStatus(422);

        $this->as($this->admin)->putJson(route('users.update', $this->id($this->e1)), ['name' => 'Renamed One'])->assertOk();
        $this->as($this->admin)->putJson(route('users.update', $this->id($this->e2)), ['name' => 'Renamed Two'])->assertForbidden();

        // Delete = deactivate (soft delete), restore reinstates; both stay inside his department.
        $this->as($this->admin)->deleteJson(route('users.destroy', $this->id($this->e1)))->assertOk();
        $this->assertSoftDeleted('users', ['employee_id' => $this->id($this->e1)]);
        $this->as($this->admin)->postJson(route('users.restore', $this->id($this->e1)))->assertOk();
        $this->assertNotNull(User::find($this->id($this->e1)));

        $this->as($this->admin)->deleteJson(route('users.destroy', $this->id($this->e2)))->assertForbidden();
        $this->assertNotNull(User::find($this->id($this->e2)), 'out of scope: untouched');
        $this->as($this->admin)->deleteJson(route('users.destroy', $this->id($this->admin)))->assertForbidden();
        $this->assertNotNull(User::find($this->id($this->admin)), 'never himself');
    }

    public function test_bulk_delete_is_all_or_nothing_inside_the_scope(): void
    {
        $this->as($this->admin)->postJson(route('users.bulk.delete'), ['user_ids' => [$this->id($this->e1), $this->id($this->e2)]])->assertForbidden();
        $this->assertNotNull(User::find($this->id($this->e1)));
        $this->assertNotNull(User::find($this->id($this->e2)));

        $this->as($this->admin)->postJson(route('users.bulk.delete'), ['user_ids' => [$this->id($this->e1), $this->id($this->e1b)]])->assertOk()->assertJsonPath('deleted_count', 2);
        $this->assertSoftDeleted('users', ['employee_id' => $this->id($this->e1)]);
        $this->assertSoftDeleted('users', ['employee_id' => $this->id($this->e1b)]);
    }

    public function test_he_cannot_edit_anyone_who_outranks_him_or_ranks_equal(): void
    {
        $password = ['password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'];

        foreach ([$this->hrInD1, $this->peer] as $senior) {
            $this->as($this->admin)->putJson(route('users.update', $this->id($senior)), ['name' => 'Hijacked'])->assertForbidden();
            $this->as($this->admin)->postJson(route('users.changePassword', $this->id($senior)), $password)->assertForbidden();
            $this->as($this->admin)->postJson(route('admin.users.devices.reset', $this->id($senior)))->assertForbidden();
        }
    }

    // ── 4. users.update / users.create routes are safe for a scoped actor ─────

    public function test_users_update_gated_routes_reject_out_of_scope_targets(): void
    {
        $target = $this->e2;
        $device = $this->makeDevice($target);
        $departed = $this->makeUser('Employee', $this->d2, 'Departed Two');
        $departed->delete(); // soft-deleted, so restore is a real question
        $password = ['password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'];
        $id = $this->id($target);

        $cases = [
            'update' => ['put', route('users.update', $id), ['name' => 'X']],
            'change password' => ['post', route('users.changePassword', $id), $password],
            'restore' => ['post', route('users.restore', $this->id($departed)), []],
            'roles' => ['post', route('users.updateRole', $id), ['roles' => ['Employee']]],
            'attendance type' => ['post', route('users.updateAttendanceType', $id), []],
            'biometric device' => ['post', route('users.updateBiometricDevice', $id), []],
            'report to' => ['post', route('users.updateReportTo', $id), ['report_to' => null]],
            'device reset' => ['post', route('admin.users.devices.reset', $id), []],
            'device toggle' => ['post', route('admin.users.devices.toggle', $id), []],
            'device deactivate' => ['delete', route('admin.users.devices.deactivate', [$id, $device->id]), []],
            'device session revoke' => ['post', route('admin.device-sessions.revoke', $device->id), []],
        ];

        foreach ($cases as $label => [$method, $uri, $payload]) {
            $status = $this->as($this->admin)->json($method, $uri, $payload)->getStatusCode();
            $this->assertSame(403, $status, "{$label} on an out-of-scope user must be 403");
        }
        $this->assertNotNull(UserDevice::find($device->id)?->is_active, 'the device was left untouched');
    }

    public function test_in_scope_device_and_password_operations_work_for_a_department_admin(): void
    {
        $device = $this->makeDevice($this->e1);

        $this->as($this->admin)->postJson(route('admin.users.devices.reset', $this->id($this->e1)))->assertOk();
        $this->as($this->admin)->postJson(route('admin.users.devices.toggle', $this->id($this->e1)))->assertOk();
        $this->as($this->admin)->postJson(route('admin.device-sessions.revoke', $device->id))->assertOk();
        $this->as($this->admin)->postJson(route('users.changePassword', $this->id($this->e1)), [
            'password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026',
        ])->assertOk();
    }

    public function test_restore_works_inside_the_department_and_role_changes_stay_closed(): void
    {
        $this->e1->delete();
        $this->as($this->admin)->postJson(route('users.restore', $this->id($this->e1)))->assertOk();
        $this->assertNotNull(User::find($this->id($this->e1)));

        // A deactivated employee of ANOTHER department is out of his reach.
        $this->e2->delete();
        $this->as($this->admin)->postJson(route('users.restore', $this->id($this->e2)))->assertForbidden();
        $this->as($this->hr)->postJson(route('users.restore', $this->id($this->e2)))->assertOk();

        // No role management at all: the route itself is closed (employees.access.manage), and so are the
        // role / direct-permission endpoints, readable or not.
        $this->as($this->admin)->postJson(route('users.updateRole', $this->id($this->e1b)), ['roles' => ['Department Manager']])->assertForbidden();
        $this->as($this->admin)->postJson(route('users.bulk.role'), ['user_ids' => [$this->id($this->e1b)], 'role' => 'Department Manager'])->assertForbidden();
        $this->as($this->admin)->getJson('/api/users/'.$this->id($this->e1b).'/roles')->assertForbidden();
        $this->as($this->admin)->getJson('/api/users/'.$this->id($this->e1b).'/permissions')->assertForbidden();
        $this->as($this->admin)->postJson('/api/users/'.$this->id($this->e1b).'/permissions/give', ['permission' => 'users.view'])->assertForbidden();
        $this->assertSame(['Employee'], $this->e1b->fresh()->roles->pluck('name')->all());
    }

    public function test_fleet_wide_admin_surfaces_are_closed_although_he_holds_users_update(): void
    {
        $this->assertTrue($this->admin->can('users.update'));

        $this->as($this->admin)->postJson(route('admin.feature-flags.store'), ['key' => 'x.flag', 'is_enabled' => true])->assertForbidden();
        $this->as($this->admin)->postJson(route('admin.client-errors.resolve', 1))->assertForbidden();
        $this->as($this->admin)->getJson(route('admin.feature-flags.index'))->assertForbidden();
        $this->as($this->admin)->getJson(route('admin.device-sessions.index'))->assertForbidden();
        $this->assertDatabaseMissing('feature_flags', ['key' => 'x.flag']);

        // ...and the scope.global gate itself lets a global actor through.
        $this->as($this->hr)->postJson(route('admin.feature-flags.store'), ['key' => 'x.flag', 'is_enabled' => true, 'value' => 'true'])->assertSuccessful();
    }

    // ── 5. modules outside his menu ───────────────────────────────────────────

    public static function excludedModules(): array
    {
        return [
            'daily works' => ['get', 'daily-works-unified'],
            'quality ncr' => ['get', 'quality.ncr.index'],
            'holidays list' => ['get', 'holidays'],
            'holiday create' => ['post', 'holiday-add'],
            'company settings' => ['get', 'admin.settings.company'],
            'company settings update' => ['put', 'update-company-settings'],
            'leave settings' => ['get', 'leave-settings'],
            'notification settings' => ['get', 'admin.settings.notifications'],
            'attendance settings' => ['post', 'attendance-settings.update'],
            'biometric devices' => ['get', 'biometric-devices.index'],
            'attendance policies' => ['get', 'attendance.policies.index'],
            'coverage requirements' => ['get', 'attendance.coverageRequirements.index'],
            'shift definitions' => ['post', 'attendance.shifts.store'],
            'rotation patterns' => ['post', 'attendance.patterns.store'],
            'roles and permissions' => ['get', 'roles-settings'],
            'role management' => ['get', 'admin.roles-management'],
            'role create' => ['post', 'admin.roles.store'],
            'feature flags' => ['post', 'admin.feature-flags.store'],
            'client diagnostics' => ['get', 'admin.client-errors.index'],
            'device sessions' => ['get', 'admin.device-sessions.index'],
            'departments' => ['get', 'departments'],
            'o&m' => ['get', 'om.dashboard'],
            'camera' => ['get', 'om.camera'],
            'petty cash admin' => ['get', 'petty-cash.admin.overview'],
            'o&m analytics' => ['get', 'om.analytics'],
        ];
    }

    #[DataProvider('excludedModules')]
    public function test_modules_outside_the_menu_are_forbidden(string $method, string $routeName): void
    {
        $this->as($this->admin)->json($method, route($routeName))->assertForbidden();
    }

    public function test_payroll_is_forbidden_even_with_the_module_switched_on(): void
    {
        FeatureFlag::updateOrCreate(['key' => 'hr_payroll', 'role' => null], ['is_enabled' => true, 'value' => null]);
        app(FeatureFlagService::class)->forgetMemo();

        $this->as($this->admin)->getJson(route('hr.payroll.index'))->assertForbidden();
        $this->as($this->admin)->postJson(route('hr.payroll.generate'))->assertForbidden();
    }

    // ── 6. attendance: reads ─────────────────────────────────────────────────

    public static function attendanceReads(): array
    {
        return [
            'daily timesheet' => ['admin.daily-timesheet', 'present'],
            'present users' => ['admin.getPresentUsersForDate', 'present'],
            'absent users' => ['admin.getAbsentUsersForDate', 'absent'],
            'day partition' => ['attendance.dayPartition', 'present'],
            'attendance log' => ['attendance.log', 'present'],
            'monthly grid' => ['attendancesAdmin.paginate', 'present'],
        ];
    }

    #[DataProvider('attendanceReads')]
    public function test_attendance_reads_show_only_the_home_department(string $routeName, string $who): void
    {
        $query = ['date' => self::DAY, 'from' => self::DAY, 'to' => self::DAY, 'currentMonth' => 6, 'currentYear' => 2026, 'perPage' => 50, 'page' => 1];
        [$visible, $hidden] = $who === 'absent' ? [$this->e1b, $this->e2b] : [$this->e1, $this->e2];

        $body = $this->as($this->admin)->getJson(route($routeName, $query))->assertOk()->getContent();
        $this->assertOnly($body, $visible, $hidden, $routeName);

        // Global HR still sees both, so the filter is the scope, not a broken fixture.
        $all = $this->as($this->hr)->getJson(route($routeName, $query))->assertOk()->getContent();
        $this->assertStringContainsString($this->id($hidden), $all, "{$routeName}: global HR sees D2 too");
    }

    public function test_locations_and_stats_count_only_the_home_department(): void
    {
        $location = json_encode(['latitude' => 23.7, 'longitude' => 90.4, 'address' => 'Somewhere']);
        Attendance::where('user_id', $this->id($this->e1))->update(['punchin_location' => $location]);
        Attendance::where('user_id', $this->id($this->e2))->update(['punchin_location' => $location]);

        $body = $this->as($this->admin)->getJson(route('getUserLocationsForDate', ['date' => self::DAY]))->assertOk()->getContent();
        $this->assertOnly($body, $this->e1, $this->e2, 'locations');

        $stats = $this->as($this->admin)->getJson(route('attendance.monthlyStats', ['currentMonth' => 6, 'currentYear' => 2026]))->assertOk();
        $this->assertSame(2, $stats->json('data.meta.totalEmployees'), 'only the two D1 employees are counted');
        $analytics = $this->as($this->admin)->getJson(route('attendance.analytics', ['month' => '2026-06']))->assertOk();
        $this->assertSame(2, $analytics->json('data.meta.totalEmployees'));

        $global = $this->as($this->hr)->getJson(route('attendance.monthlyStats', ['currentMonth' => 6, 'currentYear' => 2026]))->assertOk();
        $this->assertSame(4, $global->json('data.meta.totalEmployees'));
    }

    // ── 7. attendance: writes ────────────────────────────────────────────────

    public function test_attendance_writes_reject_out_of_scope_employees(): void
    {
        $theirs = Attendance::where('user_id', $this->id($this->e2))->firstOrFail();
        $payload = ['punchin' => self::DAY.' 09:05:00', 'punchout' => self::DAY.' 17:05:00'];

        $this->as($this->admin)->postJson(route('attendance.correct.update', $theirs->id), $payload)->assertForbidden();
        $this->as($this->admin)->patchJson(route('attendance.correct.status', $theirs->id), ['symbol' => '√'])->assertForbidden();
        $this->as($this->admin)->deleteJson(route('attendance.correct.delete', $theirs->id))->assertForbidden();
        $this->as($this->admin)->getJson(route('attendance.audit.history', $theirs->id))->assertNotFound();
        $this->as($this->admin)->postJson(route('attendance.correct.add'), ['user_id' => $this->id($this->e2b), 'date' => self::DAY] + $payload)->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.mark-as-present'), ['user_id' => $this->id($this->e2b), 'date' => self::DAY])->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.bulk-mark-as-present'), ['user_ids' => [$this->id($this->e1b), $this->id($this->e2b)], 'date' => self::DAY])->assertForbidden();

        $this->assertNotNull(Attendance::find($theirs->id), 'the D2 punch is untouched');
        $this->assertSame(0, Attendance::where('user_id', $this->id($this->e2b))->count());
    }

    public function test_attendance_writes_work_inside_the_department(): void
    {
        $mine = Attendance::where('user_id', $this->id($this->e1))->firstOrFail();

        $this->as($this->admin)->postJson(route('attendance.correct.update', $mine->id), ['punchin' => self::DAY.' 09:10:00', 'punchout' => self::DAY.' 17:10:00'])->assertOk();
        $this->as($this->admin)->postJson(route('attendance.mark-as-present'), ['user_id' => $this->id($this->e1b), 'date' => self::DAY])->assertOk();
        $this->as($this->admin)->getJson(route('attendance.audit.history', $mine->id))->assertOk();
    }

    // ── 8. roster, shifts, coverage ──────────────────────────────────────────

    public function test_the_roster_grid_lists_only_the_home_department(): void
    {
        $shift = Shift::factory()->create();
        foreach ([$this->e1, $this->e2] as $employee) {
            RosterDay::create(['user_id' => $this->id($employee), 'date' => self::DAY, 'shift_id' => $shift->id, 'source' => 'manual']);
        }
        $query = ['from' => self::DAY, 'to' => self::DAY];

        $body = $this->as($this->admin)->getJson(route('attendance.roster.index', $query))->assertOk()->getContent();
        $this->assertOnly($body, $this->e1, $this->e2, 'roster');
        $this->assertStringContainsString($this->id($this->e2), $this->as($this->hr)->getJson(route('attendance.roster.index', $query))->getContent());
    }

    public function test_roster_cells_and_generation_reject_other_departments(): void
    {
        $shift = Shift::factory()->create();
        $cell = ['date' => self::DAY, 'shift_id' => $shift->id];

        $this->as($this->admin)->putJson(route('attendance.roster.cell'), ['user_id' => $this->id($this->e2)] + $cell)->assertForbidden();
        $this->as($this->admin)->putJson(route('attendance.roster.cell'), ['user_id' => $this->id($this->e1)] + $cell)->assertOk();
        $this->assertDatabaseMissing('roster_days', ['user_id' => $this->id($this->e2)]);

        $range = ['from' => '2026-06-08', 'to' => '2026-06-09'];
        $this->as($this->admin)->postJson(route('attendance.roster.generate'), ['user_ids' => [$this->id($this->e2)]] + $range)->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.roster.generate'), ['user_ids' => [$this->id($this->e1), $this->id($this->e2)]] + $range)->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.roster.generate'), ['user_ids' => [$this->id($this->e1)]] + $range)->assertOk();
    }

    public function test_shift_assignments_are_confined_to_the_home_department(): void
    {
        $shift = Shift::factory()->create();
        $base = ['shift_id' => $shift->id, 'anchor_date' => self::DAY, 'effective_from' => self::DAY];

        foreach ([
            'other employee' => ['scope_type' => 'user', 'scope_id' => $this->id($this->e2)],
            'other department' => ['scope_type' => 'department', 'scope_id' => $this->d2->id],
            'organization' => ['scope_type' => 'org', 'scope_id' => null],
        ] as $label => $scope) {
            $this->assertSame(403, $this->as($this->admin)->postJson(route('attendance.assignments.store'), $base + $scope)->getStatusCode(), $label);
        }
        $this->as($this->admin)->postJson(route('attendance.assignments.store'), $base + ['scope_type' => 'user', 'scope_id' => $this->id($this->e1)])->assertSuccessful();
        $this->as($this->admin)->postJson(route('attendance.assignments.store'), $base + ['scope_type' => 'department', 'scope_id' => $this->d1->id])->assertSuccessful();

        $theirs = ShiftAssignment::create($base + ['scope_type' => 'user', 'scope_id' => $this->id($this->e2), 'priority' => 0, 'assigned_by' => $this->id($this->hr)]);
        $this->as($this->admin)->putJson(route('attendance.assignments.update', $theirs->id), ['priority' => 5])->assertForbidden();
        $this->as($this->admin)->deleteJson(route('attendance.assignments.destroy', $theirs->id))->assertForbidden();

        $listing = $this->as($this->admin)->getJson(route('attendance.assignments.index'))->assertOk()->getContent();
        $this->assertStringContainsString($this->id($this->e1), $listing);
        $this->assertStringNotContainsString($this->id($this->e2), $listing);
    }

    public function test_company_wide_shift_definitions_and_patterns_stay_on_attendance_settings_but_the_catalogue_is_readable(): void
    {
        $someoneElsesShift = Shift::factory()->create(['code' => 'ZZ9', 'created_by' => $this->id($this->hr)]);

        $this->as($this->admin)->postJson(route('attendance.shifts.store'), ['name' => 'X', 'code' => 'XX1', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00'])->assertForbidden();
        $this->as($this->admin)->putJson(route('attendance.shifts.update', $someoneElsesShift->id), ['name' => 'Hijack'])->assertForbidden();
        $this->as($this->admin)->deleteJson(route('attendance.shifts.destroy', $someoneElsesShift->id))->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.patterns.store'), ['name' => 'P', 'code' => 'P1', 'cycle_length_days' => 2, 'definition' => [null, null]])->assertForbidden();

        // He must see the (company-wide) catalogue to assign from it. Templates OWNED by his department are
        // his to manage — tests/Feature/Attendance/ShiftOwnershipTest pins that delegated side.
        $this->as($this->admin)->getJson(route('attendance.shifts.index'))->assertOk()->assertJsonFragment(['code' => 'ZZ9']);
        $this->as($this->admin)->getJson(route('attendance.patterns.index'))->assertOk();
    }

    public function test_coverage_counts_only_the_home_department(): void
    {
        $date = '2026-06-10'; // future: the actual-punch side is not part of what this proves
        $location = WorkLocation::create(['name' => 'Control Room']);
        $shift = Shift::factory()->create(['code' => 'N']);
        CoverageRequirement::create(['work_location_id' => $location->id, 'shift_id' => $shift->id, 'required_headcount' => 5, 'is_active' => true]);
        foreach ([$this->e1, $this->e2, $this->e2b] as $employee) {
            $employee->forceFill(['work_location_id' => $location->id])->save();
            RosterDay::create(['user_id' => $this->id($employee), 'date' => $date, 'shift_id' => $shift->id, 'source' => 'manual']);
        }
        $path = "coverage.{$date}.{$location->id}.{$shift->id}.total.assigned";
        $query = ['from' => $date, 'to' => $date];

        $this->assertSame(1, $this->as($this->admin)->getJson(route('attendance.coverage.index', $query))->assertOk()->json($path));
        $this->assertSame(3, $this->as($this->hr)->getJson(route('attendance.coverage.index', $query))->assertOk()->json($path));
    }

    // ── 9. punch exceptions and swaps ────────────────────────────────────────

    private function provisionalPunch(User $user): Attendance
    {
        return $this->punch($user, '2026-06-02', ['policy_status' => 'provisional', 'needs_approval' => true, 'policy_exception_reason' => 'outside window']);
    }

    public function test_punch_exceptions_are_confined_and_never_decided_on_ones_own_punch(): void
    {
        [$mine, $theirs, $own] = [$this->provisionalPunch($this->e1), $this->provisionalPunch($this->e2), $this->provisionalPunch($this->admin)];

        $pending = $this->as($this->admin)->getJson(route('attendance.punch-exceptions.pending'))->assertOk();
        $ids = collect($pending->json())->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids, 'another department');
        $this->assertNotContains($own->id, $ids, 'his own punch is not his to decide');

        $this->as($this->admin)->postJson(route('attendance.punch-exceptions.approve', $theirs->id))->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.punch-exceptions.reject', $own->id), ['reason' => 'self'])->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.punch-exceptions.approve', $mine->id))->assertOk();
        $this->assertSame('provisional', $theirs->fresh()->policy_status);
        $this->assertSame('accepted', $mine->fresh()->policy_status);
    }

    private function makeSwap(User $requester, ?User $counterparty = null): ShiftSwapRequest
    {
        return ShiftSwapRequest::create([
            'type' => 'cover', 'requester_id' => $this->id($requester), 'requester_date' => '2026-06-12',
            'counterparty_id' => $counterparty ? $this->id($counterparty) : null,
            'counterparty_status' => 'accepted', 'status' => 'pending', 'reason' => 'test',
        ]);
    }

    public function test_the_swap_queue_and_decisions_are_confined(): void
    {
        [$mine, $theirs, $own] = [$this->makeSwap($this->e1, $this->e1b), $this->makeSwap($this->e2, $this->e2b), $this->makeSwap($this->admin, $this->e1)];

        $listing = $this->as($this->admin)->getJson(route('attendance.swaps.index'))->assertOk();
        $ids = collect($listing->json('swaps'))->pluck('id')->all();
        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);

        $this->as($this->admin)->postJson(route('attendance.swaps.approve', $theirs->id))->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.swaps.reject', $theirs->id))->assertForbidden();
        $this->as($this->admin)->postJson(route('attendance.swaps.reject', $own->id))->assertForbidden();
        $this->assertSame('pending', $theirs->fresh()->status);
        $this->assertSame('pending', $own->fresh()->status);

        $this->as($this->admin)->postJson(route('attendance.swaps.reject', $mine->id))->assertOk();
        $this->assertSame('rejected', $mine->fresh()->status);
    }

    // ── 10. dashboard ────────────────────────────────────────────────────────

    public function test_the_dashboard_and_its_widget_endpoints_show_only_scoped_data(): void
    {
        $command = $this->as($this->admin)->getJson(route('dashboard.command'))->assertOk();
        $this->assertFalse($command->json('access.project'), 'no project / quality permission: workforce view only');
        $this->assertSame(User::where('department_id', $this->d1->id)->count(), $command->json('workforce.total'));
        $this->assertSame(1, $command->json('workforce.present_today'), "only D1's punch counts");
        $this->assertSame([], $command->json('kpis'));

        $updates = $this->as($this->admin)->getJson(route('updates'))->assertOk()->getContent();
        $this->assertOnly($updates, $this->e1, $this->e2, 'updates widget');
        $this->assertStringContainsString($this->id($this->e2), $this->as($this->hr)->getJson(route('updates'))->getContent());

        $stats = $this->as($this->admin)->getJson(route('stats'))->assertOk();
        $this->assertSame(0, $stats->json('statistics.total'), 'no daily-works permission: empty stats');
    }

    // ── 11. exports ──────────────────────────────────────────────────────────

    public function test_export_endpoints_dispatch_a_job_bound_to_the_requester(): void
    {
        Queue::fake();

        $this->as($this->admin)->getJson(route('attendance.exportExcel', ['date' => self::DAY]))->assertOk();
        $this->as($this->admin)->getJson(route('attendance.exportAdminExcel', ['month' => '2026-06']))->assertOk();
        $this->as($this->admin)->getJson(route('attendance.log.export', ['from' => self::DAY, 'to' => self::DAY, 'department_id' => $this->d2->id]))->assertOk();

        Queue::assertPushed(ExportAttendanceReport::class, 3);
        Queue::assertPushed(ExportAttendanceReport::class, function (ExportAttendanceReport $job) {
            return (fn () => $this->userId)->call($job) === (string) $this->admin->employee_id;
        });
    }

    public function test_the_export_job_confines_a_department_admin_and_never_trusts_a_stored_filter(): void
    {
        Storage::fake('public');
        Excel::fake();

        $run = function (string $type, string $filename, User $requester, array $filters = []) {
            (new ExportAttendanceReport($type, self::DAY, '2026-06', (string) $requester->employee_id, $filename, $filters))
                ->handle(app(AttendanceReportService::class));
        };
        $employeeIds = function (string $filename): array {
            $ids = [];
            Excel::assertStored("exports/{$filename}", 'public', function ($export) use (&$ids) {
                $ids = $export->collection()->pluck('Employee ID')->map(fn ($id) => (string) $id)->all();

                return true;
            });

            return $ids;
        };

        $run('daily_excel', 'daily_admin.xlsx', $this->admin);
        $run('daily_excel', 'daily_hr.xlsx', $this->hr);

        $adminRows = $employeeIds('daily_admin.xlsx');
        $this->assertContains($this->id($this->e1), $adminRows);
        $this->assertNotContains($this->id($this->e2), $adminRows, 'a department admin never exports another department');
        $this->assertContains($this->id($this->e2), $employeeIds('daily_hr.xlsx'), 'global HR exports everyone');

        // Range export: a stored `team_member_ids` / department filter can not widen the scope.
        $run('range_excel', 'range_admin.xlsx', $this->admin, [
            'from' => self::DAY, 'to' => self::DAY, 'team_member_ids' => [$this->id($this->e2)], 'department_id' => $this->d2->id,
        ]);
        $sheet = IOFactory::load(Storage::disk('public')->path('exports/range_admin.xlsx'));
        $cells = collect($sheet->getActiveSheet()->toArray())->flatten()->map(fn ($v) => (string) $v)->implode('|');
        $this->assertStringNotContainsString($this->id($this->e2), $cells);
    }

    public function test_the_export_job_refuses_an_unresolvable_requester(): void
    {
        Storage::fake('public');
        $this->expectException(\RuntimeException::class);

        (new ExportAttendanceReport('daily_excel', self::DAY, null, 'EMP-NOBODY', 'x.xlsx'))->handle(app(AttendanceReportService::class));
    }

    // ── 12. attendance.settings holders still pass the roster routes ─────────

    public function test_a_holder_of_attendance_settings_still_passes_the_roster_routes(): void
    {
        $shift = Shift::factory()->create();
        $holder = User::factory()->create();
        $holder->givePermissionTo('attendance.settings');
        $swap = $this->makeSwap($this->e2, $this->e2b);
        $cell = ['user_id' => $this->id($this->e2), 'date' => self::DAY, 'shift_id' => $shift->id];

        $this->as($holder)->getJson(route('attendance.roster.index', ['from' => self::DAY, 'to' => self::DAY]))->assertOk();
        $this->as($holder)->putJson(route('attendance.roster.cell'), $cell)->assertOk();
        $this->as($holder)->getJson(route('attendance.swaps.index'))->assertOk();
        $this->as($holder)->postJson(route('attendance.swaps.reject', $swap->id))->assertOk();
        $this->as($holder)->postJson(route('attendance.assignments.store'), [
            'scope_type' => 'user', 'scope_id' => $this->id($this->e2), 'shift_id' => $shift->id, 'anchor_date' => self::DAY, 'effective_from' => self::DAY,
        ])->assertSuccessful();

        // The very same cell is closed to the department admin: roster.manage never widens scope.
        $this->as($this->admin)->putJson(route('attendance.roster.cell'), $cell)->assertForbidden();
        // ...and settings-only configuration is not something roster.manage unlocks.
        $rosterOnly = User::factory()->create();
        $rosterOnly->givePermissionTo('attendance.roster.manage');
        $this->as($rosterOnly)->postJson(route('attendance.shifts.store'), ['name' => 'X', 'code' => 'XX2', 'type' => 'fixed', 'start_time' => '09:00', 'end_time' => '17:00'])->assertForbidden();
        $this->as($rosterOnly)->getJson(route('attendance.roster.index', ['from' => self::DAY, 'to' => self::DAY]))->assertOk();
    }

    // ── 13. mobile API honours the same scope ────────────────────────────────

    public function test_the_mobile_api_team_roster_and_swap_queue_use_the_same_scope(): void
    {
        $shift = Shift::factory()->create();
        foreach ([$this->e1, $this->e2] as $employee) {
            RosterDay::create(['user_id' => $this->id($employee), 'date' => self::DAY, 'shift_id' => $shift->id, 'source' => 'manual']);
        }
        $mine = $this->makeSwap($this->e1, $this->e1b);
        $theirs = $this->makeSwap($this->e2, $this->e2b);
        Sanctum::actingAs($this->admin);

        $team = $this->getJson('/api/v1/manager/team-members')->assertOk()->getContent();
        $this->assertOnly($team, $this->e1, $this->e2, 'mobile team');

        $roster = $this->getJson('/api/v1/attendance/roster?from='.self::DAY.'&to='.self::DAY)->assertOk()->getContent();
        $this->assertOnly($roster, $this->e1, $this->e2, 'mobile roster');

        $queue = collect($this->getJson('/api/v1/attendance/swaps/pending')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertContains($mine->id, $queue);
        $this->assertNotContains($theirs->id, $queue);

        $this->postJson("/api/v1/attendance/swaps/{$theirs->id}/approve")->assertForbidden();
        $this->assertSame('pending', $theirs->fresh()->status);
    }

    // ── 14. pickers: scope prop, work locations, devices ─────────────────────

    /** @return array<string, mixed> the Inertia props of the Employees page for $actor */
    private function employeesPageProps(User $actor): array
    {
        return $this->as($actor)->get(route('employees'))->assertOk()->viewData('page')['props'];
    }

    /** @return array{0: WorkLocation, 1: BiometricDevice, 2: BiometricDevice, 3: BiometricDevice} */
    private function siteWithTerminals(): array
    {
        $site = WorkLocation::create(['name' => 'Plaza A', 'code' => 'PA', 'is_active' => true]);
        $active = BiometricDevice::create(['name' => 'Gate A', 'serial_number' => 'SN-A', 'is_active' => true]);
        $retired = BiometricDevice::create(['name' => 'Retired', 'serial_number' => 'SN-R', 'is_active' => false]);
        $elsewhere = BiometricDevice::create(['name' => 'Gate Z', 'serial_number' => 'SN-Z', 'is_active' => true]);
        foreach ([$active, $retired] as $device) {
            DB::table('work_location_biometric_device')->insert(['work_location_id' => $site->id, 'biometric_device_id' => $device->id]);
        }

        return [$site, $active, $retired, $elsewhere];
    }

    public function test_the_shared_scope_prop_tells_the_ui_which_departments_to_offer(): void
    {
        $adminScope = $this->employeesPageProps($this->admin)['auth']['scope'];
        $this->assertFalse($adminScope['global']);
        $this->assertFalse($adminScope['attendance']);
        $this->assertSame([['id' => $this->d1->id, 'name' => $this->d1->name]], $adminScope['departments']);

        $hrScope = $this->employeesPageProps($this->hr)['auth']['scope'];
        $this->assertTrue($hrScope['global']);
        $this->assertSame([], $hrScope['departments'], 'a global actor falls back to each page\'s full list');

        // A grant widens it to a limited list (the multi-scope picker).
        UserDepartmentScope::create(['user_id' => $this->id($this->admin), 'department_id' => $this->d2->id, 'scope_type' => 'admin', 'granted_by' => $this->id($this->hr)]);
        app(DepartmentScope::class)->forget();
        $widened = collect($this->employeesPageProps($this->admin)['auth']['scope']['departments'])->pluck('id')->sort()->values()->all();
        $this->assertSame(collect([$this->d1->id, $this->d2->id])->sort()->values()->all(), $widened);
    }

    public function test_work_locations_and_terminals_reach_a_department_admin_even_with_no_staff_at_the_site(): void
    {
        [$site, $active, $retired, $elsewhere] = $this->siteWithTerminals();
        WorkLocation::create(['name' => 'Remote Yard', 'code' => 'RY', 'is_active' => true]);
        // The ONLY person at the site is outside his department: the old device query (built from the
        // work locations of in-scope staff) came back empty for him.
        $this->e2->forceFill(['work_location_id' => $site->id])->save();

        $props = $this->employeesPageProps($this->admin);

        $this->assertCount(2, $props['workLocations'], 'every work location is offered');
        $linked = collect($props['workLocations'])->firstWhere('id', $site->id)['biometric_devices'];
        $this->assertEqualsCanonicalizing([$active->id, $retired->id], collect($linked)->pluck('id')->all());

        // Reference data for the picker: every ACTIVE terminal, never a connection secret.
        $this->assertArrayNotHasKey('devices', $props, 'the prop is named what the UI reads');
        $offered = collect($props['biometricDevices']);
        $this->assertEqualsCanonicalizing([$active->id, $elsewhere->id], $offered->pluck('id')->all());
        foreach ($offered as $device) {
            $this->assertEqualsCanonicalizing(['id', 'name', 'serial_number', 'location'], array_keys($device));
        }
    }

    public function test_assigning_a_terminal_accepts_what_the_picker_offers_and_refuses_the_rest(): void
    {
        [$site, $active, $retired, $elsewhere] = $this->siteWithTerminals();
        $assign = fn (User $employee, BiometricDevice $device) => $this->as($this->admin)
            ->postJson(route('users.updateBiometricDevice', $this->id($employee)), ['biometric_device_id' => $device->id]);

        // At a site with linked terminals: only the active linked ones.
        $this->e1->forceFill(['work_location_id' => $site->id])->save();
        $assign($this->e1, $active)->assertOk()->assertJsonPath('biometric_device_id', $active->id);
        $assign($this->e1, $retired)->assertStatus(422);
        $assign($this->e1, $elsewhere)->assertStatus(422);

        // No location (or none linked): fall back to every active terminal.
        $assign($this->e1b, $elsewhere)->assertOk();
        $assign($this->e1b, $retired)->assertStatus(422);

        // ...and out of scope stays closed whatever the terminal.
        $assign($this->e2, $active)->assertForbidden();
    }

    // ── 15. delegated administration: row actions, compensation, placement ───

    /** @return array<string, mixed> the directory row of $employee as seen by $actor */
    private function directoryRow(User $actor, User $employee): array
    {
        $rows = $this->as($actor)->getJson(route('employees.paginate', ['perPage' => 100, 'showDeleted' => true]))->assertOk()->json('employees.data');

        return collect($rows)->firstWhere('employee_id', $employee->employee_id) ?? [];
    }

    public function test_directory_rows_carry_exactly_the_actions_the_row_menu_may_offer(): void
    {
        $theirs = $this->directoryRow($this->admin, $this->e1)['can'];
        $this->assertSame([
            'is_self' => false, 'update' => true, 'placement' => true, 'transfer' => true, 'attendance_config' => true,
            'view_compensation' => true, 'update_compensation' => true, 'reset_password' => true, 'manage_devices' => true,
            'manage_access' => false, 'delete' => true, 'restore' => true,
        ], $theirs, 'a department employee: everything except access management');

        $own = $this->directoryRow($this->admin, $this->admin)['can'];
        $this->assertTrue($own['is_self']);
        foreach (['placement', 'transfer', 'update_compensation', 'reset_password', 'manage_access', 'delete'] as $never) {
            $this->assertFalse($own[$never], "never on himself: {$never}");
        }
        $this->assertTrue($own['manage_devices'], 'his own devices and lock stay his to manage');

        $peer = $this->directoryRow($this->admin, $this->peer)['can'];
        $this->assertFalse($peer['update'], 'an equal rank cannot be edited');
        $this->assertFalse($peer['delete']);
        $this->assertFalse($peer['reset_password']);

        $hrView = $this->directoryRow($this->hr, $this->e1)['can'];
        $this->assertTrue($hrView['manage_access'], 'global HR keeps role management');
    }

    public function test_salary_is_editable_on_department_employees_but_never_on_himself(): void
    {
        $this->e1->forceFill(['salary_amount' => 1000])->save();
        $this->admin->forceFill(['salary_amount' => 5000])->save();

        $this->as($this->admin)->putJson(route('users.update', $this->id($this->e1)), ['salary_amount' => 1200])->assertOk();
        $this->assertEquals(1200, $this->e1->fresh()->salary_amount);

        // ...through the profile page's salary form as well
        $salary = ['ruleSet' => 'salary', 'salary_basis' => 'monthly', 'payment_type' => 'Bank transfer'];
        $this->as($this->admin)->postJson(route('profile.update'), $salary + ['id' => $this->id($this->e1), 'salary_amount' => 1300])->assertOk();
        $this->assertEquals(1300, $this->e1->fresh()->salary_amount);

        // never his own — however it is submitted
        $this->as($this->admin)->putJson(route('users.update', $this->id($this->admin)), ['salary_amount' => 999999, 'name' => 'Still Me'])->assertOk();
        $this->assertEquals(5000, $this->admin->fresh()->salary_amount);
        $this->as($this->admin)->postJson(route('profile.update'), $salary + ['id' => $this->id($this->admin), 'salary_amount' => 999999])->assertForbidden();
        $this->assertEquals(5000, $this->admin->fresh()->salary_amount);

        // nor anyone outside his department, nor anyone who outranks him
        $this->as($this->admin)->putJson(route('users.update', $this->id($this->e2)), ['salary_amount' => 1])->assertForbidden();
        $this->as($this->admin)->postJson(route('profile.update'), $salary + ['id' => $this->id($this->e2), 'salary_amount' => 1])->assertForbidden();
        $this->as($this->admin)->postJson(route('profile.update'), $salary + ['id' => $this->id($this->hrInD1), 'salary_amount' => 1])->assertForbidden();
    }

    public function test_the_profile_page_is_open_inside_the_department_only_and_hides_salary_from_people_without_the_permission(): void
    {
        $this->e1->forceFill(['salary_amount' => 1000])->save();

        $props = $this->as($this->admin)->get(route('profile', $this->id($this->e1)))->assertOk()->viewData('page')['props'];
        $this->assertEquals(1000, $props['user']['salary_amount']);
        $this->assertTrue($props['can']['edit']);
        $this->assertTrue($props['can']['manageEmployment']);
        $this->assertTrue($props['can']['manageCompensation']);
        // the pickers hold his department only
        $this->assertSame([$this->d1->id], collect($props['departments'])->pluck('id')->all());
        $this->assertNotContains($this->id($this->e2), collect($props['allUsers'])->pluck('id')->all());

        $this->as($this->admin)->get(route('profile', $this->id($this->e2)))->assertNotFound();
        $emergency = ['ruleSet' => 'emergency', 'emergency_contact_primary_name' => 'Ally', 'emergency_contact_primary_relationship' => 'Sibling', 'emergency_contact_primary_phone' => '01700000000'];
        $this->as($this->admin)->postJson(route('profile.update'), ['id' => $this->id($this->e2)] + $emergency)->assertForbidden();
        $this->as($this->admin)->postJson(route('profile.update'), ['id' => $this->id($this->e1)] + $emergency)->assertOk();

        // employment: re-placing inside his department works, moving someone out (or placing himself) does not
        $designation = Designation::factory()->create(['department_id' => $this->d1->id]);
        $employment = ['ruleSet' => 'employment', 'designation' => $designation->id, 'report_to' => $this->id($this->admin)];
        $this->as($this->admin)->postJson(route('profile.update'), ['id' => $this->id($this->e1), 'department' => $this->d1->id] + $employment)->assertOk();
        $this->assertSame($designation->id, (int) $this->e1->fresh()->designation_id);
        $this->as($this->admin)->postJson(route('profile.update'), ['id' => $this->id($this->e1), 'department' => $this->d2->id] + $employment)->assertForbidden();
        $this->as($this->admin)->postJson(route('profile.update'), ['id' => $this->id($this->admin), 'department' => $this->d1->id] + $employment)->assertForbidden();

        // A colleague who can browse the directory but holds no compensation permission never receives it.
        $viewer = $this->makeUser('Department Manager', $this->d1, 'Plain Viewer');
        $viewer->givePermissionTo(['employees.view', 'profile.own.view']);
        $viewerProps = $this->as($viewer)->get(route('profile', $this->id($this->e1)))->assertOk()->viewData('page')['props'];
        $this->assertArrayNotHasKey('salary_amount', $viewerProps['user']);
        $this->assertFalse($viewerProps['can']['viewCompensation']);
    }

    public function test_each_employee_action_needs_its_own_permission(): void
    {
        // A department manager who can edit profiles and re-place staff, nothing else.
        $lead = $this->makeUser('Department Manager', $this->d1, 'Placement Lead');
        $lead->givePermissionTo(['employees.view', 'employees.update', 'employees.placement.update']);
        $designation = Designation::factory()->create(['department_id' => $this->d1->id]);

        // allowed: profile + placement
        $this->as($lead)->putJson(route('users.update', $this->id($this->e1)), ['name' => 'By Lead', 'designation_id' => $designation->id])->assertOk();
        $this->assertSame($designation->id, (int) $this->e1->fresh()->designation_id);
        $this->as($lead)->postJson(route('users.updateReportTo', $this->id($this->e1)), ['report_to' => $this->id($this->admin)])->assertOk();

        // refused: every other group, with the unchanged echo of it tolerated
        $this->as($lead)->putJson(route('users.update', $this->id($this->e1)), ['salary_amount' => 7])->assertForbidden();
        $this->as($lead)->putJson(route('users.update', $this->id($this->e1)), ['password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'])->assertForbidden();
        $this->as($lead)->putJson(route('users.update', $this->id($this->e1)), ['single_device_login_enabled' => true])->assertForbidden();
        $this->as($lead)->putJson(route('users.update', $this->id($this->e1)), ['attendance_type_ids' => [999]])->assertStatus(422);
        $this->as($lead)->postJson(route('users.changePassword', $this->id($this->e1)), ['password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'])->assertForbidden();
        $this->as($lead)->postJson(route('users.updateAttendanceType', $this->id($this->e1)), [])->assertForbidden();
        $this->as($lead)->postJson(route('admin.users.devices.toggle', $this->id($this->e1)))->assertForbidden();
        $this->as($lead)->deleteJson(route('users.destroy', $this->id($this->e1)))->assertForbidden();
        $this->as($lead)->putJson(route('users.update-department', $this->id($this->e1)), ['department' => $this->d1->id])->assertOk(); // employees.update covers a (here: no-op) transfer
    }

    public function test_transfers_stay_between_departments_he_manages_and_never_involve_himself(): void
    {
        $this->as($this->admin)->putJson(route('users.update-department', $this->id($this->e1)), ['department' => $this->d2->id])->assertForbidden();
        $this->as($this->admin)->putJson(route('users.update', $this->id($this->e1)), ['department_id' => $this->d2->id])->assertForbidden();
        $this->as($this->admin)->putJson(route('users.update-department', $this->id($this->e2)), ['department' => $this->d1->id])->assertForbidden();
        $this->as($this->admin)->putJson(route('users.update-department', $this->id($this->admin)), ['department' => $this->d1->id])->assertForbidden();
        $this->assertSame($this->d1->id, (int) $this->e1->fresh()->department_id);

        // With a second department granted to him, a move between his two works.
        UserDepartmentScope::create(['user_id' => $this->id($this->admin), 'department_id' => $this->d2->id, 'scope_type' => 'admin', 'granted_by' => $this->id($this->hr)]);
        app(DepartmentScope::class)->forget();
        $this->as($this->admin)->putJson(route('users.update-department', $this->id($this->e1)), ['department' => $this->d2->id])->assertOk();
        $this->assertSame($this->d2->id, (int) $this->e1->fresh()->department_id);
    }

    public function test_he_never_resets_his_own_password_through_the_admin_route_but_resets_his_departments(): void
    {
        $payload = ['password' => 'Str0ng!Passw0rd#2026', 'password_confirmation' => 'Str0ng!Passw0rd#2026'];
        $this->as($this->admin)->postJson(route('users.changePassword', $this->id($this->admin)), $payload)->assertForbidden();
        $this->as($this->admin)->postJson(route('users.changePassword', $this->id($this->e1)), $payload)->assertOk();
        $this->as($this->admin)->postJson(route('users.changePassword', $this->id($this->e2)), $payload)->assertForbidden();
    }
}
