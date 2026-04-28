<?php

namespace App\Http\Controllers;

use App\Services\ApiResponseService;
use App\Services\DailyWork\DailyWorkAuditService;
use Illuminate\Http\Request;

class DailyWorkAuditController extends Controller
{
    private DailyWorkAuditService $auditService;

    public function __construct(DailyWorkAuditService $auditService)
    {
        $this->auditService = $auditService;
    }

    /**
     * Get audit trail for a specific daily work
     */
    public function getAuditTrail(Request $request, int $dailyWorkId)
    {
        try {
            $filters = $this->buildFilters($request);
            $auditTrail = $this->auditService->getAuditTrail($dailyWorkId, $filters);

            return ApiResponseService::success($auditTrail, 'Audit trail retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve audit trail: ' . $e->getMessage());
        }
    }

    /**
     * Get audit statistics for a specific daily work
     */
    public function getAuditStatistics(Request $request, int $dailyWorkId)
    {
        try {
            $statistics = $this->auditService->getAuditStatistics($dailyWorkId);

            return ApiResponseService::success($statistics, 'Audit statistics retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve audit statistics: ' . $e->getMessage());
        }
    }

    /**
     * Get system-wide audit statistics
     */
    public function getSystemStatistics(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $statistics = $this->auditService->getSystemAuditStatistics($filters);

            return ApiResponseService::success($statistics, 'System audit statistics retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve system audit statistics: ' . $e->getMessage());
        }
    }

    /**
     * Get recent audit activity
     */
    public function getRecentActivity(Request $request)
    {
        try {
            $limit = min($request->get('limit', 20), 100); // Cap at 100
            $filters = $this->buildFilters($request);
            $activity = $this->auditService->getRecentActivity($limit, $filters);

            return ApiResponseService::success($activity, 'Recent audit activity retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve recent activity: ' . $e->getMessage());
        }
    }

    /**
     * Search audit logs
     */
    public function searchAudits(Request $request)
    {
        try {
            $searchParams = $this->buildSearchParams($request);
            $results = $this->auditService->searchAudits($searchParams);

            return ApiResponseService::success($results, 'Audit search completed successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to search audits: ' . $e->getMessage());
        }
    }

    /**
     * Export audit logs
     */
    public function exportAudits(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $exportData = $this->auditService->exportAudits($filters);

            return ApiResponseService::export(
                $exportData['audits'],
                'audit_logs_' . now()->format('Y-m-d_H-i-s') . '.json',
                'json',
                'Audit logs exported successfully'
            );
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to export audit logs: ' . $e->getMessage());
        }
    }

    /**
     * Cleanup old audit logs
     */
    public function cleanupAudits(Request $request)
    {
        try {
            $request->validate([
                'days_to_keep' => 'required|integer|min:7|max:365',
            ]);

            $daysToKeep = $request->get('days_to_keep');
            $deletedCount = $this->auditService->cleanupOldAudits($daysToKeep);

            return ApiResponseService::success([
                'deleted_count' => $deletedCount,
                'days_kept' => $daysToKeep,
            ], 'Audit cleanup completed successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to cleanup audit logs: ' . $e->getMessage());
        }
    }

    /**
     * Get audit analytics dashboard
     */
    public function getAuditDashboard(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            
            $systemStats = $this->auditService->getSystemAuditStatistics($filters);
            $recentActivity = $this->auditService->getRecentActivity(10, $filters);

            $dashboard = [
                'overview' => $systemStats,
                'recent_activity' => $recentActivity,
                'key_metrics' => [
                    'total_audits' => $systemStats['total_audits'],
                    'bulk_operations' => $systemStats['bulk_operations'],
                    'critical_actions' => $systemStats['critical_actions'],
                    'audit_growth_rate' => $this->calculateAuditGrowthRate($filters),
                ],
                'action_distribution' => $systemStats['actions_by_type'],
                'source_distribution' => $systemStats['actions_by_source'],
                'trend_data' => $this->getAuditTrends($filters),
                'generated_at' => now()->toISOString(),
            ];

            return ApiResponseService::success($dashboard, 'Audit dashboard retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve audit dashboard: ' . $e->getMessage());
        }
    }

    /**
     * Get mobile-optimized audit data
     */
    public function getMobileAuditData(Request $request)
    {
        try {
            $filters = $this->buildFilters($request);
            $recentActivity = $this->auditService->getRecentActivity(10, $filters);
            $systemStats = $this->auditService->getSystemAuditStatistics($filters);

            $mobileData = [
                'recent_activity' => array_map(function ($activity) {
                    return [
                        'id' => $activity['id'],
                        'action' => $activity['action'],
                        'description' => $activity['description'],
                        'user_name' => $activity['user']['name'] ?? 'System',
                        'created_at' => $activity['created_at'],
                        'is_critical' => in_array($activity['action'], [
                            'deleted', 'bulk_deleted', 'objection_created'
                        ]),
                    ];
                }, $recentActivity),
                'summary' => [
                    'total_audits' => $systemStats['total_audits'],
                    'critical_actions' => $systemStats['critical_actions'],
                    'bulk_operations' => $systemStats['bulk_operations'],
                ],
                'last_updated' => now()->toISOString(),
            ];

            return ApiResponseService::success($mobileData, 'Mobile audit data retrieved successfully');
        } catch (\Exception $e) {
            return ApiResponseService::serverError('Failed to retrieve mobile audit data: ' . $e->getMessage());
        }
    }

    /**
     * Build filters from request
     */
    private function buildFilters(Request $request): array
    {
        return [
            'start_date' => $request->get('start_date'),
            'end_date' => $request->get('end_date'),
            'user_id' => $request->get('user_id'),
            'action' => $request->get('action'),
            'source' => $request->get('source'),
            'per_page' => $request->get('per_page', 50),
        ];
    }

    /**
     * Build search parameters from request
     */
    private function buildSearchParams(Request $request): array
    {
        return [
            'query' => $request->get('query'),
            'start_date' => $request->get('start_date'),
            'end_date' => $request->get('end_date'),
            'user_id' => $request->get('user_id'),
            'action' => $request->get('action'),
            'source' => $request->get('source'),
            'is_bulk' => $request->get('is_bulk'),
            'per_page' => min($request->get('per_page', 50), 100),
        ];
    }

    /**
     * Calculate audit growth rate
     */
    private function calculateAuditGrowthRate(array $filters): float
    {
        // Simplified growth rate calculation
        // In a real implementation, this would compare current period vs previous period
        return 15.5; // Placeholder value
    }

    /**
     * Get audit trends
     */
    private function getAuditTrends(array $filters): array
    {
        // Simplified trend data
        // In a real implementation, this would calculate actual trends from historical data
        return [
            'daily_trend' => [
                ['date' => now()->subDays(6)->format('Y-m-d'), 'count' => 45],
                ['date' => now()->subDays(5)->format('Y-m-d'), 'count' => 52],
                ['date' => now()->subDays(4)->format('Y-m-d'), 'count' => 48],
                ['date' => now()->subDays(3)->format('Y-m-d'), 'count' => 61],
                ['date' => now()->subDays(2)->format('Y-m-d'), 'count' => 55],
                ['date' => now()->subDays(1)->format('Y-m-d'), 'count' => 58],
                ['date' => now()->format('Y-m-d'), 'count' => 63],
            ],
            'action_trends' => [
                'created' => 'increasing',
                'updated' => 'stable',
                'deleted' => 'decreasing',
                'status_changed' => 'stable',
            ],
        ];
    }
}
