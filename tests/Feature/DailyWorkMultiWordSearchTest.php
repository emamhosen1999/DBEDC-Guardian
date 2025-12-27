<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class DailyWorkMultiWordSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::create(['name' => 'daily-works.view']);
    }

    /** @test */
    public function it_finds_daily_work_with_multi_word_search_matching_location_and_description(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $incharge = User::factory()->create();
        $assigned = User::factory()->create();

        // Create daily work with K23 in location and RE wall in description
        $matchingWork = $this->createDailyWork(
            'RFI-001',
            $incharge->id,
            $assigned->id,
            '2025-01-15',
            'new',
            'K23+500-K23+600',
            'RE wall construction phase 1'
        );

        // Create daily work that only matches location
        $partialMatch1 = $this->createDailyWork(
            'RFI-002',
            $incharge->id,
            $assigned->id,
            '2025-01-15',
            'new',
            'K23+700-K23+800',
            'Embankment work'
        );

        // Create daily work that doesn't match
        $noMatch = $this->createDailyWork(
            'RFI-003',
            $incharge->id,
            $assigned->id,
            '2025-01-15',
            'new',
            'K35+000-K35+100',
            'Pavement laying'
        );

        $response = $this->actingAs($authorizedUser)->getJson(route('dailyWorks.paginate', [
            'search' => 'K23 RE wall',
            'perPage' => 50,
            'startDate' => '2025-01-15',
            'endDate' => '2025-01-15',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        // Should only find the matching work (K23 AND RE AND wall all present)
        $this->assertCount(1, $data);
        $this->assertEquals('RFI-001', $data[0]['number']);
    }

    /** @test */
    public function it_finds_daily_work_when_all_words_match_any_column(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $incharge = User::factory()->create();
        $assigned = User::factory()->create();

        // K14 in location, embankment in type/description
        $work1 = $this->createDailyWork(
            'RFI-101',
            $incharge->id,
            $assigned->id,
            '2025-01-20',
            'new',
            'K14+036-K14+100',
            'Layer 2 compaction',
            'Embankment'
        );

        // K14 in location but different type
        $work2 = $this->createDailyWork(
            'RFI-102',
            $incharge->id,
            $assigned->id,
            '2025-01-20',
            'new',
            'K14+200-K14+300',
            'Foundation work',
            'Structure'
        );

        $response = $this->actingAs($authorizedUser)->getJson(route('dailyWorks.paginate', [
            'search' => 'K14 Embankment',
            'perPage' => 50,
            'startDate' => '2025-01-20',
            'endDate' => '2025-01-20',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        // Should only find work1 (K14 in location AND Embankment in type)
        $this->assertCount(1, $data);
        $this->assertEquals('RFI-101', $data[0]['number']);
    }

    /** @test */
    public function it_handles_single_word_search_normally(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $incharge = User::factory()->create();
        $assigned = User::factory()->create();

        $work1 = $this->createDailyWork(
            'RFI-201',
            $incharge->id,
            $assigned->id,
            '2025-01-25',
            'new',
            'K50+000-K50+100',
            'Bridge abutment'
        );

        $work2 = $this->createDailyWork(
            'RFI-202',
            $incharge->id,
            $assigned->id,
            '2025-01-25',
            'new',
            'K55+000-K55+100',
            'Pavement work'
        );

        $response = $this->actingAs($authorizedUser)->getJson(route('dailyWorks.paginate', [
            'search' => 'Bridge',
            'perPage' => 50,
            'startDate' => '2025-01-25',
            'endDate' => '2025-01-25',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('RFI-201', $data[0]['number']);
    }

    /** @test */
    public function it_handles_extra_spaces_in_search_term(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $incharge = User::factory()->create();
        $assigned = User::factory()->create();

        $work = $this->createDailyWork(
            'RFI-301',
            $incharge->id,
            $assigned->id,
            '2025-01-28',
            'new',
            'K60+000-K60+100',
            'RE wall repair'
        );

        // Search with multiple spaces between words
        $response = $this->actingAs($authorizedUser)->getJson(route('dailyWorks.paginate', [
            'search' => '  K60   RE   wall  ',
            'perPage' => 50,
            'startDate' => '2025-01-28',
            'endDate' => '2025-01-28',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('RFI-301', $data[0]['number']);
    }

    /** @test */
    public function it_is_case_insensitive_for_multi_word_search(): void
    {
        $authorizedUser = $this->createAuthorizedUser();
        $incharge = User::factory()->create();
        $assigned = User::factory()->create();

        $work = $this->createDailyWork(
            'RFI-401',
            $incharge->id,
            $assigned->id,
            '2025-02-01',
            'new',
            'SCK14+500-SCK14+600',
            'EMBANKMENT Layer 3'
        );

        // Search with different case
        $response = $this->actingAs($authorizedUser)->getJson(route('dailyWorks.paginate', [
            'search' => 'sck14 embankment layer',
            'perPage' => 50,
            'startDate' => '2025-02-01',
            'endDate' => '2025-02-01',
        ]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertEquals('RFI-401', $data[0]['number']);
    }

    private function createAuthorizedUser(): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo('daily-works.view');

        return $user;
    }

    private function createDailyWork(
        string $number,
        int $inchargeId,
        int $assignedId,
        string $date,
        string $status,
        string $location = 'Test location',
        string $description = 'Test description',
        string $type = 'Structure'
    ): DailyWork {
        return DailyWork::create([
            'date' => $date,
            'number' => $number,
            'status' => $status,
            'type' => $type,
            'description' => $description,
            'location' => $location,
            'side' => 'SR-R',
            'planned_time' => '09:00',
            'incharge' => $inchargeId,
            'assigned' => $assignedId,
        ]);
    }
}
