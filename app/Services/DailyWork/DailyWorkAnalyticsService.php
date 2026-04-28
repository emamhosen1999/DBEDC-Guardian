<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyWorkAnalyticsService
{
    private \App\Services\Cache\ReferenceDataCacheService $cacheService;

    public function __construct(\App\Services\Cache\ReferenceDataCacheService $cacheService)
    {
        $this->cacheService = $cacheService;
    }
    /**
     * Get completion rate statistics.
     */
    public function getCompletionRateStats(array $filters = []): array
    {
        $query = DailyWork::query();

        $this->applyCommonFilters($query, $filters);

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)->count();
        $inProgress = (clone $query)->where('status', DailyWork::STATUS_IN_PROGRESS)->count();
        $overdue = $this->getOverdueCount($query);

        $completionRate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;

        return [
            'total_work_items' => $total,
            'completed_items' => $completed,
            'in_progress_items' => $inProgress,
            'overdue_items' => $overdue,
            'completion_rate_percentage' => $completionRate,
            'average_completion_time_days' => $this->getAverageCompletionTime($query),
        ];
    }

    /**
     * Get bottleneck analysis.
     */
    public function getBottleneckAnalysis(array $filters = []): array
    {
        $query = DailyWork::query();
        $this->applyCommonFilters($query, $filters);

        $bottlenecks = [
            'by_status' => $this->getStatusDistribution($query),
            'by_type' => $this->getTypeDistribution($query),
            'by_location' => $this->getLocationDistribution($query),
            'by_user' => $this->getUserWorkload($query),
            'objection_impacted' => $this->getObjectionImpact($query),
        ];

        return $bottlenecks;
    }

    /**
     * Get trend analysis for work types and statuses.
     */
    public function getTrendAnalysis(int $days = 30, array $filters = []): array
    {
        $startDate = now()->subDays($days);

        $trends = [
            'daily_completion_trend' => $this->getDailyCompletionTrend($startDate, $filters),
            'status_changes_over_time' => $this->getStatusChangeTrends($startDate, $filters),
            'type_distribution_trend' => $this->getTypeTrend($startDate, $filters),
        ];

        return $trends;
    }

    /**
     * Get dashboard summary metrics.
     */
    public function getDashboardSummary(array $filters = []): array
    {
        $query = DailyWork::query();
        $this->applyCommonFilters($query, $filters);

        return [
            'summary' => $this->getCompletionRateStats($filters),
            'urgent_items' => [
                'overdue_count' => $this->getOverdueCount($query),
                'blocked_by_objections' => $this->getBlockedByObjectionsCount($query),
                'escalated_count' => (clone $query)->where('status', DailyWork::STATUS_EMERGENCY)->count(),
            ],
            'recent_activity' => $this->getRecentActivity($filters),
            'performance_indicators' => $this->getPerformanceIndicators($filters),
        ];
    }

    /**
     * Apply common filters to query.
     */
    private function applyCommonFilters($query, array $filters): void
    {
        if (isset($filters['start_date'])) {
            $query->where('date', '>=', $filters['start_date']);
        }

        if (isset($filters['end_date'])) {
            $query->where('date', '<=', $filters['end_date']);
        }

        if (isset($filters['incharge_id'])) {
            $query->where('incharge', $filters['incharge_id']);
        }

        if (isset($filters['assigned_id'])) {
            $query->where('assigned', $filters['assigned_id']);
        }

        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
    }

    /**
     * Get overdue count.
     */
    private function getOverdueCount($query): int
    {
        return (clone $query)
            ->where('status', DailyWork::STATUS_IN_PROGRESS)
            ->whereNotNull('planned_time')
            ->whereNotNull('date')
            ->where(function ($q) {
                $q->where(function ($subQuery) {
                    $subQuery->where('date', now()->toDateString())
                             ->where('planned_time', '<', now()->format('H:i:s'));
                })
                ->orWhere('date', '<', now()->toDateString());
            })
            ->count();
    }

    /**
     * Get average completion time.
     */
    private function getAverageCompletionTime($query): ?float
    {
        $completedWork = (clone $query)
            ->where('status', DailyWork::STATUS_COMPLETED)
            ->whereNotNull('completion_time')
            ->whereNotNull('date')
            ->get();

        if ($completedWork->isEmpty()) {
            return null;
        }

        $totalHours = 0;
        $count = 0;

        foreach ($completedWork as $work) {
            $startDateTime = $work->date->copy()->setTimeFromTimeString($work->planned_time ?? '08:00:00');
            $completionDateTime = $work->completion_time;

            if ($completionDateTime) {
                $hours = $startDateTime->diffInHours($completionDateTime);
                $totalHours += $hours;
                $count++;
            }
        }

        return $count > 0 ? round($totalHours / $count, 2) : null;
    }

    /**
     * Get status distribution.
     */
    private function getStatusDistribution($query): Collection
    {
        return (clone $query)
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                return [
                    'status' => $item->status,
                    'count' => $item->count,
                    'percentage' => 0, // Will be calculated after total is known
                ];
            });
    }

    /**
     * Get type distribution.
     */
    private function getTypeDistribution($query): Collection
    {
        return (clone $query)
            ->select('type', DB::raw('count(*) as count'))
            ->groupBy('type')
            ->orderBy('count', 'desc')
            ->get();
    }

    /**
     * Get location distribution.
     */
    private function getLocationDistribution($query): Collection
    {
        return (clone $query)
            ->select('location', DB::raw('count(*) as count'))
            ->groupBy('location')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Get user workload distribution.
     */
    private function getUserWorkload($query): Collection
    {
        return (clone $query)
            ->join('users as incharge', 'daily_works.incharge', '=', 'incharge.id')
            ->select('incharge.name', DB::raw('count(*) as count'))
            ->groupBy('incharge.id', 'incharge.name')
            ->orderBy('count', 'desc')
            ->limit(10)
            ->get();
    }

    /**
     * Get objection impact analysis.
     */
    private function getObjectionImpact($query): array
    {
        $totalWork = (clone $query)->count();
        $workWithObjections = (clone $query)->whereHas('activeObjections')->count();

        return [
            'total_work_items' => $totalWork,
            'items_with_active_objections' => $workWithObjections,
            'objection_impact_percentage' => $totalWork > 0 ? round(($workWithObjections / $totalWork) * 100, 2) : 0,
        ];
    }

    /**
     * Get daily completion trend.
     */
    private function getDailyCompletionTrend($startDate, array $filters): Collection
    {
        $query = DailyWork::query()
            ->select(DB::raw('date(date) as completion_date'), DB::raw('count(*) as completed_count'))
            ->where('status', DailyWork::STATUS_COMPLETED)
            ->where('date', '>=', $startDate);

        $this->applyCommonFilters($query, $filters);

        return $query->groupBy('completion_date')
                    ->orderBy('completion_date')
                    ->get();
    }

    /**
     * Get status change trends.
     */
    private function getStatusChangeTrends($startDate, array $filters): array
    {
        // This would require a status change log table in a real implementation
        // For now, return a simplified version
        return [
            'note' => 'Status change trends require audit logging implementation',
            'placeholder_data' => [
                'new_to_in_progress' => 0,
                'in_progress_to_completed' => 0,
                'completed_to_resubmission' => 0,
            ],
        ];
    }

    /**
     * Get type trend over time.
     */
    private function getTypeTrend($startDate, array $filters): Collection
    {
        $query = DailyWork::query()
            ->select('type', DB::raw('count(*) as count'))
            ->where('date', '>=', $startDate);

        $this->applyCommonFilters($query, $filters);

        return $query->groupBy('type')
                    ->orderBy('count', 'desc')
                    ->get();
    }

    /**
     * Get recent activity summary.
     */
    private function getRecentActivity(array $filters): array
    {
        $recentWork = DailyWork::query()
            ->with(['inchargeUser:id,name', 'assignedUser:id,name'])
            ->orderBy('updated_at', 'desc')
            ->limit(5);

        $this->applyCommonFilters($recentWork, $filters);

        return $recentWork->get()->map(function ($work) {
            return [
                'id' => $work->id,
                'number' => $work->number,
                'status' => $work->status,
                'type' => $work->type,
                'location' => $work->location,
                'incharge' => $work->inchargeUser?->name,
                'last_updated' => $work->updated_at->diffForHumans(),
            ];
        })->toArray();
    }

    /**
     * Get performance indicators.
     */
    private function getPerformanceIndicators(array $filters): array
    {
        $query = DailyWork::query();
        $this->applyCommonFilters($query, $filters);

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)->count();
        $overdue = $this->getOverdueCount($query);
        $blocked = $this->getBlockedByObjectionsCount($query);

        return [
            'on_time_completion_rate' => $total > 0 ? round((($completed - $overdue) / $total) * 100, 2) : 0,
            'blockage_rate' => $total > 0 ? round(($blocked / $total) * 100, 2) : 0,
            'efficiency_score' => $this->calculateEfficiencyScore($completed, $overdue, $blocked, $total),
        ];
    }

    /**
     * Get count of work blocked by objections.
     */
    private function getBlockedByObjectionsCount($query): int
    {
        return (clone $query)->whereHas('activeObjections')->count();
    }

    /**
     * Calculate efficiency score.
     */
    private function calculateEfficiencyScore(int $completed, int $overdue, int $blocked, int $total): float
    {
        if ($total === 0) {
            return 0;
        }

        // Simple efficiency score: completed / (total + overdue + blocked)
        $denominator = $total + $overdue + ($blocked * 2); // Blocked items count double
        return $denominator > 0 ? round(($completed / $denominator) * 100, 2) : 0;
    }
}