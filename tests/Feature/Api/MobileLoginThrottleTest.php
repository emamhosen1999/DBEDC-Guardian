<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class MobileLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Deterministic thresholds independent of the environment.
        config()->set('security.mobile_login.max_attempts', 5);
        config()->set('security.mobile_login.decay_seconds', 60);
    }

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

    private function attemptLogin(string $email, string $password): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/login', array_merge([
            'email' => $email,
            'password' => $password,
        ], $this->mobileDevicePayload()));
    }

    public function test_account_is_locked_after_five_failed_attempts(): void
    {
        User::factory()->create([
            'email' => 'brute.target@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        // 5 failed attempts each return the normal 401 credential error.
        for ($i = 1; $i <= 5; $i++) {
            $this->attemptLogin('brute.target@example.com', 'wrong-password')
                ->assertStatus(401)
                ->assertJsonPath('error_code', 'INVALID_CREDENTIALS');
        }

        // The 6th attempt is locked out with a 429 + Retry-After.
        $locked = $this->attemptLogin('brute.target@example.com', 'wrong-password');

        $locked->assertStatus(429)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'TOO_MANY_ATTEMPTS');

        $this->assertStringContainsString('Too many login attempts', $locked->json('message'));
        $this->assertNotNull($locked->headers->get('Retry-After'));
        $this->assertGreaterThan(0, (int) $locked->json('errors.retry_after'));
    }

    public function test_lockout_even_blocks_the_correct_password_while_active(): void
    {
        User::factory()->create([
            'email' => 'brute.locked@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        for ($i = 1; $i <= 5; $i++) {
            $this->attemptLogin('brute.locked@example.com', 'wrong-password')->assertStatus(401);
        }

        // Even a correct password is refused while the key is locked.
        $this->attemptLogin('brute.locked@example.com', 'correct-horse-battery')
            ->assertStatus(429)
            ->assertJsonPath('error_code', 'TOO_MANY_ATTEMPTS');
    }

    public function test_successful_login_clears_the_counter(): void
    {
        $user = User::factory()->create([
            'email' => 'brute.reset@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        // A few failed attempts, but below the lockout threshold.
        for ($i = 1; $i <= 4; $i++) {
            $this->attemptLogin('brute.reset@example.com', 'wrong-password')->assertStatus(401);
        }

        // A correct login succeeds (happy path unaffected) and resets the counter.
        $this->attemptLogin('brute.reset@example.com', 'correct-horse-battery')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Login successful.')
            ->assertJsonPath('data.user.id', $user->id);

        // Fresh window: 4 more failures do NOT trip the lock, proving the reset.
        for ($i = 1; $i <= 4; $i++) {
            $this->attemptLogin('brute.reset@example.com', 'wrong-password')->assertStatus(401);
        }
    }

    public function test_throttle_key_is_scoped_per_account(): void
    {
        User::factory()->create([
            'email' => 'victim.a@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
        $userB = User::factory()->create([
            'email' => 'victim.b@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        // Lock out account A.
        for ($i = 1; $i <= 6; $i++) {
            $this->attemptLogin('victim.a@example.com', 'wrong-password');
        }
        $this->attemptLogin('victim.a@example.com', 'wrong-password')->assertStatus(429);

        // Account B (same IP) is unaffected and can still log in.
        $this->attemptLogin('victim.b@example.com', 'correct-horse-battery')
            ->assertOk()
            ->assertJsonPath('data.user.id', $userB->id);
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('mobile-login|brute.target@example.com|127.0.0.1');
        parent::tearDown();
    }
}
