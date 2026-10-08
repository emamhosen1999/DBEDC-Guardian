<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;

/**
 * Approved leave days this month by leave type (donut).
 *
 * Permission: leaves.view. Scope: DepartmentScope::applyToEmployeeOwned on leaves.user_id - a department head sees
 * their department, HR / global roles everyone. Leave that spans the month boundary is clipped to the month. One
 * query for the leaves, one for the type names.
 */
final class LeaveThisMonth extends DashboardWidget
{
    public function key(): string
    {
        return 'team.leave_month';
    }

    public function title(): string
    {
        return 'Leave this month';
    }

    public function permissions(): array
    {
        return ['leaves.view'];
    }

    public function personas(): array
    {
        return ['line_manager', 'department_admin', 'hr_manager', 'administrator'];
    }

    public function section(): string
    {
        return self::SECTION_TEAM;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 51;
    }

    public function span(): int
    {
        return 6;
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function ttl(): int
    {
        return 300;
    }

    public function route(User $viewer): ?string
    {
        return 'leaves.index';
    }

    public function data(User $viewer): array
    {
        $from = now()->startOfMonth();
        $to = now()->endOfMonth()->startOfDay();

        $leaves = $this->owned(Leave::query(), $viewer, 'user_id')
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->where('from_date', '<=', $to->toDateString())
            ->where('to_date', '>=', $from->toDateString())
            ->get(['user_id', 'leave_type', 'from_date', 'to_date', 'is_half_day']);

        $days = [];
        foreach ($leaves as $leave) {
            $start = Carbon::parse($leave->from_date)->startOfDay()->max($from);
            $end = Carbon::parse($leave->to_date)->startOfDay()->min($to);
            $days[$leave->leave_type] = ($days[$leave->leave_type] ?? 0) + ($leave->is_half_day ? 0.5 : $start->diffInDays($end) + 1);
        }
        arsort($days);

        $names = LeaveSetting::query()->whereIn('id', array_keys($days))->pluck('type', 'id');
        $labels = array_map(fn ($id) => (string) ($names[$id] ?? 'Leave type '.$id), array_keys($days));
        $values = array_map(fn ($d) => round((float) $d, 1), array_values($days));
        $total = round(array_sum($values), 1);

        return [
            'scope' => $this->scopeMeta($viewer),
            'total' => $total,
            'stats' => [$this->stat('days', 'Leave days this month', $total, $total > 0 ? 'info' : 'neutral', 'leaves.index')],
            'charts' => [
                $this->chart('leave_by_type', 'donut', 'Leave days by type, '.$from->format('F Y'),
                    'Approved leave days this month by type: '.implode(', ', array_map(fn ($l, $v) => "{$l} {$v}", $labels, $values)),
                    ['labels' => $labels, 'series' => $values, 'unit' => 'days'],
                    ['from' => $from->toDateString(), 'to' => $to->toDateString()], 'No approved leave this month.'),
            ],
        ];
    }
}
