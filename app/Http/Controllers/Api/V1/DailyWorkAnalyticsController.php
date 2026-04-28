<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DailyWork\DailyWorkAnalyticsService;
use Illuminate\Http\Request;

class DailyWorkAnalyticsController extends Controller
{
    public function __construct(private DailyWorkAnalyticsService $analyticsService) {}

    /**
     * Get completion rate statistics.
     */
    public function completionRates(Request $request)
    {
        $filters = $this->extractFilters($request);

        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->getCompletionRateStats($filters),
        ]);
    }

    /**
     * Get bottleneck analysis.
     */
    public function bottlenecks(Request $request)
    {
        $filters = $this->extractFilters($request);

        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->getBottleneckAnalysis($filters),
        ]);
    }

    /**
     * Get trend analysis.
     */
    public function trends(Request $request)
    {
        $filters = $this->extractFilters($request);
        $days = $request->get('days', 30);

        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->getTrendAnalysis($days, $filters),
        ]);
    }

    /**
     * Get dashboard summary.
     */
    public function dashboard(Request $request)
    {
        $filters = $this->extractFilters($request);

        return response()->json([
            'success' => true,
            'data' => $this->analyticsService->getDashboardSummary($filters),
        ]);
    }

    /**
     * Extract common filters from request.
     */
    private function extractFilters(Request $request): array
    {
        return [
            'start_date' => $request->get('start_date'),
            'end_date' => $request->get('end_date'),
            'incharge_id' => $request->get('incharge_id'),
            'assigned_id' => $request->get('assigned_id'),
            'type' => $request->get('type'),
            'status' => $request->get('status'),
        ];
    }
}