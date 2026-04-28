<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Services\DailyWork\DailyWorkAnalyticsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\DailyWorksExport;
use Barryvdh\DomPDF\Facade\Pdf;

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

        // Build base query with all filters
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

        // Clone query for total count before aggregation
        $total = $query->count();

        // Apply aggregation to the filtered query
        $summaryQuery = $query
            ->selectRaw('
                date,
                COUNT(*) as totalDailyWorks,
                SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN LOWER(type) = "embankment" THEN 1 ELSE 0 END) as embankment,
                SUM(CASE WHEN LOWER(type) = "structure" THEN 1 ELSE 0 END) as structure,
                SUM(CASE WHEN LOWER(type) = "pavement" THEN 1 ELSE 0 END) as pavement,
                SUM(COALESCE(resubmission_count, 0)) as resubmissions,
                SUM(CASE WHEN rfi_submission_date IS NOT NULL THEN 1 ELSE 0 END) as rfiSubmissions
            ')
            ->groupBy('date')
            ->orderBy('date', 'desc');

        // Get paginated results
        $summaryData = $summaryQuery->paginate($perPage, ['*'], 'page', $page);

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
        $columns = $request->get('columns', []);
        $exportType = $request->get('export_type', 'summary'); // 'summary' or 'detailed'

        // Build query with filters
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

        $fileName = 'daily_works_export_' . date('Y-m-d_H-i-s');

        // Export based on type
        if ($exportType === 'summary') {
            // Aggregate data by date for summary export
            $summaryQuery = clone $query;
            $data = $summaryQuery
                ->selectRaw('
                    date,
                    COUNT(*) as totalDailyWorks,
                    SUM(CASE WHEN status = "Completed" THEN 1 ELSE 0 END) as completed,
                    SUM(CASE WHEN LOWER(type) = "embankment" THEN 1 ELSE 0 END) as embankment,
                    SUM(CASE WHEN LOWER(type) = "structure" THEN 1 ELSE 0 END) as structure,
                    SUM(CASE WHEN LOWER(type) = "pavement" THEN 1 ELSE 0 END) as pavement,
                    SUM(COALESCE(resubmission_count, 0)) as resubmissions,
                    SUM(CASE WHEN rfi_submission_date IS NOT NULL THEN 1 ELSE 0 END) as rfiSubmissions
                ')
                ->groupBy('date')
                ->orderBy('date', 'desc')
                ->get()
                ->toArray();

            // Filter columns if specified
            if (!empty($columns)) {
                $data = array_map(function($item) use ($columns) {
                    return array_intersect_key($item, array_flip($columns));
                }, $data);
            }

            $fileName = 'daily_works_summary_export_' . date('Y-m-d_H-i-s');

            // Export based on format
            switch ($format) {
                case 'xlsx':
                    return Excel::download(new DailyWorksSummaryExport($data, $columns), $fileName . '.xlsx');
                case 'pdf':
                    $pdf = Pdf::loadView('exports.daily_works_summary_pdf', [
                        'data' => $data,
                        'columns' => $columns,
                        'fileName' => $fileName
                    ])
                    ->setPaper('a4', 'landscape')
                    ->setOption('margin-top', '20mm')
                    ->setOption('margin-bottom', '15mm')
                    ->setOption('margin-left', '15mm')
                    ->setOption('margin-right', '15mm');
                    return $pdf->download($fileName . '.pdf');
                case 'csv':
                default:
                    return Excel::download(new DailyWorksSummaryExport($data, $columns), $fileName . '.csv');
            }
        } else {
            // Detailed export - individual records
            $data = $query->with(['inchargeUser', 'assignedUser'])
                ->orderBy('date', 'desc')
                ->get()
                ->toArray();

            // Filter columns if specified
            if (!empty($columns)) {
                $data = array_map(function($item) use ($columns) {
                    $filtered = [];
                    foreach ($columns as $column) {
                        if (str_contains($column, '.')) {
                            $keys = explode('.', $column);
                            $value = $item;
                            foreach ($keys as $key) {
                                $value = $value[$key] ?? '';
                            }
                            $filtered[$column] = $value;
                        } else {
                            $filtered[$column] = $item[$column] ?? '';
                        }
                    }
                    return $filtered;
                }, $data);
            }

            // Export based on format
            switch ($format) {
                case 'xlsx':
                    return Excel::download(new DailyWorksExport($data, $columns), $fileName . '.xlsx');
                case 'pdf':
                    $pdf = Pdf::loadView('exports.daily_works_pdf', [
                        'data' => $data,
                        'columns' => $columns,
                        'fileName' => $fileName
                    ]);
                    return $pdf->download($fileName . '.pdf');
                case 'csv':
                default:
                    return Excel::download(new DailyWorksExport($data, $columns), $fileName . '.csv');
            }
        }
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
