<?php

namespace Tests\Feature\Api;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyWorkAnalyticsApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    /** @test */
    public function it_returns_completion_rate_statistics()
    {
        // Create some test data
        DailyWork::factory()->count(5)->create(['status' => DailyWork::STATUS_COMPLETED]);
        DailyWork::factory()->count(3)->create(['status' => DailyWork::STATUS_IN_PROGRESS]);

        $response = $this->getJson('/api/v1/analytics/daily-works/completion-rates');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'total_work_items',
                        'completed_items',
                        'in_progress_items',
                        'completion_rate_percentage',
                        'average_completion_time_days',
                    ],
                ])
                ->assertJsonPath('success', true);
    }

    /** @test */
    public function it_returns_bottleneck_analysis()
    {
        DailyWork::factory()->count(10)->create();

        $response = $this->getJson('/api/v1/analytics/daily-works/bottlenecks');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'by_status',
                        'by_type',
                        'by_location',
                        'by_user',
                        'objection_impacted',
                    ],
                ])
                ->assertJsonPath('success', true);
    }

    /** @test */
    public function it_returns_trend_analysis()
    {
        DailyWork::factory()->count(20)->create();

        $response = $this->getJson('/api/v1/analytics/daily-works/trends?days=7');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'daily_completion_trend',
                        'status_changes_over_time',
                        'type_distribution_trend',
                    ],
                ])
                ->assertJsonPath('success', true);
    }

    /** @test */
    public function it_returns_dashboard_summary()
    {
        DailyWork::factory()->count(15)->create();

        $response = $this->getJson('/api/v1/analytics/daily-works/dashboard');

        $response->assertStatus(200)
                ->assertJsonStructure([
                    'success',
                    'data' => [
                        'summary',
                        'urgent_items',
                        'recent_activity',
                        'performance_indicators',
                    ],
                ])
                ->assertJsonPath('success', true);
    }

    /** @test */
    public function it_applies_date_filters_to_analytics()
    {
        // Create work with different dates
        DailyWork::factory()->create(['date' => now()->subDays(10)]);
        DailyWork::factory()->create(['date' => now()]);

        $response = $this->getJson('/api/v1/analytics/daily-works/dashboard?start_date='.now()->subDays(5)->toDateString());

        $response->assertStatus(200);

        // The response should only include work from the last 5 days
        $data = $response->json('data');
        $this->assertEquals(1, $data['summary']['total_work_items']);
    }
}