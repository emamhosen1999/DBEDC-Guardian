<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashLoan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Analytics for one petty-cash fund.
 *
 * Everything is computed from the fund's own transactions (a fund holds tens
 * to low hundreds of rows), so this works on the loaded collection rather than
 * issuing one query per figure. Money is summed in integer paisa to avoid
 * float drift, then returned as two-decimal floats.
 *
 * The balance model mirrors PettyCashLoan::calculateBalance(): the fund starts
 * at its original amount; expenses and repayments reduce it; reimbursements
 * restore it. `loan_taken` rows record the opening and do not move it again.
 */
class PettyCashAnalyticsService
{
    public const RANGES = ['all', 'mtd', '30d', '90d', '12m'];

    public function build(PettyCashLoan $loan, string $range = 'all'): array
    {
        $range = in_array($range, self::RANGES, true) ? $range : 'all';

        $transactions = $loan->transactions()
            ->with('media')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();

        $today = CarbonImmutable::today();
        $fundStart = CarbonImmutable::parse($loan->loan_date ?? $transactions->first()?->transaction_date ?? $today)->startOfDay();

        [$from, $to] = $this->window($range, $fundStart, $today);

        $inRange = $transactions->filter(fn ($t) => $this->dateOf($t)->betweenIncluded($from, $to));
        $before = $transactions->filter(fn ($t) => $this->dateOf($t)->lt($from));

        $original = $this->paisa($loan->original_amount);
        $openingBalance = $original + $this->balanceDelta($before);
        $closingBalance = $openingBalance + $this->balanceDelta($inRange);

        $expenses = $inRange->where('type', 'expense');
        $spent = $this->sum($expenses);
        $reimbursed = $this->sum($inRange->where('type', 'reimbursement'));
        $repaid = $this->sum($inRange->where('type', 'repayment'));

        $days = max(1, (int) $from->diffInDays($to) + 1);
        $avgDailySpend = intdiv($spent, $days);

        // Runway is forward-looking, so it uses the most recent 30 days of
        // spending regardless of the selected range.
        $recentFrom = $today->subDays(29);
        $recentSpend = $this->sum($transactions->filter(
            fn ($t) => $t->type === 'expense' && $this->dateOf($t)->betweenIncluded($recentFrom, $today)
        ));
        $recentDailySpend = $recentSpend / 30;
        $currentBalance = $this->paisa($loan->current_balance);
        $runwayDays = $recentDailySpend > 0 && $currentBalance > 0
            ? (int) floor($currentBalance / $recentDailySpend)
            : null;

        $withBill = $expenses->filter(fn ($t) => $t->media->where('collection_name', 'bills')->isNotEmpty())->count();

        return [
            'range' => [
                'key' => $range,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'days' => $days,
            ],
            'summary' => [
                'funded' => $this->money($original),
                'spent' => $this->money($spent),
                'reimbursed' => $this->money($reimbursed),
                'repaid' => $this->money($repaid),
                'net_spend' => $this->money($spent - $reimbursed),
                'opening_balance' => $this->money($openingBalance),
                'closing_balance' => $this->money($closingBalance),
                'current_balance' => $this->money($currentBalance),
                'utilization_pct' => $original > 0 ? round(($original - $currentBalance) / $original * 100, 1) : 0,
                'transaction_count' => $inRange->count(),
                'expense_count' => $expenses->count(),
                'avg_expense' => $this->money($expenses->count() ? intdiv($spent, $expenses->count()) : 0),
                'largest_expense' => $this->money($expenses->max(fn ($t) => $this->paisa($t->amount)) ?? 0),
                'avg_daily_spend' => $this->money($avgDailySpend),
                'recent_daily_spend' => $this->money((int) round($recentDailySpend)),
                'runway_days' => $runwayDays,
                'bills' => [
                    'with' => $withBill,
                    'without' => $expenses->count() - $withBill,
                    'coverage_pct' => $expenses->count() ? round($withBill / $expenses->count() * 100, 1) : null,
                ],
            ],
            'comparison' => $this->comparison($range, $transactions, $from, $to, $spent),
            'balance_timeline' => $this->balanceTimeline($inRange, $openingBalance, $from, $to),
            'monthly' => $this->monthly($inRange, $from, $to),
            'categories' => $this->categories($expenses, $spent),
            'weekday' => $this->weekday($expenses),
            'top_expenses' => $this->topExpenses($expenses),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function window(string $range, CarbonImmutable $fundStart, CarbonImmutable $today): array
    {
        $from = match ($range) {
            'mtd' => $today->startOfMonth(),
            '30d' => $today->subDays(29),
            '90d' => $today->subDays(89),
            '12m' => $today->subMonthsNoOverflow(11)->startOfMonth(),
            default => $fundStart,
        };

        // Never report on days before the fund existed.
        if ($from->lt($fundStart)) {
            $from = $fundStart;
        }

        return [$from, $today->max($from)];
    }

    /** Spend in the equal-length window immediately before this one. */
    private function comparison(string $range, Collection $all, CarbonImmutable $from, CarbonImmutable $to, int $spent): ?array
    {
        if ($range === 'all') {
            return null;
        }

        $length = (int) $from->diffInDays($to) + 1;
        $prevTo = $from->subDay();
        $prevFrom = $prevTo->subDays($length - 1);

        $prevSpent = $this->sum($all->filter(
            fn ($t) => $t->type === 'expense' && $this->dateOf($t)->betweenIncluded($prevFrom, $prevTo)
        ));

        return [
            'from' => $prevFrom->toDateString(),
            'to' => $prevTo->toDateString(),
            'spent' => $this->money($prevSpent),
            'spent_change_pct' => $prevSpent > 0 ? round(($spent - $prevSpent) / $prevSpent * 100, 1) : null,
        ];
    }

    /** End-of-day balance on every day that moved it, bracketed by the range ends. */
    private function balanceTimeline(Collection $inRange, int $opening, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $points = [['date' => $from->toDateString(), 'balance' => $this->money($opening)]];
        $running = $opening;

        foreach ($inRange->groupBy(fn ($t) => $this->dateOf($t)->toDateString()) as $date => $rows) {
            $running += $this->balanceDelta($rows);
            if ($date === $points[count($points) - 1]['date']) {
                array_pop($points);
            }
            $points[] = ['date' => $date, 'balance' => $this->money($running)];
        }

        if ($points[count($points) - 1]['date'] !== $to->toDateString()) {
            $points[] = ['date' => $to->toDateString(), 'balance' => $this->money($running)];
        }

        return $points;
    }

    /** Every month in the range, zero-filled, so gaps read as gaps rather than disappearing. */
    private function monthly(Collection $inRange, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byMonth = $inRange->groupBy(fn ($t) => $this->dateOf($t)->format('Y-m'));
        $out = [];

        for ($m = $from->startOfMonth(); $m->lte($to); $m = $m->addMonthNoOverflow()) {
            $rows = $byMonth->get($m->format('Y-m'), collect());
            $out[] = [
                'month' => $m->format('Y-m'),
                'label' => $m->format('M Y'),
                'expenses' => $this->money($this->sum($rows->where('type', 'expense'))),
                'reimbursements' => $this->money($this->sum($rows->where('type', 'reimbursement'))),
                'repayments' => $this->money($this->sum($rows->where('type', 'repayment'))),
                'count' => $rows->whereIn('type', ['expense', 'reimbursement', 'repayment'])->count(),
            ];
        }

        return $out;
    }

    private function categories(Collection $expenses, int $spent): array
    {
        return $expenses
            ->groupBy(fn ($t) => $t->category ?: 'miscellaneous')
            ->map(function ($rows, $key) use ($spent) {
                $amount = $this->sum($rows);

                return [
                    'key' => $key,
                    'label' => PettyCashService::CATEGORIES[$key] ?? ucwords(str_replace('_', ' ', $key)),
                    'amount' => $this->money($amount),
                    'count' => $rows->count(),
                    'average' => $this->money(intdiv($amount, max(1, $rows->count()))),
                    'share_pct' => $spent > 0 ? round($amount / $spent * 100, 1) : 0,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function weekday(Collection $expenses): array
    {
        $out = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $label) {
            // Carbon: 1 = Monday … 7 = Sunday (ISO).
            $rows = $expenses->filter(fn ($t) => $this->dateOf($t)->isoWeekday() === $i + 1);
            $out[] = ['day' => $label, 'amount' => $this->money($this->sum($rows)), 'count' => $rows->count()];
        }

        return $out;
    }

    private function topExpenses(Collection $expenses): array
    {
        return $expenses
            ->sortByDesc(fn ($t) => $this->paisa($t->amount))
            ->take(5)
            ->map(fn ($t) => [
                'id' => $t->id,
                'date' => $this->dateOf($t)->toDateString(),
                'category' => PettyCashService::CATEGORIES[$t->category] ?? ucwords(str_replace('_', ' ', (string) $t->category)),
                'description' => $t->description,
                'amount' => $this->money($this->paisa($t->amount)),
                'has_bill' => $t->media->where('collection_name', 'bills')->isNotEmpty(),
            ])
            ->values()
            ->all();
    }

    private function balanceDelta(Collection $rows): int
    {
        $delta = 0;
        foreach ($rows as $t) {
            $amount = $this->paisa($t->amount);
            if (in_array($t->type, ['expense', 'repayment'], true)) {
                $delta -= $amount;
            } elseif ($t->type === 'reimbursement') {
                $delta += $amount;
            }
        }

        return $delta;
    }

    private function sum(Collection $rows): int
    {
        return (int) $rows->sum(fn ($t) => $this->paisa($t->amount));
    }

    private function paisa($amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    private function money(int|float $paisa): float
    {
        return round($paisa / 100, 2);
    }

    private function dateOf($transaction): CarbonImmutable
    {
        return CarbonImmutable::parse($transaction->transaction_date)->startOfDay();
    }
}
