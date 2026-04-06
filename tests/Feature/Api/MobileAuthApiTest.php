<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_mobile_login_returns_token_and_user_payload(): void
    {
        $user = User::factory()->create([
            'active' => true,
            'email' => 'mobile.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mobile.user@example.com',
            'password' => 'secret-1234',
            'device_name' => 'Pixel 8',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', 'mobile.user@example.com');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
            'name' => 'Pixel 8',
        ]);
    }

    public function test_mobile_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'active' => true,
            'email' => 'mobile.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'mobile.user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'The provided credentials are incorrect.');
    }

    public function test_mobile_login_rejects_inactive_account(): void
    {
        User::factory()->create([
            'active' => false,
            'email' => 'inactive.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'inactive.user@example.com',
            'password' => 'secret-1234',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'This account has been deactivated. Please contact your administrator.');
    }

    public function test_mobile_auth_me_and_logout_require_token_and_work_with_valid_token(): void
    {
        $user = User::factory()->create([
            'active' => true,
            'email' => 'active.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $token = $user->createToken('Test Device')->plainTextToken;

        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/logout')->assertUnauthorized();

        $meResponse = $this->withToken($token)->getJson('/api/v1/auth/me');

        $meResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.email', 'active.user@example.com');

        $logoutResponse = $this->withToken($token)->postJson('/api/v1/auth/logout');

        $logoutResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    /**
     * A basic feature test example.
     */
    public function test_mobile_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }
}
