<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Covers the server-side pagination/filtering + lean candidate payload added to the
 * mobile daily-works index (performance fix H3). Previously the mobile client pulled
 * every page for each date and filtered/paginated client-side, and every row embedded
 * the full incharge/assignee candidate lists.
 */
class MobileDailyWorkPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-01-10';

    public function test_index_filters_by_type_and_paginates_server_side(): void
    {
        $manager = $this->createManager();
        $incharge = User::factory()->create();
        $assignee = User::factory()->create();

        DailyWork::factory()->count(3)->structure()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();
        DailyWork::factory()->count(2)->embankment()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();
        DailyWork::factory()->count(1)->pavement()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/daily-works?'.http_build_query([
            'type' => DailyWork::TYPE_STRUCTURE,
            'perPage' => 2,
            'page' => 1,
            'dates' => [self::DATE],
        ]));

        $response->assertOk()
            ->assertJsonPath('success', true)
            // Only the 3 Structure rows count toward the type-filtered list.
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.current_page', 1)
            ->assertJsonPath('data.pagination.last_page', 2);

        // Server returned exactly one page worth of rows (not all 3, and not all 6).
        $this->assertCount(2, $response->json('data.daily_works'));

        foreach ($response->json('data.daily_works') as $row) {
            $this->assertSame(DailyWork::TYPE_STRUCTURE, $row['type']);
        }
    }

    public function test_index_filters_by_inspection_result_server_side(): void
    {
        $manager = $this->createManager();
        $incharge = User::factory()->create();
        $assignee = User::factory()->create();

        DailyWork::factory()->count(2)->structure()->passed()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();
        DailyWork::factory()->count(3)->structure()->failed()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();

        Sanctum::actingAs($manager);

        $this->getJson('/api/v1/daily-works?'.http_build_query([
            'inspection_result' => DailyWork::INSPECTION_PASS,
            'dates' => [self::DATE],
        ]))->assertOk()
            ->assertJsonPath('data.pagination.total', 2);

        $this->getJson('/api/v1/daily-works?'.http_build_query([
            'inspection_result' => DailyWork::INSPECTION_FAIL,
            'dates' => [self::DATE],
        ]))->assertOk()
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_index_does_not_embed_candidate_lists_per_row(): void
    {
        $manager = $this->createManager();
        $incharge = User::factory()->create();
        $assignee = User::factory()->create();

        DailyWork::factory()->structure()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/daily-works?'.http_build_query([
            'dates' => [self::DATE],
        ]))->assertOk();

        $firstRow = $response->json('data.daily_works.0');
        $this->assertIsArray($firstRow);
        // The heavy candidate arrays must NOT be duplicated onto every row anymore.
        $this->assertArrayNotHasKey('assignment_options', $firstRow);
        $this->assertArrayHasKey('permissions', $firstRow);

        // They are returned exactly once at the top level instead.
        $assignmentOptions = $response->json('data.assignment_options');
        $this->assertIsArray($assignmentOptions);
        $this->assertArrayHasKey('incharge_candidates', $assignmentOptions);
        $this->assertArrayHasKey('assigned_candidates_by_incharge', $assignmentOptions);
    }

    public function test_index_summary_spans_all_types_when_requested(): void
    {
        $manager = $this->createManager();
        $incharge = User::factory()->create();
        $assignee = User::factory()->create();

        DailyWork::factory()->count(2)->structure()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();
        DailyWork::factory()->count(1)->structure()->completed()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();
        DailyWork::factory()->count(2)->embankment()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/daily-works?'.http_build_query([
            'type' => DailyWork::TYPE_STRUCTURE,
            'include_summary' => 1,
            'dates' => [self::DATE],
        ]))->assertOk();

        // The paginated list is scoped to the requested Structure type (3 rows)...
        $response->assertJsonPath('data.pagination.total', 3);

        // ...but the summary aggregates across ALL types for the same filters.
        $response->assertJsonPath('data.summary.overview.total', 5)
            ->assertJsonPath('data.summary.type.structure', 3)
            ->assertJsonPath('data.summary.type.embankment', 2)
            ->assertJsonPath('data.summary.status.completed', 1);
    }

    public function test_index_omits_summary_by_default(): void
    {
        $manager = $this->createManager();
        $incharge = User::factory()->create();
        $assignee = User::factory()->create();

        DailyWork::factory()->structure()->forUsers($incharge, $assignee)->forDate(self::DATE)->create();

        Sanctum::actingAs($manager);

        $response = $this->getJson('/api/v1/daily-works?'.http_build_query([
            'dates' => [self::DATE],
        ]))->assertOk();

        // No summary computed on plain pagination requests (keeps page loads cheap).
        $this->assertArrayNotHasKey('summary', $response->json('data'));
    }

    private function createManager(): User
    {
        $manager = User::factory()->create();
        Role::findOrCreate('Project Manager');
        $manager->assignRole('Project Manager');

        return $manager;
    }
}
