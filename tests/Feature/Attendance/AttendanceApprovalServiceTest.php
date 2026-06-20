<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceApprovalServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_single_level_chain_to_direct_manager(): void
    {
        $manager = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);

        $chain = app(AttendanceApprovalService::class)->buildChain($emp);

        $this->assertCount(1, $chain);
        $this->assertSame($manager->id, $chain[0]['approver_id']);
        $this->assertSame('pending', $chain[0]['status']);
    }

    public function test_submit_then_approve_finalizes_single_level(): void
    {
        $manager = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $svc = app(AttendanceApprovalService::class);

        $m = AttendanceRegularization::create([
            'user_id' => $emp->id, 'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ]);
        $svc->submit($m);
        $this->assertSame('pending', $m->fresh()->status);
        $this->assertTrue($svc->canApprove($m->fresh(), $manager));

        $res = $svc->approve($m->fresh(), $manager, 'ok');
        $this->assertTrue($res['success']);
        $this->assertSame('approved', $m->fresh()->status);
        $this->assertSame($manager->id, $m->fresh()->approved_by);
    }

    public function test_non_approver_cannot_approve(): void
    {
        $manager = User::factory()->create();
        $other = User::factory()->create();
        $emp = User::factory()->create(['report_to' => $manager->id]);
        $svc = app(AttendanceApprovalService::class);
        $m = AttendanceRegularization::create(['user_id'=>$emp->id,'date'=>'2026-06-18','type'=>'other','reason'=>'x']);
        $svc->submit($m);

        $this->assertFalse($svc->canApprove($m->fresh(), $other));
        $this->assertFalse($svc->approve($m->fresh(), $other)['success']);
    }

    public function test_managerless_requester_with_department_head_is_not_stranded(): void
    {
        $department = Department::factory()->create();
        $designation = Designation::factory()->create(['department_id' => $department->id]);
        $deptHead = User::factory()->create(['department_id' => $department->id, 'designation_id' => $designation->id]);
        $emp = User::factory()->create(['report_to' => null, 'department_id' => $department->id]);
        $svc = app(AttendanceApprovalService::class);

        $chain = $svc->buildChain($emp);
        $this->assertCount(1, $chain);
        $this->assertSame(1, $chain[0]['level']);
        $this->assertSame($deptHead->id, $chain[0]['approver_id']);

        $m = AttendanceRegularization::create([
            'user_id' => $emp->id, 'date' => '2026-06-18', 'type' => 'missing_punchout',
            'requested_punchout' => '2026-06-18 18:00:00', 'reason' => 'forgot',
        ]);
        $svc->submit($m);

        $this->assertSame(1, $m->fresh()->current_approval_level);
        $this->assertSame('pending', $m->fresh()->status);
        $this->assertTrue($svc->canApprove($m->fresh(), $deptHead));

        $res = $svc->approve($m->fresh(), $deptHead, 'ok');
        $this->assertTrue($res['success']);
        $this->assertSame('approved', $m->fresh()->status);
    }
}
