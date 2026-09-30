<?php

namespace Tests\Feature\Security;

use App\Models\HRM\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * UserPolicy department scoping: non-global actors are limited to their own
 * (non-null) department for view / toggleStatus / manageDevices.
 */
class UserPolicyScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Real hierarchy levels: write checks require a non-global actor to outrank the target.
        foreach (['Super Administrator' => 1, 'Administrator' => 10, 'HR Manager' => 20, 'Department Manager' => 30, 'Employee' => 60] as $name => $level) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'hierarchy_level' => $level]);
        }
        foreach (['users.view', 'users.update', 'employees.view', 'employees.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function makeUser(string $role, ?int $departmentId): User
    {
        $user = User::factory()->create(['department_id' => $departmentId]);
        $user->assignRole($role);
        $user->givePermissionTo(['users.view', 'users.update']);

        return $user;
    }

    public function test_department_manager_cannot_toggle_status_or_manage_devices_of_other_department_user(): void
    {
        [$deptA, $deptB] = [Department::factory()->create(), Department::factory()->create()];
        $dm = $this->makeUser('Department Manager', $deptA->id);
        $ownDept = $this->makeUser('Employee', $deptA->id);
        $otherDept = $this->makeUser('Employee', $deptB->id);

        $this->assertTrue($dm->can('toggleStatus', $ownDept));
        $this->assertFalse($dm->can('toggleStatus', $otherDept));
        $this->assertTrue($dm->can('manageDevices', $ownDept));
        $this->assertFalse($dm->can('manageDevices', $otherDept));
    }

    public function test_department_manager_without_department_fails_closed(): void
    {
        $dm = $this->makeUser('Department Manager', null);
        $noDeptTarget = $this->makeUser('Employee', null);

        $this->assertFalse($dm->can('toggleStatus', $noDeptTarget));
        $this->assertFalse($dm->can('manageDevices', $noDeptTarget));
        $this->assertFalse($dm->can('view', $noDeptTarget));
        $this->assertTrue($dm->can('view', $dm));
    }

    public function test_view_is_department_scoped_for_non_global_and_open_for_global(): void
    {
        [$deptA, $deptB] = [Department::factory()->create(), Department::factory()->create()];
        $dm = $this->makeUser('Department Manager', $deptA->id);
        $hr = $this->makeUser('HR Manager', $deptA->id);
        $otherDept = $this->makeUser('Employee', $deptB->id);
        $ownDept = $this->makeUser('Employee', $deptA->id);

        $this->assertTrue($dm->can('view', $ownDept));
        $this->assertFalse($dm->can('view', $otherDept));
        $this->assertTrue($hr->can('view', $otherDept));
    }

    public function test_global_roles_can_toggle_status_across_departments_and_never_self(): void
    {
        [$deptA, $deptB] = [Department::factory()->create(), Department::factory()->create()];
        $hr = $this->makeUser('HR Manager', $deptA->id);
        $otherDept = $this->makeUser('Employee', $deptB->id);

        $this->assertTrue($hr->can('toggleStatus', $otherDept));
        $this->assertFalse($hr->can('toggleStatus', $hr));
    }
}
