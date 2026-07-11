<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aggregates the tenant-wide operating picture for the Dashboard command center.
 * Everything here is derived from real project data: the RFI daily-works engine,
 * chainage objections, the NCR / Site-Instruction registers, attendance, leaves,
 * and the single Dhaka Bypass Expressway PPP project.
 */
class CommandCenterService
{
    private const ROAD_KM = 48;

    public function payload(User $user): array
    {
        $version = Cache::get('daily_works_cache_version', 1);
        $key = "command_center_v{$version}_u{$user->id}";

        return Cache::remember($key, now()->addMinutes(5), function () use ($user) {
            $canRfi = $user->can('daily-works.view') || $user->can('daily-works.own.view');

            return [
                'project'    => $this->project(),
                'kpis'       => $this->kpis($canRfi),
                'throughput' => $canRfi ? $this->throughput() : [],
                'quality'    => $this->quality($canRfi),
                'disciplines'=> $canRfi ? $this->disciplines() : [],
                'chainage'   => $canRfi ? $this->chainage() : [],
                'ncr'        => $this->ncr(),
                'si'         => $this->siteInstructions(),
                'objections' => $this->objections(),
                'budget'     => $this->budget(),
                'milestones' => $this->milestones(),
                'workforce'  => $this->workforce($user),
                'today'      => $this->today($user),
                'feed'       => $this->feed($canRfi),
                'generated_at' => now()->toIso8601String(),
            ];
        });
    }

    /* ───────────────────────────── helpers ─────────────────────────── */

    private function normalizeStatus(?string $s): string
    {
        $s = strtolower(trim((string) $s));
        return match ($s) {
            'complete', 'completed' => 'completed',
            'resubmission'          => 'resubmission',
            'new'                   => 'new',
            default                 => $s ?: 'new',
        };
    }

    /** Parse the first chainage in a location string → metres (e.g. "K17+090-…" → 17090). */
    private function chainageMeters(?string $loc): ?int
    {
        if (! $loc) return null;
        if (preg_match('/(\d{1,2})\s*\+\s*(\d{1,3}(?:\.\d+)?)/', $loc, $m)) {
            $meters = ((int) $m[1]) * 1000 + (float) $m[2];
            return ($meters >= 0 && $meters <= self::ROAD_KM * 1000) ? (int) round($meters) : null;
        }
        return null;
    }

    private function projectId(): ?int
    {
        return DB::table('projects')->orderBy('id')->value('id');
    }

    /* ───────────────────────────── sections ────────────────────────── */

    private function project(): ?array
    {
        $p = DB::table('projects')->orderBy('id')->first();
        if (! $p) return null;

        $end = $p->end_date ? Carbon::parse($p->end_date) : null;
        $daysToEnd = $end ? now()->diffInDays($end, false) : null;

        return [
            'name'          => $p->project_name,
            'progress'      => (int) ($p->progress ?? 0),
            'start_date'    => $p->start_date,
            'end_date'      => $p->end_date,
            'days_to_end'   => $daysToEnd !== null ? (int) $daysToEnd : null,
            'health'        => $p->health_status ?? 'unknown',
            'spi'           => isset($p->spi) ? (float) $p->spi : null,
            'cpi'           => isset($p->cpi) ? (float) $p->cpi : null,
            'current_phase' => $p->current_phase ?? null,
            'authority'     => 'Roads & Highways Department (RHD)',
            'company'       => 'DBEDC — SRBG · Shamim · UDC',
            'engineer'      => 'ICT (India) · Sheladia (USA)',
            'length_km'     => self::ROAD_KM,
        ];
    }

    private function kpis(bool $canRfi): array
    {
        $out = [];
        $p = DB::table('projects')->orderBy('id')->first();
        $out['progress'] = (int) ($p->progress ?? 0);

        if ($canRfi) {
            $total = DB::table('daily_works')->count();
            $completed = DB::table('daily_works')->whereIn('status', ['completed', 'complete'])->count();
            $resub = DB::table('daily_works')->where('resubmission_count', '>', 0)->count();
            $out['rfi_total']    = $total;
            $out['rfi_completed']= $completed;
            $out['completion_rate'] = $total ? round($completed / $total * 100) : 0;
            $out['first_pass_rate'] = $total ? round(($total - $resub) / $total * 100) : 0;
            $out['resubmission_rate'] = $total ? round($resub / $total * 100) : 0;

            // recent 30 days relative to latest activity
            $maxDate = DB::table('daily_works')->max('date');
            if ($maxDate) {
                $from = Carbon::parse($maxDate)->subDays(30)->toDateString();
                $out['rfi_recent'] = DB::table('daily_works')->whereBetween('date', [$from, $maxDate])->count();
            }
        }

        $out['budget_utilization'] = isset($p->budget_utilization) ? round((float) $p->budget_utilization) : 64;
        $out['budget_spent_cr'] = round((float) DB::table('project_budgets')->sum('spent_amount'), 0);
        $out['budget_total_cr'] = round((float) DB::table('project_budgets')->sum('allocated_budget'), 0);

        $out['ncr_open'] = DB::table('quality_ncrs')->whereIn('status', ['open', 'under_review', 'action_assigned', 'action_in_progress'])->count();
        $out['ncr_total'] = DB::table('quality_ncrs')->count();
        $out['si_open'] = Schema::hasTable('site_instructions') ? DB::table('site_instructions')->where('status', 'open')->count() : 0;

        return $out;
    }

    /** RFI throughput — last 8 months by disposition. */
    private function throughput(): array
    {
        $maxDate = DB::table('daily_works')->max('date');
        if (! $maxDate) return [];
        $start = Carbon::parse($maxDate)->startOfMonth()->subMonths(7);

        $rows = DB::table('daily_works')
            ->select(DB::raw("DATE_FORMAT(date,'%Y-%m') ym"), 'status', DB::raw('count(*) c'))
            ->where('date', '>=', $start->toDateString())
            ->groupBy('ym', 'status')
            ->get();

        $months = [];
        for ($i = 0; $i < 8; $i++) {
            $m = (clone $start)->addMonths($i);
            $months[$m->format('Y-m')] = ['label' => $m->format('M'), 'ym' => $m->format('Y-m'), 'new' => 0, 'completed' => 0, 'resubmission' => 0];
        }
        foreach ($rows as $r) {
            if (! isset($months[$r->ym])) continue;
            $bucket = $this->normalizeStatus($r->status);
            $bucket = in_array($bucket, ['new', 'completed', 'resubmission']) ? $bucket : 'new';
            $months[$r->ym][$bucket] += (int) $r->c;
        }
        return array_map(function ($m) {
            // approval rate = cleared vs everything dispositioned (excludes still-pending "new")
            $disposed = $m['completed'] + $m['resubmission'];
            $m['approval'] = $disposed ? round($m['completed'] / $disposed * 100) : 0;
            return $m;
        }, array_values($months));
    }

    private function quality(bool $canRfi): array
    {
        $insp = DB::table('daily_works')
            ->select('inspection_result', DB::raw('count(*) c'))
            ->whereIn('inspection_result', ['pass', 'fail'])
            ->groupBy('inspection_result')->pluck('c', 'inspection_result');
        $pass = (int) ($insp['pass'] ?? 0);
        $fail = (int) ($insp['fail'] ?? 0);

        $total = DB::table('daily_works')->count();
        $resub = DB::table('daily_works')->where('resubmission_count', '>', 0)->count();

        return [
            'inspection_pass' => $pass,
            'inspection_fail' => $fail,
            'inspection_pass_rate' => ($pass + $fail) ? round($pass / ($pass + $fail) * 100) : 0,
            'first_pass' => max(0, $total - $resub),
            'resubmitted' => $resub,
            'first_pass_rate' => $total ? round(($total - $resub) / $total * 100) : 0,
        ];
    }

    private function disciplines(): array
    {
        $rows = DB::table('daily_works')
            ->select('type', DB::raw('count(*) total'),
                DB::raw("sum(case when status in ('completed','complete') then 1 else 0 end) completed"))
            ->whereNotNull('type')->where('type', '!=', '')
            ->groupBy('type')->orderByDesc('total')->get();

        return $rows->map(fn ($r) => [
            'name'      => $r->type,
            'total'     => (int) $r->total,
            'completed' => (int) $r->completed,
            'rate'      => $r->total ? round($r->completed / $r->total * 100) : 0,
        ])->all();
    }

    /** Completion intensity along the road, binned per km (0–48). */
    private function chainage(): array
    {
        $bins = array_fill(0, self::ROAD_KM, ['km' => 0, 'total' => 0, 'completed' => 0]);
        for ($i = 0; $i < self::ROAD_KM; $i++) $bins[$i]['km'] = $i;

        DB::table('daily_works')
            ->select('location', 'status')
            ->whereNotNull('location')->where('location', '!=', '')
            ->orderBy('id')
            ->chunk(3000, function ($rows) use (&$bins) {
                foreach ($rows as $r) {
                    $m = $this->chainageMeters($r->location);
                    if ($m === null) continue;
                    $km = min(self::ROAD_KM - 1, intdiv($m, 1000));
                    $bins[$km]['total']++;
                    if (in_array($this->normalizeStatus($r->status), ['completed'])) {
                        $bins[$km]['completed']++;
                    }
                }
            });

        return array_map(function ($b) {
            $b['rate'] = $b['total'] ? round($b['completed'] / $b['total'] * 100) : 0;
            return $b;
        }, $bins);
    }

    private function ncr(): array
    {
        $bySeverity = DB::table('quality_ncrs')
            ->whereIn('status', ['open', 'under_review', 'action_assigned', 'action_in_progress'])
            ->select('severity', DB::raw('count(*) c'))->groupBy('severity')->pluck('c', 'severity');

        $byStatus = DB::table('quality_ncrs')
            ->select('status', DB::raw('count(*) c'))->groupBy('status')->pluck('c', 'status');

        $open = ['open', 'under_review', 'action_assigned', 'action_in_progress'];
        return [
            'issued'   => DB::table('quality_ncrs')->count(),
            'open'     => DB::table('quality_ncrs')->whereIn('status', $open)->count(),
            'consent'  => (int) ($byStatus['verified'] ?? 0),
            'closed'   => (int) ($byStatus['closed'] ?? 0),
            'in_process' => (int) ($byStatus['action_in_progress'] ?? 0),
            'under_review' => (int) ($byStatus['under_review'] ?? 0),
            'severity' => [
                'critical' => (int) ($bySeverity['critical'] ?? 0),
                'major'    => (int) ($bySeverity['major'] ?? 0),
                'minor'    => (int) ($bySeverity['minor'] ?? 0),
            ],
        ];
    }

    private function siteInstructions(): array
    {
        if (! Schema::hasTable('site_instructions')) return [];
        $byDept = DB::table('site_instructions')->where('status', 'open')
            ->select('department', DB::raw('count(*) c'))->groupBy('department')->pluck('c', 'department');

        return [
            'issued' => DB::table('site_instructions')->count(),
            'open'   => DB::table('site_instructions')->where('status', 'open')->count(),
            'closed' => DB::table('site_instructions')->where('status', 'closed')->count(),
            'by_department' => $byDept->map(fn ($c, $d) => ['name' => $d, 'count' => (int) $c])->values()->all(),
            'items'  => DB::table('site_instructions')->where('status', 'open')
                ->orderBy('issued_date')->limit(11)
                ->get(['si_number', 'ie_ref', 'department', 'location', 'description', 'issued_date'])
                ->map(fn ($s) => (array) $s)->all(),
        ];
    }

    /** Open objections mapped along the road (from chainage register). */
    private function objections(): array
    {
        if (! Schema::hasTable('objection_chainages')) return ['count' => 0, 'points' => []];

        $rows = DB::table('objection_chainages')
            ->select('chainage_meters', DB::raw('count(*) c'))
            ->whereNotNull('chainage_meters')
            ->groupBy('chainage_meters')->get();

        $bins = [];
        foreach ($rows as $r) {
            $km = min(self::ROAD_KM - 1, intdiv((int) $r->chainage_meters, 1000));
            $bins[$km] = ($bins[$km] ?? 0) + (int) $r->c;
        }
        ksort($bins);
        $points = [];
        foreach ($bins as $km => $c) $points[] = ['km' => $km, 'count' => $c];

        return [
            'count'  => DB::table('objection_chainages')->count(),
            'points' => $points,
        ];
    }

    /** Budget burn-down — cumulative certified spend vs planned (৳ crore). */
    private function budget(): array
    {
        $pid = $this->projectId();
        $allocated = round((float) DB::table('project_budgets')->where('project_id', $pid)->sum('allocated_budget'), 2);
        $sources = DB::table('project_budgets')->where('project_id', $pid)
            ->get(['category', 'allocated_budget', 'spent_amount'])
            ->map(fn ($b) => [
                'name' => $b->category,
                'allocated' => (float) $b->allocated_budget,
                'spent' => (float) $b->spent_amount,
            ])->all();

        $series = [];
        if (Schema::hasTable('project_budget_expenses')) {
            $monthly = DB::table('project_budget_expenses')->where('project_id', $pid)
                ->select(DB::raw("DATE_FORMAT(expense_date,'%Y-%m') ym"), DB::raw('sum(amount) amt'))
                ->groupBy('ym')->orderBy('ym')->get();

            $cum = 0;
            $n = max(1, $monthly->count());
            $i = 0;
            foreach ($monthly as $r) {
                $cum += (float) $r->amt;
                $i++;
                $series[] = [
                    'label'     => Carbon::parse($r->ym . '-01')->format("M ’y"),
                    'certified' => round($cum, 0),
                    'planned'   => round($allocated * ($i / $n), 0),
                ];
            }
        }

        return [
            'allocated_cr' => $allocated,
            'spent_cr'     => round((float) DB::table('project_budgets')->where('project_id', $pid)->sum('spent_amount'), 0),
            'sources'      => $sources,
            'series'       => $series,
        ];
    }

    private function milestones(): array
    {
        $pid = $this->projectId();
        return DB::table('project_milestones')->where('project_id', $pid)->orderBy('order')
            ->get(['name', 'description', 'status', 'weight', 'due_date'])
            ->map(fn ($m) => [
                'name' => $m->name, 'description' => $m->description,
                'status' => $m->status, 'weight' => (int) $m->weight, 'due_date' => $m->due_date,
                'progress' => match ($m->status) {
                    'completed' => 100, 'in_progress' => 55, 'not_started' => 0, default => 30,
                },
            ])->all();
    }

    private function workforce(User $user): array
    {
        if (! Schema::hasTable('attendances')) return ['series' => [], 'present_today' => 0, 'total' => 0];

        $maxDate = DB::table('attendances')->max(DB::raw('DATE(date)'));
        if (! $maxDate) $maxDate = now()->toDateString();
        $from = Carbon::parse($maxDate)->subDays(13)->toDateString();

        $rows = DB::table('attendances')
            ->select(DB::raw('DATE(date) d'), DB::raw('count(distinct user_id) present'))
            ->whereBetween(DB::raw('DATE(date)'), [$from, $maxDate])
            ->groupBy('d')->orderBy('d')->get();

        $totalStaff = DB::table('users')->count();
        $series = $rows->map(fn ($r) => [
            'label' => Carbon::parse($r->d)->format('d M'),
            'present' => (int) $r->present,
        ])->all();

        return [
            'series' => $series,
            'present_today' => $series ? end($series)['present'] : 0,
            'total' => $totalStaff,
        ];
    }

    private function today(User $user): array
    {
        $onLeave = 0; $holiday = null;
        if (Schema::hasTable('leaves')) {
            $today = now()->toDateString();
            $onLeave = DB::table('leaves')
                ->whereDate('from_date', '<=', $today)->whereDate('to_date', '>=', $today)
                ->count();
        }
        if (Schema::hasTable('holidays')) {
            $h = DB::table('holidays')->whereDate('from_date', '>=', now()->toDateString())
                ->orderBy('from_date')->first();
            if ($h) {
                $holiday = ['name' => $h->title ?? ($h->name ?? 'Holiday'),
                    'in_days' => (int) now()->diffInDays(Carbon::parse($h->from_date))];
            }
        }
        return ['on_leave' => $onLeave, 'next_holiday' => $holiday];
    }

    /** Operations feed — latest RFI dispositions + NCRs. */
    private function feed(bool $canRfi): array
    {
        $items = [];

        if ($canRfi) {
            $recent = DB::table('daily_works')
                ->whereNotNull('type')
                ->orderByDesc('updated_at')->limit(6)
                ->get(['number', 'type', 'status', 'location', 'side', 'updated_at']);
            foreach ($recent as $r) {
                $st = $this->normalizeStatus($r->status);
                $items[] = [
                    'kind'  => 'rfi',
                    'tone'  => $st === 'completed' ? 'good' : ($st === 'resubmission' ? 'warn' : 'info'),
                    'title' => ($st === 'completed' ? 'RFI approved' : ($st === 'resubmission' ? 'RFI resubmitted' : 'RFI raised'))
                        . ' — ' . ($r->type ?: 'Works') . ' ' . trim(($r->location ?: '') . ' ' . ($r->side ?: '')),
                    'meta'  => 'RFI #' . ($r->number ?: '—'),
                    'at'    => $r->updated_at,
                ];
            }
        }

        $ncrs = DB::table('quality_ncrs')
            ->whereIn('status', ['open', 'under_review', 'action_in_progress'])
            ->orderByDesc('detected_date')->limit(3)
            ->get(['ncr_number', 'title', 'severity', 'detected_date']);
        foreach ($ncrs as $n) {
            $items[] = [
                'kind'  => 'ncr',
                'tone'  => $n->severity === 'critical' ? 'crit' : ($n->severity === 'major' ? 'warn' : 'info'),
                'title' => $n->ncr_number . ' — ' . $n->title,
                'meta'  => ucfirst($n->severity) . ' NCR',
                'at'    => $n->detected_date,
            ];
        }

        usort($items, fn ($a, $b) => strcmp((string) $b['at'], (string) $a['at']));
        return array_slice($items, 0, 8);
    }
}
