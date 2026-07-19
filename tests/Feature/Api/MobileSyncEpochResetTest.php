<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\Admin\UserManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Coverage for the OFFLINE-CACHE VISIBILITY RESIDUAL left open by the per-row
 * visibility tombstones.
 *
 * Tombstones handle a ROW leaving a user's scope (reassignment/delete). They cannot
 * handle the mirror case: the USER'S OWN scope inputs changing — report_to,
 * department_id, designation_id, or their role set — which shifts their ENTIRE
 * visibility window at once with no per-row event. The device would then cache rows
 * it can no longer see AND miss rows it now should.
 *
 * The fix is a per-user monotonic `sync_epoch`: bumped on every scope-input change,
 * surfaced on bootstrap/pull, and compared against the epoch the client presents. A
 * stale epoch earns a re-bootstrap directive rather than an unbounded tombstone
 * storm.
 */
class MobileSyncEpochResetTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────
    //  Scope inputs bump the epoch
    // ──────────────────────────────────────────────

    public function test_changing_report_to_bumps_the_sync_epoch(): void
    {
        $manager = User::factory()->create();
        $user = User::factory()->create();

        $this->assertSame(1, (int) $user->fresh()->sync_epoch, 'A new user starts at epoch 1.');

        // Direct assignment + save, mirroring UserManagementService::updateReportTo.
        // report_to is deliberately NOT mass-assignable on User, so ->update() would
        // silently drop it and prove nothing.
        $user->report_to = $manager->id;
        $user->save();

        $this->assertSame(
            2,
            (int) $user->fresh()->sync_epoch,
            'report_to drives team visibility — changing it must force a re-bootstrap.'
        );
    }

    public function test_changing_designation_or_department_bumps_the_sync_epoch(): void
    {
        $user = User::factory()->create();
        [$departmentId, $designationId] = $this->orgUnitIds();

        $user->designation_id = $designationId;
        $user->save();
        $this->assertSame(2, (int) $user->fresh()->sync_epoch, 'designation drives the scope predicate.');

        $user->department_id = $departmentId;
        $user->save();
        $this->assertSame(3, (int) $user->fresh()->sync_epoch, 'department is a declared scope input.');
    }

    public function test_a_role_change_bumps_the_sync_epoch(): void
    {
        Role::create(['name' => 'Project Manager', 'guard_name' => 'web']);
        $user = User::factory()->create();

        // Roles live in the Spatie pivot and fire no model `updated` event, so the
        // bump has to come from the application's role-assignment path.
        (new UserManagementService)->syncRoles($user, ['Project Manager']);

        $this->assertSame(
            2,
            (int) $user->fresh()->sync_epoch,
            'Gaining a privileged role widens visibility to every daily work — the device must re-bootstrap.'
        );

        // Removing the role narrows it again, which is the more dangerous direction:
        // the cache would otherwise keep every row the user could once see.
        (new UserManagementService)->syncRoles($user->fresh(), []);

        $this->assertSame(3, (int) $user->fresh()->sync_epoch, 'Losing a role must bump too.');
    }

    public function test_an_unrelated_profile_edit_does_not_bump_the_epoch(): void
    {
        $user = User::factory()->create();

        $user->update(['phone' => '01700000000']);

        $this->assertSame(
            1,
            (int) $user->fresh()->sync_epoch,
            'Only scope INPUTS may bump — a routine profile edit must not cost every device a full re-bootstrap.'
        );
    }

    // ──────────────────────────────────────────────
    //  Protocol: epoch surfacing + reset directive
    // ──────────────────────────────────────────────

    public function test_bootstrap_and_pull_surface_the_current_epoch(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/sync/bootstrap?modules[]=daily_works&limit=5')
            ->assertOk()
            ->assertJsonPath('data.sync_epoch', 1);

        $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=5')
            ->assertOk()
            ->assertJsonPath('data.sync_epoch', 1);
    }

    public function test_pull_with_a_stale_epoch_returns_the_reset_directive(): void
    {
        $manager = User::factory()->create();
        $user = User::factory()->create();

        Sanctum::actingAs($user);

        // Device bootstraps at epoch 1.
        $epoch = (int) $this->getJson('/api/v1/sync/bootstrap?modules[]=daily_works&limit=5')
            ->assertOk()
            ->json('data.sync_epoch');

        $this->assertSame(1, $epoch);

        // The user's scope shifts wholesale while the device is offline.
        $user->report_to = $manager->id;
        $user->save();

        $response = $this->getJson(
            '/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=5&epoch='.$epoch
        )->assertOk();

        $response->assertJsonPath('data.reset', true)
            ->assertJsonPath('data.must_bootstrap', true)
            ->assertJsonPath('data.sync_epoch', 2);

        // The reset short-circuits the delta: no partial change set to half-apply.
        $response->assertJsonMissingPath('data.changes');
        $response->assertJsonMissingPath('data.next_cursor');
    }

    public function test_pull_with_the_current_epoch_is_a_normal_delta(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson(
            '/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=5&epoch=1'
        )->assertOk();

        $response->assertJsonPath('data.reset', false)
            ->assertJsonPath('data.sync_epoch', 1);

        // Normal delta shape is intact.
        $response->assertJsonStructure(['data' => ['changes', 'next_cursor', 'has_more', 'counts']]);
    }

    public function test_a_future_epoch_from_the_client_never_triggers_a_reset(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        // A client ahead of the server (clock/replay oddity) must not be reset into
        // a bootstrap loop.
        $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=5&epoch=99')
            ->assertOk()
            ->assertJsonPath('data.reset', false);
    }

    public function test_a_legacy_client_that_sends_no_epoch_still_works(): void
    {
        $manager = User::factory()->create();
        $user = User::factory()->create();

        // Server epoch has moved on, but an old build sends no epoch at all.
        $user->report_to = $manager->id;
        $user->save();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=5')
            ->assertOk();

        $response->assertJsonPath('data.reset', false)
            ->assertJsonPath('data.sync_epoch', 2)
            ->assertJsonStructure(['data' => ['changes', 'next_cursor', 'has_more']]);
    }

    /**
     * Real department + designation rows, so the users FK constraints hold.
     *
     * @return array{0: int, 1: int} [departmentId, designationId]
     */
    private function orgUnitIds(): array
    {
        $departmentId = (int) DB::table('departments')->insertGetId([
            'name' => 'Quality Control',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $designationId = (int) DB::table('designations')->insertGetId([
            'title' => 'Quality Control Inspector',
            'department_id' => $departmentId,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$departmentId, $designationId];
    }
}
