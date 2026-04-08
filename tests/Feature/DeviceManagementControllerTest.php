<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceManagementControllerTest extends TestCase
{
    use RefreshDatabase;

    protected DeviceAuthService $deviceAuthService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware();
        $this->deviceAuthService = app(DeviceAuthService::class);
    }

    public function test_get_user_devices_json_includes_summary_and_user_state(): void
    {
        /** @var User $admin */
        $admin = User::factory()->createOne();
        /** @var User $user */
        $user = User::factory()->createOne([
            'single_device_login_enabled' => true,
        ]);

        $firstDevice = $this->deviceAuthService->registerDevice(
            $user,
            $this->makeDeviceRequest('Mozilla/5.0 (Windows NT 10.0; Win64; x64)'),
            Str::uuid()->toString()
        );

        $secondDevice = $this->deviceAuthService->registerDevice(
            $user,
            $this->makeDeviceRequest('okhttp/4.12.0'),
            Str::uuid()->toString()
        );

        $this->assertNotNull($firstDevice);
        $this->assertNotNull($secondDevice);

        $secondDevice->update([
            'is_active' => false,
            'is_trusted' => true,
        ]);

        $response = $this->actingAs($admin)
            ->getJson(route('admin.users.devices', ['userId' => $user->id]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.active', 1)
            ->assertJsonPath('summary.inactive', 1)
            ->assertJsonPath('summary.trusted', 1)
            ->assertJsonPath('user_state.id', $user->id)
            ->assertJsonPath('user_state.single_device_login_enabled', true);
    }

    public function test_reset_devices_returns_updated_user_state_and_summary(): void
    {
        /** @var User $admin */
        $admin = User::factory()->createOne();
        /** @var User $user */
        $user = User::factory()->createOne([
            'single_device_login_enabled' => true,
        ]);

        $device = $this->deviceAuthService->registerDevice(
            $user,
            $this->makeDeviceRequest('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)'),
            Str::uuid()->toString()
        );

        $this->assertNotNull($device);

        $reason = 'Security reset requested by admin';

        $response = $this->actingAs($admin)
            ->postJson(route('admin.users.devices.reset', ['userId' => $user->id]), [
                'reason' => $reason,
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.active', 0)
            ->assertJsonPath('summary.inactive', 1)
            ->assertJsonPath('user_state.id', $user->id)
            ->assertJsonPath('user_state.device_reset_reason', $reason);

        $user->refresh();

        $this->assertSame($reason, $user->device_reset_reason);
        $this->assertNotNull($user->device_reset_at);

        $this->assertDatabaseHas('user_devices', [
            'id' => $device->id,
            'is_active' => false,
        ]);
    }

    public function test_reset_devices_revokes_user_sessions_and_tokens(): void
    {
        /** @var User $admin */
        $admin = User::factory()->createOne();
        /** @var User $user */
        $user = User::factory()->createOne([
            'single_device_login_enabled' => true,
        ]);

        $device = $this->deviceAuthService->registerDevice(
            $user,
            $this->makeDeviceRequest('okhttp/4.12.0'),
            Str::uuid()->toString()
        );

        $this->assertNotNull($device);

        $token = $user->createToken('device-reset-test');
        $tokenSessionId = 'api-token:'.$token->accessToken->id;
        $sessionId = (string) Str::uuid();

        DB::table('sessions')->insert([
            'id' => $sessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test Agent',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);

        DB::table('user_sessions')->insert([
            'session_id' => $tokenSessionId,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Feature Test Agent',
            'device_fingerprint' => 'api-session-fingerprint',
            'device_info' => json_encode(['channel' => 'api', 'token_id' => $token->accessToken->id]),
            'location_info' => json_encode(['country' => 'Unknown']),
            'is_current' => true,
            'last_activity' => now(),
            'expires_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($admin)
            ->postJson(route('admin.users.devices.reset', ['userId' => $user->id]), [
                'reason' => 'Forced logout verification',
            ]);

        $response->assertOk()->assertJsonPath('success', true);

        $this->assertDatabaseMissing('sessions', [
            'id' => $sessionId,
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
            'tokenable_id' => $user->id,
        ]);

        $this->assertDatabaseHas('user_sessions', [
            'session_id' => $tokenSessionId,
            'user_id' => $user->id,
            'is_current' => false,
        ]);
    }

    protected function makeDeviceRequest(string $userAgent): Request
    {
        $request = Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', $userAgent);
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        return $request;
    }
}
