<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Holiday;
use App\Models\User;
use Carbon\Carbon;
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
    public function getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null): Collection
    {
        $query = User::role('Employee')
            ->select('users.*')
            ->leftJoin('designations', 'users.designation_id', '=', 'designations.id');

        if ($departmentId) {
            $query->where('users.department_id', $departmentId);
        }

        return $query->orderByRaw('COALESCE(designations.hierarchy_level, 999) ASC')
            ->orderBy('users.name')
            ->with([
                'attendances' => function ($query) use ($year, $month) {
                    $query->whereYear('date', $year)
                        ->whereMonth('date', $month);
                },
                'leaves' => function ($query) use ($year, $month) {
                    $query->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                        ->select('leaves.*', 'leave_settings.type as leave_type')
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
        return Holiday::where(function ($query) use ($year, $month) {
            $query->whereYear('from_date', $year)
                ->whereMonth('from_date', $month)
                ->orWhereYear('to_date', $year)
                ->whereMonth('to_date', $month);
        })->get();
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
        $daysInMonth = Carbon::create($year, $month)->daysInMonth;
        $attendanceData = [
            'user_id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'profile_image_url' => $user->profile_image_url,
        ];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day);
            $dateString = $date->toDateString();

            $attendancesForDate = $user->attendances
                ->filter(fn ($a) => Carbon::parse($a->date)->isSameDay($date))
                ->sortBy('punchin');

            $holiday = $holidays->first(fn ($h) => $date->between(
                Carbon::parse($h->from_date)->startOfDay(),
                Carbon::parse($h->to_date)->endOfDay()
            ));
            $leave = $user->leaves
                ->first(fn ($l) => $date->between(
                    Carbon::parse($l->from_date)->startOfDay(),
                    Carbon::parse($l->to_date)->endOfDay()
                ));

            // Defaults
            $symbol = '▼';
            $punchIn = null;
            $punchOut = null;
            $totalWorkHours = '00:00';
            $remarks = 'Absent';

            if ($holiday && ! $leave) {
                if ($attendancesForDate->isNotEmpty()) {
                    $first = $attendancesForDate->first();
                    $last = $attendancesForDate->count() === 1 ? $first : $attendancesForDate->reverse()->first(fn ($item) => $item->punchout !== null);

                    $totalMinutes = $this->calculateTotalMinutes($attendancesForDate);

                    $hours = floor($totalMinutes / 60);
                    $mins = $totalMinutes % 60;
                    $totalWorkHours = sprintf('%02d:%02d', $hours, $mins);
                    $symbol = '√';
                    $remarks = $totalMinutes > 0 ? 'Present on Holiday' : ((now()->toDateString() === $date) ? 'Currently Working' : 'Not Punched Out');
                    $punchIn = $first->punchin;
                    $punchOut = $last ? $last->punchout : null;
                } else {
                    $symbol = '#';
                    $remarks = 'Holiday';
                }
            } elseif ($leave) {
                $symbol = $leaveTypes->firstWhere('id', $leave->leave_type)->symbol ?? '/';
                $remarks = 'On Leave';
            } elseif ($attendancesForDate->isNotEmpty()) {
                $first = $attendancesForDate->first();
                $last = $attendancesForDate->count() === 1 ? $first : $attendancesForDate->reverse()->first(fn ($item) => $item->punchout !== null);

                $totalMinutes = $this->calculateTotalMinutes($attendancesForDate);

                $hours = floor($totalMinutes / 60);
                $mins = $totalMinutes % 60;
                $totalWorkHours = sprintf('%02d:%02d', $hours, $mins);
                $symbol = '√';
                $remarks = $totalMinutes > 0 ? 'Present' : ($date->isToday() ? 'Currently Working' : 'Not Punched Out');
                $punchIn = $first->punchin;
                $punchOut = $last ? $last->punchout : null;
            } elseif ($holiday && ! $attendancesForDate->isNotEmpty()) {
                $symbol = '#';
                $remarks = 'Holiday';
            }

            $attendanceData[$dateString] = [
                'status' => $symbol,
                'punch_in' => $punchIn,
                'punch_out' => $punchOut,
                'total_work_hours' => $totalWorkHours,
                'remarks' => $remarks,
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
        // SETTINGS & DATES
        $settings = AttendanceSetting::first();
        $officeStart = Carbon::parse($settings->office_start_time ?? '09:00:00');
        $lateGraceMins = $settings->late_mark_after ?? 15;
        $weekendDays = $settings->weekend_days ?? ['saturday', 'sunday'];

        $startOfMonth = Carbon::create($currentYear, $currentMonth, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();

        // Stop calculating "Absents" for future dates
        $analysisEndDate = $endOfMonth->isFuture() ? Carbon::now()->endOfDay() : $endOfMonth;

        // BASE CALENDAR METRICS
        $totalDaysInMonth = $startOfMonth->daysInMonth;
        $holidaysCount = $this->getTotalHolidayDays($currentYear, $currentMonth);
        $weekendCount = $this->getWeekendDaysCount($currentYear, $currentMonth, $weekendDays);

        // "Calendar Working Days"
        $calendarWorkingDays = max(0, $totalDaysInMonth - $holidaysCount - $weekendCount);

        // EMPLOYEE COUNT
        $totalEmployees = $isGlobalScope
            ? User::role('Employee')->count()
            : 1;

        // FETCH DATA (Scoped)
        // A. Attendance
        $attendanceQuery = Attendance::whereBetween('date', [$startOfMonth, $endOfMonth])
            ->whereNotNull('punchin');

        if (! $isGlobalScope) {
            $attendanceQuery->where('user_id', $userId);
        }

        $attendanceRecords = $attendanceQuery->get();

        // B. Leaves (Approved Only)
        $leaveQuery = DB::table('leaves')
            ->where('status', 'approved')
            ->where(function ($q) use ($startOfMonth, $endOfMonth) {
                $q->whereBetween('from_date', [$startOfMonth, $endOfMonth])
                    ->orWhereBetween('to_date', [$startOfMonth, $endOfMonth]);
            });

        if (! $isGlobalScope) {
            $leaveQuery->where('user_id', $userId);
        }

        // Calculate "Man-Days" lost to leave
        $totalLeaveManDays = $leaveQuery->get()->sum(function ($leave) use ($startOfMonth, $endOfMonth) {
            $start = Carbon::parse($leave->from_date);
            $end = Carbon::parse($leave->to_date);
            // Clamp leave to this month only
            $effectiveStart = $start->max($startOfMonth);
            $effectiveEnd = $end->min($endOfMonth);

            return max(0, $effectiveStart->diffInDays($effectiveEnd) + 1);
        });

        // AGGREGATE ATTENDANCE METRICS
        $totalPresentManDays = 0;
        $totalLateArrivals = 0;
        $totalWorkMinutes = 0;
        $totalOvertimeMinutes = 0;
        $usersWithPerfectAttendance = 0;

        // Group by User to calculate per-user stats
        $recordsByUser = $attendanceRecords->groupBy('user_id');

        foreach ($recordsByUser as $uId => $userRecords) {
            // Count unique days present for this user
            $daysPresent = $userRecords->groupBy(fn ($r) => Carbon::parse($r->date)->format('Y-m-d'))->count();

            $totalPresentManDays += $daysPresent;

            // Check Perfect Attendance
            if ($daysPresent >= $calendarWorkingDays) {
                $usersWithPerfectAttendance++;
            }

            // Calculate Lates & Hours
            foreach ($userRecords as $record) {
                // Late Check
                if ($record->punchin) {
                    $punchIn = Carbon::parse($record->punchin);
                    $dateStr = $punchIn->format('Y-m-d');
                    $threshold = Carbon::parse("$dateStr ".$officeStart->format('H:i:s'))->addMinutes($lateGraceMins);
                    if ($punchIn->gt($threshold)) {
                        $totalLateArrivals++;
                    }
                }

                // Hours Calculation
                if ($record->punchin && $record->punchout) {
                    $in = Carbon::parse($record->punchin);
                    $out = Carbon::parse($record->punchout);
                    $minutes = $in->diffInMinutes($out);
                    $totalWorkMinutes += $minutes;

                    // Daily Overtime (> 8 hours)
                    if ($minutes > 480) {
                        $totalOvertimeMinutes += ($minutes - 480);
                    }
                }
            }
        }

        // DERIVED CALCULATIONS
        $daysPassed = $startOfMonth->diffInDays($analysisEndDate) + 1;
        $workingDaysPassed = (int) max(0, $daysPassed - (int) ($daysPassed * 2 / 7));

        $totalPotentialManDays = $calendarWorkingDays * $totalEmployees;
        $potentialManDaysPassed = $workingDaysPassed * $totalEmployees;

        // Absent = Potential (So Far) - Present - Leaves
        $totalAbsentManDays = (int) max(0, $potentialManDaysPassed - $totalPresentManDays - $totalLeaveManDays);

        // Percentages
        $attendancePercentage = $totalPotentialManDays > 0
            ? round(($totalPresentManDays / $totalPotentialManDays) * 100, 1)
            : 0;

        // Averages
        $averageWorkHours = $totalPresentManDays > 0
            ? round(($totalWorkMinutes / 60) / $totalPresentManDays, 1)
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
                'present' => (int) $totalPresentManDays,
                'absent' => (int) $totalAbsentManDays,
                'leaves' => (int) $totalLeaveManDays,
                'lateArrivals' => (int) $totalLateArrivals,
                'percentage' => $attendancePercentage,
                'perfectCount' => (int) $usersWithPerfectAttendance,
            ],
            'hours' => [
                'totalWork' => round($totalWorkMinutes / 60, 1),
                'averageDaily' => $averageWorkHours,
                'overtime' => round($totalOvertimeMinutes / 60, 1),
            ],
        ];
    }

    /**
     * Count total holiday days falling within a given month.
     */
    public function getTotalHolidayDays(int $year, int $month): int
    {
        $holidays = Holiday::where(function ($query) use ($year, $month) {
            $query->whereYear('from_date', $year)
                ->whereMonth('from_date', $month)
                ->orWhereYear('to_date', $year)
                ->whereMonth('to_date', $month);
        })->get();

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
        $holidayRanges = Holiday::where(function ($query) use ($year, $month) {
            $query->whereYear('from_date', $year)->whereMonth('from_date', $month)
                ->orWhereYear('to_date', $year)->whereMonth('to_date', $month);
        })->get()->map(function ($holiday) use ($startOfMonth, $endOfRange) {
            $from = Carbon::parse($holiday->from_date);
            $to = Carbon::parse($holiday->to_date);

            return [
                'start' => $from->greaterThan($startOfMonth) ? $from : $startOfMonth,
                'end' => $to->lessThan($endOfRange) ? $to : $endOfRange,
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
     * Calculate total worked minutes from a collection of attendance records for a single date.
     */
    private function calculateTotalMinutes(Collection $attendancesForDate): int
    {
        $totalMinutes = 0;
        foreach ($attendancesForDate as $attendance) {
            if ($attendance->punchin && $attendance->punchout) {
                $in = Carbon::parse($attendance->punchin);
                $out = Carbon::parse($attendance->punchout);
                $totalMinutes += $in->diffInMinutes($out);
            }
        }

        return $totalMinutes;
    }
}
