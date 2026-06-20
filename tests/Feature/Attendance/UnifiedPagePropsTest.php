<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UnifiedPagePropsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_unified_page_includes_employees_and_designations(): void
    {
        // A designation WITH a department: serializing it must NOT lazy-load `department`
        // (the Designation model appends department_name). Reproduces the dev 500 / prod N+1
        // if the controller passes Eloquent models instead of plain arrays.
        $department = Department::factory()->create();
        $designation = Designation::factory()->create(['department_id' => $department->id]);

        $emp = User::factory()->create([
            'name' => 'Picker Emp',
            'department_id' => $department->id,
            'designation_id' => $designation->id,
        ]);
        $emp->assignRole('Employee');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $this->actingAs($admin)
            ->get(route('attendance.unified'))
            ->assertOk() // must render, not 500 from a lazy-loading violation
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('employees', 1)
                ->has('designations', 1)
                ->where('designations.0.title', $designation->title)
                // Guard the regression: props must be plain arrays, NOT Eloquent models.
                // A serialized Designation model would append `department_name` (which also
                // lazy-loads `department` and 500s in dev). Plain arrays omit it.
                ->missing('designations.0.department_name')
            );
    }
}
