<?php

namespace App\Jobs;

use App\Exports\DailyWorkSummaryExport;
use App\Models\DailyWork;
use App\Models\User;
use App\Models\Jurisdiction;
use App\Models\DailyWorkSummary;
use App\Traits\DailyWorkFilterable;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Maatwebsite\Excel\Facades\Excel;

class ExportDailyWorkSummary implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, DailyWorkFilterable;

    protected string $type;
    protected array $filters;
    protected int $userId;
    protected string $filename;

    /**
     * Create a new job instance.
     */
    public function __construct(string $type, array $filters, int $userId, string $filename)
    {
        $this->type = $type;
        $this->filters = $filters;
        $this->userId = $userId;
        $this->filename = $filename;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            Log::info("ExportDailyWorkSummary started: Type={$this->type}, File={$this->filename}");

            // Ensure exports directory exists
            if (!Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            $filePath = 'exports/' . $this->filename;

            // Load user with roles and designation to determine permissions
            $user = User::with(['designation', 'roles'])->findOrFail($this->userId);
            $userRoles = $user->roles->pluck('name')->toArray();
            $isAdmin = in_array('Super Administrator', $userRoles) || in_array('Administrator', $userRoles);

            // Build query based on user roles
            $query = DailyWork::with(['inchargeUser', 'assignedUser']);

            if (! $isAdmin && in_array('Employee', $userRoles)) {
                $hasJurisdiction = Jurisdiction::where('incharge', $user->id)->exists();
                if ($hasJurisdiction) {
                    $query->where('incharge', $user->id);
                } else {
                    if ($user->report_to) {
                        $query->where('incharge', $user->report_to);
                    } else {
                        $query->where('incharge', $user->id);
                    }
                }
            } elseif (! $isAdmin && $user->report_to) {
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id)
                        ->orWhere('incharge', $user->report_to);
                });
            } elseif (! $isAdmin) {
                $query->where(function ($q) use ($user) {
                    $q->where('incharge', $user->id)
                        ->orWhere('assigned', $user->id);
                });
            }

            // Apply filters from request
            $this->applyDateRangeFilter($query, $this->filters['startDate'] ?? null, $this->filters['endDate'] ?? null);
            $this->applyMonthFilter($query, $this->filters['month'] ?? null);
            $this->applyStatusFilter($query, $this->filters['status'] ?? null);
            $this->applyTypeFilter($query, $this->filters['type'] ?? null);
            $this->applySearchFilter($query, $this->filters['search'] ?? null);

            $inchargeFilter = $isAdmin ? $this->normalizeIdFilter($this->filters['incharge'] ?? null) : null;
            $jurisdictionFilter = $isAdmin ? $this->normalizeIdFilter($this->filters['jurisdiction'] ?? null) : null;
            $this->applyInchargeJurisdictionFilters($query, $inchargeFilter, $jurisdictionFilter);

            $works = $query->get();
            $summaries = $this->generateSummariesFromDailyWorks($works);
            $analytics = $this->buildAnalyticsPayload($works);

            if ($this->type === 'excel') {
                Excel::store(
                    new DailyWorkSummaryExport($summaries, $analytics, $this->filters),
                    $filePath,
                    'public'
                );
            } elseif ($this->type === 'pdf') {
                $startDate = $this->filters['startDate'] ?? null;
                $endDate = $this->filters['endDate'] ?? null;

                $chartImages = [];
                if (!empty($analytics['dailyTrend'])) {
                    $chartImages['daily_trend'] = $this->generateDailyTrendChart($analytics['dailyTrend']);
                }
                if (!empty($analytics['typeBreakdown'])) {
                    $chartImages['work_type'] = $this->generatePieChart($analytics['typeBreakdown'], 'Work Type Distribution');
                }
                if (!empty($analytics['statusBreakdown'])) {
                    $chartImages['status'] = $this->generatePieChart($analytics['statusBreakdown'], 'Status Distribution');
                }

                $pdf = Pdf::loadView('daily_work_summary_pdf', [
                    'title' => 'Daily Work Summary Report',
                    'generatedOn' => Carbon::now()->format('F d, Y h:i A'),
                    'periodLabel' => $startDate && $endDate
                        ? Carbon::parse($startDate)->format('M d, Y') . ' - ' . Carbon::parse($endDate)->format('M d, Y')
                        : 'All Time',
                    'summaries' => $summaries,
                    'analytics' => $analytics,
                    'chartImages' => $chartImages,
                ])->setPaper('a4', 'landscape');

                Storage::disk('public')->put($filePath, $pdf->output());
            }

            Log::info("ExportDailyWorkSummary completed: File={$this->filename}");
        } catch (\Exception $e) {
            Log::error("ExportDailyWorkSummary failed: " . $e->getMessage(), [
                'type' => $this->type,
                'filters' => $this->filters,
                'file' => $this->filename,
                'exception' => $e
            ]);
            throw $e;
        }
    }

    private function generateSummariesFromDailyWorks($dailyWorks)
    {
        $groupedByDate = $dailyWorks->groupBy('date');
        $summaries = [];

        foreach ($groupedByDate as $date => $works) {
            $totalWorks = $works->count();
            $completed = $works->filter(function ($work) {
                return $work->status === 'completed' || str_starts_with($work->status ?? '', 'completed:');
            })->count();

            $pending = $works->whereIn('status', ['new', 'pending', 'in-progress', 'resubmission'])->count();
            $inProgress = $works->where('status', 'in-progress')->count();
            $rejected = $works->where('status', 'rejected')->count();
            $emergency = $works->where('status', 'emergency')->count();
            $rfiSubmissions = $works->whereNotNull('rfi_submission_date')->count();

            $typeBreakdown = $works->groupBy('type');
            $rfiSubmissionPercentage = $completed > 0 ? round(($rfiSubmissions / $completed) * 100, 1) : 0;

            $importSummaries = Schema::hasTable('daily_work_summaries')
                ? DailyWorkSummary::where('date', $date)->get()
                : collect();
            
            $importSnapshot = $importSummaries->isEmpty() ? null : [
                'totalDailyWorks' => $importSummaries->sum('totalDailyWorks'),
                'resubmissions' => $importSummaries->sum('resubmissions'),
                'embankment' => $importSummaries->sum('embankment'),
                'structure' => $importSummaries->sum('structure'),
                'pavement' => $importSummaries->sum('pavement'),
                'incharges' => $importSummaries->map(fn($s) => [
                    'incharge' => $s->incharge,
                    'totalDailyWorks' => $s->totalDailyWorks,
                    'resubmissions' => $s->resubmissions,
                    'embankment' => $s->embankment,
                    'structure' => $s->structure,
                    'pavement' => $s->pavement,
                ]),
            ];

            $realTimeEmbankment = $typeBreakdown->get('Embankment', collect())->count();
            $realTimeStructure = $typeBreakdown->get('Structure', collect())->count();
            $realTimePavement = $typeBreakdown->get('Pavement', collect())->count();
            $realTimeResubmissions = $works->where('resubmission_count', '>', 0)->count();

            $totalDailyWorks = $importSnapshot['totalDailyWorks'] ?? $totalWorks;
            $resubmissions = $importSnapshot['resubmissions'] ?? $realTimeResubmissions;
            $embankment = $importSnapshot['embankment'] ?? $realTimeEmbankment;
            $structure = $importSnapshot['structure'] ?? $realTimeStructure;
            $pavement = $importSnapshot['pavement'] ?? $realTimePavement;

            $completionPercentage = $totalDailyWorks > 0 ? round(($completed / $totalDailyWorks) * 100, 1) : 0;

            $summaries[] = [
                'date' => $date,
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
            ];
        }

        usort($summaries, fn($a, $b) => strtotime($b['date']) - strtotime($a['date']));
        return $summaries;
    }

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

        $byDate = $works->groupBy(fn($w) => $w->date instanceof \DateTimeInterface ? $w->date->format('Y-m-d') : (string) $w->date);
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

        $typeBreakdown = [
            ['name' => 'Embankment', 'value' => $works->filter(fn ($w) => stripos($w->type ?? '', 'embankment') !== false)->count()],
            ['name' => 'Structure', 'value' => $works->filter(fn ($w) => stripos($w->type ?? '', 'structure') !== false)->count()],
            ['name' => 'Pavement', 'value' => $works->filter(fn ($w) => stripos($w->type ?? '', 'pavement') !== false)->count()],
        ];

        $statusBreakdown = [
            ['name' => 'Completed', 'value' => $completed],
            ['name' => 'In Progress', 'value' => $inProgress],
            ['name' => 'Pending', 'value' => max(0, $pending - $inProgress)],
            ['name' => 'Rejected', 'value' => $rejected],
            ['name' => 'Emergency', 'value' => $emergency],
        ];

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
            ],
            'dailyTrend' => $dailyTrend,
            'typeBreakdown' => $typeBreakdown,
            'statusBreakdown' => $statusBreakdown,
        ];
    }

    private function generateDailyTrendChart($dailyTrend)
    {
        if (empty($dailyTrend)) return null;
        $width = 800; $height = 300; $padding = 40;
        $maxValue = max(array_column($dailyTrend, 'total')) ?: 1;
        $maxValue = ceil($maxValue * 1.1);
        $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';
        $points = [];
        foreach ($dailyTrend as $i => $day) {
            $x = $padding + ($i * ($width - 2 * $padding) / max(1, count($dailyTrend) - 1));
            $y = ($height - $padding) - ($day['total'] / $maxValue * ($height - 2 * $padding));
            $points[] = "{$x},{$y}";
        }
        if (count($points) > 1) {
            $svg .= '<polyline points="' . implode(' ', $points) . '" fill="none" stroke="#0070F0" stroke-width="2"/>';
        }
        $svg .= '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function generatePieChart($data, $title)
    {
        if (empty($data)) return null;
        $width = 400; $height = 300; $cx = $width / 2; $cy = $height / 2; $radius = 100;
        $total = array_sum(array_column($data, 'value'));
        if ($total == 0) return null;
        $colors = ['#0070F0', '#17C964', '#F5A524', '#F31260', '#9333EA'];
        $svg = '<svg width="' . $width . '" height="' . $height . '" xmlns="http://www.w3.org/2000/svg">';
        $svg .= '<rect width="' . $width . '" height="' . $height . '" fill="#ffffff"/>';
        $startAngle = 0;
        foreach ($data as $i => $item) {
            $sliceAngle = ($item['value'] / $total) * 2 * M_PI;
            $endAngle = $startAngle + $sliceAngle;
            $x1 = $cx + $radius * cos($startAngle); $y1 = $cy + $radius * sin($startAngle);
            $x2 = $cx + $radius * cos($endAngle); $y2 = $cy + $radius * sin($endAngle);
            $largeArc = $sliceAngle > M_PI ? 1 : 0;
            $color = $colors[$i % count($colors)];
            $svg .= '<path d="M ' . $cx . ' ' . $cy . ' L ' . $x1 . ' ' . $y1 . ' A ' . $radius . ' ' . $radius . ' 0 ' . $largeArc . ' 1 ' . $x2 . ' ' . $y2 . ' Z" fill="' . $color . '"/>';
            $startAngle = $endAngle;
        }
        $svg .= '</svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
}
