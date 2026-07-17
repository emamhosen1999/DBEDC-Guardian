<?php

namespace Tests\Feature\Api;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Refresh-token rotation: login issues one, /auth/refresh rotates it, a reused
 * (already-rotated) token is treated as theft and burns the chain, and logout
 * revokes it. The access-token flow is unchanged and backward-compatible.
 */
class MobileRefreshTokenTest extends TestCase
{
    use RefreshDatabase;

    private function credentials(User $user, string $deviceId): array
    {
        return [
            'email' => $user->email,
            'password' => 'password',
            'device_id' => $deviceId,
            'device_name' => 'Test Device',
            // MobileLoginRequest requires a device_signature with platform + os_version.
            'device_signature' => [
                'platform' => 'android',
                'os_version' => '14',
                'model' => 'Test Phone',
            ],
        ];
    }

    private function user(): User
    {
        return User::factory()->create(['password' => bcrypt('password')]);
    }

    public function test_login_issues_a_refresh_token(): void
    {
        $user = $this->user();
        $deviceId = '11111111-1111-4111-8111-111111111111';

        $response = $this->postJson('/api/v1/auth/login', $this->credentials($user, $deviceId));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && $t !== '')
            ->assertJsonPath('data.refresh_token', fn ($t) => is_string($t) && $t !== '');

        $this->assertDatabaseCount('refresh_tokens', 1);
        $this->assertDatabaseHas('refresh_tokens', ['user_id' => $user->id, 'device_id' => $deviceId, 'revoked_at' => null]);
    }

    public function test_refresh_rotates_the_token_and_returns_a_new_pair(): void
    {
        $user = $this->user();
        $deviceId = '22222222-2222-4222-8222-222222222222';

        $login = $this->postJson('/api/v1/auth/login', $this->credentials($user, $deviceId));
        $oldRefresh = $login->json('data.refresh_token');

        $refresh = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);

        $refresh->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token', fn ($t) => is_string($t) && $t !== '')
            ->assertJsonPath('data.refresh_token', fn ($t) => is_string($t) && $t !== '' && $t !== $oldRefresh);

        // The presented token is now revoked and linked to its successor.
        $this->assertDatabaseCount('refresh_tokens', 2);
        $original = RefreshToken::where('token_hash', hash('sha256', $oldRefresh))->first();
        $this->assertNotNull($original->revoked_at);
        $this->assertNotNull($original->replaced_by);
    }

    public function test_reusing_a_rotated_refresh_token_is_rejected_and_burns_the_chain(): void
    {
        $user = $this->user();
        $deviceId = '33333333-3333-4333-8333-333333333333';

        $login = $this->postJson('/api/v1/auth/login', $this->credentials($user, $deviceId));
        $oldRefresh = $login->json('data.refresh_token');

        // First refresh succeeds and rotates.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh])->assertOk();

        // Replaying the now-revoked token is a theft signal.
        $replay = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $oldRefresh]);

        $replay->assertStatus(401)->assertJsonPath('error_code', 'REFRESH_TOKEN_REUSED');

        // The whole active chain for the device is revoked.
        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_invalid_refresh_token_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'not-a-real-token'])
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'REFRESH_TOKEN_INVALID');

        $this->postJson('/api/v1/auth/refresh', [])
            ->assertStatus(422)
            ->assertJsonPath('error_code', 'REFRESH_TOKEN_REQUIRED');
    }

    public function test_logout_revokes_the_refresh_chain(): void
    {
        $user = $this->user();
        $deviceId = '44444444-4444-4444-8444-444444444444';

        $login = $this->postJson('/api/v1/auth/login', $this->credentials($user, $deviceId));
        $token = $login->json('data.token');
        $refresh = $login->json('data.refresh_token');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The refresh token no longer works after logout.
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
            ->assertStatus(401);

        $this->assertSame(0, RefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->count());
    }
}
