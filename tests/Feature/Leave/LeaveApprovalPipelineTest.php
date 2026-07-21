<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Notifications\LeaveApprovedNotification;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Realtime\RealtimeSignal;
use Database\Seeders\NotificationTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Guards the unified Leave approval pipeline: every approve/reject/status
 * decision (mobile + web-admin) flows through LeaveApprovalService, which owns
 * chain advancement, status, ledger, audit, notification and the realtime
 * signal — fired exactly once. See the "manager sees 0 pending" desync bug.
 */
class LeaveApprovalPipelineTest extends TestCase
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
            'reason' => 'Pipeline test leave.',
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

    public function test_web_admin_bulk_approve_notifies_employee_signals_and_clears_manager_queue(): void
    {
        // The employee notification only records channels once its notification
        // type is registered/active (via DeliversViaPreferences -> resolver).
        $this->seed(NotificationTypeSeeder::class);

        Notification::fake();

        $signal = Mockery::spy(RealtimeSignal::class);
        $this->app->instance(RealtimeSignal::class, $signal);

        Permission::findOrCreate('leaves.approve', 'web');
        Permission::findOrCreate('leaves.manage', 'web');

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->givePermissionTo(['leaves.approve', 'leaves.manage']);

        $manager = User::factory()->create();   // Wang Fu — the chain level-1 approver
        $employee = User::factory()->create();

        $leaveTypeId = $this->createLeaveType();
        $leave = $this->makePendingLeave($employee->id, $leaveTypeId, [
            $this->chainLevel(1, $manager->id),
        ]);

        $approvalService = app(LeaveApprovalService::class);

        // Before: the leave sits in the manager's pending queue.
        $this->assertCount(1, $approvalService->getPendingApprovalsForUser($manager));

        $this->actingAs($admin)
            ->postJson('/leaves/bulk-approve', ['leave_ids' => [$leave->id]])
            ->assertOk()
            ->assertJsonPath('updated_count', 1);

        // Status finalized.
        $this->assertSame('approved', $leave->fresh()->status);

        // Employee was notified (this was silently missing on the old web path).
        Notification::assertSentTo($employee, LeaveApprovedNotification::class);

        // The realtime signal fired exactly once, on the canonical bucket, with
        // the acting admin as actor and the 'approve' action.
        $signal->shouldHaveReceived('touch')
            ->with('leave', 'all', $admin->id, 'approve')
            ->once();

        // After: the manager's pending queue is clean — no "0 pending" orphan.
        $this->assertCount(0, $approvalService->getPendingApprovalsForUser($manager));
    }

    public function test_admin_override_finalizes_mid_chain_leave_and_records_override_entry(): void
    {
        Permission::findOrCreate('leaves.manage', 'web');

        $admin = User::factory()->create();
        $admin->givePermissionTo('leaves.manage');

        $level1 = User::factory()->create();
        $level2 = User::factory()->create();
        $employee = User::factory()->create();

        $leaveTypeId = $this->createLeaveType();
        // Two-level chain, still waiting at level 1. The admin is NOT in the chain.
        $leave = $this->makePendingLeave($employee->id, $leaveTypeId, [
            $this->chainLevel(1, $level1->id),
            $this->chainLevel(2, $level2->id),
        ], currentLevel: 1);

        $approvalService = app(LeaveApprovalService::class);

        $result = $approvalService->approve($leave, $admin, 'Escalated — approving directly.', ['force' => true]);

        $this->assertTrue($result['success']);
        $this->assertSame('approved', $result['status']);

        $fresh = $leave->fresh();
        $this->assertSame('approved', $fresh->status);

        // An 'admin_override' entry was appended to the chain history…
        $chain = $fresh->approval_chain;
        $overrideEntries = collect($chain)->where('status', 'admin_override');
        $this->assertCount(1, $overrideEntries);
        $this->assertSame($admin->id, $overrideEntries->first()['approver_id']);
        $this->assertSame('approved', $overrideEntries->first()['action']);

        // …and every previously-pending level was superseded, so neither approver
        // is left waiting and the leave leaves both of their queues.
        $this->assertSame('superseded', collect($chain)->firstWhere('level', 1)['status']);
        $this->assertSame('superseded', collect($chain)->firstWhere('level', 2)['status']);
        $this->assertCount(0, $approvalService->getPendingApprovalsForUser($level1));
        $this->assertCount(0, $approvalService->getPendingApprovalsForUser($level2));
    }

    public function test_normal_manager_approval_advances_one_level_without_finalizing(): void
    {
        $level1 = User::factory()->create();
        $level2 = User::factory()->create();
        $employee = User::factory()->create();

        $leaveTypeId = $this->createLeaveType();
        $leave = $this->makePendingLeave($employee->id, $leaveTypeId, [
            $this->chainLevel(1, $level1->id),
            $this->chainLevel(2, $level2->id),
        ], currentLevel: 1);

        $approvalService = app(LeaveApprovalService::class);

        // The current-level manager approves normally (no override).
        $result = $approvalService->approve($leave, $level1, 'Approved at level 1.');

        $this->assertTrue($result['success']);
        $this->assertSame('pending', $result['status']); // forwarded, NOT finalized

        $fresh = $leave->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame(2, $fresh->current_approval_level);

        $chain = $fresh->approval_chain;
        $this->assertSame('approved', collect($chain)->firstWhere('level', 1)['status']);
        $this->assertSame('pending', collect($chain)->firstWhere('level', 2)['status']);

        // The leave has now moved from level-1's queue to level-2's queue.
        $this->assertCount(0, $approvalService->getPendingApprovalsForUser($level1));
        $this->assertCount(1, $approvalService->getPendingApprovalsForUser($level2));
    }
}
