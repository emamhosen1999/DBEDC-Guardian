<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminResetPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Super Administrator']);
        Role::firstOrCreate(['name' => 'Manager']);
        Permission::firstOrCreate(['name' => 'users.update']);
    }

    private function managerWithUsersUpdate(): User
    {
        $manager = User::factory()->create();
        $manager->assignRole('Manager');
        $manager->givePermissionTo('users.update');

        return $manager;
    }

    public function test_admin_with_users_update_can_reset_a_user_password(): void
    {
        $actor = $this->managerWithUsersUpdate();
        $target = User::factory()->create(['password' => Hash::make('old-password-1')]);

        $response = $this->actingAs($actor)->postJson(route('users.changePassword', ['id' => $target->id]), [
            'password' => 'new-secure-pass-9',
            'password_confirmation' => 'new-secure-pass-9',
        ]);

        $response->assertOk();
        $this->assertTrue(Hash::check('new-secure-pass-9', $target->fresh()->password));
    }

    public function test_reset_requires_min_8_and_confirmation(): void
    {
        $actor = $this->managerWithUsersUpdate();
        $target = User::factory()->create();

        $this->actingAs($actor)->postJson(route('users.changePassword', ['id' => $target->id]), [
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertStatus(422);

        $this->actingAs($actor)->postJson(route('users.changePassword', ['id' => $target->id]), [
            'password' => 'long-enough-pass',
            'password_confirmation' => 'does-not-match',
        ])->assertStatus(422);
    }

    public function test_user_without_users_update_cannot_reset_password(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Manager'); // role but NO users.update permission
        $target = User::factory()->create();

        $this->actingAs($actor)->postJson(route('users.changePassword', ['id' => $target->id]), [
            'password' => 'new-secure-pass-9',
            'password_confirmation' => 'new-secure-pass-9',
        ])->assertStatus(403);
    }

    public function test_non_super_admin_cannot_reset_a_super_admin_password(): void
    {
        $actor = $this->managerWithUsersUpdate(); // has users.update but not Super Admin
        $superAdmin = User::factory()->create();
        $superAdmin->assignRole('Super Administrator');

        $this->actingAs($actor)->postJson(route('users.changePassword', ['id' => $superAdmin->id]), [
            'password' => 'new-secure-pass-9',
            'password_confirmation' => 'new-secure-pass-9',
        ])->assertStatus(403);

        // The super admin's password must be unchanged.
        $this->assertFalse(Hash::check('new-secure-pass-9', $superAdmin->fresh()->password));
    }

    public function test_super_admin_can_reset_a_super_admin_password(): void
    {
        $actor = User::factory()->create();
        $actor->assignRole('Super Administrator');
        $target = User::factory()->create();
        $target->assignRole('Super Administrator');

        $this->actingAs($actor)->postJson(route('users.changePassword', ['id' => $target->id]), [
            'password' => 'new-secure-pass-9',
            'password_confirmation' => 'new-secure-pass-9',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure-pass-9', $target->fresh()->password));
    }
}
