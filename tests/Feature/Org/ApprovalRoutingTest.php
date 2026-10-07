<?php

namespace Tests\Feature\Org;

use App\Models\CompanySetting;
use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Notifications\Approvals\ApprovalRoutingIncompleteNotification;
use App\Services\Approvals\ApprovalRouting;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Leave\LeaveApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Access\Concerns\BuildsDepartmentAdminWorld;
use Tests\TestCase;

class ApprovalRoutingTest extends TestCase
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
        $this->lab = new Department(['name' => 'Routing Lab', 'code' => 'RTL-LAB']);
        $this->lab->forceFill(['is_active' => true])->save();
        // Start from an organization with no HR Manager at all.
        $this->hr->syncRoles(['Employee']);
        $this->hrInD1->syncRoles(['Employee']);
    }

    private function leaveFor(User $user, int $days = 1): Leave
    {
        $type = LeaveSetting::create([
            'type' => 'Routing '.uniqid(), 'days' => 30, 'accrual_method' => 'annual_upfront', 'accrual_rate' => 30,
            'requires_approval' => true, 'auto_approve' => false, 'allow_negative' => true,
        ]);

        return Leave::create([
            'user_id' => $user->employee_id, 'leave_type' => $type->id,
            'from_date' => now()->addDays(20)->toDateString(), 'to_date' => now()->addDays(19 + $days)->toDateString(),
            'no_of_days' => $days, 'reason' => 'routing', 'status' => 'pending',
        ]);
    }

    private function approvers(array $chain): array
    {
        return array_map('strval', array_column($chain, 'approver_id'));
    }

    private function configure(?User $approver): void
    {
        $settings = CompanySetting::first() ?? new CompanySetting;
        $settings->forceFill([
            'companyName' => 'Test Co', 'country' => 'BD', 'city' => 'Dhaka', 'state' => 'Dhaka', 'postalCode' => '1200',
            'email' => 'co@example.com', 'escalation_approver_id' => $approver?->employee_id,
        ])->save();
    }

    public function test_level_two_is_the_department_head_from_manager_id(): void
    {
        $head = $this->person('Lab Head', $this->lab, ['Employee']);
        $manager = $this->person('Lab Manager', $this->lab, ['Employee']);
        $employee = $this->person('Lab Engineer', $this->lab, ['Employee']);
        $employee->forceFill(['report_to' => $manager->employee_id])->save();
        $this->lab->forceFill(['manager_id' => $head->employee_id])->save();

        $chain = app(LeaveApprovalService::class)->buildApprovalChain($this->leaveFor($employee));
        $this->assertSame([(string) $manager->employee_id, (string) $head->employee_id], $this->approvers($chain));
        $this->assertSame([1, 2], array_column($chain, 'level'));

        // Skipped when the head IS the direct manager...
        $employee->forceFill(['report_to' => $head->employee_id])->save();
        $chain = app(LeaveApprovalService::class)->buildApprovalChain($this->leaveFor($employee->fresh()));
        $this->assertSame([(string) $head->employee_id], $this->approvers($chain));

        // ...and when the head is the requester.
        $head->forceFill(['report_to' => $manager->employee_id])->save();
        $chain = app(LeaveApprovalService::class)->buildApprovalChain($this->leaveFor($head->fresh()));
        $this->assertSame([(string) $manager->employee_id], $this->approvers($chain));
    }

    public function test_escalation_order_is_hr_then_configured_then_super_administrator_never_the_requester(): void
    {
        $super = $this->person('Super One', null, ['Super Administrator']);
        $configured = $this->person('Configured Approver', $this->lab, ['Employee']);
        $requester = $this->person('Top Person', $this->lab, ['Employee']);
        $routing = app(ApprovalRouting::class);

        // (c) nothing else exists: Super Administrator, and Super Administrators are told.
        $this->assertSame((string) $super->employee_id, (string) $routing->escalationApprover($requester)->employee_id);
        Notification::assertSentTo($super, ApprovalRoutingIncompleteNotification::class);

        // (b) a configured approver wins over the last resort, with no notice.
        Notification::fake();
        $this->configure($configured);
        $this->assertSame((string) $configured->employee_id, (string) $routing->escalationApprover($requester)->employee_id);
        Notification::assertNothingSent();

        // (a) an HR Manager wins over the configured approver.
        $hr = $this->person('Real Hr', null, ['HR Manager']);
        $this->assertSame((string) $hr->employee_id, (string) $routing->escalationApprover($requester)->employee_id);

        // Never the requester: the only HR Manager asking falls through to the configured approver.
        $this->assertSame((string) $configured->employee_id, (string) $routing->escalationApprover($hr)->employee_id);
        // And the configured approver asking falls through to HR.
        $this->assertSame((string) $hr->employee_id, (string) $routing->escalationApprover($configured)->employee_id);
    }

    public function test_top_of_the_organization_requests_go_to_the_escalation_approver(): void
    {
        $configured = $this->person('Configured Approver', $this->lab, ['Employee']);
        $this->configure($configured);
        $top = $this->person('Top Person', $this->lab, ['Employee']);
        $this->assertNull($top->report_to);

        $leaveChain = app(LeaveApprovalService::class)->buildApprovalChain($this->leaveFor($top));
        $this->assertSame([(string) $configured->employee_id], $this->approvers($leaveChain));
        $this->assertSame([1], array_column($leaveChain, 'level'));

        $otChain = app(AttendanceApprovalService::class)->buildChain($top);
        $this->assertSame([(string) $configured->employee_id], $this->approvers($otChain));
    }

    public function test_an_offboarded_manager_routes_to_escalation_not_to_the_requester(): void
    {
        $hr = $this->person('Real Hr', null, ['HR Manager']);
        $manager = $this->person('Gone Manager', $this->lab, ['Employee']);
        $employee = $this->person('Orphan', $this->lab, ['Employee']);
        $employee->forceFill(['report_to' => $manager->employee_id])->save();
        $manager->delete();

        $chain = app(AttendanceApprovalService::class)->buildChain($employee->fresh());
        $this->assertSame([(string) $hr->employee_id], $this->approvers($chain));
    }

    public function test_an_inactive_manager_is_not_a_usable_approver(): void
    {
        $hr = $this->person('Real Hr', null, ['HR Manager']);
        $manager = $this->person('Locked Manager', $this->lab, ['Employee']);
        $manager->forceFill(['is_active' => false])->save();
        $employee = $this->person('Staff', $this->lab, ['Employee']);
        $employee->forceFill(['report_to' => $manager->employee_id])->save();

        $chain = app(AttendanceApprovalService::class)->buildChain($employee->fresh());
        $this->assertSame([(string) $hr->employee_id], $this->approvers($chain));
    }
}
