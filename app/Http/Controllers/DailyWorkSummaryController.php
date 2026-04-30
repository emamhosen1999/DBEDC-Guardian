<?php

namespace App\Http\Controllers;

use App\Exports\DailyWorkSummaryExport;
use App\Models\DailyWork;
use App\Models\Jurisdiction;
use App\Models\User;
use App\Traits\DailyWorkFilterable;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;

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

        // Universal logic: Show own works (incharge/assigned) AND manager's works (incharge) if report_to is set
        if (! $isAdmin && $user->report_to) {
            \Log::info('DailyWorkSummaryController index - User with manager', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'report_to' => $user->report_to,
                'designation' => $userDesignationTitle,
            ]);
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id)
                    ->orWhere('incharge', $user->report_to);
            });
        } elseif (! $isAdmin) {
            // Show only own works (incharge or assigned)
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);
            });
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

            // Universal logic: Show own works (incharge/assigned) AND manager's works (incharge) if report_to is set
            if (! $isAdmin && $user->report_to) {
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id)
                        ->orWhere('incharge', $user->report_to);
                });
            } elseif (! $isAdmin) {
                // Show only own works (incharge or assigned)
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);
                });
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

    /**
     * Build a base DailyWork query with common filtering rules.
     */
    private function buildFilteredQuery(Request $request)
    {
        $user = User::with(['designation', 'roles'])->find(Auth::id());
        $userDesignationTitle = $user->designation?->title;
        $userRoles = $user->roles->pluck('name')->toArray();
        $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);

        $query = DailyWork::with(['inchargeUser', 'assignedUser']);

        // Universal logic: Show own works (incharge/assigned) AND manager's works (incharge) if report_to is set
        if (! $isAdmin && $user->report_to) {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id)
                    ->orWhere('incharge', $user->report_to);
            });
        } elseif (! $isAdmin) {
            // Show only own works (incharge or assigned)
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);
            });
        }

        $this->applyDateRangeFilter($query, $request->input('startDate'), $request->input('endDate'));
        $this->applyMonthFilter($query, $request->input('month'));
        $this->applyStatusFilter($query, $request->input('status'));
        $this->applyTypeFilter($query, $request->input('type'));
        $this->applySearchFilter($query, $request->input('search'));

        // Only admins can apply incharge/jurisdiction filters
        $inchargeFilter = $isAdmin ? $this->normalizeIdFilter($request->input('incharge')) : null;
        $jurisdictionFilter = $isAdmin ? $this->normalizeIdFilter($request->input('jurisdiction')) : null;
        $this->applyInchargeJurisdictionFilters($query, $inchargeFilter, $jurisdictionFilter);

        return $query;
    }

    /**
     * Build chart-ready analytics data from filtered daily works.
     */
    public function getAnalytics(Request $request)
    {
        try {
            $query = $this->buildFilteredQuery($request);
            $works = $query->get();

            $analytics = $this->buildAnalyticsPayload($works);

            return response()->json($analytics);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Failed to load analytics: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Aggregate works into the structure consumed by the analytics UI.
     */
    private function buildAnalyticsPayload($works): array
    {
        $totalWorks = $works->count();

        $isCompleted = fn ($w) => $w->status === 'completed' || str_starts_with($w->status ?? '', 'completed:');
        $completed = $works->filter($isCompleted)->count();
        $pending = $works->whereIn('status', ['new', 'pending', 'in-progress', 'resubmission'])->count();
        $inProgress = $works->where('status', 'in-progress')->count();
        $rejected = $works->where('status', 'rejected')->count();
        $emergency = $works->where('status', 'emergency')->count();
        $rfiSubmissions = $works->whereNotNull('rfi_submission_date')->count();

        $totalResubmissions = (int) $works->sum('resubmission_count');
        $worksWithResubmissions = $works->where('resubmission_count', '>', 0)->count();

        $completionRate = $totalWorks > 0 ? round(($completed / $totalWorks) * 100, 1) : 0;
        $rfiRate = $totalWorks > 0 ? round(($rfiSubmissions / $totalWorks) * 100, 1) : 0;

        // Daily trend (one row per date with all relevant counts)
        $byDate = $works->groupBy(function ($w) {
            return $w->date instanceof \DateTimeInterface
                ? $w->date->format('Y-m-d')
                : (string) $w->date;
        });

        $dailyTrend = $byDate->map(function ($dayWorks, $date) use ($isCompleted) {
            $total = $dayWorks->count();
            $done = $dayWorks->filter($isCompleted)->count();
            $pend = $dayWorks->whereIn('status', ['new', 'pending', 'in-progress', 'resubmission'])->count();
            $rfis = $dayWorks->whereNotNull('rfi_submission_date')->count();
            $resubs = $dayWorks->where('resubmission_count', '>', 0)->count();

            return [
                'date' => $date,
                'total' => $total,
                'completed' => $done,
                'pending' => $pend,
                'rfiSubmissions' => $rfis,
                'resubmissions' => $resubs,
                'completionRate' => $total > 0 ? round(($done / $total) * 100, 1) : 0,
            ];
        })->sortBy('date')->values()->all();

        // Best/worst day
        $bestDay = collect($dailyTrend)->sortByDesc('completionRate')->first();
        $worstDay = collect($dailyTrend)->sortBy('completionRate')->first();
        $busiestDay = collect($dailyTrend)->sortByDesc('total')->first();

        // Type distribution
        $typeBreakdown = [
            ['name' => 'Embankment', 'value' => $works->filter(fn ($w) => stripos($w->type ?? '', 'embankment') !== false)->count(), 'color' => '#F59E0B'],
            ['name' => 'Structure', 'value' => $works->filter(fn ($w) => stripos($w->type ?? '', 'structure') !== false)->count(), 'color' => '#3B82F6'],
            ['name' => 'Pavement', 'value' => $works->filter(fn ($w) => stripos($w->type ?? '', 'pavement') !== false)->count(), 'color' => '#8B5CF6'],
        ];

        // Status distribution
        $statusBreakdown = [
            ['name' => 'Completed', 'value' => $completed, 'color' => '#17C964'],
            ['name' => 'In Progress', 'value' => $inProgress, 'color' => '#0070F0'],
            ['name' => 'Pending', 'value' => max(0, $pending - $inProgress), 'color' => '#F5A524'],
            ['name' => 'Rejected', 'value' => $rejected, 'color' => '#F31260'],
            ['name' => 'Emergency', 'value' => $emergency, 'color' => '#EF4444'],
        ];
        $statusBreakdown = array_values(array_filter($statusBreakdown, fn ($s) => $s['value'] > 0));

        // Per-incharge performance
        $inchargeGroups = $works->groupBy('incharge');
        $inchargePerformance = [];
        foreach ($inchargeGroups as $inchargeId => $inchargeWorks) {
            if (! $inchargeId) {
                continue;
            }
            $user = $inchargeWorks->first()->inchargeUser;
            $name = $user ? ($user->name ?? $user->user_name ?? 'User #'.$inchargeId) : 'Unknown';
            $tw = $inchargeWorks->count();
            $cw = $inchargeWorks->filter($isCompleted)->count();
            $rfi = $inchargeWorks->whereNotNull('rfi_submission_date')->count();
            $inchargePerformance[] = [
                'incharge' => $name,
                'total' => $tw,
                'completed' => $cw,
                'pending' => $tw - $cw,
                'rfiSubmissions' => $rfi,
                'completionRate' => $tw > 0 ? round(($cw / $tw) * 100, 1) : 0,
            ];
        }
        usort($inchargePerformance, fn ($a, $b) => $b['total'] <=> $a['total']);

        $topIncharge = ! empty($inchargePerformance)
            ? collect($inchargePerformance)->sortByDesc('completionRate')->first()
            : null;

        // Trend direction: compare first half vs second half completion rate
        $half = (int) floor(count($dailyTrend) / 2);
        $trendDirection = 0;
        if ($half > 0) {
            $firstHalf = array_slice($dailyTrend, 0, $half);
            $secondHalf = array_slice($dailyTrend, $half);
            $firstAvg = collect($firstHalf)->avg('completionRate') ?? 0;
            $secondAvg = collect($secondHalf)->avg('completionRate') ?? 0;
            $trendDirection = round($secondAvg - $firstAvg, 1);
        }

        return [
            'kpi' => [
                'totalWorks' => $totalWorks,
                'completed' => $completed,
                'pending' => $pending,
                'rfiSubmissions' => $rfiSubmissions,
                'completionRate' => $completionRate,
                'rfiRate' => $rfiRate,
                'totalResubmissions' => $totalResubmissions,
                'worksWithResubmissions' => $worksWithResubmissions,
                'avgDailyWorks' => count($dailyTrend) > 0 ? round($totalWorks / count($dailyTrend), 1) : 0,
                'trendDirection' => $trendDirection, // positive = improving
            ],
            'highlights' => [
                'bestDay' => $bestDay,
                'worstDay' => $worstDay,
                'busiestDay' => $busiestDay,
                'topIncharge' => $topIncharge,
                'mostCommonType' => collect($typeBreakdown)->sortByDesc('value')->first(),
            ],
            'dailyTrend' => $dailyTrend,
            'typeBreakdown' => $typeBreakdown,
            'statusBreakdown' => $statusBreakdown,
            'inchargePerformance' => $inchargePerformance,
        ];
    }

    /**
     * Server-side Excel export with multiple sheets.
     */
    public function exportExcel(Request $request)
    {
        try {
            $query = $this->buildFilteredQuery($request);
            $works = $query->get();
            $summaries = $this->generateSummariesFromDailyWorks($works);
            $analytics = $this->buildAnalyticsPayload($works);

            $filename = 'daily_work_summary_'.now()->format('Y-m-d_His').'.xlsx';

            return Excel::download(
                new DailyWorkSummaryExport($summaries, $analytics, $request->all()),
                $filename
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Excel export failed: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Server-side PDF export with branding, KPIs, and breakdown table.
     */
    public function exportPdf(Request $request)
    {
        try {
            $query = $this->buildFilteredQuery($request);
            $works = $query->get();
            $summaries = $this->generateSummariesFromDailyWorks($works);
            $analytics = $this->buildAnalyticsPayload($works);

            $startDate = $request->input('startDate');
            $endDate = $request->input('endDate');

            // Optional chart images sent from client (data URIs)
            $chartImages = $request->input('chart_images', []);
            if (is_string($chartImages)) {
                $decoded = json_decode($chartImages, true);
                $chartImages = is_array($decoded) ? $decoded : [];
            }

            $pdf = Pdf::loadView('daily_work_summary_pdf', [
                'title' => 'Daily Work Summary Report',
                'generatedOn' => Carbon::now()->format('F d, Y h:i A'),
                'periodLabel' => $startDate && $endDate
                    ? Carbon::parse($startDate)->format('M d, Y').' - '.Carbon::parse($endDate)->format('M d, Y')
                    : 'All Time',
                'summaries' => $summaries,
                'analytics' => $analytics,
                'chartImages' => $chartImages,
            ])->setPaper('a4', 'landscape');

            $filename = 'daily_work_summary_'.now()->format('Y-m-d_His').'.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'PDF export failed: '.$e->getMessage(),
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

            // Universal logic: Show own works (incharge/assigned) AND manager's works (incharge) if report_to is set
            if (! $isAdmin && $user->report_to) {
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id)
                        ->orWhere('incharge', $user->report_to);
                });
            } elseif (! $isAdmin) {
                // Show only own works (incharge or assigned)
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);
                });
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
     * Combines import snapshot from daily_work_summaries with real-time data from daily_works
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

            // Fetch import snapshot from daily_work_summaries (immutable)
            $importSummaries = \App\Models\DailyWorkSummary::where('date', $date)->get();
            $importSnapshot = $importSummaries->isEmpty() ? null : [
                'totalDailyWorks' => $importSummaries->sum('totalDailyWorks'),
                'resubmissions' => $importSummaries->sum('resubmissions'),
                'embankment' => $importSummaries->sum('embankment'),
                'structure' => $importSummaries->sum('structure'),
                'pavement' => $importSummaries->sum('pavement'),
                'incharges' => $importSummaries->map(function ($s) {
                    return [
                        'incharge' => $s->incharge,
                        'totalDailyWorks' => $s->totalDailyWorks,
                        'resubmissions' => $s->resubmissions,
                        'embankment' => $s->embankment,
                        'structure' => $s->structure,
                        'pavement' => $s->pavement,
                    ];
                }),
            ];

            // Real-time counts from daily_works table
            $realTimeEmbankment = $typeBreakdown->get('Embankment', collect())->count();
            $realTimeStructure = $typeBreakdown->get('Structure', collect())->count();
            $realTimePavement = $typeBreakdown->get('Pavement', collect())->count();
            $realTimeResubmissions = $works->where('resubmission_count', '>', 0)->count();

            // Use import snapshot when present (preserves what was actually imported);
            // fall back to real-time aggregations from daily_works otherwise.
            $totalDailyWorks = $importSnapshot['totalDailyWorks'] ?? $totalWorks;
            $resubmissions = $importSnapshot['resubmissions'] ?? $realTimeResubmissions;
            $embankment = $importSnapshot['embankment'] ?? $realTimeEmbankment;
            $structure = $importSnapshot['structure'] ?? $realTimeStructure;
            $pavement = $importSnapshot['pavement'] ?? $realTimePavement;

            $completionPercentage = $totalDailyWorks > 0
                ? round(($completed / $totalDailyWorks) * 100, 1)
                : 0;

            $summaries[] = [
                'date' => $date,
                // Flat fields consumed by the frontend table/cards
                'totalDailyWorks' => $totalDailyWorks,
                'resubmissions' => $resubmissions,
                'embankment' => $embankment,
                'structure' => $structure,
                'pavement' => $pavement,
                'completed' => $completed,
                'pending' => $pending,
                'inProgress' => $inProgress,
                'rejected' => $rejected,
                'emergency' => $emergency,
                'rfiSubmissions' => $rfiSubmissions,
                'completionPercentage' => $completionPercentage,
                'rfiSubmissionPercentage' => $rfiSubmissionPercentage,
                // Supplementary nested objects (kept for UIs that want to compare)
                'realTime' => [
                    'totalDailyWorks' => $totalWorks,
                    'completed' => $completed,
                    'pending' => $pending,
                    'inProgress' => $inProgress,
                    'rejected' => $rejected,
                    'emergency' => $emergency,
                    'rfiSubmissions' => $rfiSubmissions,
                    'embankment' => $realTimeEmbankment,
                    'structure' => $realTimeStructure,
                    'pavement' => $realTimePavement,
                    'resubmissions' => $realTimeResubmissions,
                ],
                'importSnapshot' => $importSnapshot,
            ];
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

        // Universal logic: Show own works (incharge/assigned) AND manager's works (incharge) if report_to is set
        if ($isAdmin) {
            // Super Administrator and Administrator get all data - no filtering
            // Query remains unfiltered to get all daily works
        } elseif ($user->report_to) {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id)
                    ->orWhere('incharge', $user->report_to);
            });
        } else {
            // Show only own works (incharge or assigned)
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);
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
