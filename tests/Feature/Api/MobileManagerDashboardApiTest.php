<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MobileManagerDashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_manager_dashboard_summary_endpoint(): void
    {
        $this->getJson('/api/v1/manager/dashboard-summary')->assertUnauthorized();
    }

    public function test_non_manager_cannot_access_manager_dashboard_summary_endpoint(): void
    {
        $user = User::factory()->create(['active' => true]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v1/manager/dashboard-summary')
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to access manager dashboard summary.');
    }

    public function test_manager_gets_team_approval_and_objection_summary(): void
    {
        $manager = User::factory()->create(['active' => true]);
        $this->assignRole($manager, 'Project Manager');

        $teamMemberA = User::factory()->create([
            'active' => true,
            'report_to' => $manager->id,
        ]);

        $teamMemberB = User::factory()->create([
            'active' => true,
            'report_to' => $manager->id,
        ]);

        $outsideUser = User::factory()->create(['active' => true]);

        DB::table('attendances')->insert([
            [
                'user_id' => $teamMemberA->id,
                'date' => now()->toDateString(),
                'punchin' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'punchout' => now()->setTime(12, 0)->format('Y-m-d H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $outsideUser->id,
                'date' => now()->toDateString(),
                'punchin' => now()->setTime(9, 0)->format('Y-m-d H:i:s'),
                'punchout' => now()->setTime(12, 0)->format('Y-m-d H:i:s'),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $leaveTypeId = $this->createLeaveType();

        $this->insertLeaveForUser($teamMemberB->id, $leaveTypeId, [
            'from_date' => now()->toDateString(),
            'to_date' => now()->toDateString(),
            'status' => 'Approved',
        ]);

        $this->insertPendingApprovalLeave($teamMemberA->id, $manager->id, $leaveTypeId);

        $teamWork = DailyWork::factory()->forUsers($teamMemberA, $teamMemberA)->create([
            'status' => DailyWork::STATUS_IN_PROGRESS,
        ]);

        DailyWork::factory()->forUsers($outsideUser, $outsideUser)->create([
            'status' => DailyWork::STATUS_COMPLETED,
        ]);

        $this->insertObjectionForDailyWork($teamWork, $teamMemberA->id, 'submitted');
        $this->insertObjectionForDailyWork($teamWork, $teamMemberA->id, 'under_review');
        $this->insertObjectionForDailyWork($teamWork, $teamMemberA->id, 'resolved');

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/manager/dashboard-summary');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.team.total_members', 2)
            ->assertJsonPath('data.team.present_today', 1)
            ->assertJsonPath('data.team.on_leave_today', 1)
            ->assertJsonPath('data.approvals.pending_leave_approvals', 1)
            ->assertJsonPath('data.daily_works.total', 1)
            ->assertJsonPath('data.daily_works.completed', 0)
            ->assertJsonPath('data.daily_works.pending', 1)
            ->assertJsonPath('data.objections.submitted', 1)
            ->assertJsonPath('data.objections.under_review', 1)
            ->assertJsonPath('data.objections.total_active', 2);
    }

    private function assignRole(User $user, string $roleName): void
    {
        Role::findOrCreate($roleName);
        $user->assignRole($roleName);
    }

    private function createLeaveType(): int
    {
        return (int) DB::table('leave_settings')->insertGetId([
            'type' => 'ManagerSummaryType',
            'symbol' => 'MST',
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

    private function insertLeaveForUser(int $userId, int $leaveTypeId, array $overrides = []): int
    {
        $payload = array_merge([
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(1)->toDateString(),
            'to_date' => now()->addDays(1)->toDateString(),
            'no_of_days' => 1,
            'reason' => 'Summary leave',
            'status' => 'Approved',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);

        if (Schema::hasColumn('leaves', 'user')) {
            $payload['user'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'user_id')) {
            $payload['user_id'] = $userId;
        }

        if (Schema::hasColumn('leaves', 'submitted_at') && ! array_key_exists('submitted_at', $payload)) {
            $payload['submitted_at'] = now();
        }

        if (Schema::hasColumn('leaves', 'approved_at') && ! array_key_exists('approved_at', $payload) && strtolower((string) ($payload['status'] ?? '')) === 'approved') {
            $payload['approved_at'] = now();
        }

        return (int) DB::table('leaves')->insertGetId($payload);
    }

    private function insertPendingApprovalLeave(int $requesterId, int $approverId, int $leaveTypeId): int
    {
        $approvalChain = [
            [
                'level' => 1,
                'approver_id' => $approverId,
                'approver_name' => 'Project Manager',
                'status' => 'pending',
                'approved_at' => null,
                'comments' => null,
            ],
        ];

        $payload = [
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(2)->toDateString(),
            'to_date' => now()->addDays(3)->toDateString(),
            'no_of_days' => 2,
            'reason' => 'Pending manager approval',
            'status' => 'pending',
            'approval_chain' => json_encode($approvalChain),
            'current_approval_level' => 1,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('leaves', 'user')) {
            $payload['user'] = $requesterId;
        }

        if (Schema::hasColumn('leaves', 'user_id')) {
            $payload['user_id'] = $requesterId;
        }

        return (int) DB::table('leaves')->insertGetId($payload);
    }

    private function insertObjectionForDailyWork(DailyWork $dailyWork, int $creatorId, string $status): int
    {
        $payload = [
            'title' => 'Summary Objection '.strtoupper($status),
            'category' => 'other',
            'description' => 'Objection for summary metrics.',
            'reason' => 'Need decision.',
            'status' => $status,
            'created_by' => $creatorId,
            'updated_by' => $creatorId,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('rfi_objections', 'daily_work_id')) {
            $payload['daily_work_id'] = $dailyWork->id;
        }

        if (Schema::hasColumn('rfi_objections', 'type')) {
            $payload['type'] = DailyWork::TYPE_STRUCTURE;
        }

        if (Schema::hasColumn('rfi_objections', 'chainage_from')) {
            $payload['chainage_from'] = null;
        }

        if (Schema::hasColumn('rfi_objections', 'chainage_to')) {
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
}
