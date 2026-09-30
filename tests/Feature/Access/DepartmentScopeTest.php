<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Department;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * DepartmentScope is the single who-sees-whom model: global roles see all; everyone
 * else sees managed departments (grants / manager_id / Department Manager or
 * department.admin over own dept) ∪ report_to sub-tree ∪ self — failing closed.
 */
class DepartmentScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    private Department $d3;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'Super Administrator' => 1,
            'Administrator' => 10,
            'HR Manager' => 20,
            'Department Admin' => 25,
            'Department Manager' => 30,
            'Team Lead' => 40,
            'Employee' => 60,
        ] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        Permission::findOrCreate(DepartmentScope::DEPARTMENT_ADMIN_PERMISSION, 'web');

        [$this->d1, $this->d2, $this->d3] = [Department::factory()->create(), Department::factory()->create(), Department::factory()->create()];
    }

    private function scope(): DepartmentScope
    {
        // Fresh instance per assertion: the memo is per request by design.
        return new DepartmentScope;
    }

    private function user(?string $role = null, ?Department $department = null, array $attrs = []): User
    {
        $user = User::factory()->create(array_merge(['department_id' => $department?->id], $attrs));
        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }

    private function grant(User $user, Department $department, array $attrs = []): UserDepartmentScope
    {
        return UserDepartmentScope::create(array_merge([
            'user_id' => $user->employee_id,
            'department_id' => $department->id,
            'scope_type' => UserDepartmentScope::TYPE_ADMIN,
        ], $attrs));
    }

    /** @return array<int, string> */
    private function visibleIds(User $actor): array
    {
        return $this->scope()->applyToUsers(User::query(), $actor)->pluck('employee_id')->map(fn ($id) => (string) $id)->sort()->values()->all();
    }

    /** @param  array<int, User>  $users */
    private function ids(array $users): array
    {
        return collect($users)->map(fn (User $u) => (string) $u->employee_id)->sort()->values()->all();
    }

    public function test_global_role_sees_everyone(): void
    {
        $hr = $this->user('HR Manager', $this->d3);
        $a = $this->user('Employee', $this->d1);
        $b = $this->user('Employee', $this->d2);
        $noDept = $this->user('Employee');

        $this->assertTrue($this->scope()->isGlobal($hr));
        $this->assertTrue($this->scope()->canSeeAll($hr));
        $this->assertSame($this->ids([$hr, $a, $b, $noDept]), $this->visibleIds($hr));
        $this->assertTrue($this->scope()->canActOn($hr, $b));
    }

    public function test_department_admin_with_d1_grant_sees_d1_and_self_only(): void
    {
        $admin = $this->user('Department Admin');
        $this->grant($admin, $this->d1);
        $inD1 = $this->user('Employee', $this->d1);
        $inD2 = $this->user('Employee', $this->d2);

        $this->assertSame([$this->d1->id], $this->scope()->managedDepartmentIds($admin));
        $this->assertSame($this->ids([$admin, $inD1]), $this->visibleIds($admin));
        $this->assertTrue($this->scope()->canActOn($admin, $inD1));
        $this->assertTrue($this->scope()->canActOn($admin, (string) $inD1->employee_id));
        $this->assertFalse($this->scope()->canActOn($admin, $inD2));
    }

    public function test_admin_over_two_departments_sees_both(): void
    {
        $admin = $this->user('Department Admin');
        $this->grant($admin, $this->d1);
        $this->grant($admin, $this->d2, ['scope_type' => UserDepartmentScope::TYPE_ACTING, 'expires_at' => now()->addWeek()]);
        $inD1 = $this->user('Employee', $this->d1);
        $inD2 = $this->user('Employee', $this->d2);
        $inD3 = $this->user('Employee', $this->d3);

        $this->assertSame([$this->d1->id, $this->d2->id], $this->scope()->managedDepartmentIds($admin));
        $this->assertSame($this->ids([$admin, $inD1, $inD2]), $this->visibleIds($admin));
        $this->assertFalse($this->scope()->canActOn($admin, $inD3));
    }

    public function test_expired_acting_scope_grants_nothing(): void
    {
        $actor = $this->user('Team Lead');
        $this->grant($actor, $this->d1, ['scope_type' => UserDepartmentScope::TYPE_ACTING, 'expires_at' => now()->subMinute()]);
        $inD1 = $this->user('Employee', $this->d1);

        $this->assertSame([], $this->scope()->managedDepartmentIds($actor));
        $this->assertSame($this->ids([$actor]), $this->visibleIds($actor));
        $this->assertFalse($this->scope()->canActOn($actor, $inD1));
    }

    public function test_acting_scope_stops_applying_the_moment_it_expires(): void
    {
        $actor = $this->user('Team Lead');
        $this->grant($actor, $this->d1, ['scope_type' => UserDepartmentScope::TYPE_ACTING, 'expires_at' => now()->addHour()]);
        $inD1 = $this->user('Employee', $this->d1);

        $this->assertTrue($this->scope()->canActOn($actor, $inD1));

        $this->travel(61)->minutes();

        $this->assertFalse($this->scope()->canActOn($actor, $inD1));
    }

    public function test_future_start_is_not_active_until_it_starts(): void
    {
        $actor = $this->user('Team Lead');
        $this->grant($actor, $this->d1, ['starts_at' => now()->addDay()]);
        $inD1 = $this->user('Employee', $this->d1);

        $this->assertSame([], $this->scope()->managedDepartmentIds($actor));
        $this->assertFalse($this->scope()->canActOn($actor, $inD1));

        $this->travel(2)->days();

        $this->assertSame([$this->d1->id], $this->scope()->managedDepartmentIds($actor));
    }

    public function test_department_manager_id_grants_that_department(): void
    {
        $head = $this->user('Employee', $this->d3);
        $this->d2->update(['manager_id' => $head->employee_id]);
        $inD2 = $this->user('Employee', $this->d2);
        $inD3 = $this->user('Employee', $this->d3);

        $this->assertSame([$this->d2->id], $this->scope()->managedDepartmentIds($head));
        $this->assertSame($this->ids([$head, $inD2]), $this->visibleIds($head));
        $this->assertFalse($this->scope()->canActOn($head, $inD3), 'Heading D2 does not open the head\'s own D3.');
    }

    public function test_department_manager_role_and_department_admin_permission_cover_own_department(): void
    {
        $dm = $this->user('Department Manager', $this->d1);
        $permHolder = $this->user('Employee', $this->d2);
        $permHolder->givePermissionTo(DepartmentScope::DEPARTMENT_ADMIN_PERMISSION);

        $this->assertSame([$this->d1->id], $this->scope()->managedDepartmentIds($dm));
        $this->assertSame([$this->d2->id], $this->scope()->managedDepartmentIds($permHolder));
    }

    public function test_null_department_without_scopes_fails_closed_to_self(): void
    {
        $dm = $this->user('Department Manager');
        $employee = $this->user('Employee');
        $this->user('Employee');
        $this->user('Employee', $this->d1);

        $this->assertSame([], $this->scope()->managedDepartmentIds($dm));
        $this->assertSame($this->ids([$dm]), $this->visibleIds($dm));
        $this->assertSame($this->ids([$employee]), $this->visibleIds($employee));
    }

    public function test_report_to_subtree_is_visible_including_indirect_reports(): void
    {
        $lead = $this->user('Team Lead', $this->d1);
        $direct = $this->user('Employee', $this->d2, ['report_to' => $lead->employee_id]);
        $indirect = $this->user('Employee', $this->d3, ['report_to' => $direct->employee_id]);
        $peer = $this->user('Employee', $this->d1);

        $this->assertSame($this->ids([$lead, $direct, $indirect]), $this->visibleIds($lead));
        $this->assertTrue($this->scope()->canActOn($lead, $indirect));
        $this->assertFalse($this->scope()->canActOn($lead, $peer));
    }

    public function test_self_is_allowed_only_when_the_caller_opts_in(): void
    {
        $hr = $this->user('HR Manager');

        $this->assertFalse($this->scope()->canActOn($hr, $hr));
        $this->assertTrue($this->scope()->canActOn($hr, $hr, allowSelf: true));
    }

    public function test_employee_owned_records_follow_scope_and_keep_former_employees(): void
    {
        $admin = $this->user('Department Admin');
        $this->grant($admin, $this->d1);
        $former = $this->user('Employee', $this->d1);
        $outsider = $this->user('Employee', $this->d2);
        // Any table keyed by an employee FK works; user_department_scopes.user_id is one.
        $formerRow = $this->grant($former, $this->d3);
        $outsiderRow = $this->grant($outsider, $this->d3);
        $former->delete();

        $visible = $this->scope()->applyToEmployeeOwned(UserDepartmentScope::query(), $admin, 'user_id')->pluck('id')->all();

        $this->assertContains($formerRow->id, $visible, 'A soft-deleted employee stays visible to their department admin.');
        $this->assertNotContains($outsiderRow->id, $visible);
    }

    public function test_can_manage_requires_outranking_for_non_global_actors(): void
    {
        $admin = $this->user('Department Admin');
        $this->grant($admin, $this->d1);
        $employee = $this->user('Employee', $this->d1);
        $administratorInD1 = $this->user('Administrator', $this->d1);
        $noRole = $this->user(null, $this->d1);

        $this->assertTrue($this->scope()->canManage($admin, $employee));
        $this->assertTrue($this->scope()->canManage($admin, $noRole));
        $this->assertTrue($this->scope()->canActOn($admin, $administratorInD1));
        $this->assertFalse($this->scope()->canManage($admin, $administratorInD1), 'In scope, but outranks the actor.');
        $this->assertTrue($this->scope()->outranks($administratorInD1, $admin));
        $this->assertFalse($this->scope()->outranks($noRole, $employee));
    }
}
