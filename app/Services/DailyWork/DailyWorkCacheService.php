<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Auth;

class DailyWorkCacheService
{
    private const CACHE_TTL = 3600; // 1 hour
    private const STATS_TTL = 1800; // 30 minutes
    private const USER_DATA_TTL = 900; // 15 minutes

    /**
     * Cache key prefixes
     */
    private const KEY_PREFIX = 'daily_works';
    private const STATS_PREFIX = 'daily_works_stats';
    private const USER_PREFIX = 'daily_works_user';

    /**
     * Get cached paginated daily works
     */
    public function getCachedPaginatedWorks(array $params): array
    {
        $cacheKey = $this->generatePaginatedKey($params);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($params) {
            return $this->fetchPaginatedWorks($params);
        });
    }

    /**
     * Get cached statistics for a user
     */
    public function getCachedStatistics(?int $userId = null): array
    {
        $userId = $userId ?? Auth::id();
        $cacheKey = self::STATS_PREFIX . "_user_{$userId}";
        
        return Cache::remember($cacheKey, self::STATS_TTL, function () use ($userId) {
            return $this->calculateStatistics($userId);
        });
    }

    /**
     * Get cached user data (designation, roles, etc.)
     */
    public function getCachedUserData(int $userId): array
    {
        $cacheKey = self::USER_PREFIX . "_data_{$userId}";
        
        return Cache::remember($cacheKey, self::USER_DATA_TTL, function () use ($userId) {
            $user = User::with(['designation', 'roles'])->find($userId);
            
            return [
                'id' => $user->id,
                'name' => $user->name,
                'designation_title' => $user->designation?->title,
                'roles' => $user->roles->pluck('name')->toArray(),
                'is_admin' => $user->hasRole(['Super Administratoristrator', 'Administrator']),
                'is_supervision_engineer' => $user->designation?->title === 'Supervision Engineer',
            ];
        });
    }

    /**
     * Get cached summary data
     */
    public function getCachedSummary(array $filters): array
    {
        $cacheKey = $this->generateSummaryKey($filters);
        
        return Cache::remember($cacheKey, self::CACHE_TTL, function () use ($filters) {
            return $this->generateSummary($filters);
        });
    }

    /**
     * Invalidate relevant caches when daily work is created/updated/deleted
     */
    public function invalidateDailyWorkCaches(int $dailyWorkId, ?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();
        
        // Invalidate user-specific caches
        $this->invalidateUserCaches($userId);
        
        // Invalidate paginated caches (pattern-based invalidation)
        $this->invalidatePattern(self::KEY_PREFIX . '_paginated_*');
        
        // Invalidate summary caches
        $this->invalidatePattern(self::KEY_PREFIX . '_summary_*');
        
        // Invalidate statistics for all users who might be affected
        $this->invalidateStatisticsForRelevantUsers($dailyWorkId);
    }

    /**
     * Invalidate caches when user data changes
     */
    public function invalidateUserCaches(int $userId): void
    {
        Cache::forget(self::USER_PREFIX . "_data_{$userId}");
        Cache::forget(self::STATS_PREFIX . "_user_{$userId}");
    }

    /**
     * Warm up cache for frequently accessed data
     */
    public function warmUpCache(int $userId): void
    {
        // Pre-load user data
        $this->getCachedUserData($userId);
        
        // Pre-load statistics
        $this->getCachedStatistics($userId);
        
        // Pre-load recent works (last 7 days)
        $recentParams = [
            'startDate' => now()->subDays(7)->format('Y-m-d'),
            'endDate' => now()->format('Y-m-d'),
            'page' => 1,
            'perPage' => 30,
        ];
        $this->getCachedPaginatedWorks($recentParams);
    }

    /**
     * Generate cache key for paginated works
     */
    private function generatePaginatedKey(array $params): string
    {
        $userId = Auth::id();
        $keyData = [
            'user_id' => $userId,
            'params' => $params,
        ];
        
        return self::KEY_PREFIX . '_paginated_' . md5(json_encode($keyData));
    }

    /**
     * Generate cache key for summary data
     */
    private function generateSummaryKey(array $filters): string
    {
        $userId = Auth::id();
        $keyData = [
            'user_id' => $userId,
            'filters' => $filters,
        ];
        
        return self::KEY_PREFIX . '_summary_' . md5(json_encode($keyData));
    }

    /**
     * Fetch paginated works (actual data retrieval)
     */
    private function fetchPaginatedWorks(array $params): array
    {
        $query = DailyWork::with(['inchargeUser', 'assignedUser']);
        
        // Apply user role filters
        $this->applyUserFilters($query);
        
        // Apply date filters
        if (isset($params['startDate']) && isset($params['endDate'])) {
            $query->whereBetween('date', [$params['startDate'], $params['endDate']]);
        }
        
        // Apply other filters
        if (!empty($params['status']) && $params['status'] !== 'all') {
            $query->where('status', $params['status']);
        }
        
        if (!empty($params['search'])) {
            $this->applySearchFilter($query, $params['search']);
        }
        
        // Apply pagination
        $page = $params['page'] ?? 1;
        $perPage = $params['perPage'] ?? 30;
        
        $total = $query->count();
        $data = $query->offset(($page - 1) * $perPage)
                     ->limit($perPage)
                     ->orderBy('date', 'desc')
                     ->get();
        
        return [
            'data' => $data->toArray(),
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'last_page' => ceil($total / $perPage),
        ];
    }

    /**
     * Calculate statistics for user
     */
    private function calculateStatistics(int $userId): array
    {
        $userData = $this->getCachedUserData($userId);
        $query = DailyWork::query();
        
        // Apply user role filters
        if ($userData['is_admin']) {
            // Admin sees all data
        } elseif ($userData['is_supervision_engineer']) {
            $query->where('incharge', $userId);
        } else {
            $query->where(function ($q) use ($userId) {
                $q->where('assigned', $userId)
                  ->orWhere('incharge', $userId);
            });
        }
        
        $totalWorks = $query->count();
        $completedWorks = $query->where('status', 'completed')->count();
        $pendingWorks = $query->whereIn('status', ['new', 'pending', 'resubmission', 'in-progress'])->count();
        $rfiSubmissions = $query->whereNotNull('rfi_submission_date')->count();
        
        return [
            'total_works' => $totalWorks,
            'completed_works' => $completedWorks,
            'pending_works' => $pendingWorks,
            'rfi_submissions' => $rfiSubmissions,
            'completion_rate' => $totalWorks > 0 ? round(($completedWorks / $totalWorks) * 100, 1) : 0,
            'rfi_rate' => $totalWorks > 0 ? round(($rfiSubmissions / $totalWorks) * 100, 1) : 0,
        ];
    }

    /**
     * Generate summary data
     */
    private function generateSummary(array $filters): array
    {
        $query = DailyWork::with(['inchargeUser', 'assignedUser']);
        
        // Apply user role filters
        $this->applyUserFilters($query);
        
        // Apply filters
        if (!empty($filters['startDate']) && !empty($filters['endDate'])) {
            $query->whereBetween('date', [$filters['startDate'], $filters['endDate']]);
        }
        
        if (!empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }
        
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }
        
        if (!empty($filters['search'])) {
            $this->applySearchFilter($query, $filters['search']);
        }
        
        $works = $query->get();
        
        // Group by date
        $groupedByDate = $works->groupBy('date');
        $summaries = [];
        
        foreach ($groupedByDate as $date => $dateWorks) {
            $totalWorks = $dateWorks->count();
            $completed = $dateWorks->filter(fn($w) => $w->status === 'completed')->count();
            $pending = $dateWorks->whereIn('status', ['new', 'pending', 'resubmission', 'in-progress'])->count();
            $rfiSubmissions = $dateWorks->whereNotNull('rfi_submission_date')->count();
            
            $typeBreakdown = $dateWorks->groupBy('type');
            
            $summaries[] = [
                'date' => $date,
                'totalDailyWorks' => $totalWorks,
                'completed' => $completed,
                'pending' => $pending,
                'rfiSubmissions' => $rfiSubmissions,
                'completionPercentage' => $totalWorks > 0 ? round(($completed / $totalWorks) * 100, 1) : 0,
                'embankment' => $typeBreakdown->get('Embankment', collect())->count(),
                'structure' => $typeBreakdown->get('Structure', collect())->count(),
                'pavement' => $typeBreakdown->get('Pavement', collect())->count(),
            ];
        }
        
        return $summaries;
    }

    /**
     * Apply user role filters to query
     */
    private function applyUserFilters($query): void
    {
        $userData = $this->getCachedUserData(Auth::id());
        
        if ($userData['is_admin']) {
            // Admin sees all data - no filters
        } elseif ($userData['is_supervision_engineer']) {
            $query->where('incharge', Auth::id());
        } else {
            $query->where(function ($q) {
                $q->where('assigned', Auth::id())
                  ->orWhere('incharge', Auth::id());
            });
        }
    }

    /**
     * Apply search filter to query
     */
    private function applySearchFilter($query, string $search): void
    {
        $words = array_filter(preg_split('/\s+/', trim($search)));
        
        if (!empty($words)) {
            $query->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $q->where(function ($wordQuery) use ($word) {
                        $wordQuery->where('number', 'like', "%{$word}%")
                                ->orWhere('description', 'like', "%{$word}%")
                                ->orWhere('location', 'like', "%{$word}%")
                                ->orWhere('type', 'like', "%{$word}%");
                    });
                }
            });
        }
    }

    /**
     * Invalidate cache by pattern
     */
    private function invalidatePattern(string $pattern): void
    {
        try {
            // For Redis cache, we can use pattern matching
            $cacheStore = Cache::getStore();
            
            // Check if we're using Redis
            if (method_exists($cacheStore, 'connection')) {
                $redis = $cacheStore->connection();
                $keys = $redis->keys('*' . str_replace('*', '*', $pattern) . '*');
                
                if (!empty($keys)) {
                    $redis->del($keys);
                }
            } else {
                // For other cache stores, try to use tags if available
                if (method_exists($cacheStore, 'tags')) {
                    Cache::tags([self::KEY_PREFIX])->flush();
                }
            }
        } catch (\Exception $e) {
            // Fallback: do nothing if pattern matching fails
        }
    }

    /**
     * Invalidate statistics for users who might be affected by a daily work change
     */
    private function invalidateStatisticsForRelevantUsers(int $dailyWorkId): void
    {
        try {
            $dailyWork = DailyWork::find($dailyWorkId);
            
            if ($dailyWork) {
                // Invalidate for incharge
                if ($dailyWork->incharge) {
                    Cache::forget(self::STATS_PREFIX . "_user_{$dailyWork->incharge}");
                }
                
                // Invalidate for assigned user
                if ($dailyWork->assigned) {
                    Cache::forget(self::STATS_PREFIX . "_user_{$dailyWork->assigned}");
                }
                
                // Invalidate for admin users (they see all data)
                $adminIds = User::role(['Super Administratoristrator', 'Administrator'])->pluck('id');
                foreach ($adminIds as $adminId) {
                    Cache::forget(self::STATS_PREFIX . "_user_{$adminId}");
                }
            }
        } catch (\Exception $e) {
            // If invalidation fails, continue
        }
    }
}
