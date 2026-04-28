<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\HRM\AttendanceType;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileSyncApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_sync_endpoints(): void
    {
        $this->getJson('/api/v1/sync/bootstrap')->assertUnauthorized();
        $this->getJson('/api/v1/sync/pull')->assertUnauthorized();
        $this->postJson('/api/v1/sync/push', [])->assertUnauthorized();
    }

    public function test_sync_bootstrap_returns_requested_modules_with_user_scoped_data(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $this->insertAttendanceRecord($user->id, now()->subMinutes(20)->toDateTimeString());
        $this->insertAttendanceRecord($otherUser->id, now()->subMinutes(10)->toDateTimeString());

        $leaveTypeId = $this->createLeaveType();
        $this->insertLeaveForUser($user->id, $leaveTypeId, now()->subMinutes(30)->toDateTimeString());
        $this->insertLeaveForUser($otherUser->id, $leaveTypeId, now()->subMinutes(15)->toDateTimeString());

        DailyWork::factory()->forUsers($user, $user)->create();
        DailyWork::factory()->forUsers($otherUser, $otherUser)->create();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/sync/bootstrap?modules[]=attendance&modules[]=leaves&modules[]=daily_works&limit=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.modules.attendance.total', 1)
            ->assertJsonPath('data.modules.leaves.total', 1)
            ->assertJsonPath('data.modules.daily_works.total', 1);

        $this->assertNotEmpty($response->json('data.cursor'));
        $this->assertNotEmpty($response->json('data.server_time'));
    }

    public function test_sync_pull_returns_changes_since_cursor_with_module_filter_and_scope(): void
    {
        $user = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $oldAttendanceId = $this->insertAttendanceRecord($user->id, now()->subHours(2)->toDateTimeString());
        $newAttendanceId = $this->insertAttendanceRecord($user->id, now()->subMinutes(20)->toDateTimeString());
        $this->insertAttendanceRecord($otherUser->id, now()->subMinutes(10)->toDateTimeString());

        $oldDailyWork = DailyWork::factory()->forUsers($user, $user)->create();
        DB::table('daily_works')->where('id', $oldDailyWork->id)->update(['updated_at' => now()->subHours(2)]);

        $newDailyWork = DailyWork::factory()->forUsers($user, $user)->create();
        DB::table('daily_works')->where('id', $newDailyWork->id)->update(['updated_at' => now()->subMinutes(15)]);

        $otherDailyWork = DailyWork::factory()->forUsers($otherUser, $otherUser)->create();
        DB::table('daily_works')->where('id', $otherDailyWork->id)->update(['updated_at' => now()->subMinutes(10)]);

        Sanctum::actingAs($user);

        $cursor = now()->subHour()->toAtomString();
        $response = $this->getJson('/api/v1/sync/pull?cursor='.urlencode($cursor).'&modules[]=attendance&modules[]=daily_works&limit=10');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.counts.attendance', 1)
            ->assertJsonPath('data.counts.daily_works', 1);

        $attendanceIds = collect($response->json('data.changes.attendance'))->pluck('id');
        $this->assertTrue($attendanceIds->contains($newAttendanceId));
        $this->assertFalse($attendanceIds->contains($oldAttendanceId));

        $dailyWorkIds = collect($response->json('data.changes.daily_works'))->pluck('id');
        $this->assertTrue($dailyWorkIds->contains($newDailyWork->id));
        $this->assertFalse($dailyWorkIds->contains($oldDailyWork->id));
        $this->assertFalse($dailyWorkIds->contains($otherDailyWork->id));

        $this->assertNotEmpty($response->json('data.next_cursor'));
    }

    public function test_sync_pull_requires_cursor_and_valid_modules(): void
    {
        $user = User::factory()->create(['active' => true]);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/sync/pull')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cursor']);

        $this->getJson('/api/v1/sync/pull?cursor='.urlencode(now()->toAtomString()).'&modules[]=unknown')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['modules.0']);
    }

    public function test_sync_push_applies_leave_cancel_and_daily_work_status_mutations(): void
    {
        $user = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, now()->subMinutes(3)->toDateTimeString());

        $dailyWork = DailyWork::factory()->forUsers($user, $user)->newStatus()->create();

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'leave-cancel-'.$leaveId,
                    'module' => 'leaves',
                    'action' => 'cancel',
                    'payload' => [
                        'leave_id' => $leaveId,
                    ],
                ],
                [
                    'idempotency_key' => 'daily-work-status-'.$dailyWork->id,
                    'module' => 'daily_works',
                    'action' => 'update_status',
                    'payload' => [
                        'daily_work_id' => $dailyWork->id,
                        'status' => DailyWork::STATUS_COMPLETED,
                        'inspection_result' => DailyWork::INSPECTION_PASS,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.applied', 2)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.1.status', 'applied');

        $this->assertDatabaseMissing('leaves', [
            'id' => $leaveId,
        ]);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
        ]);

        $this->assertDatabaseHas('mobile_sync_mutations', [
            'user_id' => $user->id,
            'idempotency_key' => 'leave-cancel-'.$leaveId,
            'module' => 'leaves',
            'action' => 'cancel',
            'status' => 'applied',
        ]);
    }

    public function test_sync_push_applies_leave_apply_mutation(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();

        Sanctum::actingAs($user);

        $fromDate = now()->addDays(5)->toDateString();
        $toDate = now()->addDays(6)->toDateString();

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'leave-apply-'.$user->id,
                    'module' => 'leaves',
                    'action' => 'apply',
                    'payload' => [
                        'leave_type_id' => $leaveTypeId,
                        'from_date' => $fromDate,
                        'to_date' => $toDate,
                        'reason' => 'Sync leave apply request',
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 1)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.results.0.module', 'leaves')
            ->assertJsonPath('data.results.0.action', 'apply')
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.record.from_date', $fromDate)
            ->assertJsonPath('data.results.0.data.record.to_date', $toDate)
            ->assertJsonPath('data.results.0.data.record.reason', 'Sync leave apply request')
            ->assertJsonPath('data.results.0.data.record.status', 'New');

        $this->assertDatabaseHas('leaves', [
            'leave_type' => $leaveTypeId,
            'reason' => 'Sync leave apply request',
            'status' => 'New',
        ]);

        $this->assertDatabaseHas('mobile_sync_mutations', [
            'user_id' => $user->id,
            'idempotency_key' => 'leave-apply-'.$user->id,
            'module' => 'leaves',
            'action' => 'apply',
            'status' => 'applied',
        ]);
    }

    public function test_sync_push_fails_leave_apply_for_overlapping_dates(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();
        $this->insertLeaveForUser($user->id, $leaveTypeId, now()->subMinute()->toDateTimeString());

        Sanctum::actingAs($user);

        $overlapDate = now()->addDays(1)->toDateString();

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'leave-apply-overlap-'.$user->id,
                    'module' => 'leaves',
                    'action' => 'apply',
                    'payload' => [
                        'leave_type_id' => $leaveTypeId,
                        'from_date' => $overlapDate,
                        'to_date' => $overlapDate,
                        'reason' => 'Overlap leave request from sync',
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.message', 'Leave dates overlap with an existing leave request.');

        $this->assertSame(1, DB::table('leaves')->count());

        $this->assertDatabaseHas('mobile_sync_mutations', [
            'user_id' => $user->id,
            'idempotency_key' => 'leave-apply-overlap-'.$user->id,
            'module' => 'leaves',
            'action' => 'apply',
            'status' => 'failed',
        ]);
    }

    public function test_sync_push_returns_duplicate_result_for_replayed_idempotency_key(): void
    {
        $user = User::factory()->create(['active' => true]);
        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertLeaveForUser($user->id, $leaveTypeId, now()->subMinutes(3)->toDateTimeString());

        Sanctum::actingAs($user);

        $payload = [
            'mutations' => [
                [
                    'idempotency_key' => 'dup-leave-'.$leaveId,
                    'module' => 'leaves',
                    'action' => 'cancel',
                    'payload' => [
                        'leave_id' => $leaveId,
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v1/sync/push', $payload)
            ->assertOk()
            ->assertJsonPath('data.summary.applied', 1);

        $duplicateResponse = $this->postJson('/api/v1/sync/push', $payload);

        $duplicateResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.duplicate', 1)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.results.0.status', 'duplicate')
            ->assertJsonPath('data.results.0.result.status', 'applied');
    }

    public function test_sync_push_reports_failed_mutation_for_unauthorized_daily_work_update(): void
    {
        $owner = User::factory()->create(['active' => true]);
        $otherUser = User::factory()->create(['active' => true]);

        $dailyWork = DailyWork::factory()->forUsers($owner, $owner)->newStatus()->create();

        Sanctum::actingAs($otherUser);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'unauthorized-dw-'.$dailyWork->id,
                    'module' => 'daily_works',
                    'action' => 'update_status',
                    'payload' => [
                        'daily_work_id' => $dailyWork->id,
                        'status' => DailyWork::STATUS_COMPLETED,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.message', 'You are not authorized to update this daily work.');

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => DailyWork::STATUS_NEW,
        ]);
    }

    public function test_sync_push_applies_daily_work_objection_transition_mutations(): void
    {
        $owner = User::factory()->create(['active' => true]);
        $manager = User::factory()->create(['active' => true]);
        $this->assignManagerRole($manager);

        $dailyWork = DailyWork::factory()->forUsers($owner, $owner)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $owner->id, [
            'status' => RfiObjection::STATUS_DRAFT,
        ]);

        Sanctum::actingAs($owner);

        $submitResponse = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'sync-objection-submit-'.$objectionId,
                    'module' => 'daily_works',
                    'action' => 'submit_objection',
                    'payload' => [
                        'daily_work_id' => $dailyWork->id,
                        'objection_id' => $objectionId,
                    ],
                ],
            ],
        ]);

        $submitResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 1)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.status', RfiObjection::STATUS_SUBMITTED);

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objectionId,
            'status' => RfiObjection::STATUS_SUBMITTED,
        ]);

        Sanctum::actingAs($manager);

        $reviewAndResolveResponse = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'sync-objection-review-'.$objectionId,
                    'module' => 'daily_works',
                    'action' => 'review_objection',
                    'payload' => [
                        'daily_work_id' => $dailyWork->id,
                        'objection_id' => $objectionId,
                    ],
                ],
                [
                    'idempotency_key' => 'sync-objection-resolve-'.$objectionId,
                    'module' => 'daily_works',
                    'action' => 'resolve_objection',
                    'payload' => [
                        'daily_work_id' => $dailyWork->id,
                        'objection_id' => $objectionId,
                        'resolution_notes' => 'Resolved from sync workflow.',
                    ],
                ],
            ],
        ]);

        $reviewAndResolveResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.applied', 2)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.status', RfiObjection::STATUS_UNDER_REVIEW)
            ->assertJsonPath('data.results.1.status', 'applied')
            ->assertJsonPath('data.results.1.data.status', RfiObjection::STATUS_RESOLVED);

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objectionId,
            'status' => RfiObjection::STATUS_RESOLVED,
            'resolution_notes' => 'Resolved from sync workflow.',
        ]);

        $this->assertDatabaseHas('mobile_sync_mutations', [
            'user_id' => $manager->id,
            'idempotency_key' => 'sync-objection-resolve-'.$objectionId,
            'module' => 'daily_works',
            'action' => 'resolve_objection',
            'status' => 'applied',
        ]);
    }

    public function test_sync_push_fails_daily_work_objection_review_for_non_manager_user(): void
    {
        $owner = User::factory()->create(['active' => true]);
        $reviewer = User::factory()->create(['active' => true]);

        $dailyWork = DailyWork::factory()->forUsers($owner, $reviewer)->create();
        $objectionId = $this->insertObjectionForDailyWork($dailyWork, $owner->id, [
            'status' => RfiObjection::STATUS_SUBMITTED,
        ]);

        Sanctum::actingAs($reviewer);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'sync-objection-review-fail-'.$objectionId,
                    'module' => 'daily_works',
                    'action' => 'review_objection',
                    'payload' => [
                        'daily_work_id' => $dailyWork->id,
                        'objection_id' => $objectionId,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.message', 'You are not authorized to review this objection.');

        $this->assertDatabaseHas('rfi_objections', [
            'id' => $objectionId,
            'status' => RfiObjection::STATUS_SUBMITTED,
        ]);

        $this->assertDatabaseHas('mobile_sync_mutations', [
            'user_id' => $reviewer->id,
            'idempotency_key' => 'sync-objection-review-fail-'.$objectionId,
            'module' => 'daily_works',
            'action' => 'review_objection',
            'status' => 'failed',
        ]);
    }

    public function test_sync_push_applies_attendance_punch_mutation(): void
    {
        $attendanceType = AttendanceType::factory()->wifiIp()->create([
            'is_active' => true,
            'config' => [
                'ip_locations' => [],
                'validation_mode' => 'any',
                'allow_without_network' => true,
            ],
        ]);

        $user = User::factory()->create([
            'active' => true,
            'attendance_type_id' => $attendanceType->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'attendance-punch-'.$user->id,
                    'module' => 'attendance',
                    'action' => 'punch',
                    'payload' => [],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 1)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 0)
            ->assertJsonPath('data.results.0.module', 'attendance')
            ->assertJsonPath('data.results.0.action', 'punch')
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.data.action', 'punch_in');

        $this->assertDatabaseHas('attendances', [
            'user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('mobile_sync_mutations', [
            'user_id' => $user->id,
            'idempotency_key' => 'attendance-punch-'.$user->id,
            'module' => 'attendance',
            'action' => 'punch',
            'status' => 'applied',
        ]);
    }

    public function test_sync_push_fails_attendance_mutation_without_active_attendance_type(): void
    {
        $user = User::factory()->create([
            'active' => true,
            'attendance_type_id' => null,
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v1/sync/push', [
            'mutations' => [
                [
                    'idempotency_key' => 'attendance-failure-'.$user->id,
                    'module' => 'attendance',
                    'action' => 'punch',
                    'payload' => [],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.applied', 0)
            ->assertJsonPath('data.summary.duplicate', 0)
            ->assertJsonPath('data.summary.failed', 1)
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.message', 'No active attendance type assigned to user.');
    }

    public function test_sync_push_requires_mutations_payload(): void
    {
        $user = User::factory()->create(['active' => true]);
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/sync/push', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mutations']);
    }

    private function insertAttendanceRecord(int $userId, string $updatedAt): int
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
            'type' => 'SyncLeaveType',
            'symbol' => 'SLT',
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

    private function insertLeaveForUser(int $userId, int $leaveTypeId, string $updatedAt): int
    {
        $payload = [
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(1)->toDateString(),
            'to_date' => now()->addDays(1)->toDateString(),
            'no_of_days' => 1,
            'reason' => 'Sync leave payload',
            'status' => 'New',
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
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

    private function insertObjectionForDailyWork(DailyWork $dailyWork, int $creatorId, array $overrides = []): int
    {
        $payload = array_merge([
            'title' => 'Sync objection',
            'category' => RfiObjection::CATEGORY_OTHER,
            'description' => 'Sync objection description.',
            'reason' => 'Sync objection reason.',
            'status' => RfiObjection::STATUS_DRAFT,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
            'type' => DailyWork::TYPE_STRUCTURE,
        ], $overrides);

        if (Schema::hasColumn('rfi_objections', 'chainage_from') && ! array_key_exists('chainage_from', $payload)) {
            $payload['chainage_from'] = null;
        }

        if (Schema::hasColumn('rfi_objections', 'chainage_to') && ! array_key_exists('chainage_to', $payload)) {
            $payload['chainage_to'] = null;
        }

        $objectionId = (int) DB::table('rfi_objections')->insertGetId($payload);

        if (Schema::hasTable('daily_work_objection')) {
            DB::table('daily_work_objection')->insert([
                'daily_work_id' => $dailyWork->id,
                'rfi_objection_id' => $objectionId,
                'attached_by' => $creatorId,
                'attached_at' => now(),
                'attachment_notes' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $objectionId;
    }

    private function assignManagerRole(User $user): void
    {
        Role::findOrCreate('Project Manager');
        $user->assignRole('Project Manager');
    }
}
