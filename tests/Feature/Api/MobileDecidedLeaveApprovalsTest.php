<?php

namespace Tests\Feature\Api;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Notifications\LeaveOverrideNoticeNotification;
use App\Services\Leave\LeaveApprovalService;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Manager "decided" leave history (GET /api/v1/leaves/decided-approvals) and the
 * admin-override courtesy notice. A manager must still SEE — and be told about —
 * a leave an administrator overrode out of their pending queue.
 */
class MobileDecidedLeaveApprovalsTest extends TestCase
{
    use RefreshDatabase;

    private function createLeaveType(): int
    {
        return (int) LeaveSetting::query()->insertGetId([
            'type' => 'Annual Leave',
            'symbol' => 'AL',
            'days' => 20,
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

    /**
     * @param  array<int, array<string, mixed>>  $chain
     */
    private function makePendingLeave(int $employeeId, int $leaveTypeId, array $chain, int $currentLevel = 1): Leave
    {
        return Leave::create([
            'user_id' => $employeeId,
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(6)->toDateString(),
            'no_of_days' => 2,
            'reason' => 'Decided-history test leave.',
            'status' => 'pending',
            'approval_chain' => $chain,
            'current_approval_level' => $currentLevel,
            'submitted_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function chainLevel(int $level, int $approverId, string $status = 'pending'): array
    {
        return [
            'level' => $level,
            'approver_id' => $approverId,
            'approver_name' => "Approver {$level}",
            'status' => $status,
            'approved_at' => null,
            'comments' => null,
        ];
    }

    public function test_guest_cannot_access_decided_approvals(): void
    {
        $this->getJson('/api/v1/leaves/decided-approvals')->assertUnauthorized();
    }

    public function test_manager_sees_admin_overridden_leave_in_decided_not_pending(): void
    {
        Permission::findOrCreate('leaves.manage', 'web');

        $admin = User::factory()->create();
        $admin->givePermissionTo('leaves.manage');

        $manager = User::factory()->create();   // chain level-1 approver, still pending
        $employee = User::factory()->create();

        $leaveTypeId = $this->createLeaveType();
        $leave = $this->makePendingLeave($employee->id, $leaveTypeId, [
            $this->chainLevel(1, $manager->id),
        ]);

        // An admin (not in the chain) overrides and finalizes the leave.
        $result = app(LeaveApprovalService::class)
            ->approve($leave, $admin, 'Approved directly by admin.', ['force' => true]);
        $this->assertTrue($result['success']);

        Sanctum::actingAs($manager);

        // It has LEFT the manager's pending queue…
        $this->getJson('/api/v1/leaves/pending-approvals')
            ->assertOk()
            ->assertJsonCount(0, 'data.pending_leaves')
            ->assertJsonPath('data.stats.pending', 0);

        // …but it appears in decided-approvals, flagged as an admin override,
        // in the SAME item shape as the pending card (transformApprovalLeave).
        $res = $this->getJson('/api/v1/leaves/decided-approvals')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.decided_leaves')
            ->assertJsonPath('data.decided_leaves.0.id', $leave->id)
            ->assertJsonPath('data.decided_leaves.0.status', 'approved')
            ->assertJsonPath('data.decided_leaves.0.was_admin_override', true)
            ->assertJsonPath('data.decided_leaves.0.decided_by', $admin->name);

        // Pending-card fields are present (shape reuse).
        $row = $res->json('data.decided_leaves.0');
        $this->assertArrayHasKey('approval_chain', $row);
        $this->assertArrayHasKey('employee', $row);
        $this->assertArrayHasKey('decided_at', $row);
        $this->assertSame($employee->name, $row['employee']['name']);
    }

    public function test_decided_approvals_excludes_leaves_the_user_never_approved(): void
    {
        $manager = User::factory()->create();
        $otherApprover = User::factory()->create();
        $employee = User::factory()->create();
        $leaveTypeId = $this->createLeaveType();

        // A decided leave whose chain never included $manager.
        $this->makePendingLeave($employee->id, $leaveTypeId, [
            $this->chainLevel(1, $otherApprover->id, 'approved'),
        ])->update(['status' => 'approved', 'approved_at' => now()]);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/leaves/decided-approvals')
            ->assertOk()
            ->assertJsonCount(0, 'data.decided_leaves');
    }

    public function test_admin_override_notifies_the_superseded_manager(): void
    {
        $this->seed(NotificationTypeSeeder::class);
        Notification::fake();

        Permission::findOrCreate('leaves.manage', 'web');

        $admin = User::factory()->create();
        $admin->givePermissionTo('leaves.manage');

        $manager = User::factory()->create();   // still pending at override time
        $employee = User::factory()->create();

        $leaveTypeId = $this->createLeaveType();
        $leave = $this->makePendingLeave($employee->id, $leaveTypeId, [
            $this->chainLevel(1, $manager->id),
        ]);

        app(LeaveApprovalService::class)
            ->approve($leave, $admin, 'Approved directly by admin.', ['force' => true]);

        // The superseded manager is told — "no action needed".
        Notification::assertSentTo(
            $manager,
            LeaveOverrideNoticeNotification::class,
            function (LeaveOverrideNoticeNotification $n) use ($leave, $admin) {
                return $n->leave->id === $leave->id
                    && $n->actor->id === $admin->id
                    && $n->action === 'approved';
            }
        );

        // The acting admin is never notified about their own override.
        Notification::assertNotSentTo($admin, LeaveOverrideNoticeNotification::class);
    }
}
