<?php

namespace Tests\Feature\Admin;

use App\Models\RefreshToken;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceAuthService;
use App\Services\RefreshTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Covers the fleet-wide admin device-session dashboard: what an authorised admin
 * sees, exactly what a revoke destroys, and that it is gated behind the same
 * users.view / users.update permissions as the rest of admin user management.
 */
class DeviceSessionDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected DeviceAuthService $deviceAuthService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deviceAuthService = app(DeviceAuthService::class);
    }

    public function test_admin_sees_device_sessions_with_token_counts(): void
    {
        $admin = $this->adminWith(['users.view']);
        $employee = User::factory()->create(['name' => 'Rafiq Islam', 'email' => 'rafiq@example.com']);

        $device = $this->registerDevice($employee);
        $tokenId = $this->issueAccessTokenForDevice($employee, $device->device_id);
        $this->issueRefreshTokenForDevice($employee, $device->device_id);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.device-sessions.index'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('sessions.0.user.email', 'rafiq@example.com')
            ->assertJsonPath('sessions.0.device_id', $device->device_id)
            ->assertJsonPath('sessions.0.is_active', true)
            ->assertJsonPath('sessions.0.access_tokens_active', 1)
            ->assertJsonPath('sessions.0.refresh_tokens_active', 1)
            ->assertJsonPath('sessions.0.has_live_session', true)
            ->assertJsonPath('summary.total_devices', 1)
            ->assertJsonPath('summary.active_devices', 1);

        $this->assertNotNull($tokenId);
    }

    public function test_search_filters_sessions_by_user_email(): void
    {
        $admin = $this->adminWith(['users.view']);

        $wanted = User::factory()->create(['name' => 'Nadia Karim', 'email' => 'nadia@example.com']);
        $other = User::factory()->create(['name' => 'Imran Hossain', 'email' => 'imran@example.com']);

        $this->registerDevice($wanted);
        $this->registerDevice($other);

        $this->actingAs($admin)
            ->getJson(route('admin.device-sessions.index', ['search' => 'nadia@example.com']))
            ->assertOk()
            ->assertJsonCount(1, 'sessions')
            ->assertJsonPath('sessions.0.user.email', 'nadia@example.com');
    }

    public function test_revoke_kills_access_token_refresh_chain_and_deactivates_device(): void
    {
        $admin = $this->adminWith(['users.view', 'users.update']);
        $employee = User::factory()->create();

        $device = $this->registerDevice($employee);
        $tokenId = $this->issueAccessTokenForDevice($employee, $device->device_id);
        $refreshToken = $this->issueRefreshTokenForDevice($employee, $device->device_id);

        $employee->forceFill(['current_device_id' => $device->device_id])->save();

        // A second device that must survive the revoke untouched.
        $otherDevice = $this->registerDevice($employee);
        $otherTokenId = $this->issueAccessTokenForDevice($employee, $otherDevice->device_id);
        $otherRefreshToken = $this->issueRefreshTokenForDevice($employee, $otherDevice->device_id);

        $this->actingAs($admin)
            ->postJson(route('admin.device-sessions.revoke', ['device' => $device->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('access_tokens_revoked', 1)
            ->assertJsonPath('refresh_tokens_revoked', 1)
            ->assertJsonPath('unbound_current_device', true);

        // 1. access token destroyed
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // 2. tracked session marked no longer current
        $this->assertDatabaseHas('user_sessions', [
            'session_id' => 'api-token:'.$tokenId,
            'is_current' => false,
        ]);

        // 3. refresh chain revoked
        $this->assertNotNull($refreshToken->fresh()->revoked_at);

        // 4. device deactivated
        $this->assertFalse((bool) $device->fresh()->is_active);

        // 5. active-device binding released
        $this->assertNull($employee->fresh()->current_device_id);

        // The other device keeps its credentials.
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherTokenId]);
        $this->assertNull($otherRefreshToken->fresh()->revoked_at);
        $this->assertTrue((bool) $otherDevice->fresh()->is_active);
    }

    public function test_non_admin_cannot_view_or_revoke_sessions(): void
    {
        $employee = User::factory()->create();
        $device = $this->registerDevice($employee);

        $this->actingAs($employee)
            ->getJson(route('admin.device-sessions.index'))
            ->assertForbidden();

        $this->actingAs($employee)
            ->postJson(route('admin.device-sessions.revoke', ['device' => $device->id]))
            ->assertForbidden();

        $this->assertTrue((bool) $device->fresh()->is_active);
    }

    public function test_viewer_without_update_permission_cannot_revoke(): void
    {
        $viewer = $this->adminWith(['users.view']);
        $employee = User::factory()->create();
        $device = $this->registerDevice($employee);

        $this->actingAs($viewer)
            ->getJson(route('admin.device-sessions.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->postJson(route('admin.device-sessions.revoke', ['device' => $device->id]))
            ->assertForbidden();

        $this->assertTrue((bool) $device->fresh()->is_active);
    }

    /* ── helpers ── */

    /**
     * @param  array<int, string>  $permissions
     */
    protected function adminWith(array $permissions): User
    {
        $admin = User::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $admin->givePermissionTo($permission);
        }

        return $admin;
    }

    protected function registerDevice(User $user): UserDevice
    {
        $device = $this->deviceAuthService->registerDevice(
            $user,
            $this->makeDeviceRequest(),
            Str::uuid()->toString()
        );

        $this->assertNotNull($device);

        return $device;
    }

    /**
     * Mint a Sanctum token and record the user_sessions row that binds it to the
     * device, exactly as DeviceAuthService::trackApiTokenSession does on login.
     */
    protected function issueAccessTokenForDevice(User $user, string $deviceId): int
    {
        $tokenId = (int) $user->createToken('mobile-app', ['*'], now()->addDay())->accessToken->id;

        DB::table('user_sessions')->insert([
            'session_id' => 'api-token:'.$tokenId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'okhttp/4.12.0',
            'device_fingerprint' => hash('sha256', $deviceId),
            'device_info' => json_encode([
                'channel' => 'api',
                'token_id' => $tokenId,
                'device_id' => $deviceId,
                'platform' => 'android',
            ]),
            'location_info' => json_encode(['country' => 'Unknown']),
            'is_current' => true,
            'last_activity' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $tokenId;
    }

    protected function issueRefreshTokenForDevice(User $user, string $deviceId): RefreshToken
    {
        return app(RefreshTokenService::class)->create($user, $deviceId)['model'];
    }

    protected function makeDeviceRequest(string $userAgent = 'okhttp/4.12.0'): Request
    {
        $request = Request::create('/api/v1/login', 'POST');
        $request->headers->set('User-Agent', $userAgent);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        return $request;
    }
}
