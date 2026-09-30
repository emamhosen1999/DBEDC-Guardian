<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AttendanceApprovalRoutingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['Employee', 'HR Manager', 'Administrator', 'Team Lead'] as $role) {
            Role::firstOrCreate(['name' => $role]);
        }
        Permission::firstOrCreate(['name' => 'attendance.own.view']);
    }

    private function employee(array $attrs = []): User
    {
        $emp = User::factory()->create($attrs);
        $emp->assignRole('Employee');
        $emp->givePermissionTo('attendance.own.view');

        return $emp;
    }

    private function submitOt(User $emp)
    {
        return $this->actingAs($emp)->postJson(route('attendance.overtime.store'), [
            'date' => '2026-06-18', 'requested_minutes' => 60, 'reason' => 'release',
        ]);
    }

    public function test_overtime_without_report_to_routes_to_hr(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Manager');
        $emp = $this->employee(['report_to' => null]);

        $this->submitOt($emp)->assertCreated();

        $ot = OvertimeRequest::firstOrFail();
        $this->assertSame('pending', $ot->status);
        $this->assertSame($hr->employee_id, $ot->approval_chain[0]['approver_id']);
    }

    public function test_overtime_with_soft_deleted_manager_routes_to_hr(): void
    {
        $hr = User::factory()->create();
        $hr->assignRole('HR Manager');
        $manager = User::factory()->create();
        $emp = $this->employee(['report_to' => $manager->employee_id]);
        $manager->delete();

        $this->submitOt($emp)->assertCreated();

        $ot = OvertimeRequest::firstOrFail();
        $this->assertSame('pending', $ot->status);
        $this->assertSame($hr->employee_id, $ot->approval_chain[0]['approver_id']);
    }

    public function test_no_approver_leaves_request_pending_and_global_role_can_act_but_not_requester(): void
    {
        $emp = $this->employee(['report_to' => null]);
        $this->submitOt($emp)->assertCreated();

        $ot = OvertimeRequest::firstOrFail();
        $this->assertSame('pending', $ot->status);
        $this->assertSame([], $ot->approval_chain);

        $svc = app(AttendanceApprovalService::class);
        $admin = User::factory()->create();
        $admin->assignRole('Administrator');
        $lead = User::factory()->create();
        $lead->assignRole('Team Lead');

        $this->assertTrue($svc->canApprove($ot, $admin));
        $this->assertFalse($svc->canApprove($ot, $lead));

        // A global-role requester still can never act on their own request.
        $selfAdmin = User::factory()->create();
        $selfAdmin->assignRole('Administrator');
        $ownOt = OvertimeRequest::create([
            'user_id' => $selfAdmin->employee_id, 'date' => '2026-06-19',
            'requested_minutes' => 30, 'reason' => 'x',
        ]);
        $ownOt->update(['status' => 'pending', 'approval_chain' => [], 'current_approval_level' => 0]);
        $this->assertFalse($svc->canApprove($ownOt->fresh(), $selfAdmin));
    }
}
