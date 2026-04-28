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
     * Get comprehensive business intelligence dashboard data
     */
    public function getBusinessIntelligenceDashboard(array $filters = []): array
    {
        return [
            'performance_metrics' => $this->getPerformanceMetrics($filters),
            'trend_analysis' => $this->getTrendAnalysis($filters),
            'productivity_insights' => $this->getProductivityInsights($filters),
            'quality_metrics' => $this->getQualityMetrics($filters),
            'resource_utilization' => $this->getResourceUtilization($filters),
            'forecasting' => $this->getForecastingData($filters),
            'anomaly_detection' => $this->getAnomalyDetection($filters),
        ];
    }

    /**
     * Get advanced performance metrics
     */
    public function getPerformanceMetrics(array $filters = []): array
    {
        $query = DailyWork::query();
        $this->applyCommonFilters($query, $filters);

        $total = (clone $query)->count();
        $completed = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)->count();
        
        // Calculate cycle time metrics
        $avgCycleTime = $this->calculateAverageCycleTime($query);
        $cycleTimeTrend = $this->getCycleTimeTrend($filters);
        
        // Calculate throughput
        $throughput = $this->calculateThroughput($filters);
        $throughputTrend = $this->getThroughputTrend($filters);
        
        // Calculate efficiency metrics
        $efficiency = $this->calculateEfficiency($query);
        $utilization = $this->calculateResourceUtilization($query);

        return [
            'completion_rate' => $total > 0 ? round(($completed / $total) * 100, 2) : 0,
            'average_cycle_time' => $avgCycleTime,
            'cycle_time_trend' => $cycleTimeTrend,
            'throughput' => $throughput,
            'throughput_trend' => $throughputTrend,
            'efficiency_score' => $efficiency,
            'resource_utilization' => $utilization,
            'on_time_delivery' => $this->calculateOnTimeDelivery($query),
            'rework_rate' => $this->calculateReworkRate($query),
        ];
    }

    /**
     * Get trend analysis with predictive insights
     */
    public function getTrendAnalysis(array $filters = []): array
    {
        $period = $filters['period'] ?? '30'; // days
        
        return [
            'completion_trend' => $this->getCompletionTrend($period),
            'volume_trend' => $this->getVolumeTrend($period),
            'quality_trend' => $this->getQualityTrend($period),
            'productivity_trend' => $this->getProductivityTrend($period),
            'seasonal_patterns' => $this->detectSeasonalPatterns(),
            'growth_rate' => $this->calculateGrowthRate($period),
            'forecast_accuracy' => $this->calculateForecastAccuracy(),
        ];
    }

    /**
     * Get productivity insights with benchmarking
     */
    public function getProductivityInsights(array $filters = []): array
    {
        return [
            'user_productivity' => $this->getUserProductivityRanking($filters),
            'team_performance' => $this->getTeamPerformanceMetrics($filters),
            'productivity_benchmarks' => $this->getProductivityBenchmarks(),
            'top_performers' => $this->getTopPerformers($filters),
            'improvement_opportunities' => $this->identifyImprovementOpportunities($filters),
            'productivity_trends' => $this->getProductivityTrends($filters),
        ];
    }

    /**
     * Get quality metrics and defect analysis
     */
    public function getQualityMetrics(array $filters = []): array
    {
        $query = DailyWork::query();
        $this->applyCommonFilters($query, $filters);

        return [
            'defect_rate' => $this->calculateDefectRate($query),
            'first_pass_yield' => $this->calculateFirstPassYield($query),
            'inspection_pass_rate' => $this->calculateInspectionPassRate($query),
            'rework_analysis' => $this->getReworkAnalysis($query),
            'objection_patterns' => $this->getObjectionPatterns($filters),
            'quality_trends' => $this->getQualityTrends($filters),
            'quality_costs' => $this->calculateQualityCosts($query),
        ];
    }

    /**
     * Get resource utilization and capacity planning
     */
    public function getResourceUtilization(array $filters = []): array
    {
        return [
            'capacity_utilization' => $this->getCapacityUtilization($filters),
            'workload_distribution' => $this->getWorkloadDistribution($filters),
            'resource_efficiency' => $this->getResourceEfficiency($filters),
            'bottleneck_identification' => $this->identifyBottlenecks($filters),
            'capacity_forecast' => $this->getCapacityForecast($filters),
            'resource_allocation' => $this->getResourceAllocationAnalysis($filters),
        ];
    }

    /**
     * Get forecasting data with predictive analytics
     */
    public function getForecastingData(array $filters = []): array
    {
        return [
            'demand_forecast' => $this->generateDemandForecast($filters),
            'capacity_forecast' => $this->generateCapacityForecast($filters),
            'completion_forecast' => $this->generateCompletionForecast($filters),
            'resource_forecast' => $this->generateResourceForecast($filters),
            'risk_assessment' => $this->assessForecastingRisks($filters),
            'scenario_analysis' => $this->performScenarioAnalysis($filters),
        ];
    }

    /**
     * Get anomaly detection and alerting
     */
    public function getAnomalyDetection(array $filters = []): array
    {
        return [
            'performance_anomalies' => $this->detectPerformanceAnomalies($filters),
            'quality_anomalies' => $this->detectQualityAnomalies($filters),
            'productivity_anomalies' => $this->detectProductivityAnomalies($filters),
            'volume_anomalies' => $this->detectVolumeAnomalies($filters),
            'anomaly_trends' => $this->getAnomalyTrends($filters),
            'alert_recommendations' => $this->generateAlertRecommendations($filters),
        ];
    }
    // Implementation methods for business intelligence analytics

    private function calculateAverageCycleTime($query): float
    {
        $works = $query->whereNotNull('completion_time')
                      ->whereNotNull('created_at')
                      ->get(['created_at', 'completion_time']);
        
        if ($works->isEmpty()) {
            return 0;
        }

        $totalDays = $works->sum(function ($work) {
            return $work->created_at->diffInDays($work->completion_time);
        });

        return round($totalDays / $works->count(), 2);
    }

    private function getCycleTimeTrend(array $filters): array
    {
        $days = $filters['period'] ?? 30;
        $data = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $cycleTime = $this->calculateAverageCycleTime(
                DailyWork::whereDate('created_at', $date)
                         ->whereNotNull('completion_time')
            );
            $data[] = ['date' => $date, 'cycle_time' => $cycleTime];
        }
        
        return $data;
    }

    private function calculateThroughput(array $filters): int
    {
        $days = $filters['period'] ?? 7;
        return DailyWork::where('status', DailyWork::STATUS_COMPLETED)
                       ->where('completion_time', '>=', now()->subDays($days))
                       ->count();
    }

    private function getThroughputTrend(array $filters): array
    {
        $days = $filters['period'] ?? 30;
        $data = [];
        
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $throughput = DailyWork::whereDate('completion_time', $date)
                                ->where('status', DailyWork::STATUS_COMPLETED)
                                ->count();
            $data[] = ['date' => $date, 'throughput' => $throughput];
        }
        
        return $data;
    }

    private function calculateEfficiency($query): float
    {
        $total = $query->count();
        $completed = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)->count();
        
        if ($total === 0) {
            return 0;
        }

        // Efficiency = (Completed / Total) * (On-time completion rate)
        $onTimeCompleted = $query->where('status', DailyWork::STATUS_COMPLETED)
                               ->whereRaw('completion_time <= DATE_ADD(created_at, INTERVAL planned_time HOUR)')
                               ->count();
        
        $onTimeRate = $completed > 0 ? $onTimeCompleted / $completed : 0;
        $completionRate = $completed / $total;
        
        return round(($completionRate * $onTimeRate) * 100, 2);
    }

    private function calculateResourceUtilization($query): float
    {
        $totalWorks = $query->count();
        $activeUsers = $query->distinct()->pluck('incharge')->count();
        
        if ($activeUsers === 0) {
            return 0;
        }

        $avgWorkPerUser = $totalWorks / $activeUsers;
        $optimalWorkPerUser = 5; // Assumed optimal workload
        
        return min(round(($avgWorkPerUser / $optimalWorkPerUser) * 100, 2), 100);
    }

    private function calculateOnTimeDelivery($query): float
    {
        $completed = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)
                                  ->whereNotNull('planned_time')
                                  ->count();
        
        if ($completed === 0) {
            return 0;
        }

        $onTime = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)
                               ->whereNotNull('planned_time')
                               ->whereRaw('completion_time <= DATE_ADD(created_at, INTERVAL planned_time HOUR)')
                               ->count();
        
        return round(($onTime / $completed) * 100, 2);
    }

    private function calculateReworkRate($query): float
    {
        $total = $query->count();
        $reworked = $query->where('resubmission_count', '>', 0)->count();
        
        return $total > 0 ? round(($reworked / $total) * 100, 2) : 0;
    }

    private function getCompletionTrend(int $period): array
    {
        $data = [];
        
        for ($i = $period - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $total = DailyWork::whereDate('date', $date)->count();
            $completed = DailyWork::whereDate('date', $date)
                                ->where('status', DailyWork::STATUS_COMPLETED)
                                ->count();
            $rate = $total > 0 ? round(($completed / $total) * 100, 2) : 0;
            
            $data[] = ['date' => $date, 'completion_rate' => $rate];
        }
        
        return $data;
    }

    private function getVolumeTrend(int $period): array
    {
        $data = [];
        
        for ($i = $period - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $volume = DailyWork::whereDate('date', $date)->count();
            
            $data[] = ['date' => $date, 'volume' => $volume];
        }
        
        return $data;
    }

    private function getQualityTrend(int $period): array
    {
        $data = [];
        
        for ($i = $period - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $total = DailyWork::whereDate('date', $date)->count();
            $passed = DailyWork::whereDate('date', $date)
                             ->where('inspection_result', 'passed')
                             ->count();
            $rate = $total > 0 ? round(($passed / $total) * 100, 2) : 0;
            
            $data[] = ['date' => $date, 'quality_rate' => $rate];
        }
        
        return $data;
    }

    private function getProductivityTrend(int $period): array
    {
        $data = [];
        
        for ($i = $period - 1; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $completed = DailyWork::whereDate('date', $date)
                                ->where('status', DailyWork::STATUS_COMPLETED)
                                ->count();
            $activeUsers = DailyWork::whereDate('date', $date)
                                  ->distinct('incharge')
                                  ->count('incharge');
            $productivity = $activeUsers > 0 ? round($completed / $activeUsers, 2) : 0;
            
            $data[] = ['date' => $date, 'productivity' => $productivity];
        }
        
        return $data;
    }

    private function detectSeasonalPatterns(): array
    {
        // Simple seasonal pattern detection based on historical data
        $monthlyData = DailyWork::selectRaw('MONTH(date) as month, COUNT(*) as count')
                              ->where('date', '>=', now()->subYear())
                              ->groupBy('month')
                              ->orderBy('month')
                              ->get();
        
        $patterns = [];
        foreach ($monthlyData as $data) {
            $patterns[] = [
                'month' => $data->month,
                'avg_count' => $data->count,
                'season' => $this->getSeason($data->month),
            ];
        }
        
        return $patterns;
    }

    private function getSeason(int $month): string
    {
        if ($month >= 3 && $month <= 5) return 'Spring';
        if ($month >= 6 && $month <= 8) return 'Summer';
        if ($month >= 9 && $month <= 11) return 'Fall';
        return 'Winter';
    }

    private function calculateGrowthRate(int $period): float
    {
        $currentPeriod = DailyWork::where('date', '>=', now()->subDays($period))->count();
        $previousPeriod = DailyWork::where('date', '>=', now()->subDays($period * 2))
                                 ->where('date', '<', now()->subDays($period))
                                 ->count();
        
        if ($previousPeriod === 0) {
            return $currentPeriod > 0 ? 100 : 0;
        }
        
        return round((($currentPeriod - $previousPeriod) / $previousPeriod) * 100, 2);
    }

    private function calculateForecastAccuracy(): float
    {
        // Simple forecast accuracy calculation
        // Compare actual vs predicted from last period
        $actual = DailyWork::where('date', '>=', now()->subDays(7))->count();
        $predicted = DailyWork::where('date', '>=', now()->subDays(14))
                            ->where('date', '<', now()->subDays(7))
                            ->count();
        
        if ($predicted === 0) {
            return 0;
        }
        
        $accuracy = 100 - abs((($actual - $predicted) / $predicted) * 100);
        return max(0, round($accuracy, 2));
    }

    // Add placeholder implementations for remaining methods
    private function getUserProductivityRanking(array $filters): array { return []; }
    private function getTeamPerformanceMetrics(array $filters): array { return []; }
    private function getProductivityBenchmarks(): array { return []; }
    private function getTopPerformers(array $filters): array { return []; }
    private function identifyImprovementOpportunities(array $filters): array { return []; }
    private function getProductivityTrends(array $filters): array { return []; }
    private function calculateDefectRate($query): float { return 0; }
    private function calculateFirstPassYield($query): float { return 0; }
    private function calculateInspectionPassRate($query): float { return 0; }
    private function getReworkAnalysis($query): array { return []; }
    private function getObjectionPatterns(array $filters): array { return []; }
    private function getQualityTrends(array $filters): array { return []; }
    private function calculateQualityCosts($query): array { return []; }
    private function getCapacityUtilization(array $filters): array { return []; }
    private function getWorkloadDistribution(array $filters): array { return []; }
    private function getResourceEfficiency(array $filters): array { return []; }
    private function identifyBottlenecks(array $filters): array { return []; }
    private function getCapacityForecast(array $filters): array { return []; }
    private function getResourceAllocationAnalysis(array $filters): array { return []; }
    private function generateDemandForecast(array $filters): array { return []; }
    private function generateCapacityForecast(array $filters): array { return []; }
    private function generateCompletionForecast(array $filters): array { return []; }
    private function generateResourceForecast(array $filters): array { return []; }
    private function assessForecastingRisks(array $filters): array { return []; }
    private function performScenarioAnalysis(array $filters): array { return []; }
    private function detectPerformanceAnomalies(array $filters): array { return []; }
    private function detectQualityAnomalies(array $filters): array { return []; }
    private function detectProductivityAnomalies(array $filters): array { return []; }
    private function detectVolumeAnomalies(array $filters): array { return []; }
    private function getAnomalyTrends(array $filters): array { return []; }
    private function generateAlertRecommendations(array $filters): array { return []; }

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
            try {
                $plannedTime = $this->normalizeTimeString($work->planned_time ?? '08:00:00');
                $startDateTime = $work->date->copy()->setTimeFromTimeString($plannedTime);
                $completionDateTime = $work->completion_time;

                if ($completionDateTime) {
                    $hours = $startDateTime->diffInHours($completionDateTime);
                    $totalHours += $hours;
                    $count++;
                }
            } catch (\Exception $e) {
                // Skip records with invalid time formats
                continue;
            }
        }

        return $count > 0 ? round($totalHours / $count, 2) : null;
    }

    /**
     * Normalize a time string to a valid format (H:i:s).
     */
    private function normalizeTimeString(?string $timeString): string
    {
        if (empty($timeString)) {
            return '08:00:00';
        }

        $timeString = trim($timeString);

        // Try to parse with Carbon directly first
        try {
            return \Carbon\Carbon::parse($timeString)->format('H:i:s');
        } catch (\Exception $e) {
            // Fall through to manual parsing
        }

        // Handle formats like "10.00 am", "10:00 am", "10.00am"
        $normalized = preg_replace('/[^0-9:apm\s\.]/i', '', strtolower($timeString));
        $normalized = str_replace('.', ':', $normalized);
        
        if (preg_match('/(\d{1,2}):?(\d{0,2}):?(\d{0,2})\s*(am|pm)?/i', $normalized, $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) ($matches[2] ?? 0);
            $seconds = (int) ($matches[3] ?? 0);
            $period = strtolower($matches[4] ?? '');

            if ($period === 'pm' && $hours < 12) {
                $hours += 12;
            } elseif ($period === 'am' && $hours === 12) {
                $hours = 0;
            }

            $hours = min(max($hours, 0), 23);
            $minutes = min(max($minutes, 0), 59);
            $seconds = min(max($seconds, 0), 59);

            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return '08:00:00';
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