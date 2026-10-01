<?php

namespace Tests\Feature\Access;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Services\Access\DepartmentScope;
use Database\Seeders\ComprehensiveRolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Designation CRUD is delegated to a department admin for his OWN department(s): scoped lists, creation
 * and edits only inside the departments he administers, a parent designation that stays in the same
 * department, and no deleting a designation employees hold (reassign or deactivate instead).
 */
class DesignationScopeTest extends TestCase
{
    use RefreshDatabase;

    private Department $d1;

    private Department $d2;

    private User $admin;   // Department Admin of D1

    private User $hr;      // company-wide

    private Designation $head1;  // D1, top of the tree

    private Designation $dev1;   // D1, child of head1

    private Designation $head2;  // D2

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Administrator' => 1, 'HR Manager' => 20, 'Department Admin' => 25, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findByName('Department Admin')->syncPermissions(ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames());
        Role::findByName('HR Manager')->syncPermissions(Permission::all());

        [$this->d1, $this->d2] = [Department::factory()->create(), Department::factory()->create()];
        $this->admin = User::factory()->create(['department_id' => $this->d1->id]);
        $this->admin->assignRole('Department Admin');
        $this->hr = User::factory()->create(['department_id' => null]);
        $this->hr->assignRole('HR Manager');

        $this->head1 = Designation::factory()->create(['department_id' => $this->d1->id, 'title' => 'Head Inspector', 'hierarchy_level' => 1, 'parent_id' => null, 'is_active' => true]);
        $this->dev1 = Designation::factory()->create(['department_id' => $this->d1->id, 'title' => 'Inspector', 'hierarchy_level' => 2, 'parent_id' => $this->head1->id, 'is_active' => true]);
        $this->head2 = Designation::factory()->create(['department_id' => $this->d2->id, 'title' => 'Ops Head', 'hierarchy_level' => 1, 'parent_id' => null, 'is_active' => true]);
    }

    private function payload(int $departmentId, array $extra = []): array
    {
        return array_merge(['title' => 'Senior Inspector', 'department_id' => $departmentId, 'hierarchy_level' => 3, 'parent_id' => null, 'is_active' => true], $extra);
    }

    public function test_lists_and_stats_show_only_his_departments_designations(): void
    {
        $titles = collect($this->actingAs($this->admin)->getJson(route('designations.json'))->assertOk()->json('designations.data'))->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Head Inspector', 'Inspector'], $titles);

        $this->assertSame(2, $this->actingAs($this->admin)->getJson(route('designations.stats'))->assertOk()->json('stats.total'));
        $this->assertSame(3, $this->actingAs($this->hr)->getJson(route('designations.stats'))->assertOk()->json('stats.total'));
        $this->assertCount(3, $this->actingAs($this->hr)->getJson(route('designations.json'))->json('designations.data'));

        $list = collect($this->actingAs($this->admin)->getJson(route('designations.list'))->assertOk()->json())->pluck('title')->all();
        $this->assertEqualsCanonicalizing(['Head Inspector', 'Inspector'], $list);

        $this->actingAs($this->admin)->getJson(route('designations.show', $this->dev1->id))->assertOk();
        $this->actingAs($this->admin)->getJson(route('designations.show', $this->head2->id))->assertNotFound();

        $props = $this->actingAs($this->admin)->get(route('employees'))->assertOk()->viewData('page')['props'];
        $this->assertEqualsCanonicalizing([$this->head1->id, $this->dev1->id], collect($props['allDesignations'])->pluck('id')->all());
    }

    public function test_a_department_admin_creates_designations_in_his_own_department_only(): void
    {
        $this->actingAs($this->admin)->postJson(route('designations.store'), $this->payload($this->d1->id, ['parent_id' => $this->head1->id]))
            ->assertCreated()->assertJsonPath('designation.department_id', $this->d1->id);
        $this->assertDatabaseHas('designations', ['title' => 'Senior Inspector', 'department_id' => $this->d1->id]);

        $this->actingAs($this->admin)->postJson(route('designations.store'), $this->payload($this->d2->id, ['title' => 'Sneaky']))->assertForbidden();
        $this->assertDatabaseMissing('designations', ['title' => 'Sneaky']);

        // global HR can create anywhere
        $this->actingAs($this->hr)->postJson(route('designations.store'), $this->payload($this->d2->id, ['title' => 'By HR']))->assertCreated();
    }

    public function test_the_parent_designation_must_stay_in_the_same_department(): void
    {
        $this->actingAs($this->admin)->postJson(route('designations.store'), $this->payload($this->d1->id, ['title' => 'Cross Parent', 'parent_id' => $this->head2->id]))
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->assertDatabaseMissing('designations', ['title' => 'Cross Parent']);

        // ...on update too, and never itself or one of its own descendants
        $this->actingAs($this->admin)->putJson(route('designations.update', $this->dev1->id), $this->payload($this->d1->id, ['title' => 'Inspector', 'hierarchy_level' => 2, 'parent_id' => $this->head2->id]))
            ->assertStatus(422);
        $this->actingAs($this->admin)->putJson(route('designations.update', $this->head1->id), $this->payload($this->d1->id, ['title' => 'Head Inspector', 'hierarchy_level' => 1, 'parent_id' => $this->dev1->id]))
            ->assertStatus(422)->assertJsonValidationErrors('parent_id');
        $this->actingAs($this->admin)->putJson(route('designations.update', $this->head1->id), $this->payload($this->d1->id, ['title' => 'Head Inspector', 'hierarchy_level' => 1, 'parent_id' => $this->head1->id]))
            ->assertStatus(422);
        $this->assertNull($this->head1->fresh()->parent_id);
    }

    public function test_updates_stay_inside_his_departments(): void
    {
        $this->actingAs($this->admin)->putJson(route('designations.update', $this->dev1->id), $this->payload($this->d1->id, ['title' => 'Inspector II', 'hierarchy_level' => 2, 'parent_id' => $this->head1->id]))->assertOk();
        $this->assertSame('Inspector II', $this->dev1->fresh()->title);

        $this->actingAs($this->admin)->putJson(route('designations.update', $this->head2->id), $this->payload($this->d2->id, ['title' => 'Hijack']))->assertForbidden();
        $this->actingAs($this->admin)->putJson(route('designations.update', $this->dev1->id), $this->payload($this->d2->id, ['title' => 'Moved Away', 'parent_id' => null]))->assertForbidden();
        $this->assertSame($this->d1->id, (int) $this->dev1->fresh()->department_id);
    }

    public function test_a_designation_held_by_employees_cannot_be_deleted_but_can_be_deactivated(): void
    {
        $holder = User::factory()->create(['department_id' => $this->d1->id, 'designation_id' => $this->dev1->id]);

        $this->actingAs($this->admin)->deleteJson(route('designations.destroy', $this->dev1->id))
            ->assertStatus(422)->assertJsonPath('employee_count', 1);
        $this->assertNull($this->dev1->fresh()->deleted_at);

        // reassign-or-deactivate: deactivation keeps the employee's record intact
        $this->actingAs($this->admin)->putJson(route('designations.update', $this->dev1->id), $this->payload($this->d1->id, ['title' => 'Inspector', 'hierarchy_level' => 2, 'parent_id' => $this->head1->id, 'is_active' => false]))->assertOk();
        $this->assertFalse((bool) $this->dev1->fresh()->is_active);
        $this->assertSame($this->dev1->id, (int) $holder->fresh()->designation_id);

        // once nobody holds it (and nothing reports to it) it can go
        $holder->forceFill(['designation_id' => $this->head1->id])->save();
        $this->actingAs($this->admin)->deleteJson(route('designations.destroy', $this->dev1->id))->assertOk();
        $this->assertSoftDeleted('designations', ['id' => $this->dev1->id]);

        // a parent others report to is protected too; another department's is out of reach
        $child = Designation::factory()->create(['department_id' => $this->d1->id, 'parent_id' => $this->head1->id]);
        $this->actingAs($this->admin)->deleteJson(route('designations.destroy', $this->head1->id))->assertStatus(422);
        $this->actingAs($this->admin)->deleteJson(route('designations.destroy', $this->head2->id))->assertForbidden();
        $this->assertNotNull($child->fresh());
    }

    public function test_inline_designation_assignment_uses_the_employees_own_department(): void
    {
        $employee = User::factory()->create(['department_id' => $this->d1->id]);
        $employee->assignRole('Employee');

        $this->actingAs($this->admin)->postJson(route('users.updateDesignation', $employee->employee_id), ['designation_id' => $this->dev1->id])->assertOk();
        $this->assertSame($this->dev1->id, (int) $employee->fresh()->designation_id);

        // another department's designation: not his to hand out
        $this->actingAs($this->admin)->postJson(route('users.updateDesignation', $employee->employee_id), ['designation_id' => $this->head2->id])->assertForbidden();

        // with D2 also granted, a D2 designation still must match the EMPLOYEE's department
        UserDepartmentScope::create(['user_id' => $this->admin->employee_id, 'department_id' => $this->d2->id, 'scope_type' => 'admin']);
        app(DepartmentScope::class)->forget();
        $this->actingAs($this->admin)->postJson(route('users.updateDesignation', $employee->employee_id), ['designation_id' => $this->head2->id])->assertStatus(422);

        // and an employee outside his departments is out of reach
        $outsider = User::factory()->create(['department_id' => null]);
        $outsider->assignRole('Employee');
        $this->actingAs($this->admin)->postJson(route('users.updateDesignation', $outsider->employee_id), ['designation_id' => $this->dev1->id])->assertForbidden();
    }
}
