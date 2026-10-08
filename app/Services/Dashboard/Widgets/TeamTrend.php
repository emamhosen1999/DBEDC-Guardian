<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\AttendanceTrend;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;

/**
 * The 30-day attendance trend: on-time, late, absent and on-leave headcount per day (stacked bars).
 *
 * Permission: attendance.view. Scope: DepartmentScope::applyToUsers (the viewer's team, never themselves; a global
 * role gets the whole organization). Series come from AttendanceTrend, a constant number of queries.
 */
final class TeamTrend extends DashboardWidget
{
    public function __construct(DepartmentScope $scope, private readonly AttendanceTrend $trend)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'team.trend';
    }

    public function title(): string
    {
        return 'Attendance trend';
    }

    public function permissions(): array
    {
        return ['attendance.view'];
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
        return 30;
    }

    public function span(): int
    {
        return 8;
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
        return 'attendance.unified';
    }

    public function mobileRoute(): ?string
    {
        return '/team-attendance';
    }

    public function data(User $viewer): array
    {
        $series = $this->trend->forPeople($this->team($viewer), 30);
        $scope = $this->scopeMeta($viewer);
        $days = array_map(fn (string $d) => Carbon::parse($d)->format('d M'), $series['dates']);

        return [
            'scope' => $scope,
            'charts' => [
                $this->chart('attendance_trend', 'bar', 'Attendance, last 30 days',
                    'Daily on-time, late, absent and on-leave headcount for '.$scope['label'],
                    [
                        'stacked' => true,
                        'categories' => $days,
                        'series' => [
                            ['name' => 'On time', 'tone' => 'good', 'data' => array_map(fn ($p, $l) => max(0, $p - $l), $series['present'], $series['late'])],
                            ['name' => 'Late', 'tone' => 'warn', 'data' => $series['late']],
                            ['name' => 'Absent', 'tone' => 'crit', 'data' => $series['absent']],
                            ['name' => 'On leave', 'tone' => 'info', 'data' => $series['on_leave']],
                        ],
                    ], ['from' => $series['from'], 'to' => $series['to']], 'No attendance recorded in this period.'),
            ],
        ];
    }
}
