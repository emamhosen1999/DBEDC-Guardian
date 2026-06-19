<?php

namespace Tests\Feature\Attendance;

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
        $emp = User::factory()->create(['name' => 'Picker Emp']);
        $emp->assignRole('Employee');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $this->actingAs($admin)
            ->get(route('attendance.unified'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('employees')
                ->has('designations')
            );
    }
}
