<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceRegularization;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_requester_cannot_approve_or_reject_their_own_request_even_if_in_chain(): void
    {
        $svc = app(AttendanceApprovalService::class);
        $emp = User::factory()->create();
        $m = AttendanceRegularization::create(['user_id' => $emp->id, 'date' => '2026-06-18', 'type' => 'other', 'reason' => 'x']);
        $m->update([
            'status' => 'pending',
            'current_approval_level' => 1,
            'approval_chain' => [[
                'level' => 1, 'approver_id' => $emp->employee_id, 'approver_name' => $emp->name,
                'status' => 'pending', 'approved_at' => null, 'comments' => null,
            ]],
        ]);

        $this->assertFalse($svc->canApprove($m->fresh(), $emp));
        $this->assertFalse($svc->approve($m->fresh(), $emp)['success']);
        $this->assertFalse($svc->reject($m->fresh(), $emp, 'self reject')['success']);
        $this->assertSame('pending', $m->fresh()->status);
    }
}
