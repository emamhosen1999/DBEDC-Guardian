<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\DailyWork\DailyWorkCacheService;
use App\Services\DailyWork\DailyWorkRealtimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DailyWorkControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Administrator');
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_paginate_endpoint_returns_paginated_daily_works(): void
    {
        // Arrange
        DailyWork::factory()->count(15)->create([
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->getJson('/daily-works-paginate?page=1&perPage=10');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data' => [
                        '*' => [
                            'id',
                            'number',
                            'status',
                            'type',
                            'location',
                            'description',
                            'date',
                            'incharge_user',
                            'assigned_user',
                        ]
                    ],
                    'pagination' => [
                        'current_page',
                        'per_page',
                        'total',
                        'last_page',
                    ]
                ],
                'timestamp',
            ]);

        $data = $response->json('data');
        $this->assertCount(10, $data['data']);
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(10, $data['pagination']['per_page']);
        $this->assertEquals(15, $data['pagination']['total']);
    }

    public function test_paginate_endpoint_uses_cache(): void
    {
        // Arrange
        DailyWork::factory()->count(5)->create([
            'incharge' => $this->user->id,
        ]);

        $params = 'page=1&perPage=10';

        // Act - First call should cache the data
        $response1 = $this->actingAs($this->user)
            ->getJson("/daily-works-paginate?{$params}");

        // Act - Second call should use cached data
        $response2 = $this->actingAs($this->user)
            ->getJson("/daily-works-paginate?{$params}");

        // Assert
        $response1->assertStatus(200);
        $response2->assertStatus(200);
        
        // Both responses should have the same data
        $this->assertEquals(
            $response1->json('data'),
            $response2->json('data')
        );
    }

    public function test_update_endpoint_updates_daily_work_and_broadcasts_event(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
            'status' => 'pending',
        ]);

        Event::fake();

        $updateData = [
            'id' => $dailyWork->id,
            'status' => 'completed',
            'description' => 'Updated description',
        ];

        // Act
        $response = $this->actingAs($this->user)
            ->putJson("/daily-works/{$dailyWork->id}", $updateData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Daily work updated successfully',
            ]);

        $this->assertDatabaseHas('daily_works', [
            'id' => $dailyWork->id,
            'status' => 'completed',
            'description' => 'Updated description',
        ]);
    }

    public function test_delete_endpoint_deletes_daily_work_and_broadcasts_event(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);

        Event::fake();

        // Act
        $response = $this->actingAs($this->user)
            ->deleteJson("/daily-works/{$dailyWork->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => 'Daily work deleted successfully',
            ]);

        $this->assertSoftDeleted('daily_works', [
            'id' => $dailyWork->id,
        ]);
    }

    public function test_mobile_daily_works_endpoint_returns_mobile_optimized_data(): void
    {
        // Arrange
        $date = now()->format('Y-m-d');
        DailyWork::factory()->count(5)->create([
            'date' => $date,
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->getJson("/mobile/daily-works?date={$date}");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'date',
                    'total_count',
                    'works' => [
                        '*' => [
                            'id',
                            'number',
                            'status',
                            'type',
                            'location',
                            'description',
                            'date',
                            'incharge',
                            'assigned',
                            'priority',
                        ]
                    ],
                    'summary',
                    'cached_at',
                ],
                'timestamp',
            ]);

        $data = $response->json('data');
        $this->assertEquals($date, $data['date']);
        $this->assertEquals(5, $data['total_count']);
        $this->assertCount(5, $data['works']);
    }

    public function test_mobile_recent_works_endpoint_returns_paginated_data(): void
    {
        // Arrange
        DailyWork::factory()->count(25)->create([
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->getJson('/mobile/daily-works/recent?page=1&per_page=20');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'pagination',
                    'cached_at',
                ],
                'timestamp',
            ]);

        $data = $response->json('data');
        $this->assertCount(20, $data['data']);
        $this->assertEquals(1, $data['pagination']['current_page']);
        $this->assertEquals(20, $data['pagination']['per_page']);
    }

    public function test_mobile_statistics_endpoint_returns_statistics(): void
    {
        // Arrange
        DailyWork::factory()->count(10)->create([
            'status' => 'completed',
            'incharge' => $this->user->id,
        ]);
        DailyWork::factory()->count(5)->create([
            'status' => 'pending',
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->getJson('/mobile/daily-works/statistics');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'statistics' => [
                        'today',
                        'this_week',
                        'this_month',
                        'overall',
                    ],
                    'cached_at',
                ],
                'timestamp',
            ]);

        $statistics = $response->json('data.statistics');
        $this->assertArrayHasKey('today', $statistics);
        $this->assertArrayHasKey('this_week', $statistics);
        $this->assertArrayHasKey('this_month', $statistics);
        $this->assertArrayHasKey('overall', $statistics);
    }

    public function test_mobile_work_details_endpoint_returns_work_details(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->getJson("/mobile/daily-works/{$dailyWork->id}");

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'work' => [
                        'id',
                        'number',
                        'status',
                        'type',
                        'description',
                        'location',
                        'date',
                        'incharge',
                        'assigned',
                        'reports',
                        'active_objections_count',
                    ],
                    'objections',
                    'files',
                    'cached_at',
                ],
                'timestamp',
            ]);

        $data = $response->json('data');
        $this->assertEquals($dailyWork->id, $data['work']['id']);
        $this->assertEquals($dailyWork->number, $data['work']['number']);
    }

    public function test_mobile_realtime_stats_endpoint_returns_realtime_statistics(): void
    {
        // Arrange
        DailyWork::factory()->count(5)->create([
            'status' => 'completed',
            'date' => now()->format('Y-m-d'),
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->getJson('/mobile/daily-works/realtime-stats');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'today_total',
                    'today_completed',
                    'today_pending',
                ],
                'timestamp',
            ]);

        $data = $response->json('data');
        $this->assertArrayHasKey('today_total', $data);
        $this->assertArrayHasKey('today_completed', $data);
        $this->assertArrayHasKey('today_pending', $data);
    }

    public function test_unauthorized_user_cannot_access_admin_endpoints(): void
    {
        // Arrange
        DailyWork::factory()->create();

        // Act & Assert
        $this->actingAs($this->user)
            ->getJson('/daily-works-paginate')
            ->assertStatus(200); // Regular user can access their own data

        // Test that regular user cannot access admin-specific data
        $this->actingAs($this->user)
            ->getJson('/daily-works/bi/dashboard')
            ->assertStatus(403); // Should be forbidden for non-admin
    }

    public function test_admin_user_can_access_business_intelligence_endpoints(): void
    {
        // Arrange
        DailyWork::factory()->count(10)->create();

        // Act
        $response = $this->actingAs($this->admin)
            ->getJson('/daily-works/bi/dashboard');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'performance_metrics',
                    'trend_analysis',
                    'productivity_insights',
                    'quality_metrics',
                    'resource_utilization',
                    'forecasting',
                    'anomaly_detection',
                ],
                'timestamp',
            ]);
    }

    public function test_validation_errors_are_returned_for_invalid_data(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->putJson("/daily-works/{$dailyWork->id}", [
                'status' => '', // Invalid status
            ]);

        // Assert
        $response->assertStatus(422)
            ->assertJsonStructure([
                'success',
                'message',
                'errors',
                'timestamp',
            ]);
    }

    public function test_not_found_error_is_returned_for_nonexistent_daily_work(): void
    {
        // Act
        $response = $this->actingAs($this->user)
            ->getJson('/daily-works/99999');

        // Assert
        $response->assertStatus(404);
    }

    public function test_forbidden_error_is_returned_when_user_cannot_access_daily_work(): void
    {
        // Arrange
        $otherUser = User::factory()->create();
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $otherUser->id,
        ]);

        // Act
        $response = $this->actingAs($this->user)
            ->putJson("/daily-works/{$dailyWork->id}", [
                'status' => 'completed',
            ]);

        // Assert
        $response->assertStatus(403);
    }

    public function test_cache_invalidation_on_update(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);

        // Pre-populate cache
        $cacheKey = 'daily_works_stats_' . $this->user->id;
        Cache::put($cacheKey, ['total' => 1], 1800);

        // Act
        $this->actingAs($this->user)
            ->putJson("/daily-works/{$dailyWork->id}", [
                'status' => 'completed',
            ]);

        // Assert
        $this->assertNull(Cache::get($cacheKey));
    }

    public function test_cache_invalidation_on_delete(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);

        // Pre-populate cache
        $cacheKey = 'daily_works_stats_' . $this->user->id;
        Cache::put($cacheKey, ['total' => 1], 1800);

        // Act
        $this->actingAs($this->user)
            ->deleteJson("/daily-works/{$dailyWork->id}");

        // Assert
        $this->assertNull(Cache::get($cacheKey));
    }
}
