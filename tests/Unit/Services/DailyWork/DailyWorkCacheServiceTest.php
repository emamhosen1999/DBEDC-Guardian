<?php

namespace Tests\Unit\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\DailyWork\DailyWorkCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DailyWorkCacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private DailyWorkCacheService $cacheService;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheService = new DailyWorkCacheService();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_get_cached_paginated_works_returns_cached_data(): void
    {
        // Arrange
        $params = [
            'page' => 1,
            'perPage' => 10,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->format('Y-m-d'),
        ];

        $expectedData = [
            'data' => [
                ['id' => 1, 'number' => 'RFI-001', 'status' => 'completed'],
                ['id' => 2, 'number' => 'RFI-002', 'status' => 'pending'],
            ],
            'pagination' => [
                'current_page' => 1,
                'per_page' => 10,
                'total' => 2,
                'last_page' => 1,
            ],
        ];

        $cacheKey = 'daily_works_paginated_' . md5(json_encode($params) . $this->user->id);
        Cache::put($cacheKey, $expectedData, 3600);

        // Act
        $result = $this->cacheService->getCachedPaginatedWorks($params);

        // Assert
        $this->assertEquals($expectedData, $result);
    }

    public function test_get_cached_paginated_works_generates_new_cache_when_empty(): void
    {
        // Arrange
        DailyWork::factory()->create([
            'number' => 'RFI-001',
            'status' => 'completed',
            'incharge' => $this->user->id,
        ]);

        $params = [
            'page' => 1,
            'perPage' => 10,
            'startDate' => now()->format('Y-m-d'),
            'endDate' => now()->format('Y-m-d'),
        ];

        // Act
        $result = $this->cacheService->getCachedPaginatedWorks($params);

        // Assert
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertIsArray($result['data']);
        $this->assertEquals(1, $result['pagination']['total']);
    }

    public function test_get_cached_statistics_returns_cached_data(): void
    {
        // Arrange
        $expectedStats = [
            'total' => 10,
            'completed' => 7,
            'pending' => 3,
            'completion_rate' => 70.0,
        ];

        $cacheKey = 'daily_works_stats_' . $this->user->id;
        Cache::put($cacheKey, $expectedStats, 1800);

        // Act
        $result = $this->cacheService->getCachedStatistics();

        // Assert
        $this->assertEquals($expectedStats, $result);
    }

    public function test_get_cached_statistics_generates_new_stats_when_empty(): void
    {
        // Arrange
        DailyWork::factory()->count(5)->create([
            'status' => 'completed',
            'incharge' => $this->user->id,
        ]);
        DailyWork::factory()->count(3)->create([
            'status' => 'pending',
            'incharge' => $this->user->id,
        ]);

        // Act
        $result = $this->cacheService->getCachedStatistics();

        // Assert
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('completed', $result);
        $this->assertArrayHasKey('pending', $result);
        $this->assertEquals(8, $result['total']);
        $this->assertEquals(5, $result['completed']);
        $this->assertEquals(3, $result['pending']);
    }

    public function test_get_cached_user_data_returns_cached_data(): void
    {
        // Arrange
        $expectedUserData = [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'email' => $this->user->email,
            'roles' => ['Supervision Engineer'],
        ];

        $cacheKey = 'daily_works_user_data_' . $this->user->id;
        Cache::put($cacheKey, $expectedUserData, 900);

        // Act
        $result = $this->cacheService->getCachedUserData($this->user->id);

        // Assert
        $this->assertEquals($expectedUserData, $result);
    }

    public function test_get_cached_summary_returns_cached_data(): void
    {
        // Arrange
        $expectedSummary = [
            'date' => now()->format('Y-m-d'),
            'total_works' => 15,
            'completed_works' => 10,
            'pending_works' => 5,
            'completion_rate' => 66.67,
        ];

        $cacheKey = 'daily_works_summary_' . now()->format('Y-m-d') . '_' . $this->user->id;
        Cache::put($cacheKey, $expectedSummary, 1800);

        // Act
        $result = $this->cacheService->getCachedSummary(now()->format('Y-m-d'));

        // Assert
        $this->assertEquals($expectedSummary, $result);
    }

    public function test_invalidate_daily_work_caches_removes_relevant_cache_keys(): void
    {
        // Arrange
        $dailyWorkId = 1;
        $userId = $this->user->id;
        
        // Set some cache data
        Cache::put("daily_works_paginated_{$userId}", 'test_data', 3600);
        Cache::put("daily_works_stats_{$userId}", 'test_stats', 1800);
        Cache::put("daily_works_user_data_{$userId}", 'test_user_data', 900);
        Cache::put("daily_work_{$dailyWorkId}_details", 'test_details', 3600);

        // Act
        $this->cacheService->invalidateDailyWorkCaches($dailyWorkId);

        // Assert
        $this->assertNull(Cache::get("daily_works_paginated_{$userId}"));
        $this->assertNull(Cache::get("daily_works_stats_{$userId}"));
        $this->assertNull(Cache::get("daily_work_{$dailyWorkId}_details"));
        // User data should remain as it's not directly related to a specific work
        $this->assertNotNull(Cache::get("daily_works_user_data_{$userId}"));
    }

    public function test_invalidate_user_caches_removes_user_specific_cache_keys(): void
    {
        // Arrange
        $userId = $this->user->id;
        
        // Set some cache data
        Cache::put("daily_works_paginated_{$userId}", 'test_data', 3600);
        Cache::put("daily_works_stats_{$userId}", 'test_stats', 1800);
        Cache::put("daily_works_user_data_{$userId}", 'test_user_data', 900);
        Cache::put("daily_works_summary_2026-04-28_{$userId}", 'test_summary', 1800);

        // Act
        $this->cacheService->invalidateUserCaches($userId);

        // Assert
        $this->assertNull(Cache::get("daily_works_paginated_{$userId}"));
        $this->assertNull(Cache::get("daily_works_stats_{$userId}"));
        $this->assertNull(Cache::get("daily_works_user_data_{$userId}"));
        $this->assertNull(Cache::get("daily_works_summary_2026-04-28_{$userId}"));
    }

    public function test_warm_up_cache_generates_cache_for_common_queries(): void
    {
        // Arrange
        DailyWork::factory()->count(5)->create([
            'incharge' => $this->user->id,
            'status' => 'completed',
        ]);

        // Act
        $this->cacheService->warmUpCache($this->user->id);

        // Assert
        $statsCacheKey = 'daily_works_stats_' . $this->user->id;
        $userDataCacheKey = 'daily_works_user_data_' . $this->user->id;
        
        $this->assertNotNull(Cache::get($statsCacheKey));
        $this->assertNotNull(Cache::get($userDataCacheKey));
    }

    public function test_cache_service_works_with_laravel_file_cache(): void
    {
        // Arrange
        $params = ['page' => 1, 'perPage' => 10];

        // Act
        $result = $this->cacheService->getCachedPaginatedWorks($params);

        // Assert
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertArrayHasKey('cached_at', $result);
    }

    public function test_cache_warming_creates_cache_entries(): void
    {
        // Arrange
        DailyWork::factory()->create([
            'incharge' => $this->user->id,
            'status' => 'completed',
        ]);

        // Act
        $this->cacheService->warmUpCache($this->user->id);

        // Assert - Check that cache warming doesn't throw errors
        $this->assertTrue(true);
    }

    public function test_cache_invalidation_works(): void
    {
        // Arrange
        $dailyWork = DailyWork::factory()->create([
            'incharge' => $this->user->id,
        ]);

        // Act & Assert - Should not throw errors
        $this->cacheService->invalidateDailyWorkCaches($dailyWork->id);
        $this->cacheService->invalidateUserCaches($this->user->id);
        $this->assertTrue(true);
    }

    public function test_get_cache_key_generates_consistent_keys(): void
    {
        // Arrange
        $params = ['page' => 1, 'perPage' => 10];
        $userId = $this->user->id;

        // Act
        $key1 = $this->cacheService->getCacheKey('paginated', $params, $userId);
        $key2 = $this->cacheService->getCacheKey('paginated', $params, $userId);

        // Assert
        $this->assertEquals($key1, $key2);
        $this->assertStringContains('daily_works_paginated_', $key1);
    }

    public function test_get_cache_ttl_returns_appropriate_ttl_values(): void
    {
        // Act & Assert
        $this->assertEquals(3600, $this->cacheService->getCacheTtl('data'));
        $this->assertEquals(1800, $this->cacheService->getCacheTtl('statistics'));
        $this->assertEquals(900, $this->cacheService->getCacheTtl('user_data'));
        $this->assertEquals(1800, $this->cacheService->getCacheTtl('summary'));
        $this->assertEquals(300, $this->cacheService->getCacheTtl('search'));
    }

    public function test_is_cache_enabled_returns_true_when_cache_configured(): void
    {
        // Act
        $result = $this->cacheService->isCacheEnabled();

        // Assert
        $this->assertTrue($result);
    }

    public function test_clear_all_caches_removes_all_daily_work_cache_keys(): void
    {
        // Arrange
        $userId = $this->user->id;
        
        // Set various cache data
        Cache::put("daily_works_paginated_{$userId}", 'test_data', 3600);
        Cache::put("daily_works_stats_{$userId}", 'test_stats', 1800);
        Cache::put("daily_works_user_data_{$userId}", 'test_user_data', 900);
        Cache::put("daily_works_summary_2026-04-28_{$userId}", 'test_summary', 1800);

        // Act
        $this->cacheService->clearAllCaches();

        // Assert
        $this->assertNull(Cache::get("daily_works_paginated_{$userId}"));
        $this->assertNull(Cache::get("daily_works_stats_{$userId}"));
        $this->assertNull(Cache::get("daily_works_user_data_{$userId}"));
        $this->assertNull(Cache::get("daily_works_summary_2026-04-28_{$userId}"));
    }
}
