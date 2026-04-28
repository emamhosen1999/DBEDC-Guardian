<?php

namespace App\Http\Controllers;

use App\Services\ApiResponseService;
use App\Services\DailyWork\DailyWorkAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DailyWorkBusinessIntelligenceController extends Controller
{
    private DailyWorkAnalyticsService $analyticsService;

    public function __construct(DailyWorkAnalyticsService $analyticsService)
    {
        $this->analyticsService = $analyticsService;
    }

    /**
     * Get comprehensive business intelligence dashboard
     */
    public function dashboard(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $dashboardData = $this->analyticsService->getBusinessIntelligenceDashboard($filters);

            return ApiResponseService::success($dashboardData, 'Business intelligence dashboard retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve business intelligence dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Get performance metrics
     */
    public function performanceMetrics(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $metrics = $this->analyticsService->getPerformanceMetrics($filters);

            return ApiResponseService::success($metrics, 'Performance metrics retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve performance metrics: ' . $e->getMessage());
        }
    }

    /**
     * Get trend analysis
     */
    public function trendAnalysis(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $trends = $this->analyticsService->getTrendAnalysis($filters);

            return ApiResponseService::success($trends, 'Trend analysis retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve trend analysis: ' . $e->getMessage());
        }
    }

    /**
     * Get productivity insights
     */
    public function productivityInsights(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $insights = $this->analyticsService->getProductivityInsights($filters);

            return ApiResponseService::success($insights, 'Productivity insights retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve productivity insights: ' . $e->getMessage());
        }
    }

    /**
     * Get quality metrics
     */
    public function qualityMetrics(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $metrics = $this->analyticsService->getQualityMetrics($filters);

            return ApiResponseService::success($metrics, 'Quality metrics retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve quality metrics: ' . $e->getMessage());
        }
    }

    /**
     * Get resource utilization
     */
    public function resourceUtilization(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $utilization = $this->analyticsService->getResourceUtilization($filters);

            return ApiResponseService::success($utilization, 'Resource utilization data retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve resource utilization: ' . $e->getMessage());
        }
    }

    /**
     * Get forecasting data
     */
    public function forecasting(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $forecasting = $this->analyticsService->getForecastingData($filters);

            return ApiResponseService::success($forecasting, 'Forecasting data retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve forecasting data: ' . $e->getMessage());
        }
    }

    /**
     * Get anomaly detection
     */
    public function anomalyDetection(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $anomalies = $this->analyticsService->getAnomalyDetection($filters);

            return ApiResponseService::success($anomalies, 'Anomaly detection data retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve anomaly detection data: ' . $e->getMessage());
        }
    }

    /**
     * Get executive summary
     */
    public function executiveSummary(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            
            // Get key metrics for executive dashboard
            $performanceMetrics = $this->analyticsService->getPerformanceMetrics($filters);
            $trendAnalysis = $this->analyticsService->getTrendAnalysis($filters);
            $qualityMetrics = $this->analyticsService->getQualityMetrics($filters);
            $productivityInsights = $this->analyticsService->getProductivityInsights($filters);

            $summary = [
                'key_performance_indicators' => [
                    'completion_rate' => $performanceMetrics['completion_rate'],
                    'efficiency_score' => $performanceMetrics['efficiency_score'],
                    'on_time_delivery' => $performanceMetrics['on_time_delivery'],
                    'quality_rate' => $qualityMetrics['first_pass_yield'],
                ],
                'trend_highlights' => [
                    'growth_rate' => $trendAnalysis['growth_rate'],
                    'volume_trend' => 'increasing', // Simplified for demo
                    'quality_trend' => 'stable', // Simplified for demo
                ],
                'top_performers' => $productivityInsights['top_performers'],
                'improvement_areas' => $productivityInsights['improvement_opportunities'],
                'alerts' => $this->generateExecutiveAlerts($performanceMetrics, $qualityMetrics),
                'generated_at' => now()->toISOString(),
            ];

            return ApiResponseService::success($summary, 'Executive summary retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve executive summary: ' . $e->getMessage());
        }
    }

    /**
     * Get mobile-optimized BI data
     */
    public function mobileDashboard(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            
            // Get simplified mobile-friendly data
            $performanceMetrics = $this->analyticsService->getPerformanceMetrics($filters);
            $trendAnalysis = $this->analyticsService->getTrendAnalysis($filters);

            $mobileData = [
                'performance' => [
                    'completion_rate' => $performanceMetrics['completion_rate'],
                    'efficiency' => $performanceMetrics['efficiency_score'],
                    'throughput' => $performanceMetrics['throughput'],
                ],
                'trends' => [
                    'growth_rate' => $trendAnalysis['growth_rate'],
                    'forecast_accuracy' => $trendAnalysis['forecast_accuracy'],
                ],
                'alerts' => $this->generateMobileAlerts($performanceMetrics),
                'last_updated' => now()->toISOString(),
            ];

            return ApiResponseService::success($mobileData, 'Mobile BI dashboard retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve mobile BI dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Build filters from request
     */
    private function buildFilters(Request $request): array
    {
        $user = Auth::user();
        $filters = [
            'period' => $request->get('period', 30),
            'start_date' => $request->get('start_date'),
            'end_date' => $request->get('end_date'),
            'user_id' => $request->get('user_id'),
            'team_id' => $request->get('team_id'),
            'project_id' => $request->get('project_id'),
        ];

        // Apply user role-based filtering
        if (!$user->hasRole(['Super Administratoristrator', 'Administrator'])) {
            $filters['user_id'] = $user->id;
        }

        return $filters;
    }

    /**
     * Generate executive alerts
     */
    private function generateExecutiveAlerts(array $performanceMetrics, array $qualityMetrics): array
    {
        $alerts = [];

        if ($performanceMetrics['completion_rate'] < 70) {
            $alerts[] = [
                'type' => 'warning',
                'message' => 'Completion rate is below target',
                'metric' => 'completion_rate',
                'value' => $performanceMetrics['completion_rate'],
            ];
        }

        if ($qualityMetrics['first_pass_yield'] < 80) {
            $alerts[] = [
                'type' => 'critical',
                'message' => 'First pass yield is below acceptable level',
                'metric' => 'first_pass_yield',
                'value' => $qualityMetrics['first_pass_yield'],
            ];
        }

        if ($performanceMetrics['efficiency_score'] < 60) {
            $alerts[] = [
                'type' => 'warning',
                'message' => 'Efficiency score needs improvement',
                'metric' => 'efficiency_score',
                'value' => $performanceMetrics['efficiency_score'],
            ];
        }

        return $alerts;
    }

    /**
     * Generate mobile-friendly alerts
     */
    private function generateMobileAlerts(array $performanceMetrics): array
    {
        $alerts = [];

        if ($performanceMetrics['completion_rate'] < 70) {
            $alerts[] = [
                'type' => 'performance',
                'title' => 'Low Completion Rate',
                'message' => 'Current: ' . $performanceMetrics['completion_rate'] . '%',
                'priority' => 'medium',
            ];
        }

        if ($performanceMetrics['efficiency_score'] < 60) {
            $alerts[] = [
                'type' => 'efficiency',
                'title' => 'Efficiency Alert',
                'message' => 'Score: ' . $performanceMetrics['efficiency_score'] . '%',
                'priority' => 'high',
            ];
        }

        return $alerts;
    }
}
