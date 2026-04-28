<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\DailyWork\DailyWorkCacheService;
use Illuminate\Support\Facades\Auth;

class DailyWorkMobileService
{
    private DailyWorkCacheService $cacheService;

    public function __construct(DailyWorkCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }

    /**
     * Get optimized mobile data for a specific date
     * Uses lean data structure and efficient caching
     */
    public function getMobileDataForDate(string $date): array
    {
        $cacheKey = "mobile_daily_works_date_{$date}_user_" . Auth::id();
        
        return \Cache::remember($cacheKey, 1800, function () use ($date) {
            return $this->fetchMobileDataForDate($date);
        });
    }

    /**
     * Get recent mobile data with pagination
     * Optimized for mobile bandwidth
     */
    public function getRecentMobileData(int $page = 1, int $perPage = 20): array
    {
        $cacheKey = "mobile_recent_works_page_{$page}_user_" . Auth::id();
        
        return \Cache::remember($cacheKey, 900, function () use ($page, $perPage) {
            return $this->fetchRecentMobileData($page, $perPage);
        });
    }

    /**
     * Get mobile-friendly statistics
     */
    public function getMobileStatistics(): array
    {
        $cacheKey = "mobile_stats_user_" . Auth::id();
        
        return \Cache::remember($cacheKey, 1800, function () {
            return $this->fetchMobileStatistics();
        });
    }

    /**
     * Get mobile-optimized daily work details
     */
    public function getMobileWorkDetails(int $dailyWorkId): array
    {
        $cacheKey = "mobile_work_details_{$dailyWorkId}_user_" . Auth::id();
        
        return \Cache::remember($cacheKey, 3600, function () use ($dailyWorkId) {
            return $this->fetchMobileWorkDetails($dailyWorkId);
        });
    }

    /**
     * Fetch mobile data for specific date
     */
    private function fetchMobileDataForDate(string $date): array
    {
        $query = DailyWork::with(['inchargeUser:id,name', 'assignedUser:id,name'])
            ->whereDate('date', $date);

        // Apply user role filters
        $this->applyUserFilters($query);

        $works = $query->orderBy('created_at', 'desc')->get();

        return [
            'date' => $date,
            'total_count' => $works->count(),
            'works' => $works->map(fn($work) => $this->transformForMobile($work))->toArray(),
            'summary' => $this->generateMobileSummary($works),
            'cached_at' => now()->toISOString(),
        ];
    }

    /**
     * Fetch recent mobile data with pagination
     */
    private function fetchRecentMobileData(int $page, int $perPage): array
    {
        $query = DailyWork::with(['inchargeUser:id,name', 'assignedUser:id,name'])
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc');

        // Apply user role filters
        $this->applyUserFilters($query);

        $paginated = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'data' => collect($paginated->items())->map(fn($work) => $this->transformForMobile($work))->toArray(),
            'pagination' => [
                'current_page' => $paginated->currentPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
                'last_page' => $paginated->lastPage(),
            ],
            'cached_at' => now()->toISOString(),
        ];
    }

    /**
     * Fetch mobile statistics
     */
    private function fetchMobileStatistics(): array
    {
        $query = DailyWork::query();
        $this->applyUserFilters($query);

        $today = now()->format('Y-m-d');
        $thisWeekStart = now()->startOfWeek()->format('Y-m-d');
        $thisMonthStart = now()->startOfMonth()->format('Y-m-d');

        return [
            'today' => [
                'total' => (clone $query)->whereDate('date', $today)->count(),
                'completed' => (clone $query)->whereDate('date', $today)->where('status', 'completed')->count(),
                'pending' => (clone $query)->whereDate('date', $today)->whereIn('status', ['new', 'pending', 'in-progress'])->count(),
            ],
            'this_week' => [
                'total' => (clone $query)->whereDate('date', '>=', $thisWeekStart)->count(),
                'completed' => (clone $query)->whereDate('date', '>=', $thisWeekStart)->where('status', 'completed')->count(),
            ],
            'this_month' => [
                'total' => (clone $query)->whereDate('date', '>=', $thisMonthStart)->count(),
                'completed' => (clone $query)->whereDate('date', '>=', $thisMonthStart)->where('status', 'completed')->count(),
            ],
            'overall' => [
                'total' => $query->count(),
                'completed' => $query->where('status', 'completed')->count(),
                'completion_rate' => $this->calculateCompletionRate($query),
            ],
            'cached_at' => now()->toISOString(),
        ];
    }

    /**
     * Fetch mobile work details
     */
    private function fetchMobileWorkDetails(int $dailyWorkId): array
    {
        $work = DailyWork::with([
            'inchargeUser:id,name,email',
            'assignedUser:id,name,email',
            'reports:id,name',
            'objections' => function ($q) {
                $q->whereIn('status', ['draft', 'submitted', 'under_review'])
                   ->with('createdBy:id,name');
            }
        ])->findOrFail($dailyWorkId);

        // Check if user can access this work
        if (!$this->canAccessWork($work)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Access denied');
        }

        return [
            'work' => $this->transformDetailedForMobile($work),
            'objections' => $work->objections->map(fn($objection) => [
                'id' => $objection->id,
                'title' => $objection->title,
                'category' => $objection->category,
                'status' => $objection->status,
                'created_by' => $objection->createdBy->name,
                'created_at' => $objection->created_at->toISOString(),
            ])->toArray(),
            'files' => $work->getRfiFilesAttribute(),
            'cached_at' => now()->toISOString(),
        ];
    }

    /**
     * Transform daily work for mobile (lean structure)
     */
    private function transformForMobile(DailyWork $work): array
    {
        return [
            'id' => $work->id,
            'number' => $work->number,
            'status' => $work->status,
            'type' => $work->type,
            'location' => $work->location,
            'description' => $work->description,
            'date' => $work->date?->format('Y-m-d') ?? 'N/A',
            'incharge' => $work->inchargeUser?->name,
            'assigned' => $work->assignedUser?->name,
            'completion_time' => $work->completion_time?->format('Y-m-d H:i'),
            'rfi_submission_date' => $work->rfi_submission_date?->format('Y-m-d'),
            'active_objections_count' => $work->active_objections_count,
            'has_active_objections' => $work->has_active_objections,
            'priority' => $this->calculatePriority($work),
        ];
    }

    /**
     * Transform detailed work for mobile
     */
    private function transformDetailedForMobile(DailyWork $work): array
    {
        return [
            'id' => $work->id,
            'number' => $work->number,
            'status' => $work->status,
            'type' => $work->type,
            'description' => $work->description,
            'location' => $work->location,
            'side' => $work->side,
            'qty_layer' => $work->qty_layer,
            'planned_time' => $work->planned_time,
            'date' => $work->date?->format('Y-m-d') ?? 'N/A',
            'incharge' => [
                'id' => $work->inchargeUser?->id,
                'name' => $work->inchargeUser?->name,
                'email' => $work->inchargeUser?->email,
            ],
            'assigned' => [
                'id' => $work->assignedUser?->id,
                'name' => $work->assignedUser?->name,
                'email' => $work->assignedUser?->email,
            ],
            'completion_time' => $work->completion_time?->format('Y-m-d H:i'),
            'rfi_submission_date' => $work->rfi_submission_date?->format('Y-m-d'),
            'inspection_result' => $work->inspection_result,
            'inspection_details' => $work->inspection_details,
            'resubmission_count' => $work->resubmission_count,
            'reports' => $work->reports->map(fn($report) => [
                'id' => $report->id,
                'name' => $report->name,
            ])->toArray(),
            'active_objections_count' => $work->active_objections_count,
            'has_active_objections' => $work->has_active_objections,
            'created_at' => $work->created_at->toISOString(),
            'updated_at' => $work->updated_at->toISOString(),
        ];
    }

    /**
     * Generate mobile summary
     */
    private function generateMobileSummary($works): array
    {
        $total = $works->count();
        $completed = $works->where('status', 'completed')->count();
        $pending = $works->whereIn('status', ['new', 'pending', 'in-progress'])->count();
        $withObjections = $works->filter(fn($w) => $w->active_objections_count > 0)->count();

        return [
            'total' => $total,
            'completed' => $completed,
            'pending' => $pending,
            'with_objections' => $withObjections,
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
        ];
    }

    /**
     * Apply user role filters to query
     */
    private function applyUserFilters($query): void
    {
        $user = User::with(['roles', 'designation'])->find(Auth::id());
        
        if (!$user) {
            return;
        }

        if ($user->hasRole(['Super Administratoristrator', 'Administrator'])) {
            // Admin sees all data
        } elseif ($user->designation?->title === 'Supervision Engineer') {
            $query->where('incharge', Auth::id());
        } else {
            $query->where(function ($q) {
                $q->where('assigned', Auth::id())
                  ->orWhere('incharge', Auth::id());
            });
        }
    }

    /**
     * Check if user can access a specific work
     */
    private function canAccessWork(DailyWork $work): bool
    {
        $user = User::with(['roles', 'designation'])->find(Auth::id());
        
        if (!$user) {
            return false;
        }

        if ($user->hasRole(['Super Administratoristrator', 'Administrator'])) {
            return true;
        }

        if ($work->incharge === Auth::id() || $work->assigned === Auth::id()) {
            return true;
        }

        return false;
    }

    /**
     * Calculate completion rate
     */
    private function calculateCompletionRate($query): float
    {
        $total = $query->count();
        $completed = (clone $query)->where('status', 'completed')->count();
        
        return $total > 0 ? round(($completed / $total) * 100, 1) : 0;
    }

    /**
     * Calculate priority for mobile display
     */
    private function calculatePriority(DailyWork $work): string
    {
        if ($work->active_objections_count > 0) {
            return 'high';
        }
        
        if ($work->status === 'emergency') {
            return 'high';
        }
        
        if ($work->status === 'new' || $work->status === 'resubmission') {
            return 'medium';
        }
        
        return 'low';
    }

    /**
     * Invalidate mobile caches for user
     */
    public function invalidateMobileCaches(?int $userId = null): void
    {
        $userId = $userId ?? Auth::id();
        
        // Invalidate all mobile-related caches for the user
        $patterns = [
            "mobile_daily_works_date_*_user_{$userId}",
            "mobile_recent_works_page_*_user_{$userId}",
            "mobile_stats_user_{$userId}",
            "mobile_work_details_*_user_{$userId}",
        ];
        
        foreach ($patterns as $pattern) {
            // Use direct cache invalidation since invalidatePattern is private
            \Cache::forget($pattern);
        }
    }
}
