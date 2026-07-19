<?php

namespace Tests\Feature\Admin;

use App\Models\ClientErrorLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Client Diagnostics end to end:
 *   - ingest stores a group and fingerprints it server-side,
 *   - a repeat of the same error DEDUPS into count rather than a second row,
 *   - a malformed event is skipped, never fatal,
 *   - the batch cap is enforced,
 *   - the admin surface is gated exactly like admin device sessions,
 *   - retention prunes on the documented windows.
 */
class ClientErrorTelemetryTest extends TestCase
{
    use RefreshDatabase;

    /* ─────────────────────────────── ingest ─────────────────────────────── */

    public function test_ingest_stores_and_fingerprints_an_event(): void
    {
        $this->postJson('/api/v1/client-errors', [
            'events' => [$this->event()],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('skipped', 0);

        $this->assertDatabaseCount('client_error_logs', 1);

        $group = ClientErrorLog::first();

        $this->assertSame('TypeError', $group->error_type);
        $this->assertSame('fatal', $group->severity);
        $this->assertSame('AttendanceScreen', $group->screen);
        $this->assertSame('android', $group->platform);
        $this->assertSame('Pixel 7', $group->device_model);
        $this->assertSame(1, $group->count);
        $this->assertNotEmpty($group->fingerprint);
        // Fingerprint is computed by the SERVER, not accepted from the client.
        $this->assertSame(
            ClientErrorLog::fingerprintFor('TypeError', "undefined is not an object (evaluating 'x.y')", "at PunchButton.js:12:9"),
            $group->fingerprint,
        );
        $this->assertSame(['android' => 1], $group->platform_counts);
        $this->assertSame(['device-a'], $group->affected_devices);
    }

    public function test_a_repeat_dedups_into_the_count_instead_of_a_new_row(): void
    {
        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();

        // Same bug, different device + volatile ids in the message: the
        // normalizer must still land it in the SAME group.
        $this->postJson('/api/v1/client-errors', [
            'events' => [
                $this->event([
                    'device_id' => 'device-b',
                    'platform' => 'ios',
                ]),
            ],
        ])->assertOk()->assertJsonPath('accepted', 1);

        $this->assertDatabaseCount('client_error_logs', 1);

        $group = ClientErrorLog::first();

        $this->assertSame(2, $group->count);
        $this->assertEqualsCanonicalizing(['device-a', 'device-b'], $group->affected_devices);
        $this->assertSame(['android' => 1, 'ios' => 1], $group->platform_counts);
    }

    public function test_volatile_numbers_and_ids_do_not_fork_the_group(): void
    {
        $this->postJson('/api/v1/client-errors', [
            'events' => [$this->event(['message' => 'Request failed with status 500 for id 42', 'stack' => null])],
        ])->assertOk();

        $this->postJson('/api/v1/client-errors', [
            'events' => [$this->event(['message' => 'Request failed with status 503 for id 91', 'stack' => null])],
        ])->assertOk();

        $this->assertDatabaseCount('client_error_logs', 1);
        $this->assertSame(2, ClientErrorLog::first()->count);
    }

    public function test_a_malformed_event_is_skipped_not_fatal(): void
    {
        $this->postJson('/api/v1/client-errors', [
            'events' => [
                $this->event(),
                ['error_type' => 'TypeError'],               // no message -> invalid
                ['message' => str_repeat('x', 5000)],        // over the length cap
                $this->event(['message' => 'A second real error', 'stack' => 'at Other.js:1:1']),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('accepted', 2)
            ->assertJsonPath('skipped', 2);

        $this->assertDatabaseCount('client_error_logs', 2);
    }

    public function test_batch_cap_is_enforced(): void
    {
        $events = array_fill(0, 51, $this->event());

        $this->postJson('/api/v1/client-errors', ['events' => $events])
            ->assertStatus(422)
            ->assertJsonValidationErrors('events');

        $this->assertDatabaseCount('client_error_logs', 0);
    }

    public function test_pre_login_event_is_accepted_without_a_user(): void
    {
        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();

        $this->assertNull(ClientErrorLog::first()->user_id);
    }

    public function test_a_valid_bearer_token_attributes_the_event_to_a_user(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();

        $group = ClientErrorLog::first();

        $this->assertSame($user->id, $group->user_id);
        $this->assertSame([$user->id], $group->affected_users);
    }

    public function test_a_recurrence_reopens_a_resolved_group(): void
    {
        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();

        $group = ClientErrorLog::first();
        $group->forceFill(['resolved_at' => Carbon::now()->subDay(), 'resolved_by' => 1])->save();

        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();

        $this->assertNull($group->fresh()->resolved_at);
    }

    /* ──────────────────────────── admin surface ──────────────────────────── */

    public function test_admin_with_users_view_sees_grouped_errors(): void
    {
        $admin = $this->adminWith(['users.view']);

        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();
        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('errors.0.error_type', 'TypeError')
            ->assertJsonPath('errors.0.count', 2)
            ->assertJsonPath('summary.total_groups', 1)
            ->assertJsonPath('summary.total_occurrences', 2);
    }

    public function test_non_admin_cannot_see_client_errors(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('admin.client-errors.index'))
            ->assertForbidden();
    }

    public function test_admin_with_update_permission_can_resolve(): void
    {
        $admin = $this->adminWith(['users.view', 'users.update']);

        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();
        $group = ClientErrorLog::first();

        $this->actingAs($admin)
            ->postJson(route('admin.client-errors.resolve', ['error' => $group->id]), ['resolved' => true])
            ->assertOk()
            ->assertJsonPath('resolved', true);

        $group->refresh();

        $this->assertNotNull($group->resolved_at);
        $this->assertSame($admin->id, $group->resolved_by);
    }

    public function test_read_permission_alone_cannot_resolve(): void
    {
        $admin = $this->adminWith(['users.view']);

        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();
        $group = ClientErrorLog::first();

        $this->actingAs($admin)
            ->postJson(route('admin.client-errors.resolve', ['error' => $group->id]))
            ->assertForbidden();

        $this->assertNull($group->fresh()->resolved_at);
    }

    public function test_detail_endpoint_returns_stack_and_breadcrumbs(): void
    {
        $admin = $this->adminWith(['users.view']);

        $this->postJson('/api/v1/client-errors', ['events' => [$this->event()]])->assertOk();
        $group = ClientErrorLog::first();

        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.show', ['error' => $group->id]))
            ->assertOk()
            ->assertJsonPath('error.stack', 'at PunchButton.js:12:9')
            ->assertJsonPath('error.breadcrumbs.0.message', 'tapped punch in');
    }

    public function test_filters_narrow_the_list(): void
    {
        $admin = $this->adminWith(['users.view']);

        $this->postJson('/api/v1/client-errors', [
            'events' => [
                $this->event(),
                $this->event(['message' => 'Warned', 'severity' => 'warning', 'platform' => 'ios', 'stack' => 'at A.js:1:1']),
            ],
        ])->assertOk();

        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index', ['severity' => 'warning']))
            ->assertOk()
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.severity', 'warning');

        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index', ['platform' => 'android']))
            ->assertOk()
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.platform', 'android');
    }

    /* ───────────────────────────── retention ───────────────────────────── */

    public function test_prune_drops_old_resolved_and_stale_groups(): void
    {
        // resolved + old -> pruned
        $this->makeGroup('a', ['resolved_at' => Carbon::now()->subDays(40), 'last_seen_at' => Carbon::now()->subDays(40)]);
        // resolved but recent -> kept
        $this->makeGroup('b', ['resolved_at' => Carbon::now(), 'last_seen_at' => Carbon::now()->subDays(2)]);
        // unresolved and ancient -> pruned by the hard 90d window
        $this->makeGroup('c', ['last_seen_at' => Carbon::now()->subDays(120)]);
        // unresolved and live -> kept
        $this->makeGroup('d', ['last_seen_at' => Carbon::now()->subDay()]);

        $this->artisan('client-errors:prune')->assertSuccessful();

        $this->assertDatabaseCount('client_error_logs', 2);
        $this->assertDatabaseMissing('client_error_logs', ['fingerprint' => 'a']);
        $this->assertDatabaseMissing('client_error_logs', ['fingerprint' => 'c']);
        $this->assertDatabaseHas('client_error_logs', ['fingerprint' => 'b']);
        $this->assertDatabaseHas('client_error_logs', ['fingerprint' => 'd']);
    }

    /* ─────────────────────────────── helpers ─────────────────────────────── */

    /**
     * The EXACT wire shape the mobile client sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function event(array $overrides = []): array
    {
        return array_merge([
            'message' => "undefined is not an object (evaluating 'x.y')",
            'error_type' => 'TypeError',
            'severity' => 'fatal',
            'stack' => 'at PunchButton.js:12:9',
            'screen' => 'AttendanceScreen',
            'platform' => 'android',
            'os_version' => '14',
            'model' => 'Pixel 7',
            'app_version' => '1.4.0',
            'build' => '104',
            'device_id' => 'device-a',
            'session_id' => 'session-1',
            'breadcrumbs' => [
                ['type' => 'ui', 'message' => 'tapped punch in', 'at' => '2026-07-19T08:00:00Z'],
            ],
            'context' => ['network' => 'wifi'],
            'occurred_at' => '2026-07-19T08:00:01Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeGroup(string $fingerprint, array $attributes = []): ClientErrorLog
    {
        return ClientErrorLog::create(array_merge([
            'fingerprint' => $fingerprint,
            'message' => 'seeded '.$fingerprint,
            'severity' => 'error',
            'count' => 1,
            'received_at' => Carbon::now()->subDays(200),
            'last_seen_at' => Carbon::now(),
        ], $attributes));
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
