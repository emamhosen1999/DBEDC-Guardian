<?php

namespace Tests\Feature\Admin;

use App\Models\ClientErrorLog;
use App\Models\User;
use App\Services\Diagnostics\ServerErrorReporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use ReflectionProperty;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Server-side exception capture — the backend half of the crash-triage loop.
 *
 * Proves:
 *   - a real 500 lands as a grouped row with source=server and the request facts,
 *   - EXPECTED client faults (404 / 422 / 403 / 401) are NOT logged,
 *   - a repeat dedups into `count` exactly like the mobile stream,
 *   - the admin source filter narrows to one stream,
 *   - a failure INSIDE the logger cannot break the request (rule 1),
 *   - a re-entrant capture is a no-op (rule 2, recursion guard).
 */
class ServerErrorTelemetryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        ServerErrorReporter::flushRequestId();

        // Throwing routes, registered per test so no production route is involved.
        Route::get('/__telemetry/boom', function () {
            throw new RuntimeException('Boom in the engine room');
        })->name('telemetry.boom');

        Route::get('/__telemetry/forbidden', function () {
            throw new AuthorizationException('Nope');
        });

        Route::post('/__telemetry/validate', function (Request $request) {
            $request->validate(['required_field' => 'required']);

            return ['ok' => true];
        });
    }

    /* ─────────────────────────────── capture ─────────────────────────────── */

    public function test_a_real_server_exception_lands_as_a_grouped_row(): void
    {
        $this->get('/__telemetry/boom')->assertStatus(500);

        $this->assertDatabaseCount('client_error_logs', 1);

        $group = ClientErrorLog::first();

        $this->assertSame(ClientErrorLog::SOURCE_SERVER, $group->source);
        $this->assertSame(RuntimeException::class, $group->error_type);
        $this->assertSame('Boom in the engine room', $group->message);
        $this->assertSame('error', $group->severity);
        $this->assertSame('GET', $group->http_method);
        $this->assertSame('/__telemetry/boom', $group->path);
        $this->assertSame('telemetry.boom', $group->route_name);
        $this->assertSame(500, $group->status_code);
        $this->assertNotNull($group->request_id);
        $this->assertNotNull($group->line);
        // The throw site is relativized to the app root so a deploy-path change
        // cannot fork the group.
        $this->assertStringContainsString('tests/Feature/Admin/ServerErrorTelemetryTest.php', $group->file);
        $this->assertStringContainsString('Boom in the engine room', $group->stack);
        $this->assertSame(1, $group->count);
    }

    public function test_a_php_error_is_recorded_as_fatal(): void
    {
        Route::get('/__telemetry/fatal', function () {
            throw new \TypeError('bad type');
        });

        $this->get('/__telemetry/fatal')->assertStatus(500);

        $this->assertSame('fatal', ClientErrorLog::first()->severity);
    }

    public function test_the_exception_is_attributed_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/__telemetry/boom')->assertStatus(500);

        $group = ClientErrorLog::first();

        $this->assertSame($user->id, $group->user_id);
        $this->assertSame([$user->id], $group->affected_users);
    }

    public function test_a_repeat_dedups_into_the_count(): void
    {
        $this->get('/__telemetry/boom')->assertStatus(500);
        $this->get('/__telemetry/boom')->assertStatus(500);
        $this->get('/__telemetry/boom')->assertStatus(500);

        $this->assertDatabaseCount('client_error_logs', 1);
        $this->assertSame(3, ClientErrorLog::first()->count);
    }

    public function test_two_different_throw_sites_do_not_merge(): void
    {
        Route::get('/__telemetry/boom-two', function () {
            throw new RuntimeException('Boom in the engine room');
        });

        $this->get('/__telemetry/boom')->assertStatus(500);
        $this->get('/__telemetry/boom-two')->assertStatus(500);

        // Same class, same message — different file:line, so different bugs.
        $this->assertDatabaseCount('client_error_logs', 2);
    }

    /* ───────────────────────────── the skip list ──────────────────────────── */

    public function test_a_404_is_not_logged(): void
    {
        $this->get('/__telemetry/definitely-not-a-route')->assertNotFound();

        $this->assertDatabaseCount('client_error_logs', 0);
    }

    public function test_a_422_validation_failure_is_not_logged(): void
    {
        $this->postJson('/__telemetry/validate', [])->assertStatus(422);

        $this->assertDatabaseCount('client_error_logs', 0);
    }

    public function test_a_403_authorization_failure_is_not_logged(): void
    {
        $this->getJson('/__telemetry/forbidden')->assertStatus(403);

        $this->assertDatabaseCount('client_error_logs', 0);
    }

    public function test_a_401_authentication_failure_is_not_logged(): void
    {
        Route::get('/__telemetry/guarded', fn () => 'secret')->middleware('auth:sanctum');

        $this->getJson('/__telemetry/guarded')->assertStatus(401);

        $this->assertDatabaseCount('client_error_logs', 0);
    }

    /**
     * Documents a FRAMEWORK boundary, not a choice of ours.
     *
     * Laravel's exception handler lists HttpException in `$internalDontReport`,
     * so `abort()` — at ANY status, 404 or 500 — never reaches a report()
     * callback. This telemetry therefore covers UNCAUGHT exceptions (the actual
     * gap: the 500s nobody sees), while a deliberate abort(500) stays a
     * deliberate, already-intentional response written by a developer.
     *
     * Pinned as a test so that if a future Laravel upgrade starts reporting
     * HttpException, this fails loudly instead of quietly flooding the board
     * with aborts.
     */
    public function test_a_deliberate_abort_is_not_reported_by_the_framework(): void
    {
        Route::get('/__telemetry/abort', fn () => abort(500, 'We failed'));

        $this->get('/__telemetry/abort')->assertStatus(500);

        $this->assertDatabaseCount('client_error_logs', 0);
    }

    /* ──────────────────────── the logger cannot bite ──────────────────────── */

    public function test_a_failure_inside_the_logger_cannot_break_the_request(): void
    {
        // Remove the telemetry table out from under the reporter — the same
        // shape of failure as a DB outage or a half-applied migration, which is
        // exactly when exceptions spike.
        Schema::drop('client_error_logs');

        // The request must still complete with its ORIGINAL outcome (500 from
        // the route), not a secondary fatal from the reporter.
        $this->get('/__telemetry/boom')->assertStatus(500);

        // And a request that never faults must be entirely unaffected.
        Route::get('/__telemetry/healthy', fn () => ['ok' => true]);
        $this->getJson('/__telemetry/healthy')->assertOk()->assertJsonPath('ok', true);

        $this->assertFalse(Schema::hasTable('client_error_logs'));
    }

    public function test_a_reentrant_capture_is_a_no_op(): void
    {
        // Simulate being mid-capture: an exception raised while recording an
        // exception must NOT be recorded, or the reporter loops until the
        // process dies.
        $flag = new ReflectionProperty(ServerErrorReporter::class, 'capturing');
        $flag->setAccessible(true);
        $flag->setValue(null, true);

        try {
            ServerErrorReporter::capture(new RuntimeException('Recursive boom'));

            $this->assertDatabaseCount('client_error_logs', 0);
        } finally {
            $flag->setValue(null, false);
        }

        // Guard released: the very same exception now records normally, proving
        // the no-op was the flag and not a broken capture path.
        ServerErrorReporter::capture(new RuntimeException('Recursive boom'));

        $this->assertDatabaseCount('client_error_logs', 1);
    }

    /* ──────────────────────────── admin surface ──────────────────────────── */

    public function test_the_source_filter_narrows_to_one_stream(): void
    {
        $admin = $this->adminWith(['users.view']);

        // one server group…
        $this->get('/__telemetry/boom')->assertStatus(500);

        // …and one mobile group, through the real ingest endpoint.
        $this->postJson('/api/v1/client-errors', [
            'events' => [[
                'message' => 'undefined is not an object',
                'error_type' => 'TypeError',
                'severity' => 'fatal',
                'stack' => 'at PunchButton.js:12:9',
                'platform' => 'android',
                'device_id' => 'device-a',
            ]],
        ])->assertOk();

        $this->assertDatabaseCount('client_error_logs', 2);

        // Unfiltered: both streams on one board.
        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index'))
            ->assertOk()
            ->assertJsonCount(2, 'errors')
            ->assertJsonPath('summary.mobile_unresolved', 1)
            ->assertJsonPath('summary.server_unresolved', 1);

        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index', ['source' => 'server']))
            ->assertOk()
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.source', 'server')
            ->assertJsonPath('errors.0.is_server', true)
            ->assertJsonPath('errors.0.error_type', RuntimeException::class)
            ->assertJsonPath('errors.0.path', '/__telemetry/boom')
            ->assertJsonPath('errors.0.status_code', 500);

        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index', ['source' => 'mobile']))
            ->assertOk()
            ->assertJsonCount(1, 'errors')
            ->assertJsonPath('errors.0.source', 'mobile')
            ->assertJsonPath('errors.0.is_server', false);

        // An unknown source must fall back to "all" rather than 500 or empty.
        $this->actingAs($admin)
            ->getJson(route('admin.client-errors.index', ['source' => 'martian']))
            ->assertOk()
            ->assertJsonCount(2, 'errors')
            ->assertJsonPath('filters.source', 'all');
    }

    public function test_a_server_group_can_be_resolved_like_any_other(): void
    {
        $admin = $this->adminWith(['users.view', 'users.update']);

        $this->get('/__telemetry/boom')->assertStatus(500);
        $group = ClientErrorLog::first();

        $this->actingAs($admin)
            ->postJson(route('admin.client-errors.resolve', ['error' => $group->id]), ['resolved' => true])
            ->assertOk();

        $this->assertNotNull($group->fresh()->resolved_at);

        // A recurrence reopens it — same regression rule as the mobile stream.
        $this->get('/__telemetry/boom')->assertStatus(500);

        $this->assertNull($group->fresh()->resolved_at);
    }

    public function test_prune_covers_the_server_stream(): void
    {
        ClientErrorLog::create([
            'fingerprint' => 'server-stale',
            'source' => ClientErrorLog::SOURCE_SERVER,
            'message' => 'old server fault',
            'severity' => 'error',
            'count' => 1,
            'received_at' => now()->subDays(200),
            'last_seen_at' => now()->subDays(120),
        ]);

        ClientErrorLog::create([
            'fingerprint' => 'server-live',
            'source' => ClientErrorLog::SOURCE_SERVER,
            'message' => 'live server fault',
            'severity' => 'error',
            'count' => 1,
            'received_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
        ]);

        $this->artisan('client-errors:prune')->assertSuccessful();

        $this->assertDatabaseMissing('client_error_logs', ['fingerprint' => 'server-stale']);
        $this->assertDatabaseHas('client_error_logs', ['fingerprint' => 'server-live']);
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
