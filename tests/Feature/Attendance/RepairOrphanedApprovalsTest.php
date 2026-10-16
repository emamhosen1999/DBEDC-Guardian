<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RepairOrphanedApprovalsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
    }

    public function test_a_claim_whose_approver_does_not_exist_is_rerouted_to_the_line_manager_only_with_apply(): void
    {
        $manager = User::factory()->create();
        $employee = User::factory()->create(['report_to' => $manager->employee_id]);
        $ghost = ['level' => 1, 'approver_id' => 'no-such-person', 'approver_name' => 'Manager', 'status' => 'pending', 'approved_at' => null, 'comments' => null];
        $ot = OvertimeRequest::create(['user_id' => $employee->employee_id, 'date' => '2026-08-05', 'requested_minutes' => 480, 'reason' => 'x']);
        $ot->update(['status' => 'pending', 'approval_chain' => [$ghost], 'current_approval_level' => 1]);
        $healthy = OvertimeRequest::create(['user_id' => $employee->employee_id, 'date' => '2026-08-06', 'requested_minutes' => 60, 'reason' => 'y']);
        $healthy->update(['status' => 'pending', 'approval_chain' => [array_merge($ghost, ['approver_id' => (string) $manager->employee_id])], 'current_approval_level' => 1]);

        $this->artisan('approvals:repair-orphaned')->expectsOutputToContain('1 to re-route')->assertSuccessful();
        $this->assertSame('no-such-person', $ot->fresh()->approval_chain[0]['approver_id'], 'a dry run changes nothing');

        $this->artisan('approvals:repair-orphaned --apply')->expectsOutputToContain('Re-routed 1.')->assertSuccessful();
        $this->assertSame((string) $manager->employee_id, $ot->fresh()->approval_chain[0]['approver_id']);
        $this->assertSame(1, $ot->fresh()->current_approval_level);
        $this->assertSame('pending', $ot->fresh()->status);
        $this->assertSame((string) $manager->employee_id, $healthy->fresh()->approval_chain[0]['approver_id'], 'a healthy chain is untouched');
    }
}
