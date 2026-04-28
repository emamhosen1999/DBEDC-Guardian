<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Services\DailyWork\DailyWorkAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DailyWorksAnalyticsController extends Controller
{
    public function __construct(private DailyWorkAnalyticsService $analyticsService)
    {
    }

    /**
     * Display the daily works analytics page
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        
        // Check permission
        if (! $user->can('daily-works.view')) {
            abort(403, 'You do not have permission to view daily works analytics.');
        }

        return Inertia::render('Project/DailyWorksAnalytics', [
            'title' => 'Daily Works Analytics',
            'user' => $user,
            'csrfToken' => csrf_token(),
        ]);
    }

    /**
     * Get dashboard overview data
     */
    public function getDashboard(Request $request)
    {
        $user = Auth::user();
        
        if (! $user->can('daily-works.view')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $filters = $this->extractFilters($request);
        
        // Add period to filters for trend analysis
        $filters['period'] = $request->get('days', 30);

        $data = $this->analyticsService->getDashboardSummary($filters);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get summary table data with filters
     */
    public function getSummary(Request $request)
    {
        $user = Auth::user();
        
        if (! $user->can('daily-works.view')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $filters = $this->extractFilters($request);
        $perPage = $request->get('per_page', 15);
        $page = $request->get('page', 1);

        // Build query with filters
        $query = DailyWork::query();

        // Apply filters
        if (!empty($filters['start_date'])) {
            $query->whereDate('date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('date', '<=', $filters['end_date']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['incharge_id'])) {
            $query->where('incharge', $filters['incharge_id']);
        }
        if (!empty($filters['assigned_id'])) {
            $query->where('assigned', $filters['assigned_id']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('work_id', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        // Apply permission-based filtering
        if (! $user->can('daily-works.view') && $user->can('daily-works.own.view')) {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                  ->orWhere('assigned', $user->id);
            });
        }

        $total = $query->count();
        $summaryData = $query->with(['inchargeUser', 'assignedUser'])
            ->orderBy('date', 'desc')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'success' => true,
            'data' => $summaryData,
            'total' => $total,
        ]);
    }

    /**
     * Get analytics visualization data
     */
    public function getAnalytics(Request $request)
    {
        $user = Auth::user();
        
        if (! $user->can('daily-works.view')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $filters = $this->extractFilters($request);
        
        // Add period to filters for trend analysis
        $filters['period'] = $request->get('days', 30);

        $data = [
            'completion_rates' => $this->analyticsService->getCompletionRateStats($filters),
            'bottlenecks' => $this->analyticsService->getBottleneckAnalysis($filters),
            'trends' => $this->analyticsService->getTrendAnalysis($filters),
        ];

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Export filtered data
     */
    public function exportFiltered(Request $request)
    {
        $user = Auth::user();
        
        if (! $user->can('daily-works.export')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $filters = $this->extractFilters($request);
        $format = $request->get('format', 'csv');

        // Build query with filters (same as getSummary)
        $query = DailyWork::query();

        if (!empty($filters['start_date'])) {
            $query->whereDate('date', '>=', $filters['start_date']);
        }
        if (!empty($filters['end_date'])) {
            $query->whereDate('date', '<=', $filters['end_date']);
        }
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['incharge_id'])) {
            $query->where('incharge', $filters['incharge_id']);
        }
        if (!empty($filters['assigned_id'])) {
            $query->where('assigned', $filters['assigned_id']);
        }
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('work_id', 'like', "%{$search}%")
                  ->orWhere('location', 'like', "%{$search}%")
                  ->orWhere('type', 'like', "%{$search}%");
            });
        }

        // Apply permission-based filtering
        if (! $user->can('daily-works.view') && $user->can('daily-works.own.view')) {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                  ->orWhere('assigned', $user->id);
            });
        }

        $data = $query->with(['inchargeUser', 'assignedUser'])
            ->orderBy('date', 'desc')
            ->get();

        // Export logic (to be implemented based on format)
        // For now, return the data
        return response()->json([
            'success' => true,
            'data' => $data,
            'format' => $format,
        ]);
    }

    /**
     * Extract common filters from request
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
            'search' => $request->get('search'),
        ];
    }
}
