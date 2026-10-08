<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;

/**
 * The viewer's own leave balance per type, from the immutable ledger (the same source
 * as /leave-balances). A person with no ledger rows yet sees the policy defaults less
 * their approved leave, labelled as an estimate — never invented numbers.
 * Own records only.
 */
final class MyLeaveBalances extends DashboardWidget
{
    public function key(): string
    {
        return 'me.leave_balances';
    }

    public function title(): string
    {
        return 'Leave balances';
    }

    public function permissions(): array
    {
        return ['leave.own.view'];
    }

    public function personas(): array
    {
        return ['employee', 'line_manager', 'department_admin', 'hr_manager'];
    }

    public function section(): string
    {
        return self::SECTION_ME;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_EMPLOYEE;
    }

    public function priority(): int
    {
        return 30;
    }

    public function span(): int
    {
        return 6;
    }

    public function type(): string
    {
        return 'progress';
    }

    public function ttl(): int
    {
        return 300;
    }

    public function route(User $viewer): ?string
    {
        return 'leaves-employee';
    }

    public function mobileRoute(): ?string
    {
        return '/leaves';
    }

    public function data(User $viewer): array
    {
        $me = $this->id($viewer);
        $year = now()->year;

        $ledger = LeaveLedger::query()
            ->where('user_id', $me)
            ->where('period_year', $year)
            ->orderBy('id')
            ->get(['id', 'leave_type', 'txn_type', 'amount', 'balance_after']);

        if ($ledger->isNotEmpty()) {
            $names = LeaveSetting::query()->whereIn('id', $ledger->pluck('leave_type')->unique())->pluck('type', 'id');

            $items = $ledger->groupBy('leave_type')->map(function ($transactions, $typeId) use ($names): array {
                $sum = fn (array $kinds): float => (float) $transactions->whereIn('txn_type', $kinds)->sum('amount');
                $total = $sum(['opening']) + $sum(['accrual']) + $sum(['carry_forward']);
                $used = -$sum(['consumption', 'consumption_reversal']);
                $remaining = (float) ($transactions->last()->balance_after ?? ($total - $used));

                return $this->row($typeId, (string) ($names[$typeId] ?? 'Leave'), $remaining, $total, $used);
            })->values()->all();

            return $this->withKpi(['year' => $year, 'basis' => 'ledger', 'rows' => $items]);
        }

        // No ledger yet: policy default entitlement less approved leave this year.
        $used = Leave::query()
            ->where('user_id', $me)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->whereYear('from_date', $year)
            ->get(['leave_type', 'no_of_days'])
            ->groupBy('leave_type')
            ->map(fn ($leaves) => (float) $leaves->sum('no_of_days'));

        $items = LeaveSetting::query()->orderBy('id')->get(['id', 'type', 'days'])->map(function (LeaveSetting $setting) use ($used): array {
            $taken = (float) ($used[$setting->id] ?? 0);

            return $this->row($setting->id, (string) $setting->type, max(0.0, (float) $setting->days - $taken), (float) $setting->days, $taken);
        })->values()->all();

        return $this->withKpi(['year' => $year, 'basis' => 'policy_default', 'rows' => $items]);
    }

    /**
     * Adds the "Leave left" KPI: the sum of what is remaining across leave types.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withKpi(array $data): array
    {
        $rows = $data['rows'] ?? [];
        if ($rows === []) {
            return $data;
        }

        $left = round(array_sum(array_map(fn (array $r) => (float) ($r['remaining'] ?? 0), $rows)), 1);
        $data['kpis'] = [$this->kpi('leave_left', 'Leave left (days)', $left, 'info', 'leaves-employee', null, null, null, 'this year')];

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int|string $typeId, string $label, float $remaining, float $total, float $used): array
    {
        return $this->stat((string) $typeId, $label, round($remaining, 1), $remaining <= 0 && $total > 0 ? 'warn' : 'neutral', 'leaves-employee', '/leaves', [
            'remaining' => round($remaining, 1),
            'total' => round($total, 1),
            'used' => round($used, 1),
            'unit' => 'days',
        ]);
    }
}
