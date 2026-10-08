<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Models\HRM\OvertimeRequest;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\AttendanceTrend;
use App\Services\Dashboard\DashboardWidget;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * The viewer's own month to date: days worked, approved leave, punches to fix, overtime.
 * Own records only. ("Late" and "absent" need a per-day schedule verdict; until the
 * platform persists one per day they stay on the attendance page rather than being
 * guessed here.)
 */
final class MyAttendanceMonth extends DashboardWidget
{
    public function __construct(DepartmentScope $scope, private readonly AttendanceTrend $trend)
    {
        parent::__construct($scope);
    }

    public function type(): string
    {
        return 'analytics';
    }

    public function key(): string
    {
        return 'me.attendance_month';
    }

    public function title(): string
    {
        return 'This month';
    }

    public function permissions(): array
    {
        return ['attendance.own.view'];
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
        return 20;
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
        return 'attendance-employee';
    }

    public function mobileRoute(): ?string
    {
        return '/my-attendance';
    }

    public function data(User $viewer): array
    {
        $me = $this->id($viewer);
        $today = now()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();
        $monthEnd = $today->copy()->endOfMonth()->startOfDay();

        $attendance = $this->between(Attendance::query()->where('user_id', $me), 'date', $monthStart, $today)
            ->where(fn (Builder $q) => $q->whereNull('policy_status')->orWhere('policy_status', '!=', 'rejected'))
            ->where(fn (Builder $q) => $q->whereNotNull('punchin')->orWhere('symbol', '√'))
            ->toBase()
            ->selectRaw(
                'COUNT(DISTINCT DATE(date)) as present_days, '
                .'SUM(CASE WHEN punchin IS NOT NULL AND punchout IS NULL AND date < ? THEN 1 ELSE 0 END) as missing_out',
                [$today->toDateString()],
            )
            ->first();

        $leaveDays = Leave::query()
            ->where('user_id', $me)
            ->whereRaw('LOWER(status) = ?', ['approved'])
            ->where('from_date', '<', $monthEnd->copy()->addDay()->toDateString())
            ->where('to_date', '>=', $monthStart->toDateString())
            ->get(['id', 'from_date', 'to_date', 'no_of_days'])
            ->sum(function (Leave $leave) use ($monthStart, $monthEnd): float {
                $from = Carbon::parse($leave->from_date)->startOfDay();
                $to = Carbon::parse($leave->to_date)->startOfDay();
                $overlap = max($from, $monthStart)->diffInDays(min($to, $monthEnd)) + 1;

                return (float) min((float) $leave->no_of_days, $overlap);
            });

        $overtimeMinutes = (int) $this->between(OvertimeRequest::query()->where('user_id', $me), 'date', $monthStart, $monthEnd)
            ->where('status', 'approved')
            ->sum('requested_minutes');

        $missingOut = (int) ($attendance->missing_out ?? 0);

        // My own days, classified with the same rules as the team trend (a one-person set).
        $mine = $this->trend->forPeople(User::query()->where('users.employee_id', $me), (int) $today->day);
        $days = [];
        for ($d = $monthStart->copy(); $d->lte($monthEnd); $d->addDay()) {
            $i = $d->day - 1;
            $state = match (true) {
                $d->gt($today) => 'future',
                ($mine['late'][$i] ?? 0) > 0 => 'late',
                ($mine['present'][$i] ?? 0) > 0 => 'present',
                ($mine['on_leave'][$i] ?? 0) > 0 => 'leave',
                ($mine['absent'][$i] ?? 0) > 0 => 'absent',
                ($mine['rostered'][$i] ?? 0) > 0 => 'pending',
                default => 'off',
            };
            $days[] = ['date' => $d->toDateString(), 'day' => $d->day, 'state' => $state];
        }
        $present = array_sum($mine['present']);
        $late = array_sum($mine['late']);
        $onTime = $present > 0 ? (int) round(($present - $late) / $present * 100) : 0;

        return [
            'month' => $monthStart->format('Y-m'),
            'kpis' => $present > 0 ? [$this->kpi('on_time', 'On-time this month', $onTime.'%', $onTime >= 90 ? 'good' : ($onTime >= 70 ? 'warn' : 'crit'), 'attendance-employee', null, null, null, $present.' days worked')] : [],
            'charts' => [
                $this->chart('month_heat', 'heatstrip', 'My month, day by day', 'Each day of '.$monthStart->format('F').': present '.($present - $late).', late '.$late.', leave '.array_sum($mine['on_leave']).', absent '.array_sum($mine['absent']),
                    ['days' => $days], ['from' => $monthStart->toDateString(), 'to' => $today->toDateString()], 'No attendance recorded this month.'),
                $this->chart('on_time', 'radial', 'On-time arrivals', $present > 0 ? "{$onTime}% of {$present} days started within the shift's grace time" : 'No days worked yet this month',
                    ['value' => $onTime, 'unit' => '%', 'detail' => $present > 0 ? ($present - $late).' of '.$present.' days on time' : null, 'total' => $present], ['from' => $monthStart->toDateString(), 'to' => $today->toDateString()], 'No days worked yet this month.'),
            ],
            'stats' => [
                $this->stat('present_days', 'Days worked', (int) ($attendance->present_days ?? 0), 'good', 'attendance-employee', '/my-attendance'),
                $this->stat('leave_days', 'Leave taken', round($leaveDays, 1), 'info', 'leaves-employee', '/leaves'),
                $this->stat('missing_punchout', 'Missing punch-out', $missingOut, $this->toneFor($missingOut), 'attendance-employee', '/my-requests'),
                $this->stat('overtime_hours', 'Approved overtime (h)', round($overtimeMinutes / 60, 1), 'neutral', 'attendance-employee', '/my-requests'),
            ],
        ];
    }
}
