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
    public function employee_without_jurisdiction_can_only_view_manager_works(): void
    {
        // Create users: employee, manager, and assigned user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $assignedUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager
        $employee->report_to = $manager->id;
        $employee->save();

        // Give employee Employee role and permission
        $employee->assignRole('Employee');
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where employee is incharge (should NOT be visible)
        $ownDailyWork = $this->createDailyWork('DW-3004', $employee->id, $assignedUser->id, '2025-03-01', 'completed');

        // Create daily work where manager is incharge (should be visible)
        $managerDailyWork = $this->createDailyWork('DW-3005', $manager->id, $assignedUser->id, '2025-03-01', 'completed');

        // Test policy allows viewing only manager's work, not own work
        $policy = new DailyWorkPolicy();
        $canViewOwn = $policy->view($employee, $ownDailyWork);
        $canViewManager = $policy->view($employee, $managerDailyWork);

        $this->assertFalse($canViewOwn, 'Employee without jurisdiction should not be able to view their own daily work');
        $this->assertTrue($canViewManager, 'Employee without jurisdiction should be able to view manager\'s daily work');
    }

    /** @test */
    public function employee_with_jurisdiction_can_view_own_incharge_works(): void
    {
        // Create users: employee, manager, and assigned user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $assignedUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager, but has jurisdiction
        $employee->report_to = $manager->id;
        $employee->save();

        // Create a jurisdiction with employee as incharge
        \App\Models\Jurisdiction::create([
            'location' => 'Test Location',
            'start_chainage' => 0,
            'end_chainage' => 100,
            'incharge' => $employee->id,
        ]);

        // Give employee Employee role and permission
        $employee->assignRole('Employee');
        $employee->givePermissionTo('daily-works.view');

        // Create daily work where employee is incharge (should be visible)
        $ownDailyWork = $this->createDailyWork('DW-3006', $employee->id, $assignedUser->id, '2025-03-01', 'completed');

        // Create daily work where manager is incharge (should NOT be visible)
        $managerDailyWork = $this->createDailyWork('DW-3007', $manager->id, $assignedUser->id, '2025-03-01', 'completed');

        // Test policy allows viewing only own work, not manager's work
        $policy = new DailyWorkPolicy();
        $canViewOwn = $policy->view($employee, $ownDailyWork);
        $canViewManager = $policy->view($employee, $managerDailyWork);

        $this->assertTrue($canViewOwn, 'Employee with jurisdiction should be able to view their own daily work');
        $this->assertFalse($canViewManager, 'Employee with jurisdiction should not be able to view manager\'s daily work');
    }

    /** @test */
    public function employee_without_jurisdiction_pagination_shows_only_manager_incharge_works(): void
    {
        // Create users: employee, manager, other user, and assigned user
        $employee = User::factory()->create();
        $manager = User::factory()->create();
        $otherUser = User::factory()->create();
        $assignedUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager, no jurisdiction
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
        $this->assertCount(1, $data, 'Employee without jurisdiction should see only manager\'s works');

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

    /** @test */
    public function incharge_and_reporting_employee_see_identical_daily_works_and_stats(): void
    {
        // Create users: manager (incharge), employee (reports to manager, no jurisdiction), other users
        $manager = User::factory()->create();
        $employee = User::factory()->create();
        $otherUser = User::factory()->create();
        $assignedUser = User::factory()->create();

        // Set up reporting relationship: employee reports to manager, no jurisdiction
        $employee->report_to = $manager->id;
        $employee->save();

        // Give employee Employee role and permission
        $employee->assignRole('Employee');
        $employee->givePermissionTo('daily-works.view');

        // Give manager permission
        $manager->givePermissionTo('daily-works.view');

        // Create daily works where manager is incharge (should be visible to both)
        $this->createDailyWork('DW-5001', $manager->id, $assignedUser->id, '2025-05-01', 'completed');
        $this->createDailyWork('DW-5002', $manager->id, $otherUser->id, '2025-05-01', 'in-progress');
        $this->createDailyWork('DW-5003', $manager->id, $assignedUser->id, '2025-05-01', 'new');

        // Create daily works where other user is incharge (should NOT be visible to either)
        $this->createDailyWork('DW-5004', $otherUser->id, $assignedUser->id, '2025-05-01', 'completed');

        // Create daily work where employee is incharge (should be visible to manager, NOT to employee)
        $this->createDailyWork('DW-5005', $employee->id, $assignedUser->id, '2025-05-01', 'completed');

        // Test manager's view
        $managerResponse = $this->actingAs($manager)->getJson(route('dailyWorks.paginate', [
            'perPage' => 10,
            'startDate' => '2025-05-01',
            'endDate' => '2025-05-01',
        ]));

        $managerResponse->assertOk();
        $managerData = $managerResponse->json('data');

        // Test employee's view
        $employeeResponse = $this->actingAs($employee)->getJson(route('dailyWorks.paginate', [
            'perPage' => 10,
            'startDate' => '2025-05-01',
            'endDate' => '2025-05-01',
        ]));

        $employeeResponse->assertOk();
        $employeeData = $employeeResponse->json('data');

        // Manager should see 3 works (DW-5001, DW-5002, DW-5003) - only their own incharge works
        $this->assertCount(3, $managerData, 'Manager should see only works where they are incharge');

        // Employee should see 3 works (DW-5001, DW-5002, DW-5003) - only manager's works
        $this->assertCount(3, $employeeData, 'Employee without jurisdiction should see only manager\'s works');

        // Extract work numbers for comparison
        $managerWorkNumbers = collect($managerData)->pluck('number')->sort()->values()->all();
        $employeeWorkNumbers = collect($employeeData)->pluck('number')->sort()->values()->all();

        // Employee should see exactly the same works as manager (manager's incharge works)
        $this->assertContains('DW-5001', $employeeWorkNumbers, 'Employee should see work where manager is incharge');
        $this->assertContains('DW-5002', $employeeWorkNumbers, 'Employee should see work where manager is incharge');
        $this->assertContains('DW-5003', $employeeWorkNumbers, 'Employee should see work where manager is incharge');
        $this->assertNotContains('DW-5004', $employeeWorkNumbers, 'Employee should not see work where non-manager is incharge');
        $this->assertNotContains('DW-5005', $employeeWorkNumbers, 'Employee should not see work where they are incharge');

        // Manager should see only their own incharge works
        $this->assertContains('DW-5001', $managerWorkNumbers, 'Manager should see their own work');
        $this->assertContains('DW-5002', $managerWorkNumbers, 'Manager should see their own work');
        $this->assertContains('DW-5003', $managerWorkNumbers, 'Manager should see their own work');
        $this->assertNotContains('DW-5004', $managerWorkNumbers, 'Manager should not see work where non-manager is incharge');
        $this->assertNotContains('DW-5005', $managerWorkNumbers, 'Manager should not see work where their report is incharge');

        // Verify employee sees exactly the same works as manager (manager's incharge works)
        $this->assertEqualsCanonicalizing($managerWorkNumbers, $employeeWorkNumbers, 
            'Employee should see exactly the same daily works as manager (manager\'s incharge works)');
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
