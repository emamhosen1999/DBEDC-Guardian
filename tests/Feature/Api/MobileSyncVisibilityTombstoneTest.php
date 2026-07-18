<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the VISIBILITY-TOMBSTONE gap in the delta-sync engine.
 *
 * `pull` returns currently-visible rows plus deletion tombstones. A row can also
 * leave a user's visibility WITHOUT being deleted — a daily work reassigned to
 * someone else — and before this fix the departing user's device cached it
 * forever, because their own scoped query could no longer see it to report on.
 *
 * The fix records a per-user tombstone on the owning-column change (and on the
 * soft delete, which likewise left no trace for daily_works).
 */
class MobileSyncVisibilityTombstoneTest extends TestCase
{
    use RefreshDatabase;

    public function test_daily_work_reassigned_away_tombstones_for_the_old_user_and_arrives_for_the_new_one(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $dailyWork = $this->dailyWorkFor($userA);

        // 1. A is synced and can see the row.
        Sanctum::actingAs($userA);
        $first = $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=50')->assertOk();

        $this->assertSame(
            [$dailyWork->id],
            collect($first->json('data.changes.daily_works'))->pluck('id')->map('intval')->all(),
            'User A must start out seeing the daily work.'
        );

        $cursorA = $first->json('data.next_cursor.daily_works');

        // 2. Reassign the work away from A to B.
        $dailyWork->update(['incharge' => $userB->id, 'assigned' => $userB->id]);

        $this->assertDatabaseHas('sync_tombstones', [
            'user_id' => $userA->id,
            'module' => 'daily_works',
            'entity_id' => $dailyWork->id,
        ]);

        // 3. A's next delta pull must tell the device to evict it.
        $delta = $this->getJson(
            '/api/v1/sync/pull?modules[]=daily_works&limit=50&cursor[daily_works]='.urlencode($cursorA)
        )->assertOk();

        $tombstone = collect($delta->json('data.changes.daily_works'))->firstWhere('id', $dailyWork->id);

        $this->assertNotNull($tombstone, 'The reassigned daily work must appear in user A pull.');
        $this->assertTrue((bool) ($tombstone['deleted'] ?? false), 'It must appear as a tombstone, not a row.');

        // 4. B gets it as a NORMAL row — an arrival needs no tombstone, the
        //    updated_at bump carries it into B's visibility-scoped cursor pull.
        Sanctum::actingAs($userB);
        $arrival = $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=50')->assertOk();

        $rowForB = collect($arrival->json('data.changes.daily_works'))->firstWhere('id', $dailyWork->id);

        $this->assertNotNull($rowForB, 'User B must receive the reassigned daily work.');
        $this->assertArrayNotHasKey('deleted', $rowForB, 'For B this is an arrival, not a tombstone.');
        $this->assertSame($dailyWork->number, $rowForB['number']);

        // And B must NOT have been handed a tombstone for a row that is now theirs.
        $this->assertDatabaseMissing('sync_tombstones', [
            'user_id' => $userB->id,
            'module' => 'daily_works',
            'entity_id' => $dailyWork->id,
        ]);
    }

    public function test_deleted_daily_work_still_surfaces_as_a_tombstone(): void
    {
        $user = User::factory()->create();
        $dailyWork = $this->dailyWorkFor($user);

        Sanctum::actingAs($user);
        $cursor = $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=50')
            ->assertOk()
            ->json('data.next_cursor.daily_works');

        // Soft delete: the row still exists in the table but leaves every scope,
        // so without a tombstone pull would simply never mention it again.
        $dailyWork->delete();

        $delta = $this->getJson(
            '/api/v1/sync/pull?modules[]=daily_works&limit=50&cursor[daily_works]='.urlencode($cursor)
        )->assertOk();

        $tombstone = collect($delta->json('data.changes.daily_works'))->firstWhere('id', $dailyWork->id);

        $this->assertNotNull($tombstone, 'A deleted daily work must be tombstoned.');
        $this->assertTrue((bool) ($tombstone['deleted'] ?? false));
    }

    public function test_pull_paging_drains_every_visibility_tombstone(): void
    {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $works = collect(range(1, 3))->map(fn () => $this->dailyWorkFor($userA));

        Sanctum::actingAs($userA);
        $cursor = $this->getJson('/api/v1/sync/pull?modules[]=daily_works&cursor[daily_works]=0&limit=50')
            ->assertOk()
            ->json('data.next_cursor.daily_works');

        foreach ($works as $work) {
            $work->update(['incharge' => $userB->id, 'assigned' => $userB->id]);
        }

        // A deliberately small limit: has_more must keep the client looping until
        // every tombstone has been drained, exactly as it does for rows.
        $collected = [];
        $guard = 0;

        do {
            $response = $this->getJson(
                '/api/v1/sync/pull?modules[]=daily_works&limit=1&cursor[daily_works]='.urlencode($cursor)
            )->assertOk();

            foreach ($response->json('data.changes.daily_works') as $change) {
                if (! empty($change['deleted'])) {
                    $collected[] = (int) $change['id'];
                }
            }

            $cursor = $response->json('data.next_cursor.daily_works');
            $hasMore = (bool) $response->json('data.has_more.daily_works');
            $guard++;
        } while ($hasMore && $guard < 20);

        sort($collected);

        $this->assertSame($works->pluck('id')->sort()->values()->all(), $collected);
        $this->assertGreaterThanOrEqual(3, $guard, 'A limit of 1 over 3 tombstones must require multiple pages.');
    }

    private function dailyWorkFor(User $user): DailyWork
    {
        return DailyWork::factory()->create([
            'incharge' => $user->id,
            'assigned' => $user->id,
        ]);
    }
}
