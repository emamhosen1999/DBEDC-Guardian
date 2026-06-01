<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyWorkFilterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'daily-works.view']);
    }

    /** @test */
    public function it_filters_summary_by_multiple_incharges(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $inchargeOne = User::factory()->create();
        $inchargeTwo = User::factory()->create();
        $outsideIncharge = User::factory()->create();
        $assignedUser = User::factory()->create();

        $this->createDailyWork('DW-1001', $inchargeOne->id, $assignedUser->id, '2025-02-01', 'completed');
        $this->createDailyWork('DW-1002', $inchargeTwo->id, $assignedUser->id, '2025-02-01', 'completed');
        $this->createDailyWork('DW-1003', $outsideIncharge->id, $assignedUser->id, '2025-02-01', 'new');

        $response = $this->actingAs($authorizedUser)->postJson(route('daily-works-summary.filter'), [
            'startDate' => '2025-02-01',
            'endDate' => '2025-02-02',
            'incharge' => [$inchargeOne->id, $inchargeTwo->id],
        ]);

        $response->assertOk();
        $summaries = $response->json('summaries');

        $this->assertNotEmpty($summaries);
        $this->assertSame(2, collect($summaries)->sum('totalDailyWorks'));
    }

    /** @test */
    public function it_filters_paginated_results_by_multiple_jurisdictions(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $assignedUser = User::factory()->create();

        $inchargeOne = User::factory()->create();
        $inchargeTwo = User::factory()->create();
        $inchargeThree = User::factory()->create();

        $jurisdictionOne = Jurisdiction::create([
            'location' => 'Chainage 0-10',
            'start_chainage' => '0+000',
            'end_chainage' => '0+010',
            'incharge' => $inchargeOne->id,
        ]);

        $jurisdictionTwo = Jurisdiction::create([
            'location' => 'Chainage 10-20',
            'start_chainage' => '0+010',
            'end_chainage' => '0+020',
            'incharge' => $inchargeTwo->id,
        ]);

        Jurisdiction::create([
            'location' => 'Chainage 20-30',
            'start_chainage' => '0+020',
            'end_chainage' => '0+030',
            'incharge' => $inchargeThree->id,
        ]);

        $this->createDailyWork('DW-2001', $inchargeOne->id, $assignedUser->id, '2025-02-01', 'completed');
        $this->createDailyWork('DW-2002', $inchargeTwo->id, $assignedUser->id, '2025-02-01', 'completed');
        $this->createDailyWork('DW-2003', $inchargeThree->id, $assignedUser->id, '2025-02-01', 'new');

        $response = $this->actingAs($authorizedUser)->getJson(route('dailyWorks.paginate', [
            'jurisdiction' => [$jurisdictionOne->id, $jurisdictionTwo->id],
            'perPage' => 10,
            'startDate' => '2025-02-01',
            'endDate' => '2025-02-01',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $this->assertEqualsCanonicalizing(
            [$inchargeOne->id, $inchargeTwo->id],
            collect($data)->pluck('incharge')->all()
        );
    }

    private function createAuthorizedUser(): User
    {
        $user = User::factory()->create();
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'Administrator']);
        $user->assignRole('Administrator');
        $user->givePermissionTo('daily-works.view');

        return $user;
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
