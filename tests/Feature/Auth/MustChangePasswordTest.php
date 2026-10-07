<?php

namespace Tests\Feature\Auth;

use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * An admin-set password is known to the admin: it is flagged (users.must_change_password) and, while
 * flagged, the account can do nothing but change it (web: redirect; API: 423 except me/logout/change).
 */
class MustChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Str0ng!Passw0rd#2026';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Super Administrator' => 1, 'HR Manager' => 20, 'Employee' => 60] as $name => $level) {
            Role::updateOrCreate(['name' => $name, 'guard_name' => 'web'], ['hierarchy_level' => $level]);
        }
        foreach (['employees.password.reset', 'employees.create', 'employees.update', 'core.dashboard.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findByName('HR Manager')->syncPermissions(Permission::all());
    }

    private function hr(): User
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Manager');

        return $hr;
    }

    private function flagged(): User
    {
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);
        $user->assignRole('Employee');
        $user->givePermissionTo('core.dashboard.view');
        $user->forceFill(['must_change_password' => true])->save();

        return $user;
    }

    public function test_an_admin_reset_or_an_admin_set_password_raises_the_flag(): void
    {
        $hr = $this->hr();
        $target = User::factory()->create();
        $target->assignRole('Employee');
        $this->assertFalse((bool) $target->fresh()->must_change_password, 'default is off');

        $this->actingAs($hr)->postJson(route('users.changePassword', $target->employee_id), ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertOk();
        $this->assertTrue((bool) $target->fresh()->must_change_password);

        $created = $this->actingAs($hr)->postJson(route('users.store'), [
            'name' => 'New Hire', 'user_name' => 'newhire', 'email' => 'nh@example.com', 'employee_id' => 'NH-1',
            'password' => self::STRONG, 'password_confirmation' => self::STRONG,
            // creation requires a way to check in (see AttendanceMethodRequiredTest)
            'attendance_type_ids' => [AttendanceType::factory()->create(['is_active' => true])->id],
        ])->assertCreated();
        $this->assertTrue((bool) User::find($created->json('user.employee_id'))->must_change_password);

        // editing someone else's password through the edit form counts as an admin reset too
        $other = User::factory()->create();
        $other->assignRole('Employee');
        $this->actingAs($hr)->putJson(route('users.update', $other->employee_id), ['password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertOk();
        $this->assertTrue((bool) $other->fresh()->must_change_password);
    }

    public function test_web_pages_redirect_to_the_change_password_page_until_it_is_changed(): void
    {
        $user = $this->flagged();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('account.password.edit'));
        $this->actingAs($user)->get(route('employees'))->assertRedirect(route('account.password.edit'));
        $this->actingAs($user)->getJson('/employees/paginate')->assertStatus(423)->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');

        $page = $this->actingAs($user)->get(route('account.password.edit'))->assertOk()->viewData('page');
        $this->assertTrue($page['props']['forced']);
        $this->assertTrue($page['props']['auth']['user']['must_change_password']);
        $this->assertTrue($page['props']['auth']['mustChangePassword']);

        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'wrong', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertSessionHasErrors('current_password');
        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'old-password-1', 'password' => 'old-password-1', 'password_confirmation' => 'old-password-1'])->assertSessionHasErrors('password');
        $this->assertTrue((bool) $user->fresh()->must_change_password);

        $this->actingAs($user)->put(route('account.password.update'), ['current_password' => 'old-password-1', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertRedirect();
        $this->assertFalse((bool) $user->fresh()->must_change_password);
        $this->assertTrue(Hash::check(self::STRONG, $user->fresh()->password));
        $this->actingAs($user->fresh())->get(route('dashboard'))->assertRedirect(route('employee-dashboard'));
    }

    public function test_the_flag_never_blocks_logging_out_and_a_normal_user_is_untouched(): void
    {
        $this->actingAs($this->flagged())->post(route('logout'))->assertRedirect();

        $normal = User::factory()->create();
        $normal->assignRole('Employee');
        $normal->givePermissionTo('core.dashboard.view');
        $this->actingAs($normal)->get(route('dashboard'))->assertRedirect(route('employee-dashboard'));
    }

    public function test_the_api_allows_only_me_logout_and_the_password_change_while_flagged(): void
    {
        $user = $this->flagged();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
        $this->getJson('/api/v1/manager/team-members')->assertStatus(423)->assertJsonPath('error_code', 'PASSWORD_CHANGE_REQUIRED');

        $this->postJson('/api/v1/account/change-password', ['current_password' => 'old-password-1', 'new_password' => self::STRONG, 'new_password_confirmation' => self::STRONG])->assertOk();
        $this->assertFalse((bool) $user->fresh()->must_change_password);

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->assertNotSame(423, $this->getJson('/api/v1/manager/team-members')->getStatusCode(), 'no longer gated by the password change');
    }

    public function test_the_mobile_login_response_carries_the_flag(): void
    {
        $user = $this->flagged();
        $user->forceFill(['email' => 'flagged@example.com'])->save();

        $response = $this->postJson('/api/v1/auth/login', ['email' => 'flagged@example.com', 'password' => 'old-password-1', 'device_id' => '3f2b8c1e-9a4d-4b7e-8c3a-1d2e3f4a5b6c', 'device_name' => 'Test Phone',
            'device_signature' => ['platform' => 'android', 'os_version' => '14', 'model' => 'Pixel']]);
        $response->assertOk()->assertJsonPath('data.user.must_change_password', true);
    }
}
