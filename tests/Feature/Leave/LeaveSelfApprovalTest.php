<?php

namespace Tests\Feature\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Leave approval authority: leaves.approve alone grants no company-wide override,
 * and nobody may decide their own leave request.
 */
class LeaveSelfApprovalTest extends TestCase
{
    use RefreshDatabase;

    private LeaveApprovalService $service;

    private int $leaveTypeId;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Super Administrator', 'Department Manager', 'Employee'] as $name) {
            Role::create(['name' => $name, 'guard_name' => 'web']);
        }
        Permission::findOrCreate('leaves.approve', 'web');
        Permission::findOrCreate('leaves.manage', 'web');

        $this->service = app(LeaveApprovalService::class);
        $this->leaveTypeId = (int) LeaveSetting::query()->insertGetId([
            'type' => 'Annual Leave', 'symbol' => 'AL', 'days' => 20, 'eligibility' => null,
            'carry_forward' => false, 'earned_leave' => false, 'is_earned' => false,
            'requires_approval' => true, 'auto_approve' => false, 'special_conditions' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function manager(): User
    {
        $user = User::factory()->create();
        $user->assignRole('Department Manager');
        $user->givePermissionTo('leaves.approve');

        return $user;
    }

    private function leaveFor(User $employee, array $chain): Leave
    {
        return Leave::create([
            'user_id' => $employee->employee_id,
            'leave_type' => $this->leaveTypeId,
            'from_date' => now()->addDays(5)->toDateString(),
            'to_date' => now()->addDays(6)->toDateString(),
            'no_of_days' => 2,
            'reason' => 'Self approval test leave.',
            'status' => 'pending',
            'approval_chain' => $chain,
            'current_approval_level' => 1,
            'submitted_at' => now(),
        ]);
    }

    private function chain(User $approver): array
    {
        return [[
            'level' => 1, 'approver_id' => $approver->employee_id, 'approver_name' => $approver->name,
            'status' => 'pending', 'approved_at' => null, 'comments' => null,
        ]];
    }

    public function test_leaves_approve_permission_is_not_an_override(): void
    {
        $this->assertFalse($this->service->canOverride($this->manager()));

        $manageOnly = User::factory()->create();
        $manageOnly->givePermissionTo('leaves.manage');
        $this->assertTrue($this->service->canOverride($manageOnly));
    }

    public function test_department_manager_cannot_approve_leave_outside_their_chain(): void
    {
        $dm = $this->manager();
        $realApprover = $this->manager();
        $leave = $this->leaveFor(User::factory()->create(), $this->chain($realApprover));

        $result = $this->service->approve($leave, $dm, null, ['force' => $this->service->canOverride($dm)]);

        $this->assertFalse($result['success']);
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_department_manager_cannot_approve_or_reject_own_leave_even_when_in_chain(): void
    {
        $dm = $this->manager();
        $leave = $this->leaveFor($dm, $this->chain($dm));

        $approve = $this->service->approve($leave, $dm);
        $reject = $this->service->reject($leave, $dm, 'Rejecting my own leave request.');

        $this->assertFalse($approve['success']);
        $this->assertFalse($reject['success']);
        $this->assertSame('You cannot approve or reject your own leave request.', $approve['message']);
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_super_administrator_cannot_self_approve_via_override_status(): void
    {
        $sa = User::factory()->create();
        $sa->assignRole('Super Administrator');
        $leave = $this->leaveFor($sa, []);

        $result = $this->service->overrideStatus($leave, $sa, 'approved');

        $this->assertFalse($result['success']);
        $this->assertFalse($result['updated']);
        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_web_status_endpoint_blocks_own_leave(): void
    {
        $dm = $this->manager();
        $leave = $this->leaveFor($dm, $this->chain($dm));

        $this->actingAs($dm)
            ->postJson(route('leave-update-status'), ['id' => $leave->id, 'status' => 'approved'])
            ->assertForbidden();

        $this->assertSame('pending', $leave->fresh()->status);
    }

    public function test_chain_approver_can_still_approve_subordinate_leave(): void
    {
        $dm = $this->manager();
        $employee = User::factory()->create();
        $leave = $this->leaveFor($employee, $this->chain($dm));

        $this->assertTrue($this->service->canApprove($leave, $dm));
        $result = $this->service->approve($leave, $dm);

        $this->assertTrue($result['success'], $result['message'] ?? '');
        $this->assertSame('approved', strtolower($leave->fresh()->status));
    }
}
