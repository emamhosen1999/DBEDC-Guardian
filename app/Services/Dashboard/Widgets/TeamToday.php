<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\AttendanceTrend;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Dashboard\TodaySummary;

/**
 * Who is in today: present / late / absent / on leave for the viewer's team.
 *
 * Scope: DepartmentScope::applyToUsers — a department admin sees their departments and
 * reporting line, global HR/admin the whole organization, nobody else's figures.
 *
 * Cost: a constant number of queries (see TodaySummary) - never one lookup per employee.
 * Bucket rules mirror AttendanceDayPartitionService; a parity test pins them together.
 *
 * "Late" and "absent" follow the MATERIALIZED roster (roster_days, manual > swap >
 * pattern) — the same source the absence-streak job escalates from. Employees with no
 * roster row today are neither late nor absent: they are counted present/on-leave when
 * that is known, else reported as `unrostered` so the gap is visible instead of guessed.
 */
final class TeamToday extends DashboardWidget
{
    public function __construct(DepartmentScope $scope, private readonly TodaySummary $today, private readonly AttendanceTrend $trend)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'team.today';
    }

    public function title(): string
    {
        return 'Team today';
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
        return 11;
    }

    public function span(): int
    {
        return 8;
    }

    public function ttl(): int
    {
        return 60;
    }

    public function route(User $viewer): ?string
    {
        return 'attendance.unified';
    }

    public function mobileRoute(): ?string
    {
        return '/team-attendance';
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function data(User $viewer): array
    {
        $t = $this->today->summarize($this->team($viewer));
        $series = $this->trend->forPeople($this->team($viewer), 14);
        $last = count($series['dates']) - 1;
        $prev = $last > 0 ? $last - 1 : null;
        $tail = fn (string $key) => array_slice($series[$key], -14);
        $yesterday = fn (string $key) => $prev === null ? null : $series[$key][$prev];
        $hasHistory = array_sum($series['rostered']) + array_sum($series['present']) > 0;

        return [
            'scope' => $this->scopeMeta($viewer),
            'total' => $t['total'],
            'stats' => $this->stats($t['present'], $t['late'], $t['absent'], $t['on_leave']),
            'meta' => $this->meta($t['upcoming'], $t['off'], $t['unrostered'], $t['total']),
            'kpis' => [
                $this->kpi('present', 'Present today', $t['present'], 'good', 'attendance.unified',
                    $hasHistory ? $this->delta($t['present'], $yesterday('present'), 'vs yesterday', 'up') : null,
                    $hasHistory ? $tail('present') : null, 'Present, last 14 days',
                    $series['rostered'][$last] > 0 ? 'of '.$series['rostered'][$last].' rostered' : null),
                $this->kpi('late', 'Late arrivals', $t['late'], $this->toneFor($t['late']), 'attendance.unified',
                    $hasHistory ? $this->delta($t['late'], $yesterday('late'), 'vs yesterday', 'down') : null,
                    $hasHistory ? $tail('late') : null, 'Late arrivals, last 14 days'),
                $this->kpi('on_leave', 'On leave', $t['on_leave'], $t['on_leave'] > 0 ? 'info' : 'neutral', 'leaves.index',
                    $hasHistory ? $this->delta($t['on_leave'], $yesterday('on_leave'), 'vs yesterday', 'up') : null,
                    $hasHistory ? $tail('on_leave') : null, 'People on leave, last 14 days'),
            ],
            'charts' => [$this->distribution($t)],
        ];
    }

    /**
     * Where everyone stands right now, as one donut: on time, late, absent, on leave, shift not started, day off and
     * employees with no roster row today (shown, not guessed).
     *
     * @param  array<string, int>  $t  TodaySummary figures
     */
    private function distribution(array $t): array
    {
        $parts = [
            'On time' => max(0, $t['present'] - $t['late']), 'Late' => $t['late'], 'Absent' => $t['absent'], 'On leave' => $t['on_leave'],
            'Shift not started' => $t['upcoming'], 'Day off' => $t['off'], 'Not rostered' => $t['unrostered'],
        ];
        $parts = array_filter($parts, fn (int $n) => $n > 0);
        $today = now()->toDateString();

        return $this->chart('today_distribution', 'donut', 'Team right now',
            'Team members by status today: '.implode(', ', array_map(fn ($l, $n) => "{$l} {$n}", array_keys($parts), $parts)),
            ['labels' => array_keys($parts), 'series' => array_values($parts), 'unit' => 'people'], ['from' => $today, 'to' => $today], 'Nobody in scope yet.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stats(int $present, int $late, int $absent, int $onLeave): array
    {
        return [
            $this->stat('present', 'Present', $present, 'good', 'attendance.unified', '/team-attendance'),
            $this->stat('late', 'Late', $late, $this->toneFor($late), 'attendance.unified', '/team-attendance'),
            $this->stat('absent', 'Absent', $absent, $this->toneFor($absent, 'crit'), 'attendance.unified', '/team-attendance'),
            $this->stat('on_leave', 'On leave', $onLeave, $onLeave > 0 ? 'info' : 'neutral', 'leaves.index', '/leave-approvals'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function meta(int $upcoming, int $off, int $unrostered, int $total): array
    {
        return ['upcoming' => $upcoming, 'day_off' => $off, 'unrostered' => $unrostered, 'team_size' => $total];
    }
}
