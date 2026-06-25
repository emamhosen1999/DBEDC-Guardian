<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Department;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Service for attendance report aggregation, stats calculation,
 * monthly report generation, and leave count calculations.
 */
class AttendanceReportService
{
    /**
     * Get all Employee users with their attendances and leaves for a given month.
     */
    public function getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null, ?int $userId = null): Collection
    {
        $query = User::query()
            ->select('users.*')
            ->leftJoin('designations', 'users.designation_id', '=', 'designations.id');

        if ($userId !== null) {
            $query->where('users.id', $userId);
        } else {
            $query->role('Employee');

            if ($departmentId) {
                $query->where('users.department_id', $departmentId);
            }
        }

        return $query->orderByRaw('COALESCE(designations.hierarchy_level, 999) ASC')
            ->orderBy('users.name')
            ->with([
                'offboarding',
                'department',
                'attendances' => function ($query) use ($year, $month) {
                    $query->whereYear('date', $year)
                        ->whereMonth('date', $month)
                        ->where('policy_status', '!=', 'rejected');
                },
                'leaves' => function ($query) use ($year, $month) {
                    $query->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                        ->select('leaves.*', 'leave_settings.type as leave_type')
                        // Only APPROVED leaves mark a day as on-leave; pending/rejected leaves must
                        // not mask an absence in the grid (stats already count approved-only).
                        ->where('leaves.status', 'approved')
                        ->where(function ($query) use ($year, $month) {
                            $query->whereYear('leaves.from_date', $year)
                                ->whereMonth('leaves.from_date', $month)
                                ->orWhereYear('leaves.to_date', $year)
                                ->whereMonth('leaves.to_date', $month);
                        })
                        ->orderBy('leaves.from_date', 'desc');
                },
            ])->get();
    }

    /**
     * Get holidays for a given month.
     */
    public function getHolidaysForMonth(int $year, int $month): Collection
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        return app(HolidayService::class)->forRange($start, $end);
    }

    /**
     * Get leave counts grouped by user and type for a given month.
     */
    public function getLeaveCountsArray(int $year, int $month): array
    {
        $datediffExpression = DB::getDriverName() === 'sqlite'
            ? 'SUM(julianday(leaves.to_date) - julianday(leaves.from_date) + 1) as total_days'
            : 'SUM(DATEDIFF(leaves.to_date, leaves.from_date) + 1) as total_days';

        $leaveCounts = DB::table('leaves')
            ->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->select(
                'leaves.user_id',
                'leave_settings.type as leave_type',
                DB::raw($datediffExpression)
            )
            // Approved-only, matching the engine/grid so the leave-type tallies reconcile.
            ->where('leaves.status', 'approved')
            ->where(function ($query) use ($year, $month) {
                $query->whereYear('leaves.from_date', $year)
                    ->whereMonth('leaves.from_date', $month)
                    ->orWhereYear('leaves.to_date', $year)
                    ->whereMonth('leaves.to_date', $month);
            })
            ->groupBy('leaves.user_id', 'leave_settings.type')
            ->get();

        $leaveCountsArray = [];
        foreach ($leaveCounts as $leave) {
            $userId = $leave->user_id;
            $leaveType = $leave->leave_type;
            $totalDays = $leave->total_days;

            if (! isset($leaveCountsArray[$userId])) {
                $leaveCountsArray[$userId] = [
                    'Casual' => 0,
                    'Sick' => 0,
                    'Weekend' => 0,
                    'Earned' => 0,
                ];
            }
            $leaveCountsArray[$userId][$leaveType] = $totalDays;
        }

        return $leaveCountsArray;
    }

    /**
     * Compile per-user attendance data for a given month (day-by-day status).
     */
    public function getUserAttendanceData($user, int $year, int $month, $holidays, $leaveTypes): array
    {
        $attendanceData = [
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'profile_image_url' => $user->profile_image_url,
        ];

        $resolver = app(ScheduleResolver::class);
        $policyResolver = app(PolicyResolver::class);
        $statusEngine = app(AttendanceStatusService::class);

        $dayResults = $this->buildMonthlyDayResults(
            $user, $year, $month, $holidays, null, $resolver, $policyResolver, $statusEngine
        );

        foreach ($dayResults as $dateString => $ctx) {
            /** @var DayAttendance $result */
            $result = $ctx['result'];
            $attendancesForDate = $ctx['attendances'];
            $date = Carbon::parse($dateString);
            $worked = $result->worked_minutes;

            $effective = $this->classifyDay($ctx);

            $isWorkedDay = in_array($effective, [
                DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT,
            ], true);

            $punchIn = null;
            $punchOut = null;
            $totalWorkHours = '00:00';

            if ($isWorkedDay && $attendancesForDate->isNotEmpty()) {
                $first = $attendancesForDate->first();
                $last = $attendancesForDate->count() === 1
                    ? $first
                    : $attendancesForDate->reverse()->first(fn ($item) => $item->punchout !== null);
                $punchIn = $first->punchin;
                $punchOut = $last ? $last->punchout : null;
                $totalWorkHours = sprintf('%02d:%02d', intdiv($worked, 60), $worked % 60);
            }

            [$symbol, $remarks] = $this->mapStatusToDisplay(
                $effective, $ctx['holiday'], $ctx['leave'], $leaveTypes, $date, $worked
            );

            $attendanceData[$dateString] = [
                'status' => $symbol,
                'punch_in' => $punchIn,
                'punch_out' => $punchOut,
                'total_work_hours' => $totalWorkHours,
                'remarks' => $remarks,
                'ot_minutes' => $result->ot_minutes,
                'worked_minutes' => $result->worked_minutes,
                'double_time_minutes' => $result->double_time_minutes,
                'regular_minutes' => $result->regular_minutes,
                'break_deducted_minutes' => $result->break_deducted_minutes,
                'policy_events' => $result->policy_events,
            ];
        }

        return $attendanceData;
    }

    /**
     * Calculate monthly attendance statistics (present/absent/late/hours/etc.).
     *
     * Returns an associative array suitable for a JSON response.
     */
    public function calculateMonthlyStats(
        int $currentMonth,
        int $currentYear,
        bool $isGlobalScope,
        ?int $userId
    ): array {
        $resolver = app(ScheduleResolver::class);
        $policyResolver = app(PolicyResolver::class);
        $statusEngine = app(AttendanceStatusService::class);

        $settings = AttendanceSetting::first();
        $weekendDays = $settings->weekend_days ?? ['saturday', 'sunday'];

        $startOfMonth = Carbon::create($currentYear, $currentMonth, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
        $analysisEndDate = $endOfMonth->isFuture() ? Carbon::now()->endOfDay() : $endOfMonth;

        // Calendar-level meta (describes the month, not per-employee man-days).
        $totalDaysInMonth = $startOfMonth->daysInMonth;
        $holidaysCount = $this->getTotalHolidayDays($currentYear, $currentMonth);
        $weekendCount = $this->getWeekendDaysCount($currentYear, $currentMonth, $weekendDays);
        $calendarWorkingDays = max(0, $totalDaysInMonth - $holidaysCount - $weekendCount);

        $holidays = $this->getHolidaysForMonth($currentYear, $currentMonth);

        $users = $this->getEmployeeUsersWithAttendanceAndLeaves(
            $currentYear, $currentMonth, null, $isGlobalScope ? null : $userId
        );

        $totalEmployees = $users->count();

        $present = 0;
        $absent = 0;
        $leaveDays = 0;
        $lateArrivals = 0;
        $workMinutes = 0;
        $otMinutes = 0;
        $potentialManDays = 0;
        $perfectCount = 0;

        $workedStatuses = [
            DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT,
        ];

        foreach ($users as $user) {
            $dayResults = $this->buildMonthlyDayResults(
                $user, $currentYear, $currentMonth, $holidays, $analysisEndDate,
                $resolver, $policyResolver, $statusEngine
            );

            $userPresent = 0;
            $userWorkingDays = 0;

            foreach ($dayResults as $ctx) {
                if ($ctx['before_join'] || $ctx['after_termination']) {
                    continue;
                }

                /** @var DayAttendance $result */
                $result = $ctx['result'];
                $effective = $this->classifyDay($ctx);
                $lf = $result->leave_fraction; // 0 / 0.5 / 1.0

                if ($lf > 0) {
                    $leaveDays += $lf;
                }

                if (in_array($effective, $workedStatuses, true)) {
                    // A present day may also carry a 0.5 leave (half-day worked the other half).
                    $present += (1.0 - $lf);
                    $userPresent += (1.0 - $lf);
                    // Hours/late only accrue on worked-effective days (a punch on a leave
                    // day is effective ON_LEAVE and contributes nothing, matching the grid).
                    $workMinutes += $result->worked_minutes;
                    $otMinutes += $result->ot_minutes;
                    if ($result->late_minutes > 0) {
                        $lateArrivals++;
                    }
                } elseif ($effective === DayAttendance::ABSENT) {
                    $absent++;
                } elseif ($effective === DayAttendance::ON_LEAVE) {
                    // Half-day leave with no punch: the worked half is a no-show.
                    if (in_array('half_day_leave_unworked', $result->flags, true)) {
                        $absent += (1.0 - $lf);
                    }
                }

                // The scheduled working portion not excused by leave (excludes off-days,
                // holidays, and the leave fraction — leave does not reduce attendance %).
                if ($ctx['schedule']->isWorkingDay && ! $ctx['holiday']) {
                    $userWorkingDays += (1.0 - $lf);
                    $potentialManDays += (1.0 - $lf);
                }
            }

            if ($userWorkingDays > 0 && $userPresent >= $userWorkingDays) {
                $perfectCount++;
            }
        }

        $attendancePercentage = $potentialManDays > 0
            ? round(($present / $potentialManDays) * 100, 1)
            : 0;

        $averageWorkHours = $present > 0
            ? round(($workMinutes / 60) / $present, 1)
            : 0;

        return [
            'meta' => [
                'month' => $startOfMonth->format('F Y'),
                'scope' => $isGlobalScope ? 'Global' : 'Single',
                'totalEmployees' => (int) $totalEmployees,
                'workingDays' => (int) $calendarWorkingDays,
                'holidays' => (int) $holidaysCount,
                'weekends' => (int) $weekendCount,
            ],
            'attendance' => [
                'present' => $this->numify($present),
                'absent' => $this->numify($absent),
                'leaves' => $this->numify($leaveDays),
                'lateArrivals' => (int) $lateArrivals,
                'percentage' => $attendancePercentage,
                'perfectCount' => (int) $perfectCount,
            ],
            'hours' => [
                'totalWork' => round($workMinutes / 60, 1),
                'averageDaily' => $averageWorkHours,
                'overtime' => round($otMinutes / 60, 1),
            ],
        ];
    }

    /**
     * Per-employee monthly totals for the accounts handoff.
     *
     * Reuses the SAME engine pass as the grid/dashboard (buildMonthlyDayResults + classifyDay)
     * so the sheet cannot diverge from what the admin sees. Leave-days are engine-derived
     * (already exclude weekends/holidays via precedence). Whole-day model until B3.
     *
     * @return array{meta: array, rows: array<int, array>}
     */
    public function getPerEmployeeMonthlySummary(int $year, int $month, ?int $departmentId = null): array
    {
        $resolver = app(ScheduleResolver::class);
        $policyResolver = app(PolicyResolver::class);
        $statusEngine = app(AttendanceStatusService::class);

        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
        $analysisEndDate = $endOfMonth->isFuture() ? Carbon::now()->endOfDay() : $endOfMonth;

        $holidays = $this->getHolidaysForMonth($year, $month);
        $users = $this->getEmployeeUsersWithAttendanceAndLeaves($year, $month, $departmentId);

        // Paid/unpaid lookup. The leaves eager-load aliases leave_type to the type
        // NAME, so resolve by name; fall back to id for any numeric leave_type.
        $paidById = LeaveSetting::query()->pluck('is_paid', 'id');
        $paidByName = LeaveSetting::query()->pluck('is_paid', 'type');

        $workedStatuses = [
            DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT,
        ];

        $rows = [];
        foreach ($users as $user) {
            $dayResults = $this->buildMonthlyDayResults(
                $user, $year, $month, $holidays, $analysisEndDate,
                $resolver, $policyResolver, $statusEngine
            );

            $present = $absent = $late = $holidaysWorked = $weeklyOffWorked = $workingDays = 0;
            $leave = 0.0; $paidLeave = 0.0; $lwp = 0.0;
            $otMinutes = 0;

            foreach ($dayResults as $ctx) {
                if ($ctx['before_join'] || $ctx['after_termination']) {
                    continue;
                }

                /** @var DayAttendance $result */
                $result = $ctx['result'];
                $effective = $this->classifyDay($ctx);
                $hasPunch = $ctx['attendances']->isNotEmpty();
                $lf = $result->leave_fraction; // 0 / 0.5 / 1.0

                if ($lf > 0) {
                    $leave += $lf;
                    $lt = $ctx['leave']->leave_type ?? null;
                    $isPaid = is_numeric($lt)
                        ? (bool) ($paidById[$lt] ?? true)
                        : (bool) ($paidByName[$lt] ?? true);
                    if ($isPaid) {
                        $paidLeave += $lf;
                    } else {
                        $lwp += $lf;
                    }
                }

                if (in_array($effective, $workedStatuses, true)) {
                    // A present day may also carry a 0.5 leave (half-day worked the other half).
                    $present += (1.0 - $lf);
                    $otMinutes += $result->ot_minutes;
                    if ($result->late_minutes > 0) {
                        $late++;
                    }
                } elseif ($effective === DayAttendance::ABSENT) {
                    $absent++;
                } elseif ($effective === DayAttendance::ON_LEAVE) {
                    // Half-day leave with no punch: the worked half is a no-show.
                    if (in_array('half_day_leave_unworked', $result->flags, true)) {
                        $absent += (1.0 - $lf);
                    }
                }

                if ($ctx['holiday'] && $hasPunch) {
                    $holidaysWorked++;
                } elseif (! $ctx['holiday'] && ! $ctx['schedule']->isWorkingDay && $hasPunch) {
                    $weeklyOffWorked++;
                }

                // Scheduled working day (holiday/off-day excluded) -> Present + Absent + Leave.
                if ($ctx['schedule']->isWorkingDay && ! $ctx['holiday']) {
                    $workingDays++;
                }
            }

            $denom = $present + $absent;

            $rows[] = [
                'employee_name'        => $user->name,
                'employee_id'          => $user->employee_id,
                'department'           => optional($user->department)->name ?? '—',
                'present'              => $this->numify($present),
                'absent'               => $this->numify($absent),
                'leave'                => $this->numify($leave),
                'paid_leave'           => $this->numify($paidLeave),
                'lwp'                  => $this->numify($lwp),
                'ot_hours'             => round($otMinutes / 60, 1),
                'late'                 => $late,
                'holidays_worked'      => $holidaysWorked,
                'weekly_off_worked'    => $weeklyOffWorked,
                'working_days'         => $workingDays,
                'attendance_percentage' => $denom > 0 ? round($present / $denom * 100, 1) : 0.0,
            ];
        }

        $departmentName = null;
        if ($departmentId) {
            $departmentName = $users->first()?->department?->name
                ?? optional(Department::find($departmentId))->name;
        }

        return [
            'meta' => [
                'month'          => $startOfMonth->format('F Y'),
                'generatedAt'    => Carbon::now()->toIso8601String(),
                'departmentId'   => $departmentId,
                'departmentName' => $departmentName,
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Count total holiday days falling within a given month.
     */
    public function getTotalHolidayDays(int $year, int $month): int
    {
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
        $holidays = app(HolidayService::class)->forRange($startOfMonth, $endOfMonth);

        return $holidays->sum(function ($holiday) use ($year, $month) {
            $from = Carbon::parse($holiday->from_date);
            $to = Carbon::parse($holiday->to_date);

            // Limit to current year and month
            $startOfMonth = Carbon::create($year, $month, 1);
            $endOfMonth = Carbon::now()->year == $year && Carbon::now()->month == $month
                ? Carbon::today()
                : (clone $startOfMonth)->endOfMonth();

            if ($from > $endOfMonth || $to < $startOfMonth) {
                return 0;
            }

            $holidayStart = $from->greaterThan($startOfMonth) ? $from : $startOfMonth;
            $holidayEnd = $to->lessThan($endOfMonth) ? $to : $endOfMonth;

            return $holidayStart->diffInDays($holidayEnd) + 1;
        });
    }

    /**
     * Count weekend days in a given month, excluding days that overlap with holidays.
     */
    public function getWeekendDaysCount(int $year, int $month, array $weekendDays): int
    {
        $startOfMonth = Carbon::create($year, $month, 1);
        $endOfRange = Carbon::now()->year == $year && Carbon::now()->month == $month
            ? Carbon::today()
            : (clone $startOfMonth)->endOfMonth();

        // Fetch holiday ranges
        $holidayRanges = app(HolidayService::class)
            ->forRange($startOfMonth->copy()->startOfDay(), (clone $startOfMonth)->endOfMonth()->endOfDay())
            ->map(function ($holiday) use ($startOfMonth, $endOfRange) {
                $h = Carbon::parse($holiday->from_date);
                $t = Carbon::parse($holiday->to_date);

                return [
                    'start' => $h->greaterThan($startOfMonth) ? $h : $startOfMonth,
                    'end' => $t->lessThan($endOfRange) ? $t : $endOfRange,
                ];
            });

        $weekendCount = 0;
        $current = $startOfMonth->copy();

        while ($current <= $endOfRange) {
            $dayName = strtolower($current->format('l'));

            if (in_array($dayName, $weekendDays)) {
                $isInsideHoliday = $holidayRanges->contains(function ($range) use ($current) {
                    return $current->betweenIncluded($range['start'], $range['end']);
                });

                if (! $isInsideHoliday) {
                    $weekendCount++;
                }
            }

            $current->addDay();
        }

        return $weekendCount;
    }

    /**
     * Resolve the engine result for every day of the month for one user.
     *
     * One AttendanceStatusService pass per day, fed the same schedule / holiday /
     * leave / policy the grid uses, so every surface derives from one source.
     * Pass $until (e.g. "today") to stop at a date for current-month stats; pass
     * null for the whole month (grid).
     *
     * @return array<string, array{result: DayAttendance, holiday: ?object, leave: ?object, schedule: \App\Services\Attendance\DTO\ShiftSchedule, attendances: \Illuminate\Support\Collection, before_join: bool, after_termination: bool}>
     */
    private function buildMonthlyDayResults(
        $user,
        int $year,
        int $month,
        $holidays,
        ?CarbonInterface $until,
        ScheduleResolver $resolver,
        PolicyResolver $policyResolver,
        AttendanceStatusService $statusEngine
    ): array {
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        $joinDate = $user->date_of_joining ? Carbon::parse($user->date_of_joining)->startOfDay() : null;
        $lastWorking = $user->offboarding && $user->offboarding->last_working_date
            ? Carbon::parse($user->offboarding->last_working_date)->endOfDay()
            : null;

        $results = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day);
            if ($until !== null && $date->copy()->startOfDay()->greaterThan($until)) {
                continue;
            }
            $dateString = $date->toDateString();

            $schedule = $resolver->resolve($user->id, $date);

            $attendancesForDate = $user->attendances
                ->filter(fn ($a) => Carbon::parse($a->date)->isSameDay($date))
                ->sortBy('punchin')
                ->values();

            $holiday = $holidays->first(fn ($h) => $date->between(
                Carbon::parse($h->from_date)->startOfDay(),
                Carbon::parse($h->to_date)->endOfDay()
            ));
            $leave = $user->leaves->first(fn ($l) => $date->between(
                Carbon::parse($l->from_date)->startOfDay(),
                Carbon::parse($l->to_date)->endOfDay()
            ));

            $policy = $attendancesForDate->isNotEmpty()
                ? $policyResolver->resolve($user->id, $date)
                : null;

            $leaveFraction = 0.0;
            $leaveSession = null;
            if ($leave) {
                $leaveFraction = $leave->is_half_day ? 0.5 : 1.0;
                $leaveSession = $leave->half_day_session;
            }

            $result = $statusEngine->resolve(
                $attendancesForDate,
                $schedule,
                isHoliday: (bool) $holiday,
                isOnLeave: (bool) $leave,
                policy: $policy,
                leaveFraction: $leaveFraction,
                leaveSession: $leaveSession,
            );

            $results[$dateString] = [
                'result' => $result,
                'holiday' => $holiday,
                'leave' => $leave,
                'schedule' => $schedule,
                'attendances' => $attendancesForDate,
                'before_join' => $joinDate !== null && $date->copy()->startOfDay()->lessThan($joinDate),
                'after_termination' => $lastWorking !== null && $date->copy()->startOfDay()->greaterThan($lastWorking),
            ];
        }

        return $results;
    }

    /**
     * Present a day-count as an int when whole (clean "5" in UI/JSON) and a
     * 1-decimal float only when fractional (half-day leaves → "4.5").
     */
    private function numify(float $value): int|float
    {
        $rounded = round($value, 1);

        return $rounded == (int) $rounded ? (int) $rounded : $rounded;
    }

    /**
     * Decide what a day MEANS (effective status) so the grid and the dashboard
     * can't diverge — both consume this one classifier. Standard precedence:
     * holiday and weekly-off outrank leave (leave isn't consumed on a non-working
     * day); leave only paints a working day. A punch on an approved-leave day is a
     * conflict left "On Leave" for the Phase B exceptions workflow to reconcile —
     * it is NOT silently relabeled present here.
     *
     * @return string a DayAttendance::* constant
     */
    private function classifyDay(array $ctx): string
    {
        /** @var DayAttendance $result */
        $result = $ctx['result'];
        $hasPunch = $ctx['attendances']->isNotEmpty();

        if ($ctx['holiday']) {
            // Worked on a holiday -> present-day ("Present on Holiday"); otherwise Holiday.
            return $hasPunch ? DayAttendance::PRESENT : DayAttendance::HOLIDAY;
        }

        if (! $ctx['schedule']->isWorkingDay) {
            // Off/weekend: engine already returns PRESENT if punched (off-day work) else WEEKEND.
            return $result->status;
        }

        if ($ctx['leave']) {
            return DayAttendance::ON_LEAVE;
        }

        return $result->status; // PRESENT / LATE / HALF_DAY / SHORT / ABSENT
    }

    /**
     * Map an effective status (+ display context) to the grid's [symbol, remarks].
     *
     * @return array{0: string, 1: string}
     */
    private function mapStatusToDisplay(string $effective, $holiday, $leave, $leaveTypes, Carbon $date, int $worked): array
    {
        $isToday = now()->toDateString() === $date->toDateString();

        switch ($effective) {
            case DayAttendance::HOLIDAY:
                return ['#', 'Holiday'];

            case DayAttendance::ON_LEAVE:
                $symbol = $leave
                    ? ($leaveTypes->firstWhere('id', $leave->leave_type)->symbol ?? '/')
                    : '/';

                return [$symbol, 'On Leave'];

            case DayAttendance::WEEKEND:
            case DayAttendance::DAY_OFF:
                return ['▽', 'Day Off'];

            case DayAttendance::ABSENT:
                return ['▼', 'Absent'];

            default: // worked-day statuses: present / late / half_day / short
                if ($worked > 0) {
                    $remarks = $holiday ? 'Present on Holiday' : 'Present';
                } else {
                    $remarks = $isToday ? 'Currently Working' : 'Not Punched Out';
                }

                return ['√', $remarks];
        }
    }
}
