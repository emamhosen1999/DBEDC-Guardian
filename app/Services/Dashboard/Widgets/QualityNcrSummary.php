<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\QualityNCR;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;

/**
 * Non-conformance reports: open, critical-and-open, awaiting verification.
 *
 * Permission: quality.ncr.view (the NCR register's own gate). The NCR register is a
 * project-wide record, not employee-owned, so - exactly like the page this card links
 * to - it is governed by the permission alone and is not narrowed by DepartmentScope.
 * One grouped query.
 */
final class QualityNcrSummary extends DashboardWidget
{
    public function key(): string
    {
        return 'project.ncr';
    }

    public function title(): string
    {
        return 'Non-conformance reports';
    }

    public function permissions(): array
    {
        return ['quality.ncr.view'];
    }

    public function personas(): array
    {
        return ['project_manager', 'administrator'];
    }

    public function section(): string
    {
        return self::SECTION_PROJECT;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 40;
    }

    public function span(): int
    {
        return 6;
    }

    public function ttl(): int
    {
        return 300;
    }

    public function route(User $viewer): ?string
    {
        return 'quality.ncr.index';
    }

    public function type(): string
    {
        return 'analytics';
    }

    /** Months shown on the opened-versus-closed chart. */
    private const MONTHS = 6;

    public function data(User $viewer): array
    {
        $rows = QualityNCR::query()
            ->selectRaw('status, severity, COUNT(*) as n')
            ->groupBy('status', 'severity')
            ->get();

        $open = (int) $rows->whereNotIn('status', ['closed', 'verified'])->sum('n');
        $critical = (int) $rows->whereNotIn('status', ['closed', 'verified'])->where('severity', 'critical')->sum('n');
        $review = (int) $rows->where('status', 'under_review')->sum('n');

        $monthly = $this->monthly();

        $statusLabels = ['open' => 'Open', 'under_review' => 'Under review', 'action_assigned' => 'Action assigned', 'action_in_progress' => 'Action in progress', 'closed' => 'Closed', 'verified' => 'Verified'];
        $byStatus = $rows->groupBy('status')->map(fn ($g) => (int) $g->sum('n'));
        $present = array_filter($statusLabels, fn ($label, $status) => ($byStatus[$status] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);

        return [
            'scope' => ['kind' => 'project', 'label' => 'Whole project'],
            'total' => (int) $rows->sum('n'),
            'stats' => [
                $this->stat('open', 'Open NCRs', $open, $this->toneFor($open), 'quality.ncr.index'),
                $this->stat('critical', 'Critical open', $critical, $this->toneFor($critical, 'crit'), 'quality.ncr.index'),
                $this->stat('under_review', 'Under review', $review, $review > 0 ? 'info' : 'neutral', 'quality.ncr.index'),
            ],
            'kpis' => [
                $this->kpi('ncr_open', 'Open NCRs', $open, $this->toneFor($open, 'crit'), 'quality.ncr.index',
                    $this->delta($open, $monthly['open_at_month_end'][count($monthly['open_at_month_end']) - 2] ?? null, 'vs last month', 'down'),
                    $monthly['open_at_month_end'], 'Open NCRs at each month end, last '.self::MONTHS.' months',
                    $critical > 0 ? $critical.' critical' : null),
            ],
            'charts' => [
                $this->chart('ncr_status', 'donut', 'NCRs by status', 'NCR register by status: '.implode(', ', array_map(fn ($l, $s) => $l.' '.$byStatus[$s], $present, array_keys($present))),
                    ['labels' => array_values($present), 'series' => array_map(fn ($status) => $byStatus[$status], array_keys($present)), 'unit' => 'NCRs'], null, 'No NCRs recorded.'),
                $this->chart('ncr_monthly', 'bar', 'NCRs opened vs closed, by month',
                    'NCRs opened and closed in each of the last '.self::MONTHS.' months',
                    ['categories' => $monthly['labels'], 'series' => [
                        ['name' => 'Opened', 'tone' => 'crit', 'data' => $monthly['opened']],
                        ['name' => 'Closed', 'tone' => 'good', 'data' => $monthly['closed']],
                    ]], ['from' => $monthly['from'], 'to' => $monthly['to']], 'No NCRs opened or closed in this period.'),
            ],
        ];
    }

    /**
     * Opened / closed per month and the number still open at each month end, from the register's own dates
     * (created_at, closure_date). Fetches only rows that can matter to the window, grouped in memory.
     *
     * @return array{labels: array<int, string>, opened: array<int, int>, closed: array<int, int>, open_at_month_end: array<int, int>, from: string, to: string}
     */
    private function monthly(): array
    {
        $first = now()->startOfMonth()->subMonths(self::MONTHS - 1);
        $rows = QualityNCR::query()
            ->where(fn ($q) => $q->whereNull('closure_date')->orWhere('closure_date', '>=', $first->toDateString()))
            ->get(['created_at', 'closure_date']);

        $labels = $opened = $closed = $openAtEnd = [];
        for ($i = 0; $i < self::MONTHS; $i++) {
            $start = $first->copy()->addMonths($i);
            $end = $start->copy()->endOfMonth();
            $labels[] = $start->format('M Y');
            $opened[] = $rows->filter(fn ($r) => $r->created_at !== null && $r->created_at->between($start, $end))->count();
            $closed[] = $rows->filter(fn ($r) => $r->closure_date !== null && Carbon::parse($r->closure_date)->between($start, $end))->count();
            $openAtEnd[] = $rows->filter(fn ($r) => $r->created_at !== null && $r->created_at->lte($end)
                && ($r->closure_date === null || Carbon::parse($r->closure_date)->gt($end)))->count();
        }

        return ['labels' => $labels, 'opened' => $opened, 'closed' => $closed, 'open_at_month_end' => $openAtEnd,
            'from' => $first->toDateString(), 'to' => now()->toDateString()];
    }
}
