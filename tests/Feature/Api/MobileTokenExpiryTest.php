<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Mobile bearer tokens must follow the SAME idle-timeout policy as web sessions:
 * a sliding window of config('session.lifetime') minutes. Previously
 * sanctum.expiration was null, so mobile tokens never expired while web sessions
 * dropped after the idle window — the core web/mobile divergence.
 */
class MobileTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function login(): string
    {
        $user = User::factory()->create([
            'email' => 'idle.user@example.com',
            'password' => Hash::make('secret-1234'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'idle.user@example.com',
            'password' => 'secret-1234',
            'device_name' => 'Pixel 8',
            'device_id' => '7b25d7a4-6ee2-4e8d-98d0-3eb4bfef2f90',
            'device_signature' => [
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
            ],
        ]);

        $response->assertOk();

        return $response->json('data.token');
    }

    public function test_login_issues_token_with_expiry_matching_session_lifetime(): void
    {
        $token = $this->login();

        $accessToken = PersonalAccessToken::findToken($token);

        $this->assertNotNull($accessToken);
        $this->assertNotNull($accessToken->expires_at, 'Mobile token must be issued with an expires_at.');

        $expectedMinutes = (int) config('session.lifetime');
        $this->assertEqualsWithDelta(
            now()->addMinutes($expectedMinutes)->timestamp,
            $accessToken->expires_at->timestamp,
            120,
            'Token expiry should match the unified idle window (session.lifetime).'
        );
    }

    public function test_token_expires_after_idle_window_elapses(): void
    {
        $token = $this->login();

        // No activity for longer than the idle window.
        $this->travel((int) config('session.lifetime') + 1)->minutes();

        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'AUTHENTICATION_REQUIRED');
    }

    public function test_activity_within_window_slides_expiry_and_keeps_token_alive(): void
    {
        $idle = (int) config('session.lifetime');
        $token = $this->login();

        // Active just before the window closes -> should slide expiry forward.
        $this->travel($idle - 5)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        // Another near-window gap. Total elapsed now exceeds one window, but the
        // sliding extension from the previous request keeps the token valid.
        $this->travel($idle - 5)->minutes();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }
}
