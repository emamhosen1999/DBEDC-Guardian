<?php

namespace App\Http\Controllers;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Traits\DailyWorkFilterable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class DailyWorkSummaryController extends Controller
{
    use DailyWorkFilterable;

    public function index()
    {
        $user = User::with(['designation', 'roles'])->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;
        $userRoles = $user->roles->pluck('name')->toArray();
        $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);

        // Get daily works based on user role
        $query = DailyWork::with(['inchargeUser', 'assignedUser']);

        if (! $isAdmin && $userDesignationTitle === 'Supervision Engineer') {
            $query->where('incharge', $user->id);
        }

        $dailyWorks = $query->get();
        $summaries = $this->generateSummariesFromDailyWorks($dailyWorks);

        // Calculate date boundaries from actual data (same as DailyWorks)
        $overallStartDate = DailyWork::min('date');
        $overallEndDate = DailyWork::max('date');

        // Get incharges - only for admins
        $inCharges = $isAdmin
            ? User::whereHas('designation', fn ($q) => $q->where('title', 'Supervision Engineer'))->get()
            : collect();

        return Inertia::render('Project/DailyWorkSummary', [
            'summary' => $summaries,
            'jurisdictions' => Jurisdiction::all(),
            'inCharges' => $inCharges,
            'overallStartDate' => $overallStartDate,
            'overallEndDate' => $overallEndDate,
            'title' => 'Daily Work Summary',
        ]);
    }

    public function filterSummary(Request $request)
    {
        $user = User::with(['designation', 'roles'])->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;
        $userRoles = $user->roles->pluck('name')->toArray();
        $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);

        try {
            $query = DailyWork::with(['inchargeUser', 'assignedUser']);

            // Apply user role filter
            if (! $isAdmin && $userDesignationTitle === 'Supervision Engineer') {
                $query->where('incharge', $user->id);
            }

            // Apply filters using trait methods for consistency
            $this->applyDateRangeFilter($query, $request->input('startDate'), $request->input('endDate'));
            $this->applyMonthFilter($query, $request->input('month'));
            $this->applyStatusFilter($query, $request->input('status'));
            $this->applyTypeFilter($query, $request->input('type'));
            $this->applySearchFilter($query, $request->input('search'));

            $inchargeFilter = $this->normalizeIdFilter($request->input('incharge'));
            $jurisdictionFilter = $this->normalizeIdFilter($request->input('jurisdiction'));

            $this->applyInchargeJurisdictionFilters($query, $inchargeFilter, $jurisdictionFilter);

            $filteredWorks = $query->get();
            $summaries = $this->generateSummariesFromDailyWorks($filteredWorks);

            return response()->json([
                'summaries' => $summaries,
                'message' => 'Summary filtered successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'An error occurred while filtering summary: '.$e->getMessage(),
            ], 500);
        }
    }

    public function exportDailySummary(Request $request)
    {
        $user = User::with(['designation', 'roles'])->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;
        $userRoles = $user->roles->pluck('name')->toArray();
        $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);

        try {
            $query = DailyWork::with(['inchargeUser', 'assignedUser']);

            // Apply user role filter
            if (! $isAdmin && $userDesignationTitle === 'Supervision Engineer') {
                $query->where('incharge', $user->id);
            }

            // Apply filters using trait methods for consistency
            $this->applyDateRangeFilter($query, $request->input('startDate'), $request->input('endDate'));
            $this->applyStatusFilter($query, $request->input('status'));
            $this->applyTypeFilter($query, $request->input('type'));
            $this->applySearchFilter($query, $request->input('search'));

            $inchargeFilter = $this->normalizeIdFilter($request->input('incharge'));
            $jurisdictionFilter = $this->normalizeIdFilter($request->input('jurisdiction'));

            $this->applyInchargeJurisdictionFilters($query, $inchargeFilter, $jurisdictionFilter);

            $dailyWorks = $query->get();
            $summaries = $this->generateSummariesFromDailyWorks($dailyWorks);

            // Export logic here - could be Excel, PDF, CSV
            return response()->json([
                'data' => $summaries,
                'message' => 'Export data prepared successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Export failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Generate summaries from daily works collection
     */
    private function generateSummariesFromDailyWorks($dailyWorks)
    {
        // Group by date
        $groupedByDate = $dailyWorks->groupBy('date');

        $summaries = [];

        foreach ($groupedByDate as $date => $works) {
            $totalWorks = $works->count();

            // Properly count completed (including composite statuses like 'completed:pass')
            $completed = $works->filter(function ($work) {
                return $work->status === 'completed' || str_starts_with($work->status ?? '', 'completed:');
            })->count();

            // Count pending statuses correctly (not just totalWorks - completed)
            $pending = $works->whereIn('status', ['new', 'pending', 'in-progress', 'resubmission'])->count();
            $inProgress = $works->where('status', 'in-progress')->count();
            $rejected = $works->where('status', 'rejected')->count();
            $emergency = $works->where('status', 'emergency')->count();

            $rfiSubmissions = $works->whereNotNull('rfi_submission_date')->count();

            // Group by type
            $typeBreakdown = $works->groupBy('type');

            // RFI percentage against completed (more meaningful metric)
            $rfiSubmissionPercentage = $completed > 0 ? round(($rfiSubmissions / $completed) * 100, 1) : 0;

            $summary = [
                'date' => $date,
                'totalDailyWorks' => $totalWorks,
                'completed' => $completed,
                'pending' => $pending,
                'inProgress' => $inProgress,
                'rejected' => $rejected,
                'emergency' => $emergency,
                'rfiSubmissions' => $rfiSubmissions,
                'completionPercentage' => $totalWorks > 0 ? round(($completed / $totalWorks) * 100, 1) : 0,
                'rfiSubmissionPercentage' => $rfiSubmissionPercentage,
                'embankment' => $typeBreakdown->get('Embankment', collect())->count(),
                'structure' => $typeBreakdown->get('Structure', collect())->count(),
                'pavement' => $typeBreakdown->get('Pavement', collect())->count(),
                'resubmissions' => $works->where('resubmission_count', '>', 0)->count(),
            ];

            $summaries[] = $summary;
        }

        // Sort by date descending
        usort($summaries, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        return $summaries;
    }

    /**
     * Get comprehensive statistics for current user's daily works
     */
    public function getStatistics(Request $request)
    {
        $user = User::with(['designation', 'roles'])->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;
        $userRoles = $user->roles->pluck('name')->toArray();

        $query = DailyWork::query();

        // Check if user is Super Administrator or Administrator
        $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);

        // Filter based on user role
        if ($isAdmin) {
            // Super Administrator and Administrator get all data - no filtering
            // Query remains unfiltered to get all daily works
        } elseif ($userDesignationTitle === 'Supervision Engineer') {
            // Get works where user is incharge
            $query->where('incharge', $user->id);
        } else {
            // Get works where user is assigned or incharge
            $query->where(function ($q) use ($user) {
                $q->where('assigned', $user->id)
                    ->orWhere('incharge', $user->id);
            });
        }

        // Apply date range if provided
        if ($request->has('startDate') && $request->has('endDate')) {
            $query->whereBetween('date', [$request->startDate, $request->endDate]);
        }

        $dailyWorks = $query->get();

        // Calculate comprehensive statistics
        $totalWorks = $dailyWorks->count();

        // Status counts - handle both formats (e.g., 'completed' or 'completed:pass')
        $completedWorks = $dailyWorks->filter(function ($work) {
            return $work->status === 'completed' || str_starts_with($work->status ?? '', 'completed:');
        })->count();

        $pendingWorks = $dailyWorks->whereIn('status', ['new', 'pending', 'resubmission', 'in-progress'])->count();
        $inProgressWorks = $dailyWorks->where('status', 'in-progress')->count();
        $newWorks = $dailyWorks->where('status', 'new')->count();
        $emergencyWorks = $dailyWorks->where('status', 'emergency')->count();
        $resubmissionWorks = $dailyWorks->where('status', 'resubmission')->count();

        // Inspection results - match the actual values used in the model
        $passedInspections = $dailyWorks->filter(function ($work) {
            return in_array($work->inspection_result, ['pass', 'approved']);
        })->count();

        $failedInspections = $dailyWorks->filter(function ($work) {
            return in_array($work->inspection_result, ['fail', 'rejected']);
        })->count();

        $conditionalInspections = $dailyWorks->where('inspection_result', 'conditional')->count();
        $pendingInspections = $dailyWorks->where('inspection_result', 'pending')->count();

        // RFI and resubmission metrics
        $rfiSubmissions = $dailyWorks->whereNotNull('rfi_submission_date')->count();
        $worksWithResubmissions = $dailyWorks->where('resubmission_count', '>', 0)->count();
        $totalResubmissions = (int) $dailyWorks->sum('resubmission_count');

        // Time metrics
        $worksWithCompletionTime = $dailyWorks->whereNotNull('completion_time')->count();

        // Type breakdown
        $embankmentCount = $dailyWorks->filter(fn ($w) => stripos($w->type ?? '', 'embankment') !== false)->count();
        $structureCount = $dailyWorks->filter(fn ($w) => stripos($w->type ?? '', 'structure') !== false)->count();
        $pavementCount = $dailyWorks->filter(fn ($w) => stripos($w->type ?? '', 'pavement') !== false)->count();

        // Recent activity
        $todayWorks = $dailyWorks->filter(fn ($w) => $w->date?->isToday())->count();
        $thisWeekWorks = $dailyWorks->filter(fn ($w) => $w->date?->isCurrentWeek())->count();
        $thisMonthWorks = $dailyWorks->filter(fn ($w) => $w->date?->isCurrentMonth())->count();

        // Performance indicators
        $completionRate = $totalWorks > 0 ? round(($completedWorks / $totalWorks) * 100, 1) : 0;

        $totalInspected = $passedInspections + $failedInspections + $conditionalInspections;
        $inspectionPassRate = $totalInspected > 0
            ? round(($passedInspections / $totalInspected) * 100, 1)
            : 0;

        $rfiRate = $totalWorks > 0 ? round(($rfiSubmissions / $totalWorks) * 100, 1) : 0;

        // First-time pass rate (works that passed without resubmissions)
        $firstTimePassCount = $dailyWorks->filter(function ($work) {
            return in_array($work->inspection_result, ['pass', 'approved'])
                && ($work->resubmission_count ?? 0) === 0;
        })->count();
        $firstTimePassRate = $completedWorks > 0
            ? round(($firstTimePassCount / $completedWorks) * 100, 1)
            : 0;

        $stats = [
            'overview' => [
                'totalWorks' => $totalWorks,
                'completedWorks' => $completedWorks,
                'pendingWorks' => $pendingWorks,
                'inProgressWorks' => $inProgressWorks,
                'newWorks' => $newWorks,
                'emergencyWorks' => $emergencyWorks,
            ],
            'statusBreakdown' => [
                'new' => $newWorks,
                'in_progress' => $inProgressWorks,
                'completed' => $completedWorks,
                'resubmission' => $resubmissionWorks,
                'emergency' => $emergencyWorks,
            ],
            'typeBreakdown' => [
                'embankment' => $embankmentCount,
                'structure' => $structureCount,
                'pavement' => $pavementCount,
            ],
            'qualityMetrics' => [
                'rfiSubmissions' => $rfiSubmissions,
                'worksWithResubmissions' => $worksWithResubmissions,
                'totalResubmissions' => $totalResubmissions,
                'passedInspections' => $passedInspections,
                'failedInspections' => $failedInspections,
                'conditionalInspections' => $conditionalInspections,
                'pendingInspections' => $pendingInspections,
            ],
            'timeMetrics' => [
                'worksWithCompletionTime' => $worksWithCompletionTime,
                'averageResubmissions' => $worksWithResubmissions > 0
                    ? round($totalResubmissions / $worksWithResubmissions, 1)
                    : 0,
            ],
            'recentActivity' => [
                'todayWorks' => $todayWorks,
                'thisWeekWorks' => $thisWeekWorks,
                'thisMonthWorks' => $thisMonthWorks,
            ],
            'userRole' => [
                'designation' => $userDesignationTitle,
                'isIncharge' => $userDesignationTitle === 'Supervision Engineer',
                'totalAsIncharge' => $dailyWorks->where('incharge', $user->id)->count(),
                'totalAsAssigned' => $dailyWorks->where('assigned', $user->id)->count(),
            ],
            'performanceIndicators' => [
                'completionRate' => $completionRate,
                'inspectionPassRate' => $inspectionPassRate,
                'firstTimePassRate' => $firstTimePassRate,
                'rfiRate' => $rfiRate,
                'qualityRate' => $inspectionPassRate, // Alias for backward compatibility
            ],
        ];

        return response()->json($stats);
    }

    /**
     * Refresh is not needed anymore since we calculate on-the-fly
     */
    public function refresh(Request $request)
    {
        return response()->json([
            'message' => 'Summary is automatically calculated from current data - no refresh needed',
        ]);
    }

    public function dailySummary()
    {
        // Legacy method - can be removed or updated
        return $this->index();
    }
}
