<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\DeviceAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class DeviceAuthenticationTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    protected DeviceAuthService $deviceAuthService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->deviceAuthService = app(DeviceAuthService::class);
    }

    /**
     * Test device ID generation and token creation.
     */
    public function test_device_token_generation(): void
    {
        $deviceId = $this->faker->uuid;
        $userId = 1;

        $token = $this->deviceAuthService->generateDeviceToken($deviceId, $userId);

        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // SHA-256 produces 64-char hex string
    }

    /**
     * Test first login allows device registration.
     */
    public function test_first_login_allows_device_registration(): void
    {
        $user = User::factory()->create([
            'single_device_login_enabled' => true,
        ]);
        $deviceId = $this->faker->uuid;

        $result = $this->deviceAuthService->canLoginFromDevice($user, $deviceId);

        $this->assertTrue($result['allowed']);
        $this->assertEquals('First device registration.', $result['message']);
    }

    /**
     * Test login from registered device is allowed.
     */
    public function test_login_from_registered_device_is_allowed(): void
    {
        $user = User::factory()->create([
            'single_device_login_enabled' => true,
        ]);
        $deviceId = $this->faker->uuid;

        // Register device
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'Mozilla/5.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $device = $this->deviceAuthService->registerDevice($user, $request, $deviceId);

        $this->assertNotNull($device);

        // Try to login from same device
        $result = $this->deviceAuthService->canLoginFromDevice($user, $deviceId);

        $this->assertTrue($result['allowed']);
        $this->assertEquals('Login from registered device.', $result['message']);
    }

    /**
     * Test login from different device is blocked.
     */
    public function test_login_from_different_device_is_blocked(): void
    {
        $user = User::factory()->create([
            'single_device_login_enabled' => true,
        ]);
        $deviceId1 = $this->faker->uuid;
        $deviceId2 = $this->faker->uuid;

        // Register first device
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'Mozilla/5.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $this->deviceAuthService->registerDevice($user, $request, $deviceId1);

        // Try to login from different device
        $result = $this->deviceAuthService->canLoginFromDevice($user, $deviceId2);

        $this->assertFalse($result['allowed']);
        $this->assertStringContainsString('Device mismatch', $result['message']);
    }

    /**
     * Test admin can reset user devices.
     */
    public function test_admin_can_reset_user_devices(): void
    {
        $user = User::factory()->create([
            'single_device_login_enabled' => true,
        ]);
        $deviceId = $this->faker->uuid;

        // Register device
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'Mozilla/5.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $this->deviceAuthService->registerDevice($user, $request, $deviceId);

        $this->assertEquals(1, $user->devices()->count());

        // Reset devices
        $count = $this->deviceAuthService->resetUserDevices($user, 'Test reset');

        $this->assertGreaterThan(0, $count);
        $this->assertEquals(0, $user->devices()->where('is_active', true)->count());
    }

    /**
     * Test toggle single device login allows multiple devices when disabled.
     */
    public function test_toggle_single_device_login_allows_multiple_devices_when_disabled(): void
    {
        $user = User::factory()->create([
            'single_device_login_enabled' => false,
        ]);
        $deviceId1 = $this->faker->uuid;
        $deviceId2 = $this->faker->uuid;

        // Register first device
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'Mozilla/5.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $this->deviceAuthService->registerDevice($user, $request, $deviceId1);

        // Try to login from different device - should be allowed since toggle is off
        $result = $this->deviceAuthService->canLoginFromDevice($user, $deviceId2);

        $this->assertTrue($result['allowed']);
        $this->assertTrue($result['track_only'] ?? false);
    }

    /**
     * Test enabling single device login enforces restriction.
     */
    public function test_enabling_single_device_login_enforces_restriction(): void
    {
        $user = User::factory()->create([
            'single_device_login_enabled' => false,
        ]);
        $deviceId1 = $this->faker->uuid;
        $deviceId2 = $this->faker->uuid;

        // Register first device
        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'Mozilla/5.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $this->deviceAuthService->registerDevice($user, $request, $deviceId1);

        // Enable single device login
        $user->enableSingleDeviceLogin('Admin enabled');

        // Try to login from different device - should be blocked now
        $result = $this->deviceAuthService->canLoginFromDevice($user->fresh(), $deviceId2);

        $this->assertFalse($result['allowed']);
    }

    /**
     * Test unified storage uses web metadata when signature is not provided.
     */
    public function test_register_device_uses_web_fallback_metadata_when_signature_missing(): void
    {
        $user = User::factory()->create();
        $deviceId = $this->faker->uuid;

        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/122.0.0.0 Safari/537.36');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $device = $this->deviceAuthService->registerDevice($user, $request, $deviceId);

        $this->assertNotNull($device);
        $this->assertNotEmpty($device->browser);
        $this->assertNotEmpty($device->signature_hash);
        $this->assertEquals('web', data_get($device->signature_payload, 'platform'));
    }

    /**
     * Test unified storage persists mobile-native metadata when provided.
     */
    public function test_register_device_uses_mobile_signature_metadata_when_provided(): void
    {
        $user = User::factory()->create();
        $deviceId = $this->faker->uuid;

        $request = \Illuminate\Http\Request::create('/', 'GET');
        $request->server->set('HTTP_USER_AGENT', 'okhttp/4.12.0');
        $request->server->set('REMOTE_ADDR', '127.0.0.1');

        $signature = [
            'signature' => 'fnv1a-test-mobile',
            'platform' => 'android',
            'os_version' => '14',
            'model' => 'Pixel 8 Pro',
            'manufacturer' => 'Google',
            'brand' => 'google',
            'hardware_id' => 'google-hw-123',
            'app_version' => '1.0.0',
            'build_version' => '100',
            'mac_address' => '',
        ];

        $device = $this->deviceAuthService->registerDevice($user, $request, $deviceId, $signature, 'Pixel 8 Pro');

        $this->assertNotNull($device);
        $this->assertEquals('android', strtolower((string) $device->platform));
        $this->assertEquals('Pixel 8 Pro', $device->device_model);
        $this->assertEquals('Google', $device->device_manufacturer);
        $this->assertEquals('android', data_get($device->signature_payload, 'platform'));
    }
}
