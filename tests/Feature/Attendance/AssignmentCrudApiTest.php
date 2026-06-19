<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssignmentCrudApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');

        return $admin;
    }

    public function test_lists_assignments_with_shift_and_scope(): void
    {
        $shift = Shift::factory()->create(['code' => 'MOR', 'name' => 'Morning']);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => 7, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $res = $this->actingAs($this->admin())->getJson(route('attendance.assignments.index'));

        $res->assertOk();
        $res->assertJsonPath('assignments.0.scope_type', 'user');
        $res->assertJsonPath('assignments.0.shift.code', 'MOR');
    }

    public function test_deletes_an_assignment(): void
    {
        $shift = Shift::factory()->create();
        $a = ShiftAssignment::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson(route('attendance.assignments.destroy', $a->id))
            ->assertOk();

        $this->assertDatabaseMissing('shift_assignments', ['id' => $a->id]);
    }

    public function test_employee_without_permission_forbidden(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.assignments.index'))->assertForbidden();
    }
}
