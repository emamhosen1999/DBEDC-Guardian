<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Models\UserDevice;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * POST /api/v1/heartbeat — presence beacon.
 *
 * Contract under test: it must be authenticated, it must move the EXISTING
 * last-seen columns (no new table), and it must not invent a user_sessions row.
 */
class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_unauthenticated_heartbeat_is_rejected(): void
    {
        $this->postJson('/api/v1/heartbeat')->assertUnauthorized();
    }

    public function test_heartbeat_updates_device_last_used_at(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));

        $user = User::factory()->create();
        $device = UserDevice::create([
            'user_id' => $user->id,
            'device_id' => '11111111-2222-3333-4444-555555555555',
            'device_name' => 'Field Tablet',
            'device_token' => 'test-device-token',
            'device_type' => 'mobile',
            'is_active' => true,
            'last_used_at' => Carbon::parse('2026-07-01 06:00:00'),
        ]);

        Sanctum::actingAs($user);

        $response = $this->withHeader('X-Device-ID', $device->device_id)
            ->postJson('/api/v1/heartbeat')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.device_tracked', true)
            ->assertJsonPath('data.server_timestamp', Carbon::parse('2026-07-18 10:00:00')->getTimestamp())
            ->assertJsonPath('data.refresh_config', false);

        $this->assertSame(
            '2026-07-18 10:00:00',
            $device->fresh()->last_used_at->format('Y-m-d H:i:s'),
            'Heartbeat must move user_devices.last_used_at — the column the admin fleet dashboard displays.'
        );

        $this->assertIsString($response->json('data.server_time'));
    }

    public function test_heartbeat_without_a_known_device_still_succeeds_but_reports_untracked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.device_tracked', false);

        // It must NOT conjure a tracked session row that login never created.
        $this->assertSame(0, DB::table('user_sessions')->where('user_id', $user->id)->count());
    }

    public function test_heartbeat_does_not_touch_another_users_device(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-18 10:00:00'));

        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $device = UserDevice::create([
            'user_id' => $owner->id,
            'device_id' => '99999999-8888-7777-6666-555555555555',
            'device_name' => 'PM Handset',
            'device_token' => 'test-device-token',
            'device_type' => 'mobile',
            'is_active' => true,
            'last_used_at' => Carbon::parse('2026-07-01 06:00:00'),
        ]);

        Sanctum::actingAs($intruder);

        $this->withHeader('X-Device-ID', $device->device_id)
            ->postJson('/api/v1/heartbeat')
            ->assertOk()
            ->assertJsonPath('data.device_tracked', false);

        $this->assertSame(
            '2026-07-01 06:00:00',
            $device->fresh()->last_used_at->format('Y-m-d H:i:s'),
            'Presence is scoped to the caller: another user must never be marked live.'
        );
    }
}
