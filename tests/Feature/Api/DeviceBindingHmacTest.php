<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class DeviceBindingHmacTest extends TestCase
{
    use RefreshDatabase;

    private string $deviceId = '7b25d7a4-6ee2-4e8d-98d0-3eb4bfef2f90';

    private function signature(array $overrides = []): array
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

    private function login(string $email, string $password, ?string $deviceId = null, array $sigOverrides = []): array
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'Pixel 8',
            'device_id' => $deviceId ?? $this->deviceId,
            'device_signature' => $this->signature($sigOverrides),
        ]);

        $response->assertOk();

        return [
            'token' => $response->json('data.token'),
            'secret' => $response->json('data.device_secret'),
            'device_id' => $deviceId ?? $this->deviceId,
        ];
    }

    private function canonical(string $method, string $path, string $ts, string $nonce): string
    {
        return implode("\n", [strtoupper($method), $path, $ts, $nonce]);
    }

    private function signedHeaders(string $secret, string $deviceId, string $method, string $path, ?string $ts = null, ?string $nonce = null): array
    {
        $ts = $ts ?? (string) time();
        $nonce = $nonce ?? bin2hex(random_bytes(8));
        $hmac = hash_hmac('sha256', $this->canonical($method, $path, $ts, $nonce), $secret);

        return [
            'X-Device-ID' => $deviceId,
            'X-Device-Timestamp' => $ts,
            'X-Device-Nonce' => $nonce,
            'X-Device-HMAC' => $hmac,
        ];
    }

    private function makeUser(string $email): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('secret-1234'),
        ]);
    }

    public function test_login_returns_a_device_secret(): void
    {
        $this->makeUser('secret.user@example.com');
        $auth = $this->login('secret.user@example.com', 'secret-1234');

        $this->assertNotEmpty($auth['secret']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $auth['secret']);
        $this->assertDatabaseHas('users', [
            'email' => 'secret.user@example.com',
            'current_device_id' => $this->deviceId,
        ]);
    }

    public function test_strict_valid_hmac_passes(): void
    {
        config(['security.device_binding.strict' => true]);
        $this->makeUser('valid.hmac@example.com');
        $auth = $this->login('valid.hmac@example.com', 'secret-1234');

        $headers = $this->signedHeaders($auth['secret'], $auth['device_id'], 'GET', '/api/v1/auth/me');

        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_strict_forged_signature_is_rejected(): void
    {
        config(['security.device_binding.strict' => true]);
        $this->makeUser('forged.hmac@example.com');
        $auth = $this->login('forged.hmac@example.com', 'secret-1234');

        $headers = $this->signedHeaders('deadbeef-not-the-secret', $auth['device_id'], 'GET', '/api/v1/auth/me');

        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', $headers)
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_device');
    }

    public function test_strict_absent_signature_on_enrolled_device_is_rejected(): void
    {
        config(['security.device_binding.strict' => true]);
        $this->makeUser('absent.hmac@example.com');
        $auth = $this->login('absent.hmac@example.com', 'secret-1234');

        // Only the device id, no timestamp/nonce/hmac.
        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', ['X-Device-ID' => $auth['device_id']])
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_device');
    }

    public function test_strict_stale_timestamp_is_rejected(): void
    {
        config(['security.device_binding.strict' => true]);
        $this->makeUser('stale.hmac@example.com');
        $auth = $this->login('stale.hmac@example.com', 'secret-1234');

        $oldTs = (string) (time() - 4000);
        $headers = $this->signedHeaders($auth['secret'], $auth['device_id'], 'GET', '/api/v1/auth/me', $oldTs);

        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', $headers)
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_device');
    }

    public function test_strict_replayed_nonce_is_rejected(): void
    {
        config(['security.device_binding.strict' => true]);
        $this->makeUser('replay.hmac@example.com');
        $auth = $this->login('replay.hmac@example.com', 'secret-1234');

        $ts = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $headers = $this->signedHeaders($auth['secret'], $auth['device_id'], 'GET', '/api/v1/auth/me', $ts, $nonce);

        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', $headers)->assertOk();

        // Same nonce again → replay.
        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', $headers)
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_device');
    }

    public function test_strict_new_device_login_revokes_old_device(): void
    {
        config(['security.device_binding.strict' => true]);
        $this->makeUser('rotate.hmac@example.com');

        $first = $this->login('rotate.hmac@example.com', 'secret-1234', '7b25d7a4-6ee2-4e8d-98d0-3eb4bfef2f90');

        // First device works.
        $this->withToken($first['token'])->getJson('/api/v1/auth/me',
            $this->signedHeaders($first['secret'], $first['device_id'], 'GET', '/api/v1/auth/me'))
            ->assertOk();

        // Second device logs in → becomes current.
        $second = $this->login('rotate.hmac@example.com', 'secret-1234', '9eeb2082-09e6-4748-b5b5-ce9c30123a80',
            ['model' => 'Pixel 9']);

        $this->withToken($second['token'])->getJson('/api/v1/auth/me',
            $this->signedHeaders($second['secret'], $second['device_id'], 'GET', '/api/v1/auth/me'))
            ->assertOk();

        // First device now presents an authentic-but-wrong-device signature → rejected.
        $this->withToken($first['token'])->getJson('/api/v1/auth/me',
            $this->signedHeaders($first['secret'], $first['device_id'], 'GET', '/api/v1/auth/me'))
            ->assertStatus(401)
            ->assertJsonPath('code', 'invalid_device');
    }

    public function test_observe_mode_tolerates_forged_signature(): void
    {
        config(['security.device_binding.strict' => false]);
        $this->makeUser('observe.hmac@example.com');
        $auth = $this->login('observe.hmac@example.com', 'secret-1234');

        $headers = $this->signedHeaders('deadbeef-not-the-secret', $auth['device_id'], 'GET', '/api/v1/auth/me');

        $this->withToken($auth['token'])->getJson('/api/v1/auth/me', $headers)
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_pre_secret_legacy_session_is_tolerated_even_in_strict(): void
    {
        config(['security.device_binding.strict' => true]);
        $user = $this->makeUser('legacy.session@example.com');

        // Simulate a token minted before the rollout: no UserDevice / no secret.
        $token = $user->createToken('Legacy Device')->plainTextToken;

        // No device headers at all → not enrolled → must NOT be rejected.
        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('success', true);
    }
}
