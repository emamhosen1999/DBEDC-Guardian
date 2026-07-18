<?php

namespace Tests\Feature\Admin;

use App\Models\FeatureFlag;
use App\Models\User;
use App\Services\FeatureFlagService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature flags / remote config end to end:
 *   - the admin surface is gated exactly like admin device sessions,
 *   - GET /api/v1/config resolves global rows overlaid with role rows,
 *   - the ETag round trip 304s,
 *   - the offline-push kill switch is ENFORCED server-side, not advertised.
 */
class FeatureFlagTest extends TestCase
{
    use RefreshDatabase;

    /* ─────────────────────────── admin surface ─────────────────────────── */

    public function test_admin_with_users_view_sees_flags(): void
    {
        $admin = $this->adminWith(['users.view']);

        FeatureFlag::create([
            'key' => 'mobile.offline_sync_push_enabled',
            'value' => null,
            'is_enabled' => true,
            'role' => null,
            'description' => 'Kill switch.',
        ]);

        $this->actingAs($admin)
            ->getJson(route('admin.feature-flags.index'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('flags.0.key', 'mobile.offline_sync_push_enabled')
            ->assertJsonPath('flags.0.is_enabled', true)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.enabled', 1);
    }

    public function test_user_without_users_view_cannot_see_flags(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('admin.feature-flags.index'))
            ->assertForbidden();
    }

    public function test_read_permission_alone_cannot_write_a_flag(): void
    {
        $admin = $this->adminWith(['users.view']);

        $this->actingAs($admin)
            ->postJson(route('admin.feature-flags.store'), [
                'key' => 'mobile.new_flag',
                'value' => 'true',
                'is_enabled' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('feature_flags', 0);
    }

    public function test_admin_can_create_toggle_and_delete_a_flag(): void
    {
        $admin = $this->adminWith(['users.view', 'users.update']);

        $this->actingAs($admin)
            ->postJson(route('admin.feature-flags.store'), [
                'key' => 'mobile.sync_poll_interval_seconds',
                'value' => '300',
                'description' => 'Background sync cadence.',
                'is_enabled' => true,
                'role' => null,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('flag.value', 300);

        /** @var FeatureFlag $flag */
        $flag = FeatureFlag::firstWhere('key', 'mobile.sync_poll_interval_seconds');
        $this->assertSame(300, $flag->value);

        $this->actingAs($admin)
            ->postJson(route('admin.feature-flags.toggle', ['flag' => $flag->id]))
            ->assertOk()
            ->assertJsonPath('flag.is_enabled', false);

        $this->actingAs($admin)
            ->deleteJson(route('admin.feature-flags.destroy', ['flag' => $flag->id]))
            ->assertOk();

        $this->assertDatabaseCount('feature_flags', 0);
    }

    public function test_invalid_json_value_is_rejected_rather_than_stored_as_text(): void
    {
        $admin = $this->adminWith(['users.view', 'users.update']);

        $this->actingAs($admin)
            ->postJson(route('admin.feature-flags.store'), [
                'key' => 'mobile.broken',
                'value' => 'treu',
                'is_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('value');

        $this->assertDatabaseCount('feature_flags', 0);
    }

    public function test_duplicate_global_scope_for_one_key_is_rejected(): void
    {
        $admin = $this->adminWith(['users.view', 'users.update']);

        FeatureFlag::create(['key' => 'mobile.dupe', 'is_enabled' => true, 'role' => null]);

        $this->actingAs($admin)
            ->postJson(route('admin.feature-flags.store'), [
                'key' => 'mobile.dupe',
                'is_enabled' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('key');

        $this->assertDatabaseCount('feature_flags', 1);
    }

    /* ────────────────────────── GET /api/v1/config ────────────────────────── */

    public function test_guest_cannot_read_remote_config(): void
    {
        $this->getJson('/api/v1/config')->assertUnauthorized();
    }

    public function test_config_returns_global_flags_for_a_plain_user(): void
    {
        FeatureFlag::create([
            'key' => 'mobile.sync_poll_interval_seconds',
            'value' => 300,
            'is_enabled' => true,
            'role' => null,
        ]);

        Sanctum::actingAs(User::factory()->create());

        $response = $this->getJson('/api/v1/config')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['flags', 'updated_at', 'etag', 'server_time']]);

        // Read the array directly: flag keys contain dots, which assertJsonPath
        // would interpret as path separators.
        $flags = $response->json('data.flags');

        $this->assertSame(300, $flags['mobile.sync_poll_interval_seconds']['value']);
        $this->assertTrue($flags['mobile.sync_poll_interval_seconds']['enabled']);
    }

    public function test_role_scoped_row_overrides_the_global_row(): void
    {
        FeatureFlag::create([
            'key' => 'mobile.sync_poll_interval_seconds',
            'value' => 300,
            'is_enabled' => true,
            'role' => null,
        ]);
        FeatureFlag::create([
            'key' => 'mobile.sync_poll_interval_seconds',
            'value' => 120,
            'is_enabled' => true,
            'role' => 'Project Manager',
        ]);

        $manager = User::factory()->create();
        $manager->assignRole(Role::findOrCreate('Project Manager'));

        Sanctum::actingAs($manager);

        $managerFlags = $this->getJson('/api/v1/config')->assertOk()->json('data.flags');
        $this->assertSame(120, $managerFlags['mobile.sync_poll_interval_seconds']['value']);

        // A user without the role still gets the global value.
        Sanctum::actingAs(User::factory()->create());

        $plainFlags = $this->getJson('/api/v1/config')->assertOk()->json('data.flags');
        $this->assertSame(300, $plainFlags['mobile.sync_poll_interval_seconds']['value']);
    }

    public function test_matching_etag_gets_a_304_so_the_foreground_poll_is_cheap(): void
    {
        FeatureFlag::create(['key' => 'mobile.thing', 'value' => true, 'is_enabled' => true, 'role' => null]);

        Sanctum::actingAs(User::factory()->create());

        $first = $this->getJson('/api/v1/config')->assertOk();
        $etag = $first->json('data.etag');

        $this->assertNotEmpty($etag);

        $this->withHeaders(['If-None-Match' => '"'.$etag.'"'])
            ->getJson('/api/v1/config')
            ->assertStatus(304);
    }

    public function test_an_edit_changes_the_etag_so_devices_pick_the_change_up(): void
    {
        $flag = FeatureFlag::create(['key' => 'mobile.thing', 'value' => true, 'is_enabled' => true, 'role' => null]);

        Sanctum::actingAs(User::factory()->create());

        $etag = $this->getJson('/api/v1/config')->assertOk()->json('data.etag');

        $flag->forceFill(['is_enabled' => false])->save();
        app(FeatureFlagService::class)->forgetMemo();

        $response = $this->withHeaders(['If-None-Match' => '"'.$etag.'"'])->getJson('/api/v1/config');

        $response->assertOk();
        $this->assertNotSame($etag, $response->json('data.etag'));
        $this->assertFalse($response->json('data.flags')['mobile.thing']['enabled']);
    }

    /* ─────────────── the flag actually gating real behaviour ─────────────── */

    public function test_sync_push_is_allowed_when_the_kill_switch_row_is_absent(): void
    {
        // Fail-open: a missing seed must never brick attendance sync.
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/sync/push', $this->pushPayload())
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_disabling_the_kill_switch_flag_stops_offline_push_server_side(): void
    {
        FeatureFlag::create([
            'key' => 'mobile.offline_sync_push_enabled',
            'value' => null,
            'is_enabled' => false,
            'role' => null,
        ]);

        app(FeatureFlagService::class)->forgetMemo();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/sync/push', $this->pushPayload())
            ->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'SYNC_PUSH_DISABLED');
    }

    public function test_kill_switch_can_be_scoped_to_a_single_role(): void
    {
        // Global stays ON; only Project Managers are paused (e.g. a bad build
        // shipped to that cohort).
        FeatureFlag::create([
            'key' => 'mobile.offline_sync_push_enabled',
            'is_enabled' => true,
            'role' => null,
        ]);
        FeatureFlag::create([
            'key' => 'mobile.offline_sync_push_enabled',
            'is_enabled' => false,
            'role' => 'Project Manager',
        ]);

        $manager = User::factory()->create();
        $manager->assignRole(Role::findOrCreate('Project Manager'));

        app(FeatureFlagService::class)->forgetMemo();
        Sanctum::actingAs($manager);
        $this->postJson('/api/v1/sync/push', $this->pushPayload())->assertStatus(503);

        app(FeatureFlagService::class)->forgetMemo();
        Sanctum::actingAs(User::factory()->create());
        $this->postJson('/api/v1/sync/push', $this->pushPayload())->assertOk();
    }

    /* ────────────────────────────── resolver ────────────────────────────── */

    public function test_resolution_survives_a_null_cache_store(): void
    {
        // Production runs CACHE_STORE=null: every put is discarded and every get
        // misses. The resolver must still return the truth from the database.
        config()->set('cache.default', 'null');

        FeatureFlag::create([
            'key' => 'mobile.sync_poll_interval_seconds',
            'value' => 900,
            'is_enabled' => true,
            'role' => null,
        ]);

        $service = app(FeatureFlagService::class);
        $service->forgetMemo();

        $user = User::factory()->create();

        $this->assertSame(900, $service->value('mobile.sync_poll_interval_seconds', $user));
        $this->assertTrue($service->isEnabled('mobile.offline_sync_push_enabled', $user, true));
        $this->assertFalse($service->isEnabled('mobile.unknown_key', $user, false));
        $this->assertSame('fallback', $service->value('mobile.unknown_key', $user, 'fallback'));
    }

    public function test_a_disabled_config_row_falls_back_to_the_client_default(): void
    {
        FeatureFlag::create([
            'key' => 'mobile.sync_poll_interval_seconds',
            'value' => 30,
            'is_enabled' => false,
            'role' => null,
        ]);

        $service = app(FeatureFlagService::class);
        $service->forgetMemo();

        $this->assertSame(300, $service->value('mobile.sync_poll_interval_seconds', User::factory()->create(), 300));
    }

    public function test_seeder_is_idempotent_and_ships_the_wired_flags(): void
    {
        Role::findOrCreate('Project Manager');

        $this->seed(\Database\Seeders\FeatureFlagSeeder::class);
        $this->seed(\Database\Seeders\FeatureFlagSeeder::class);

        $this->assertDatabaseCount('feature_flags', 3);
        $this->assertDatabaseHas('feature_flags', [
            'key' => 'mobile.offline_sync_push_enabled',
            'role' => null,
            'is_enabled' => true,
        ]);
        $this->assertDatabaseHas('feature_flags', [
            'key' => 'mobile.sync_poll_interval_seconds',
            'role' => 'Project Manager',
        ]);
    }

    /* ─────────────────────────────── helpers ─────────────────────────────── */

    /**
     * A shape-valid outbox flush body. SyncPushRequest validates BEFORE the
     * controller runs, so the kill switch can only be observed on a request
     * that would otherwise have been accepted — which is exactly what a real
     * device sends.
     *
     * @return array<string, mixed>
     */
    protected function pushPayload(): array
    {
        return [
            'mutations' => [[
                'idempotency_key' => 'outbox-'.uniqid('', true),
                'module' => 'leaves',
                'action' => 'cancel',
                'payload' => ['leave_id' => 999999],
            ]],
        ];
    }

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

        return $admin->fresh();
    }
}
