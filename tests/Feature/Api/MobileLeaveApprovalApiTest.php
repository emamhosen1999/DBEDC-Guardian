<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MobileLeaveApprovalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_access_mobile_leave_approval_endpoints(): void
    {
        $this->getJson('/api/v1/leaves/pending-approvals')->assertUnauthorized();
        $this->postJson('/api/v1/leaves/1/approve', [])->assertUnauthorized();
        $this->postJson('/api/v1/leaves/1/reject', [])->assertUnauthorized();
        $this->postJson('/api/v1/leaves/bulk-approve', [])->assertUnauthorized();
        $this->postJson('/api/v1/leaves/bulk-reject', [])->assertUnauthorized();
    }

    public function test_approver_can_fetch_pending_approvals(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $requester = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $pendingLeaveId = $this->insertPendingLeaveForApprover($requester->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($approver);

        $response = $this->getJson('/api/v1/leaves/pending-approvals');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.pending', 1)
            ->assertJsonCount(1, 'data.pending_leaves')
            ->assertJsonPath('data.pending_leaves.0.id', $pendingLeaveId)
            ->assertJsonPath('data.pending_leaves.0.status', 'pending');
    }

    public function test_approver_can_approve_pending_leave(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $requester = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $pendingLeaveId = $this->insertPendingLeaveForApprover($requester->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($approver);

        $response = $this->postJson('/api/v1/leaves/'.$pendingLeaveId.'/approve', [
            'comments' => 'Approved from mobile dashboard.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('leaves', [
            'id' => $pendingLeaveId,
            'status' => 'approved',
        ]);
    }

    public function test_approver_can_reject_pending_leave(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $requester = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $pendingLeaveId = $this->insertPendingLeaveForApprover($requester->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($approver);

        $response = $this->postJson('/api/v1/leaves/'.$pendingLeaveId.'/reject', [
            'reason' => 'Insufficient coverage for this period.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('leaves', [
            'id' => $pendingLeaveId,
            'status' => 'rejected',
            'rejection_reason' => 'Insufficient coverage for this period.',
        ]);
    }

    public function test_non_approver_cannot_approve_leave(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $nonApprover = User::factory()->create(['active' => true]);
        $requester = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $pendingLeaveId = $this->insertPendingLeaveForApprover($requester->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($nonApprover);

        $response = $this->postJson('/api/v1/leaves/'.$pendingLeaveId.'/approve', [
            'comments' => 'Trying to approve without authority.',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You are not authorized to approve this leave request.');
    }

    public function test_reject_requires_reason_validation(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $requester = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $pendingLeaveId = $this->insertPendingLeaveForApprover($requester->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($approver);

        $response = $this->postJson('/api/v1/leaves/'.$pendingLeaveId.'/reject', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_approver_can_bulk_approve_pending_leaves(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $requesterA = User::factory()->create(['active' => true]);
        $requesterB = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveA = $this->insertPendingLeaveForApprover($requesterA->id, $approver->id, $leaveTypeId);
        $leaveB = $this->insertPendingLeaveForApprover($requesterB->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($approver);

        $response = $this->postJson('/api/v1/leaves/bulk-approve', [
            'leave_ids' => [$leaveA, $leaveB],
            'comments' => 'Bulk approved from mobile manager queue.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.approved_count', 2)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.total_requested', 2);

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveA,
            'status' => 'approved',
        ]);

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveB,
            'status' => 'approved',
        ]);
    }

    public function test_approver_can_bulk_reject_pending_leaves(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $requesterA = User::factory()->create(['active' => true]);
        $requesterB = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveA = $this->insertPendingLeaveForApprover($requesterA->id, $approver->id, $leaveTypeId);
        $leaveB = $this->insertPendingLeaveForApprover($requesterB->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($approver);

        $response = $this->postJson('/api/v1/leaves/bulk-reject', [
            'leave_ids' => [$leaveA, $leaveB],
            'reason' => 'Team workload cannot accommodate these leave dates.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rejected_count', 2)
            ->assertJsonPath('data.failed_count', 0)
            ->assertJsonPath('data.total_requested', 2);

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveA,
            'status' => 'rejected',
            'rejection_reason' => 'Team workload cannot accommodate these leave dates.',
        ]);

        $this->assertDatabaseHas('leaves', [
            'id' => $leaveB,
            'status' => 'rejected',
            'rejection_reason' => 'Team workload cannot accommodate these leave dates.',
        ]);
    }

    public function test_non_approver_bulk_approve_returns_failed_results(): void
    {
        $approver = User::factory()->create(['active' => true]);
        $nonApprover = User::factory()->create(['active' => true]);
        $requester = User::factory()->create(['active' => true]);

        $leaveTypeId = $this->createLeaveType();
        $leaveId = $this->insertPendingLeaveForApprover($requester->id, $approver->id, $leaveTypeId);

        Sanctum::actingAs($nonApprover);

        $response = $this->postJson('/api/v1/leaves/bulk-approve', [
            'leave_ids' => [$leaveId],
            'comments' => 'Trying unauthorized bulk approval.',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.approved_count', 0)
            ->assertJsonPath('data.failed_count', 1)
            ->assertJsonPath('data.failed.0.leave_id', $leaveId)
            ->assertJsonPath('data.failed.0.message', 'You are not authorized to approve this leave request.');
    }

    public function test_bulk_reject_requires_reason_and_leave_ids_validation(): void
    {
        $approver = User::factory()->create(['active' => true]);

        Sanctum::actingAs($approver);

        $response = $this->postJson('/api/v1/leaves/bulk-reject', [
            'leave_ids' => [],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['leave_ids', 'reason']);
    }

    private function createLeaveType(): int
    {
        return (int) DB::table('leave_settings')->insertGetId([
            'type' => 'Approval Type',
            'symbol' => 'A',
            'days' => 15,
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

    private function insertPendingLeaveForApprover(int $requesterId, int $approverId, int $leaveTypeId): int
    {
        $approvalChain = [
            [
                'level' => 1,
                'approver_id' => $approverId,
                'approver_name' => 'Approver',
                'status' => 'pending',
                'approved_at' => null,
                'comments' => null,
            ],
        ];

        $payload = [
            'leave_type' => $leaveTypeId,
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(6)->toDateString(),
            'no_of_days' => 2,
            'reason' => 'Need leave approval.',
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
}
