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
 * Guards the user-update / role-grant paths against privilege escalation:
 * self-edits, role hierarchy, HR-only salary and admin-only employee_id changes.
 */
class SelfEscalationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'Super Administrator' => 1,
            'Administrator' => 10,
            'HR Manager' => 20,
            'Department Manager' => 30,
            'Team Lead' => 40,
            'Employee' => 60,
        ] as $name => $level) {
            Role::create(['name' => $name, 'guard_name' => 'web', 'hierarchy_level' => $level]);
        }

        foreach (['users.update', 'users.view', 'users.delete', 'users.create', 'employees.view', 'employees.update', 'employees.create'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    private function makeUser(string $role, array $attrs = []): User
    {
        $user = User::factory()->create($attrs);
        $user->assignRole($role);
        $user->givePermissionTo('users.update');

        return $user;
    }

    public function test_hr_manager_cannot_grant_self_super_administrator_via_profile_update(): void
    {
        $hr = $this->makeUser('HR Manager', ['salary_amount' => 1000]);

        $this->actingAs($hr)
            ->putJson(route('users.update', $hr->employee_id), [
                'name' => 'Renamed HR',
                'roles' => ['Super Administrator'],
                'salary_amount' => 999999,
            ])
            ->assertOk();

        $hr->refresh();
        $this->assertSame(['HR Manager'], $hr->getRoleNames()->all());
        $this->assertSame('Renamed HR', $hr->name);
        $this->assertEquals(1000, $hr->salary_amount);
    }

    public function test_self_edit_ignores_org_and_identity_fields(): void
    {
        $dept = Department::factory()->create();
        $other = Department::factory()->create();
        $hr = $this->makeUser('HR Manager', ['department_id' => $dept->id, 'employee_id' => 'EMP-SELF-1']);

        $this->actingAs($hr)
            ->putJson(route('users.update', $hr->employee_id), [
                'name' => 'Same Person',
                'department_id' => $other->id,
                'employee_id' => 'EMP-HIJACK',
            ])
            ->assertOk();

        $hr->refresh();
        $this->assertSame('EMP-SELF-1', $hr->employee_id);
        $this->assertSame($dept->id, $hr->department_id);
    }

    public function test_super_administrator_self_edit_cannot_change_roles_but_may_change_other_fields(): void
    {
        $sa = $this->makeUser('Super Administrator');

        $this->actingAs($sa)
            ->putJson(route('users.update', $sa->employee_id), [
                'name' => 'Boss',
                'roles' => ['Employee'],
            ])
            ->assertOk();

        $sa->refresh();
        $this->assertSame(['Super Administrator'], $sa->getRoleNames()->all());
        $this->assertSame('Boss', $sa->name);
    }

    public function test_hr_manager_cannot_change_another_users_roles_through_update(): void
    {
        $hr = $this->makeUser('HR Manager');
        $target = User::factory()->create();
        $target->assignRole('Employee');

        $this->actingAs($hr)
            ->putJson(route('users.update', $target->employee_id), ['roles' => ['Administrator']])
            ->assertForbidden();

        $this->assertSame(['Employee'], $target->fresh()->getRoleNames()->all());
    }

    public function test_hr_manager_cannot_grant_administrator_or_super_administrator_via_role_endpoint(): void
    {
        $hr = $this->makeUser('HR Manager');
        $target = User::factory()->create();
        $target->assignRole('Employee');

        foreach (['Administrator', 'Super Administrator'] as $role) {
            $this->actingAs($hr)
                ->postJson(route('users.updateRole', $target->employee_id), ['roles' => [$role]])
                ->assertForbidden();
        }

        $this->assertSame(['Employee'], $target->fresh()->getRoleNames()->all());
    }

    public function test_administrator_cannot_grant_administrator_but_can_grant_hr_manager(): void
    {
        $admin = $this->makeUser('Administrator');
        $target = User::factory()->create();
        $target->assignRole('Employee');

        $this->actingAs($admin)
            ->postJson(route('users.updateRole', $target->employee_id), ['roles' => ['Administrator']])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('users.updateRole', $target->employee_id), ['roles' => ['HR Manager']])
            ->assertOk();

        $this->assertSame(['HR Manager'], $target->fresh()->getRoleNames()->all());
    }

    public function test_administrator_can_grant_hr_manager_through_update_endpoint(): void
    {
        $admin = $this->makeUser('Administrator');
        $target = User::factory()->create();
        $target->assignRole('Employee');

        $this->actingAs($admin)
            ->putJson(route('users.update', $target->employee_id), ['roles' => ['HR Manager']])
            ->assertOk();

        $this->assertSame(['HR Manager'], $target->fresh()->getRoleNames()->all());
    }

    public function test_bulk_assign_enforces_hierarchy(): void
    {
        $admin = $this->makeUser('Administrator');
        $target = User::factory()->create();
        $target->assignRole('Employee');

        $this->actingAs($admin)
            ->postJson(route('users.bulk.role'), ['user_ids' => [$target->employee_id], 'role' => 'Administrator'])
            ->assertForbidden();

        $this->actingAs($admin)
            ->postJson(route('users.bulk.role'), ['user_ids' => [$target->employee_id], 'role' => 'HR Manager'])
            ->assertOk();
    }

    public function test_direct_permission_endpoints_require_role_management_and_forbid_self(): void
    {
        Permission::findOrCreate('payroll.manage', 'web');
        $hr = $this->makeUser('HR Manager');
        $employee = User::factory()->create();
        $employee->assignRole('Employee');

        // HR Manager lacks updateRoles (Super Administrator / Administrator only).
        $this->actingAs($hr)
            ->postJson("/api/users/{$employee->employee_id}/permissions/give", ['permission' => 'payroll.manage'])
            ->assertForbidden();
        $this->actingAs($hr)
            ->postJson("/api/users/{$hr->employee_id}/permissions", ['permissions' => ['payroll.manage']])
            ->assertForbidden();

        $admin = $this->makeUser('Administrator');
        $this->actingAs($admin)
            ->postJson("/api/users/{$admin->employee_id}/permissions/give", ['permission' => 'payroll.manage'])
            ->assertForbidden();
        $this->actingAs($admin)
            ->postJson("/api/users/{$employee->employee_id}/permissions/give", ['permission' => 'payroll.manage'])
            ->assertOk();
        $this->assertTrue($employee->fresh()->hasDirectPermission('payroll.manage'));
    }

    public function test_only_hr_level_actor_may_change_salary_of_another_user(): void
    {
        $dept = Department::factory()->create();
        $dm = $this->makeUser('Department Manager', ['department_id' => $dept->id]);
        $target = User::factory()->create(['department_id' => $dept->id, 'salary_amount' => 1000]);
        $target->assignRole('Employee');

        $this->actingAs($dm)
            ->putJson(route('users.update', $target->employee_id), ['salary_amount' => 5000])
            ->assertForbidden();
        $this->assertEquals(1000, $target->fresh()->salary_amount);

        // Echoing the unchanged value back (edit form behaviour) is fine.
        $this->actingAs($dm)
            ->putJson(route('users.update', $target->employee_id), ['salary_amount' => 1000, 'phone' => '01700000001'])
            ->assertOk();

        $hr = $this->makeUser('HR Manager');
        $this->actingAs($hr)
            ->putJson(route('users.update', $target->employee_id), ['salary_amount' => 5000])
            ->assertOk();
        $this->assertEquals(5000, $target->fresh()->salary_amount);
    }

    public function test_only_administrator_or_above_may_change_employee_id(): void
    {
        $hr = $this->makeUser('HR Manager');
        $target = User::factory()->create(['employee_id' => 'EMP-OLD-1']);
        $target->assignRole('Employee');

        $this->actingAs($hr)
            ->putJson(route('users.update', 'EMP-OLD-1'), ['employee_id' => 'EMP-NEW-1'])
            ->assertForbidden();
        $this->assertDatabaseHas('users', ['employee_id' => 'EMP-OLD-1']);
    }

    public function test_department_manager_editing_team_lead_keeps_team_lead_role(): void
    {
        $dept = Department::factory()->create();
        $dm = $this->makeUser('Department Manager', ['department_id' => $dept->id]);
        $lead = User::factory()->create(['department_id' => $dept->id]);
        $lead->assignRole('Team Lead');

        $this->actingAs($dm)
            ->putJson(route('users.update', $lead->employee_id), [
                'phone' => '01700000009',
                'roles' => ['Employee'],
            ])
            ->assertOk();

        $lead->refresh();
        $this->assertSame('01700000009', $lead->phone);
        $this->assertSame(['Team Lead'], $lead->getRoleNames()->all());
    }

    public function test_administrator_cannot_strip_roles_from_another_administrator(): void
    {
        $actor = $this->makeUser('Administrator');
        $peer = User::factory()->create();
        $peer->assignRole('Administrator');

        $this->actingAs($actor)
            ->postJson(route('users.updateRole', $peer->employee_id), ['roles' => ['Employee']])
            ->assertForbidden();

        $this->actingAs($actor)
            ->putJson(route('users.update', $peer->employee_id), ['roles' => ['Employee']])
            ->assertForbidden();

        $this->actingAs($actor)
            ->postJson(route('users.bulk.role'), ['user_ids' => [$peer->employee_id], 'role' => 'Employee'])
            ->assertForbidden();

        $this->assertSame(['Administrator'], $peer->refresh()->getRoleNames()->all());
    }

    public function test_administrator_can_change_hr_managers_roles(): void
    {
        $actor = $this->makeUser('Administrator');
        $hr = User::factory()->create();
        $hr->assignRole('HR Manager');

        $this->actingAs($actor)
            ->postJson(route('users.updateRole', $hr->employee_id), ['roles' => ['Department Manager']])
            ->assertOk();

        $this->assertSame(['Department Manager'], $hr->refresh()->getRoleNames()->all());
    }
}
