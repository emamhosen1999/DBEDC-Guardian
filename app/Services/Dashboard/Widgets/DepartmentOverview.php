<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Attendance;
use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\TodaySummary;

/**
 * Department head view: headcount, and who is present / absent / on leave today.
 *
 * Permission: department.admin (what makes someone a department head or delegated admin).
 * Scope: DepartmentScope::applyToUsers - the departments the viewer administers plus their
 * reporting line (a global role sees the whole organization). The viewer is counted in the
 * headcount, unlike TeamToday which lists "my team" without them.
 */
final class DepartmentOverview extends DashboardWidget
{
    public function __construct(DepartmentScope $scope, private readonly TodaySummary $today)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'team.department';
    }

    public function title(): string
    {
        return 'Department overview';
    }

    public function permissions(): array
    {
        return ['department.admin'];
    }

    public function personas(): array
    {
        return ['department_admin', 'hr_manager', 'administrator'];
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
        return 31;
    }

    public function span(): int
    {
        return 4;
    }

    public function ttl(): int
    {
        return 120;
    }

    public function route(User $viewer): ?string
    {
        return 'employees';
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function data(User $viewer): array
    {
        $t = $this->today->summarize($this->people($viewer));

        return [
            'scope' => $this->scopeMeta($viewer),
            'total' => $t['total'],
            'stats' => [
                $this->stat('headcount', 'Headcount', $t['total'], 'neutral', 'employees'),
                $this->stat('present', 'Present', $t['present'], 'good', 'attendance.unified'),
                $this->stat('absent', 'Absent', $t['absent'], $this->toneFor($t['absent'], 'crit'), 'attendance.unified'),
                $this->stat('on_leave', 'On leave', $t['on_leave'], $t['on_leave'] > 0 ? 'info' : 'neutral', 'leaves.index'),
            ],
            'charts' => [$this->departmentComparison($viewer)],
        ];
    }

    /** Attendance rate today per department in scope: employees who punched in / headcount. Three small queries. */
    private function departmentComparison(User $viewer): array
    {
        $today = now()->startOfDay();
        $deptOf = $this->people($viewer)->pluck('users.department_id', 'users.employee_id')->all();

        $punched = array_flip(array_map('strval', $this->onDay(Attendance::query()->whereIn('user_id', array_map('strval', array_keys($deptOf))), 'date', $today)
            ->where(fn ($q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->where(fn ($q) => $q->whereNotNull('punchin')->orWhere('symbol', '√'))
            ->distinct()->pluck('user_id')->all()));

        $headcount = [];
        $present = [];
        foreach ($deptOf as $employeeId => $departmentId) {
            $key = $departmentId === null ? 0 : (int) $departmentId;
            $headcount[$key] = ($headcount[$key] ?? 0) + 1;
            $present[$key] = ($present[$key] ?? 0) + (isset($punched[(string) $employeeId]) ? 1 : 0);
        }

        $names = Department::withTrashed()->whereIn('id', array_filter(array_keys($headcount)))->pluck('name', 'id');
        $rows = collect($headcount)->map(fn (int $n, int $id) => [
            'name' => $id === 0 ? 'No department' : ($names[$id] ?? 'Department '.$id),
            'rate' => round(($present[$id] / max(1, $n)) * 100, 1),
            'detail' => $present[$id].' of '.$n.' present',
        ])->sortByDesc('rate')->values();

        return $this->chart('department_comparison', 'bar', 'Attendance by department, today',
            'Share of each department that has punched in today: '.$rows->map(fn ($r) => $r['name'].' '.$r['rate'].'%')->implode(', '),
            [
                'horizontal' => true,
                'unit' => '%',
                'categories' => $rows->pluck('name')->all(),
                'detail' => $rows->pluck('detail')->all(),
                'series' => [['name' => 'Attendance rate', 'tone' => 'theme', 'data' => $rows->pluck('rate')->all()]],
            ], ['from' => $today->toDateString(), 'to' => $today->toDateString()], 'Nobody in scope has punched in yet today.');
    }
}
