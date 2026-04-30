<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\User;
use App\Policies\DailyWorkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyWorkReportingHierarchyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'daily-works.view']);
        \Spatie\Permission\Models\Role::create(['name' => 'Employee']);
    }

    private function createDesignation(string $title)
    {
        return \App\Models\HRM\Designation::create([
            'title' => $title,
            'description' => 'Test designation',
        ]);
    }

    /** @test */
    public function employee_can_view_daily_work_where_manager_is_incharge(): void
    {
        // Create users: employee, manager (who employee reports to), and another user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $otherUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager
        $employee->report_to = $manager->id;
        $employee->save();

        // Give employee permission to view daily works
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where manager is incharge
        $dailyWork = $this->createDailyWork('DW-3001', $manager->id, $otherUser->id, '2025-03-01', 'completed');

        // Test policy allows employee to view
        $policy = new DailyWorkPolicy();
        $canView = $policy->view($employee, $dailyWork);

        $this->assertTrue($canView, 'Employee should be able to view daily work where their manager is incharge');
    }

    /** @test */
    public function employee_cannot_view_daily_work_where_non_manager_is_incharge(): void
    {
        // Create users: employee, manager, and another user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $otherUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager
        $employee->report_to = $manager->id;
        $employee->save();

        // Give employee permission to view daily works
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where other user (not manager) is incharge
        $dailyWork = $this->createDailyWork('DW-3002', $otherUser->id, $manager->id, '2025-03-01', 'completed');

        // Test policy does not allow employee to view
        $policy = new DailyWorkPolicy();
        $canView = $policy->view($employee, $dailyWork);

        $this->assertFalse($canView, 'Employee should not be able to view daily work where non-manager is incharge');
    }

    /** @test */
    public function employee_without_manager_can_view_own_works(): void
    {
        // Create users: employee (no manager) and manager
        $employee = User::factory()->create(['report_to' => null]);
        $manager = User::factory()->create();
        $otherUser = User::factory()->create();

        // Give employee permission to view daily works
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where employee is incharge
        $ownDailyWork = $this->createDailyWork('DW-3003', $employee->id, $otherUser->id, '2025-03-01', 'completed');

        // Create daily work where manager is incharge
        $managerDailyWork = $this->createDailyWork('DW-3003b', $manager->id, $otherUser->id, '2025-03-01', 'completed');

        // Test policy allows viewing own work but not manager's work
        $policy = new DailyWorkPolicy();
        $canViewOwn = $policy->view($employee, $ownDailyWork);
        $canViewManager = $policy->view($employee, $managerDailyWork);

        $this->assertTrue($canViewOwn, 'Employee without manager should be able to view their own daily work');
        $this->assertFalse($canViewManager, 'Employee without manager should not be able to view manager\'s daily work');
    }

    /** @test */
    public function employee_with_manager_cannot_view_own_works(): void
    {
        // Create users: employee, manager, and assigned user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $assignedUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager
        $employee->report_to = $manager->id;
        $employee->save();

        // Give employee permission to view daily works
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where employee is incharge
        $ownDailyWork = $this->createDailyWork('DW-3004', $employee->id, $assignedUser->id, '2025-03-01', 'completed');

        // Create daily work where manager is incharge
        $managerDailyWork = $this->createDailyWork('DW-3005', $manager->id, $assignedUser->id, '2025-03-01', 'completed');

        // Test policy only allows viewing manager's work, not own work
        $policy = new DailyWorkPolicy();
        $canViewOwn = $policy->view($employee, $ownDailyWork);
        $canViewManager = $policy->view($employee, $managerDailyWork);

        $this->assertFalse($canViewOwn, 'Employee with manager should not be able to view their own daily work');
        $this->assertTrue($canViewManager, 'Employee with manager should be able to view manager\'s daily work');
    }

    /** @test */
    public function employee_pagination_shows_only_manager_incharge_works(): void
    {
        // Create users: employee, manager, other user, and assigned user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $otherUser = User::factory()->create();
        $assignedUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager
        $employee->report_to = $manager->id;
        $employee->save();

        // Give employee Employee role and permission
        $employee->assignRole('Employee');
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where employee is incharge (should NOT be visible)
        $this->createDailyWork('DW-4001', $employee->id, $assignedUser->id, '2025-04-01', 'completed');

        // Create daily work where manager is incharge (should be visible)
        $this->createDailyWork('DW-4002', $manager->id, $assignedUser->id, '2025-04-01', 'completed');

        // Create daily work where other user is incharge (should NOT be visible)
        $this->createDailyWork('DW-4003', $otherUser->id, $assignedUser->id, '2025-04-01', 'completed');

        // Create daily work where employee is assigned (should NOT be visible)
        $this->createDailyWork('DW-4004', $otherUser->id, $employee->id, '2025-04-01', 'completed');

        // Test pagination query
        $response = $this->actingAs($employee)->getJson(route('dailyWorks.paginate', [
            'perPage' => 10,
            'startDate' => '2025-04-01',
            'endDate' => '2025-04-01',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        // Should see only 1 work: manager's incharge work
        $this->assertCount(1, $data, 'Employee should see only manager\'s works');

        $inchargeIds = collect($data)->pluck('incharge')->unique()->values()->all();
        $this->assertContains($manager->id, $inchargeIds, 'Should include works where manager is incharge');
        $this->assertNotContains($employee->id, $inchargeIds, 'Should not include works where employee is incharge');

        // Verify specific work is included
        $workNumbers = collect($data)->pluck('number')->values()->all();
        $this->assertContains('DW-4002', $workNumbers, 'Should include work where manager is incharge');
        $this->assertNotContains('DW-4001', $workNumbers, 'Should not include work where employee is incharge');
        $this->assertNotContains('DW-4003', $workNumbers, 'Should not include work where non-manager is incharge');
        $this->assertNotContains('DW-4004', $workNumbers, 'Should not include work where employee is assigned');
    }

    private function createDailyWork(string $number, int $inchargeId, int $assignedId, string $date, string $status): DailyWork
    {
        return DailyWork::create([
            'date' => $date,
            'number' => $number,
            'status' => $status,
            'type' => 'Embankment',
            'description' => 'Test description',
            'location' => 'Test location',
            'side' => null,
            'qty_layer' => null,
            'planned_time' => null,
            'incharge' => $inchargeId,
            'assigned' => $assignedId,
            'completion_time' => null,
            'inspection_details' => null,
            'resubmission_count' => 0,
            'resubmission_date' => null,
            'rfi_submission_date' => null,
        ]);
    }
}
