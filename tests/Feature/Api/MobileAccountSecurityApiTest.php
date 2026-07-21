<?php

namespace Tests\Feature\Api;

use App\Models\RefreshToken;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileAccountSecurityApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a device row directly with only the columns that exist in the test
     * schema. The UserDevice factory writes drifted columns (device_serial etc.)
     * absent from the migration, so we insert the real shape ourselves.
     */
    protected function makeDevice(User $user, string $deviceId, bool $active = true, ?string $name = null): UserDevice
    {
        return UserDevice::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'device_token' => hash('sha256', $deviceId.'|'.$user->id),
            'device_name' => $name ?? ('Device '.$deviceId),
            'device_type' => 'mobile',
            'platform' => 'Android',
            'is_active' => $active,
            'last_used_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_account_security_endpoints(): void
    {
        $this->postJson('/api/v1/account/change-password', [])->assertUnauthorized();
        $this->getJson('/api/v1/account/devices')->assertUnauthorized();
        $this->postJson('/api/v1/account/devices/1/revoke')->assertUnauthorized();
        $this->postJson('/api/v1/account/sign-out-all')->assertUnauthorized();
    }

    public function test_user_can_change_password_with_correct_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldSecret123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/account/change-password', [
            'current_password' => 'OldSecret123',
            'new_password' => 'BrandNew456',
            'new_password_confirmation' => 'BrandNew456',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'message' => 'Password changed successfully.',
        ]);

        $this->assertTrue(Hash::check('BrandNew456', $user->fresh()->password));
    }

    public function test_change_password_rejects_wrong_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldSecret123'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/account/change-password', [
            'current_password' => 'WrongPassword999',
            'new_password' => 'BrandNew456',
            'new_password_confirmation' => 'BrandNew456',
        ]);

        $response->assertStatus(422)
            ->assertJson(['success' => false])
            ->assertJsonValidationErrors(['current_password']);

        // Password must be untouched.
        $this->assertTrue(Hash::check('OldSecret123', $user->fresh()->password));
    }

    public function test_change_password_enforces_policy_and_confirmation(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldSecret123'),
        ]);

        Sanctum::actingAs($user);

        // Too short + confirmation mismatch.
        $this->postJson('/api/v1/account/change-password', [
            'current_password' => 'OldSecret123',
            'new_password' => 'short',
            'new_password_confirmation' => 'nomatch',
        ])->assertStatus(422)->assertJsonValidationErrors(['new_password']);
    }

    public function test_change_password_rejects_reusing_current_password(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('OldSecret123'),
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/v1/account/change-password', [
            'current_password' => 'OldSecret123',
            'new_password' => 'OldSecret123',
            'new_password_confirmation' => 'OldSecret123',
        ])->assertStatus(422)->assertJsonValidationErrors(['new_password']);
    }

    public function test_devices_endpoint_only_returns_my_own_active_devices(): void
    {
        $me = User::factory()->create(['current_device_id' => 'device-a']);
        $other = User::factory()->create();

        $this->makeDevice($me, 'device-a', true, 'My Phone');
        $this->makeDevice($me, 'device-b', true, 'My Tablet');
        $this->makeDevice($me, 'device-c', false, 'My Old Phone');
        $this->makeDevice($other, 'device-x', true, 'Their Phone');

        Sanctum::actingAs($me);

        $response = $this->getJson('/api/v1/account/devices');

        $response->assertOk()->assertJson(['success' => true]);

        $devices = $response->json('data.devices');

        // Only my two ACTIVE devices — never the other user's, never my inactive one.
        $this->assertCount(2, $devices);
        $deviceIds = collect($devices)->pluck('device_id')->all();
        $this->assertEqualsCanonicalizing(['device-a', 'device-b'], $deviceIds);

        $current = collect($devices)->firstWhere('device_id', 'device-a');
        $this->assertTrue($current['is_current']);
        $notCurrent = collect($devices)->firstWhere('device_id', 'device-b');
        $this->assertFalse($notCurrent['is_current']);
    }

    public function test_user_can_revoke_their_own_device(): void
    {
        $me = User::factory()->create(['current_device_id' => 'device-a']);

        $target = $this->makeDevice($me, 'device-b', true);
        RefreshToken::create([
            'user_id' => $me->id,
            'device_id' => 'device-b',
            'token_hash' => hash('sha256', 'plain-refresh'),
            'expires_at' => now()->addDays(30),
        ]);

        Sanctum::actingAs($me);

        $response = $this->postJson("/api/v1/account/devices/{$target->id}/revoke");

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertFalse((bool) $target->fresh()->is_active);
        $this->assertNotNull(RefreshToken::where('device_id', 'device-b')->first()->revoked_at);
    }

    public function test_user_cannot_revoke_another_users_device(): void
    {
        $me = User::factory()->create();
        $other = User::factory()->create();

        $foreignDevice = $this->makeDevice($other, 'device-x', true);

        Sanctum::actingAs($me);

        $response = $this->postJson("/api/v1/account/devices/{$foreignDevice->id}/revoke");

        $response->assertStatus(403)->assertJson(['success' => false]);

        // The foreign device must be untouched.
        $this->assertTrue((bool) $foreignDevice->fresh()->is_active);
    }

    public function test_revoke_missing_device_returns_not_found(): void
    {
        $me = User::factory()->create();
        Sanctum::actingAs($me);

        $this->postJson('/api/v1/account/devices/999999/revoke')
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    public function test_sign_out_all_deactivates_every_device_except_current(): void
    {
        $me = User::factory()->create(['current_device_id' => 'device-a']);

        $current = $this->makeDevice($me, 'device-a', true);
        $otherOne = $this->makeDevice($me, 'device-b', true);
        $otherTwo = $this->makeDevice($me, 'device-c', true);

        RefreshToken::create([
            'user_id' => $me->id,
            'device_id' => 'device-a',
            'token_hash' => hash('sha256', 'keep-me'),
            'expires_at' => now()->addDays(30),
        ]);
        RefreshToken::create([
            'user_id' => $me->id,
            'device_id' => 'device-b',
            'token_hash' => hash('sha256', 'kill-me'),
            'expires_at' => now()->addDays(30),
        ]);

        Sanctum::actingAs($me);

        // Send the device header so the server can identify the current device.
        $response = $this->postJson('/api/v1/account/sign-out-all', [], [
            'X-Device-ID' => 'device-a',
        ]);

        $response->assertOk()->assertJson(['success' => true]);

        // Current device stays active; the other two are deactivated.
        $this->assertTrue((bool) $current->fresh()->is_active);
        $this->assertFalse((bool) $otherOne->fresh()->is_active);
        $this->assertFalse((bool) $otherTwo->fresh()->is_active);

        // Current device refresh chain survives; the other device's is revoked.
        $this->assertNull(RefreshToken::where('device_id', 'device-a')->first()->revoked_at);
        $this->assertNotNull(RefreshToken::where('device_id', 'device-b')->first()->revoked_at);

        $this->assertSame(2, $response->json('data.devices_revoked'));
    }
}
