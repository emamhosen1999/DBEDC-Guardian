<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class NavigationRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear Spatie cached permissions
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Ensure default roles exist
        Role::findOrCreate('Super Administrator');
        Role::findOrCreate('Employee');
    }

    /**
     * Test that all navigation routes redirect unauthenticated users to the login page.
     */
    public function test_guest_is_redirected_to_login_for_all_navigation_routes(): void
    {
        $routes = [
            'dashboard',
            'daily-works-unified',
            'attendance.unified',
            'leaves-employee',
            'petty-cash.index',
            'organization.index',
            'holidays',
            'leaves.index',
            'users',
            'admin.settings.company',
            'request-logs.index',
            'admin.system-monitoring',
        ];

        foreach ($routes as $routeName) {
            $response = $this->get(route($routeName));
            $response->assertRedirect(route('login'));
        }
    }

    /**
     * Test permission-protected routes return 403 Forbidden for a user without permissions.
     */
    public function test_unauthorized_user_is_forbidden_from_accessing_protected_navigation_routes(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');
        $this->actingAs($user);

        $protectedRoutes = [
            'dashboard',
            'daily-works-unified',
            'attendance.unified',
            'leaves-employee',
            'holidays',
            'leaves.index',
            'users',
            'admin.settings.company',
            'request-logs.index',
            'admin.system-monitoring',
        ];

        foreach ($protectedRoutes as $routeName) {
            $response = $this->get(route($routeName));
            $response->assertStatus(403);
        }
    }

    /**
     * Test authorized access for each navigation route.
     */
    public function test_authorized_user_can_access_dashboard(): void
    {
        $user = $this->createUserWithPermission('core.dashboard.view');
        $response = $this->actingAs($user)->get(route('dashboard'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_daily_works(): void
    {
        $user = $this->createUserWithPermission('daily-works.view');
        $response = $this->actingAs($user)->get(route('daily-works-unified'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_own_leaves(): void
    {
        $user = $this->createUserWithPermission('leave.own.view');
        $response = $this->actingAs($user)->get(route('leaves-employee'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_attendances_admin_page(): void
    {
        $user = $this->createUserWithPermission('attendance.view');
        // Ensure AttendanceSetting and AttendanceType exist for the page render
        AttendanceSetting::create();
        $response = $this->actingAs($user)->get(route('attendance.unified'));
        $response->assertStatus(200);
    }

    public function test_any_authenticated_user_can_access_petty_cash(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('petty-cash.index'));
        $response->assertStatus(200);
    }

    public function test_any_authenticated_user_can_access_organization_directory(): void
    {
        $user = User::factory()->create();
        $response = $this->actingAs($user)->get(route('organization.index'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_holidays(): void
    {
        $user = $this->createUserWithPermission('holidays.view');
        $response = $this->actingAs($user)->get(route('holidays'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_leaves_management(): void
    {
        $user = $this->createUserWithPermission('leaves.view');
        $response = $this->actingAs($user)->get(route('leaves.index'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_users_roles(): void
    {
        $user = $this->createUserWithPermission('users.view');
        $response = $this->actingAs($user)->get(route('users'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_company_settings(): void
    {
        $user = $this->createUserWithPermission('company.settings');
        // Ensure CompanySetting exists for the page render
        CompanySetting::create([
            'companyName' => 'Aero Corp',
            'country' => 'Bangladesh',
            'city' => 'Dhaka',
            'state' => 'Dhaka',
            'postalCode' => '1200',
            'email' => 'aero@corp.com',
        ]);
        $response = $this->actingAs($user)->get(route('admin.settings.company'));
        $response->assertStatus(200);
    }

    public function test_authorized_user_can_access_request_logs(): void
    {
        $user = $this->createUserWithPermission('request_logs.view');
        Permission::findOrCreate('attendance.settings');
        $user->givePermissionTo('attendance.settings');

        $response = $this->actingAs($user)->get(route('request-logs.index'));
        $response->assertStatus(200);
    }

    public function test_super_administrator_can_access_monitoring(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Super Administrator');
        $response = $this->actingAs($user)->get(route('admin.system-monitoring'));
        $response->assertStatus(200);
    }

    /**
     * Helper to create a user and assign a permission.
     */
    private function createUserWithPermission(string $permission): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate($permission);
        $user->givePermissionTo($permission);

        return $user;
    }
}
