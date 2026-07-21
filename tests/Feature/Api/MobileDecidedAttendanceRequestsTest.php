<?php

namespace Tests\Feature\Api;

use App\Models\HRM\AttendanceRegularization;
use App\Models\HRM\Department;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Attendance\AttendanceApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Manager DECIDED history for overtime + regularization:
 *  - GET /api/v1/attendance/overtime/decided
 *  - GET /api/v1/attendance/regularizations/decided
 * Manager-gated and team-scoped identically to the pending counterparts (same
 * forApprover chain-membership mechanism); only decided (approved/rejected) rows.
 */
class MobileDecidedAttendanceRequestsTest extends TestCase
{
    use RefreshDatabase;

    private Department $dept;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Super Administrator']); // isManagerUser()
        $this->dept = Department::factory()->create();
    }

    private function employee(?int $reportTo = null): User
    {
        $u = User::factory()->create(['department_id' => $this->dept->id, 'report_to' => $reportTo]);
        $u->assignRole('Employee');

        return $u;
    }

    private function manager(): User
    {
        $m = User::factory()->create(['department_id' => $this->dept->id]);
        $m->assignRole('Super Administrator');

        return $m;
    }

    private function overtimeFor(User $requester, User $manager): OvertimeRequest
    {
        return OvertimeRequest::create([
            'user_id' => $requester->id,
            'date' => '2026-07-10',
            'requested_minutes' => 90,
            'reason' => 'Deadline',
            'status' => 'pending',
            'approval_chain' => [[
                'level' => 1, 'approver_id' => $manager->id, 'approver_name' => 'Mgr',
                'status' => 'pending', 'approved_at' => null, 'comments' => null,
            ]],
            'current_approval_level' => 1,
        ]);
    }

    private function regularizationFor(User $requester, User $manager): AttendanceRegularization
    {
        return AttendanceRegularization::create([
            'user_id' => $requester->id,
            'date' => '2026-07-10',
            'type' => 'missed_day',
            'reason' => 'Sick',
            'status' => 'pending',
            'approval_chain' => [[
                'level' => 1, 'approver_id' => $manager->id, 'approver_name' => 'Mgr',
                'status' => 'pending', 'approved_at' => null, 'comments' => null,
            ]],
            'current_approval_level' => 1,
        ]);
    }

    // -------------------------------------------------------------------------
    // Overtime
    // -------------------------------------------------------------------------

    public function test_decided_overtime_returns_only_decided_and_excludes_pending(): void
    {
        $manager = $this->manager();
        $report = $this->employee($manager->id);
        $approvals = app(AttendanceApprovalService::class);

        $approved = $this->overtimeFor($report, $manager);
        $approvals->approve($approved, $manager);

        $rejected = $this->overtimeFor($report, $manager);
        $approvals->reject($rejected, $manager, 'Not justified.');

        // Still pending → must NOT be in the decided list.
        $pending = $this->overtimeFor($report, $manager);

        Sanctum::actingAs($manager);

        $res = $this->getJson('/api/v1/attendance/overtime/decided')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($approved->id, $ids);
        $this->assertContains($rejected->id, $ids);
        $this->assertNotContains($pending->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_decided_overtime_is_forbidden_for_non_manager(): void
    {
        Sanctum::actingAs($this->employee());

        $this->getJson('/api/v1/attendance/overtime/decided')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_decided_overtime_excludes_other_managers_teams(): void
    {
        $manager = $this->manager();
        $otherManager = $this->manager();
        $report = $this->employee($manager->id);
        $approvals = app(AttendanceApprovalService::class);

        // Decided, but its chain approver is the OTHER manager.
        $foreign = $this->overtimeFor($report, $otherManager);
        $approvals->approve($foreign, $otherManager);

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/attendance/overtime/decided')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // -------------------------------------------------------------------------
    // Regularization
    // -------------------------------------------------------------------------

    public function test_decided_regularizations_returns_only_decided_and_excludes_pending(): void
    {
        $manager = $this->manager();
        $report = $this->employee($manager->id);
        $approvals = app(AttendanceApprovalService::class);

        $approved = $this->regularizationFor($report, $manager);
        $approvals->approve($approved, $manager);

        $rejected = $this->regularizationFor($report, $manager);
        $approvals->reject($rejected, $manager, 'Insufficient evidence.');

        $pending = $this->regularizationFor($report, $manager);

        Sanctum::actingAs($manager);

        $res = $this->getJson('/api/v1/attendance/regularizations/decided')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertContains($approved->id, $ids);
        $this->assertContains($rejected->id, $ids);
        $this->assertNotContains($pending->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_decided_regularizations_is_forbidden_for_non_manager(): void
    {
        Sanctum::actingAs($this->employee());

        $this->getJson('/api/v1/attendance/regularizations/decided')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
