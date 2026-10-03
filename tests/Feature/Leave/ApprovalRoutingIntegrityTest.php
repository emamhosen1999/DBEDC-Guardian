<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Profile\ProfileValidationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

/**
 * Approval routing integrity: a request never routes back to its own requester, a skipped approval
 * level never strands it, and the reporting line can neither point at oneself nor loop.
 */
class ApprovalRoutingIntegrityTest extends TestCase
{
    use BuildsDepartmentAdminWorld;
    use RefreshDatabase;

    private Department $lab;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class]);
        $this->buildWorld();
        Notification::fake();

        // No designation tree here, so no department head joins the chain.
        $this->lab = new Department(['name' => 'Routing Lab', 'code' => 'RTL-LAB']);
        $this->lab->forceFill(['is_active' => true])->save();
    }

    private function leaveFor(User $user, int $days): Leave
    {
        $type = LeaveSetting::create([
            'type' => 'Routing '.uniqid(), 'days' => 30, 'accrual_method' => 'annual_upfront', 'accrual_rate' => 30,
            'requires_approval' => true, 'auto_approve' => false, 'allow_negative' => true,
        ]);

        return Leave::create([
            'user_id' => $user->employee_id, 'leave_type' => $type->id,
            'from_date' => now()->addDays(20)->toDateString(), 'to_date' => now()->addDays(19 + $days)->toDateString(),
            'no_of_days' => $days, 'reason' => 'routing integrity', 'status' => 'pending',
        ]);
    }

    public function test_a_self_referencing_manager_never_becomes_the_approver_of_his_own_leave(): void
    {
        $employee = $this->person('Self Loop', $this->lab, ['Employee']);
        $employee->forceFill(['report_to' => $employee->employee_id])->save();

        $chain = app(LeaveApprovalService::class)->buildApprovalChain($this->leaveFor($employee, 1));

        $this->assertNotEmpty($chain, 'the request still has someone to decide it');
        $this->assertNotContains((string) $employee->employee_id, array_map('strval', array_column($chain, 'approver_id')));
        $this->assertSame(range(1, count($chain)), array_column($chain, 'level'));
    }

    public function test_leave_levels_stay_contiguous_so_a_skipped_level_never_strands_the_request(): void
    {
        $manager = $this->person('Lab Manager', $this->lab, ['Employee']);
        $employee = $this->person('Lab Engineer', $this->lab, ['Employee']);
        $employee->forceFill(['report_to' => $manager->employee_id])->save();
        $leave = $this->leaveFor($employee, 6); // > 5 days adds the HR level; there is no department head

        $service = app(LeaveApprovalService::class);
        $this->assertTrue($service->submitForApproval($leave));
        $leave->refresh();
        $this->assertSame([1, 2], array_column($leave->approval_chain, 'level'));
        $this->assertSame((string) $manager->employee_id, (string) $leave->approval_chain[0]['approver_id']);

        $this->assertTrue($service->approve($leave, $manager)['success']);
        $this->assertSame(2, (int) $leave->fresh()->current_approval_level, 'forwarded to the HR level, not to an empty one');

        $hrApprover = User::findOrFail($leave->fresh()->approval_chain[1]['approver_id']);
        $level = DB::transactionLevel();
        $this->assertTrue($service->approve($leave->fresh(), $hrApprover)['success']);
        $this->assertSame('approved', strtolower((string) $leave->fresh()->status));
        $this->assertSame($level, DB::transactionLevel(), 'the final approval commits its transaction');
    }

    public function test_overtime_and_regularization_of_a_self_referencing_manager_route_to_hr(): void
    {
        $employee = $this->person('Self Loop Two', $this->lab, ['Employee']);
        $employee->forceFill(['report_to' => $employee->employee_id])->save();

        $chain = app(AttendanceApprovalService::class)->buildChain($employee);

        $this->assertCount(1, $chain);
        $this->assertNotSame((string) $employee->employee_id, (string) $chain[0]['approver_id']);
        $this->assertTrue(User::findOrFail($chain[0]['approver_id'])->hasAnyRole(['HR Manager', 'HR Head', 'Super Administrator']));
    }

    public function test_the_reporting_line_can_neither_point_at_oneself_nor_loop(): void
    {
        $manager = $this->person('Line Manager', $this->lab, ['Employee']);
        $employee = $this->person('Line Engineer', $this->lab, ['Employee']);
        $report = $this->person('Line Trainee', $this->lab, ['Employee']);
        $report->forceFill(['report_to' => $employee->employee_id])->save();

        $update = fn (?string $reportTo) => $this->actingAs($this->hr)
            ->putJson(route('users.update', $employee->employee_id), ['name' => $employee->name, 'report_to' => $reportTo]);

        $update($employee->employee_id)->assertStatus(422)->assertJsonValidationErrors('report_to');
        $update($report->employee_id)->assertStatus(422)->assertJsonValidationErrors('report_to');
        $this->assertNull($employee->fresh()->report_to);

        $update($manager->employee_id)->assertOk();
        $this->assertSame((string) $manager->employee_id, (string) $employee->fresh()->report_to);

        // The profile editor enforces the same rule.
        $rules = app(ProfileValidationService::class)->getUpdateRulesBySet('employment', (string) $employee->employee_id);
        foreach ([$employee->employee_id, $report->employee_id] as $invalid) {
            $validator = Validator::make(['id' => $employee->employee_id, 'department' => $this->lab->id, 'report_to' => $invalid], $rules);
            $this->assertTrue($validator->errors()->has('report_to'), "report_to $invalid must be rejected");
        }
    }
}
