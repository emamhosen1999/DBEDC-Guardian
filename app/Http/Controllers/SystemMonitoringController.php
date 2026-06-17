<?php

namespace App\Http\Controllers;

use App\Services\Logging\ApplicationLogger;
use App\Services\Monitoring\DatabaseAnalyticsService;
use App\Services\Monitoring\LogParserService;
use App\Services\Monitoring\SecurityMonitoringService;
use App\Services\Monitoring\SystemHealthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * System Monitoring Controller
 * Provides comprehensive system monitoring dashboard using existing UI patterns
 */
class SystemMonitoringController extends Controller
{
    protected SystemHealthService $systemHealthService;

    protected DatabaseAnalyticsService $databaseAnalyticsService;

    protected SecurityMonitoringService $securityMonitoringService;

    protected LogParserService $logParserService;

    public function __construct(
        SystemHealthService $systemHealthService,
        DatabaseAnalyticsService $databaseAnalyticsService,
        SecurityMonitoringService $securityMonitoringService,
        LogParserService $logParserService
    ) {
        $this->systemHealthService = $systemHealthService;
        $this->databaseAnalyticsService = $databaseAnalyticsService;
        $this->securityMonitoringService = $securityMonitoringService;
        $this->logParserService = $logParserService;
    }

    /**
     * Display the system monitoring dashboard
     */
    public function index()
    {
        try {
            $logger = new ApplicationLogger;
            $logger->logUserAction('System Monitoring Dashboard Accessed');
        } catch (\Exception $e) {
            Log::info('System Monitoring Dashboard Accessed');
        }

        return Inertia::render('Administration/SystemMonitoringEnhanced', [
            'title' => 'Enterprise System Monitoring',
            'initialData' => $this->getSystemOverview(),
        ]);
    }

    /**
     * Get system overview data
     */
    public function getSystemOverview()
    {
        return Cache::remember('system_overview', 300, function () {
            return [
                'performance_summary' => $this->systemHealthService->getPerformanceSummary(),
                'error_summary' => $this->logParserService->getErrorSummary(),
                'user_activity' => $this->securityMonitoringService->getUserActivitySummary(),
                'system_health' => $this->systemHealthService->getSystemHealthCheck(),
                'database_stats' => $this->databaseAnalyticsService->getComprehensiveDatabaseStats(),
                'system_resources' => $this->systemHealthService->getSystemResources(),
                'security_metrics' => $this->securityMonitoringService->getSecurityMetrics(),
                'capacity_planning' => $this->systemHealthService->getCapacityPlanningData(),
                'service_availability' => $this->systemHealthService->getServiceAvailability(),
                'compliance_metrics' => $this->securityMonitoringService->getComplianceMetrics(),
            ];
        });
    }

    /**
     * Get real-time metrics API
     */
    public function getMetrics(Request $request)
    {
        $type = $request->get('type', 'overview');
        $period = $request->get('period', '24h');

        switch ($type) {
            case 'performance':
                return response()->json($this->systemHealthService->getPerformanceMetrics($period));
            case 'errors':
                return response()->json($this->logParserService->getErrorMetrics($period));
            case 'users':
                return response()->json($this->securityMonitoringService->getUserMetrics($period));
            case 'system':
                return response()->json($this->systemHealthService->getSystemMetrics());
            default:
                return response()->json($this->getSystemOverview());
        }
    }

    /**
     * Generate comprehensive optimization report
     */
    public function getOptimizationReport()
    {
        return [
            'dependencies' => $this->systemHealthService->analyzeDependencies(),
            'database_optimization' => $this->databaseAnalyticsService->getDatabaseOptimizationSuggestions(),
            'file_system' => $this->systemHealthService->analyzeFileSystem($this->logParserService),
            'performance_bottlenecks' => $this->databaseAnalyticsService->identifyPerformanceBottlenecks(),
            'security_recommendations' => $this->securityMonitoringService->getSecurityOptimizations(),
            'cache_analysis' => $this->systemHealthService->analyzeCacheUsage(),
            'recommendations' => $this->systemHealthService->generateOptimizationRecommendations(),
        ];
    }
}
