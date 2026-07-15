<?php

namespace Tests\Feature\Api;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression coverage for the three data-loss bugs hardened in the mobile
 * delta-sync engine:
 *
 *   1. pull() silently dropped every changed row beyond the first page
 *      (DESC + limit + cursor=now()). Fixed: ASC composite (updated_at, id)
 *      cursor + has_more paging that drains every row.
 *   2. Hard deletes left no tombstone, so deleted rows lived forever on the
 *      device. Fixed: an append-only sync_tombstones log the pull returns.
 *   3. Offline punches recorded at server time (push time), not capture time.
 *      Fixed: a bounded, sync-only captured_at, plus atomic idempotency so a
 *      replayed mutation never double-applies.
 */
class MobileSyncEngineHardeningTest extends TestCase
{
    use RefreshDatabase;

    // ── Bug 1: pull drains all rows across pages ────────────────────────────

    public function test_pull_drains_every_changed_row_across_pages_without_dropping_any(): void
    {
        $user = User::factory()->create([]);

        // Five rows, some sharing the exact same updated_at second, to prove the
        // composite (updated_at, id) cursor never skips a same-second neighbour.
        $t1 = now()->subMinutes(50)->format('Y-m-d H:i:s');
        $t2 = now()->subMinutes(40)->format('Y-m-d H:i:s');
        $t3 = now()->subMinutes(30)->format('Y-m-d H:i:s');

        $expectedIds = [
            $this->insertAttendance($user->id, $t1),
            $this->insertAttendance($user->id, $t1),
            $this->insertAttendance($user->id, $t2),
            $this->insertAttendance($user->id, $t2),
            $this->insertAttendance($user->id, $t3),
        ];
        sort($expectedIds);

        Sanctum::actingAs($user);

        // Page with a deliberately small limit so a naive implementation would drop rows.
        $collected = [];
        $cursor = null;
        $guard = 0;

        do {
            $url = '/api/v1/sync/pull?modules[]=attendance&limit=2';
            if ($cursor !== null) {
                $url .= '&cursor[attendance]='.urlencode($cursor);
            }

            $response = $this->getJson($url)->assertOk();

            foreach ($response->json('data.changes.attendance') as $row) {
                if (empty($row['deleted'])) {
                    $collected[] = (int) $row['id'];
                }
            }

            $cursor = $response->json('data.next_cursor.attendance');
            $hasMore = (bool) $response->json('data.has_more.attendance');
            $guard++;
        } while ($hasMore && $guard < 20);

        sort($collected);

        $this->assertSame($expectedIds, $collected, 'Every changed row must be returned exactly once across pages.');
        $this->assertCount(5, $collected);
        $this->assertGreaterThanOrEqual(3, $guard, 'A limit of 2 over 5 rows must require multiple pages.');
    }

    public function test_pull_reports_has_more_true_while_a_full_page_remains(): void
    {
        $user = User::factory()->create([]);

        foreach (range(1, 3) as $i) {
            $this->insertAttendance($user->id, now()->subMinutes(60 - $i)->format('Y-m-d H:i:s'));
        }

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sync/pull?modules[]=attendance&limit=2')->assertOk();

        $this->assertTrue($response->json('data.has_more.attendance'));
        $this->assertCount(2, $response->json('data.changes.attendance'));
    }

    // ── Bug 2: deletes surface as tombstones ────────────────────────────────

    public function test_hard_deleted_leave_surfaces_as_a_tombstone_on_pull(): void
    {
        $user = User::factory()->create([]);
        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeave($user->id, $leaveTypeId);

        Sanctum::actingAs($user);

        // Cancel (hard delete) the leave through the sync push channel.
        $this->postJson('/api/v1/sync/push', [
            'mutations' => [[
                'idempotency_key' => 'cancel-'.$leaveId,
                'module' => 'leaves',
                'action' => 'cancel',
                'payload' => ['leave_id' => $leaveId],
            ]],
        ])->assertOk()->assertJsonPath('data.summary.applied', 1);

        $this->assertDatabaseMissing('leaves', ['id' => $leaveId]);
        $this->assertDatabaseHas('sync_tombstones', [
            'user_id' => $user->id,
            'module' => 'leaves',
            'entity_id' => $leaveId,
        ]);

        // A device pulling from the beginning must be told to evict the row.
        $response = $this->getJson('/api/v1/sync/pull?modules[]=leaves&cursor[leaves]=0&limit=50')->assertOk();

        $tombstone = collect($response->json('data.changes.leaves'))
            ->firstWhere('id', $leaveId);

        $this->assertNotNull($tombstone, 'The cancelled leave must appear as a tombstone.');
        $this->assertTrue((bool) ($tombstone['deleted'] ?? false));
    }

    // ── Bug 3a: bounded captured_at honoured / out-of-bounds rejected ────────

    public function test_offline_punch_records_the_true_capture_time_and_flags_it_offline(): void
    {
        $user = $this->userWithActiveAttendanceType();
        Sanctum::actingAs($user);

        $capturedAt = now()->subHours(6)->startOfMinute();

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [[
                'idempotency_key' => 'punch-capture-'.$user->id,
                'module' => 'attendance',
                'action' => 'punch',
                'payload' => ['captured_at' => $capturedAt->toDateTimeString()],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.applied', 1)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.action', 'punch_in');

        $attendance = Attendance::where('user_id', $user->id)->firstOrFail();

        // The punch is recorded at the real 6-hours-ago capture moment, NOT at "now".
        $this->assertLessThanOrEqual(
            90,
            abs($capturedAt->diffInSeconds($attendance->punchin)),
            'Punch-in must be stamped at the client capture time, not the server push time.'
        );
        $this->assertGreaterThan(
            3000,
            abs(now()->diffInSeconds($attendance->punchin)),
            'Punch-in must NOT collapse to server "now".'
        );
        $this->assertTrue((bool) $attendance->was_offline, 'An offline-captured punch must be flagged was_offline.');
    }

    public function test_offline_punch_with_future_capture_time_is_rejected(): void
    {
        $user = $this->userWithActiveAttendanceType();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [[
                'idempotency_key' => 'punch-future-'.$user->id,
                'module' => 'attendance',
                'action' => 'punch',
                'payload' => ['captured_at' => now()->addDays(2)->toDateTimeString()],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed');

        $this->assertStringContainsString('captured_at', (string) $response->json('data.results.0.message'));
        $this->assertDatabaseCount('attendances', 0);
    }

    public function test_offline_punch_older_than_the_window_is_rejected(): void
    {
        $user = $this->userWithActiveAttendanceType();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [[
                'idempotency_key' => 'punch-stale-'.$user->id,
                'module' => 'attendance',
                'action' => 'punch',
                'payload' => ['captured_at' => now()->subHours(100)->toDateTimeString()],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed');

        $this->assertDatabaseCount('attendances', 0);
    }

    // ── Bug 3b: replay via idempotency key does not double-apply ─────────────

    public function test_replayed_punch_mutation_does_not_double_apply(): void
    {
        $user = $this->userWithActiveAttendanceType();
        Sanctum::actingAs($user);

        $payload = [
            'mutations' => [[
                'idempotency_key' => 'punch-once-'.$user->id,
                'module' => 'attendance',
                'action' => 'punch',
                'payload' => ['captured_at' => now()->subHours(1)->toDateTimeString()],
            ]],
        ];

        $this->postJson('/api/v1/sync/push', $payload)
            ->assertOk()
            ->assertJsonPath('data.summary.applied', 1);

        $replay = $this->postJson('/api/v1/sync/push', $payload)->assertOk();

        $replay->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.duplicate', 1)
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.result.status', 'applied');

        // Exactly one attendance row, still open — the replay never created a
        // second row nor toggled a punch-out.
        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame(1, Attendance::where('user_id', $user->id)->whereNull('punchout')->count());
    }

    public function test_replayed_leave_cancel_records_a_single_tombstone(): void
    {
        $user = User::factory()->create([]);
        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeave($user->id, $leaveTypeId);

        Sanctum::actingAs($user);

        $payload = [
            'mutations' => [[
                'idempotency_key' => 'cancel-once-'.$leaveId,
                'module' => 'leaves',
                'action' => 'cancel',
                'payload' => ['leave_id' => $leaveId],
            ]],
        ];

        $this->postJson('/api/v1/sync/push', $payload)->assertOk()->assertJsonPath('data.summary.applied', 1);
        $this->postJson('/api/v1/sync/push', $payload)->assertOk()->assertJsonPath('data.summary.duplicate', 1);

        // The idempotent replay must NOT append a second tombstone.
        $this->assertSame(1, DB::table('sync_tombstones')->where('entity_id', $leaveId)->count());
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function userWithActiveAttendanceType(): User
    {
        $attendanceType = AttendanceType::factory()->wifiIp()->create([
            'is_active' => true,
            'config' => [
                'ip_locations' => [],
                'validation_mode' => 'any',
                'allow_without_network' => true,
            ],
        ]);

        return User::factory()->create([
            'attendance_type_id' => $attendanceType->id,
        ]);
    }

    private function insertAttendance(int $userId, string $updatedAt): int
    {
        return (int) DB::table('attendances')->insertGetId([
            'user_id' => $userId,
            'date' => now()->toDateString(),
            'punchin' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
            'punchout' => now()->setTime(10, 0)->format('Y-m-d H:i:s'),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }

    private function createLeaveType(): int
    {
        return (int) DB::table('leave_settings')->insertGetId([
            'type' => 'HardeningLeaveType',
            'symbol' => 'HLT',
            'days' => 10,
            'eligibility' => null,
            'carry_forward' => false,
            'earned_leave' => false,
            'is_earned' => false,
            'requires_approval' => true,
            'auto_approve' => false,
            'special_conditions' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertLeave(int $userId, int $leaveTypeId): int
    {
        $payload = [
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(1)->toDateString(),
            'to_date' => now()->addDays(1)->toDateString(),
            'no_of_days' => 1,
            'reason' => 'Hardening leave payload',
            'status' => 'pending',
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ];

        if (Schema::hasColumn('leaves', 'user')) {
            $payload['user'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'user_id')) {
            $payload['user_id'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $payload['submitted_at'] = now();
        }

        return (int) DB::table('leaves')->insertGetId($payload);
    }
}
