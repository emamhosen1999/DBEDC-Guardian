<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * forApprover() powers the Approvals inbox status filter (Pending/Approved/Rejected/All).
 * 'pending' = actionable now (pendingFor); other statuses = history where the user is an
 * approver anywhere in the approval_chain.
 */
class ApprovalHistoryFilterTest extends TestCase
{
    use RefreshDatabase;

    private function reg(User $owner, User $approver, string $status, int $level = 1): AttendanceRegularization
    {
        return AttendanceRegularization::create([
            'user_id' => $owner->id,
            'date' => '2026-06-17',
            'type' => 'wrong_time',
            'reason' => 'test',
            'status' => $status,
            'current_approval_level' => 1,
            'approval_chain' => [[
                'level' => $level,
                'approver_id' => $approver->id,
                'status' => $status === 'pending' ? 'pending' : $status,
            ]],
        ]);
    }

    public function test_for_approver_filters_by_status(): void
    {
        $owner = User::factory()->create();
        $approver = User::factory()->create();
        $other = User::factory()->create();

        $this->reg($owner, $approver, 'pending');
        $this->reg($owner, $approver, 'approved');
        $this->reg($owner, $approver, 'rejected');
        $this->reg($owner, $other, 'approved'); // approver not in this chain

        $svc = app(AttendanceApprovalService::class);

        $this->assertCount(1, $svc->forApprover($approver, AttendanceRegularization::class, 'pending'));
        $this->assertCount(1, $svc->forApprover($approver, AttendanceRegularization::class, 'approved'));
        $this->assertCount(1, $svc->forApprover($approver, AttendanceRegularization::class, 'rejected'));
        // 'all' = every request this approver is on (pending + approved + rejected), excludes $other's.
        $this->assertCount(3, $svc->forApprover($approver, AttendanceRegularization::class, 'all'));
        // A different approver sees only their own chain entry.
        $this->assertCount(1, $svc->forApprover($other, AttendanceRegularization::class, 'all'));
    }
}
