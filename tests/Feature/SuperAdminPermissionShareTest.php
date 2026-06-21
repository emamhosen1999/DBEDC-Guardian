<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Super Administrator authority comes from a Gate::before bypass (AuthServiceProvider),
 * not from explicitly-assigned permissions. The frontend gates UI on
 * auth.permissions.includes(...), so HandleInertiaRequests must hand Super Admins the
 * full permission list — otherwise SA silently loses admin UI (e.g. the attendance
 * Approvals tab) despite the backend allowing the action.
 */
class SuperAdminPermissionShareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Super Administrator']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
        Permission::firstOrCreate(['name' => 'attendance.manage']);
    }

    public function test_super_admin_shared_permissions_include_unassigned_abilities(): void
    {
        $sa = User::factory()->create();
        $sa->assignRole('Super Administrator'); // NOTE: no explicit permissions assigned

        $this->actingAs($sa)
            ->get(route('attendance.unified'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.isSuperAdmin', true)
                // attendance.manage is NEVER assigned to SA, yet must be present so the
                // frontend Approvals tab (gated on permissions.includes('attendance.manage')) shows.
                ->where('auth.permissions', fn ($perms) => collect($perms)->contains('attendance.manage')
                    && collect($perms)->contains('attendance.view'))
            );
    }

    public function test_regular_user_only_gets_assigned_permissions(): void
    {
        $emp = User::factory()->create();
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.view');

        $this->actingAs($emp)
            ->get(route('attendance.unified'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('auth.isSuperAdmin', false)
                ->where('auth.permissions', fn ($perms) => collect($perms)->contains('attendance.view')
                    && ! collect($perms)->contains('attendance.manage'))
            );
    }
}
