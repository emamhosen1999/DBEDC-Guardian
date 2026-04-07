<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileAuthApiTest extends TestCase
{
    use RefreshDatabase;

    private function mobileDeviceSignature(array $overrides = []): array
    {
        return array_merge([
            'signature' => 'fnv1a-9b71d224',
            'platform' => 'android',
            'os_version' => '14',
            'model' => 'Pixel 8 Pro',
            'manufacturer' => 'Google',
            'brand' => 'google',
            'hardware_id' => 'google-fingerprint-01',
            'app_version' => '1.0.0',
            'build_version' => '100',
            'mac_address' => '',
        ], $overrides);
    }

    private function mobileDevicePayload(array $overrides = []): array
    {
        return array_merge([
            'device_name' => 'Pixel 8',
            'device_id' => '7b25d7a4-6ee2-4e8d-98d0-3eb4bfef2f90',
            'device_signature' => $this->mobileDeviceSignature(),
        ], $overrides);
    }

    public function test_mobile_login_returns_token_and_user_payload(): void
    {
        $user = User::factory()->create([
            'active' => true,
            'email' => 'mobile.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'mobile.user@example.com',
            'password' => 'secret-1234',
        ], $this->mobileDevicePayload()));

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

        $response = $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'mobile.user@example.com',
            'password' => 'wrong-password',
        ], $this->mobileDevicePayload()));

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

        $response = $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'inactive.user@example.com',
            'password' => 'secret-1234',
        ], $this->mobileDevicePayload()));

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
            ->assertJsonValidationErrors(['email', 'password', 'device_id', 'device_signature']);
    }

    public function test_mobile_login_blocks_other_device_when_single_device_lock_is_enabled(): void
    {
        User::factory()->create([
            'active' => true,
            'single_device_login_enabled' => true,
            'email' => 'locked.mobile.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $firstLogin = $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'locked.mobile.user@example.com',
            'password' => 'secret-1234',
        ], $this->mobileDevicePayload([
            'device_id' => '6f35e22a-568f-43f5-b66f-39f70f79d8c7',
            'device_name' => 'Samsung S24',
            'device_signature' => $this->mobileDeviceSignature([
                'signature' => 'fnv1a-11111111',
                'model' => 'SM-S921B',
                'brand' => 'samsung',
                'manufacturer' => 'Samsung',
            ]),
        ])));

        $firstLogin->assertOk()->assertJsonPath('success', true);

        $blockedLogin = $this->postJson('/api/v1/auth/login', array_merge([
            'email' => 'locked.mobile.user@example.com',
            'password' => 'secret-1234',
        ], $this->mobileDevicePayload([
            'device_id' => '9eeb2082-09e6-4748-b5b5-ce9c30123a80',
            'device_name' => 'Pixel 9',
            'device_signature' => $this->mobileDeviceSignature([
                'signature' => 'fnv1a-22222222',
                'model' => 'Pixel 9',
                'brand' => 'google',
                'manufacturer' => 'Google',
            ]),
        ])));

        $blockedLogin->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'device_locked')
            ->assertJsonPath('data.blocked_device_info.device_name', 'Samsung S24');
    }
}
