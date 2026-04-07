<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AttendanceHistoryRequest;
use App\Http\Requests\Api\V1\AttendanceMonthlySummaryRequest;
use App\Http\Requests\Api\V1\PunchAttendanceRequest;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceType;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Attendance\AttendanceValidatorFactory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class AttendanceController extends Controller
{
    public function today(Request $request): JsonResponse
    {
        $today = Carbon::today();
        $currentUser = $request->user();

        try {
            $userLeave = null;

            if (Schema::hasTable('leaves') && Schema::hasTable('leave_settings')) {
                $userLeave = DB::table('leaves')
                    ->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                    ->select('leaves.*', 'leave_settings.type as leave_type_name')
                    ->where('leaves.user_id', $currentUser->id)
                    ->whereDate('leaves.from_date', '<=', $today)
                    ->whereDate('leaves.to_date', '>=', $today)
                    ->first();
            }

            $userAttendances = Attendance::query()
                ->whereNotNull('punchin')
                ->whereDate('date', $today)
                ->where('user_id', $currentUser->id)
                ->orderBy('punchin')
                ->get();

            $totalProductionTime = 0;

            $punches = $userAttendances->map(function (Attendance $attendance) use (&$totalProductionTime) {
                $punchInTime = Carbon::parse($attendance->punchin);
                $punchOutTime = $attendance->punchout ? Carbon::parse($attendance->punchout) : Carbon::now();

                if ($punchOutTime->lt($punchInTime)) {
                    $punchOutTime->addDay();
                }

                $duration = $punchInTime->diffInSeconds($punchOutTime);
                $totalProductionTime += $duration;

                return [
                    'date' => $attendance->date,
                    'punchin_time' => $attendance->punchin,
                    'punchin_location' => $attendance->punchin_location_array,
                    'punchout_time' => $attendance->punchout,
                    'punchout_location' => $attendance->punchout_location_array,
                    'duration' => gmdate('H:i:s', $duration),
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'punches' => $punches,
                    'total_production_time' => gmdate('H:i:s', $totalProductionTime),
                    'is_user_on_leave' => $userLeave,
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving attendance data.',
            ], 500);
        }
    }

    public function history(AttendanceHistoryRequest $request): JsonResponse
    {
        $currentUser = $request->user();
        $perPage = (int) $request->input('perPage', 10);
        $page = (int) $request->input('page', 1);
        $currentMonth = (int) $request->input('currentMonth', now()->month);
        $currentYear = (int) $request->input('currentYear', now()->year);

        try {
            $attendanceRecords = Attendance::query()
                ->whereNotNull('punchin')
                ->where('user_id', $currentUser->id)
                ->whereYear('date', $currentYear)
                ->whereMonth('date', $currentMonth)
                ->orderBy('date')
                ->orderBy('punchin')
                ->get();

            if ($attendanceRecords->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'attendances' => [],
                        'pagination' => [
                            'current_page' => $page,
                            'last_page' => 1,
                            'per_page' => $perPage,
                            'total' => 0,
                        ],
                    ],
                ]);
            }

            $groupedByDate = $attendanceRecords->groupBy(function (Attendance $record) {
                return Carbon::parse($record->date)->format('Y-m-d');
            })->map(function ($dateAttendances, $date) {
                $sortedPunches = $dateAttendances->sortBy('punchin');

                $totalWorkMinutes = 0;
                $completePunches = 0;
                $hasIncompletePunch = false;

                $punches = $sortedPunches->map(function (Attendance $record) use (&$totalWorkMinutes, &$completePunches, &$hasIncompletePunch) {
                    if ($record->punchin && $record->punchout) {
                        $punchIn = Carbon::parse($record->punchin);
                        $punchOut = Carbon::parse($record->punchout);

                        if ($punchOut->lt($punchIn)) {
                            $punchOut->addDay();
                        }

                        $totalWorkMinutes += $punchIn->diffInMinutes($punchOut);
                        $completePunches++;
                    } elseif ($record->punchin && ! $record->punchout) {
                        $hasIncompletePunch = true;
                    }

                    return [
                        'id' => $record->id,
                        'date' => $record->date,
                        'punch_in' => $record->punchin,
                        'punch_out' => $record->punchout,
                        'punchin_location' => $record->punchin_location_array,
                        'punchout_location' => $record->punchout_location_array,
                    ];
                })->values();

                $firstPunch = $sortedPunches->first();
                $lastCompletePunch = $sortedPunches->where('punchout', '!=', null)->last();

                return [
                    'date' => $date,
                    'punchin_time' => $firstPunch?->punchin,
                    'punchout_time' => $lastCompletePunch?->punchout,
                    'total_work_minutes' => round($totalWorkMinutes, 2),
                    'punch_count' => $dateAttendances->count(),
                    'complete_punches' => $completePunches,
                    'has_incomplete_punch' => $hasIncompletePunch,
                    'punches' => $punches,
                ];
            })->values();

            $sortedByDate = $groupedByDate->sortByDesc('date')->values();
            $paginatedData = $sortedByDate->forPage($page, $perPage)->values();
            $totalRecords = $sortedByDate->count();
            $lastPage = (int) ceil($totalRecords / $perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'attendances' => $paginatedData,
                    'pagination' => [
                        'current_page' => $page,
                        'last_page' => $lastPage,
                        'per_page' => $perPage,
                        'total' => $totalRecords,
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving attendance history.',
            ], 500);
        }
    }

    public function dailyTimesheet(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        if (! $this->isManagerUser($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access daily timesheet data.',
            ], 403);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
            'employee' => ['nullable', 'string', 'max:120'],
        ]);

        $selectedDate = (string) ($validated['date'] ?? now()->toDateString());
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['perPage'] ?? 10);
        $employeeKeyword = trim((string) ($validated['employee'] ?? ''));

        try {
            $usersWithAttendanceQuery = User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Employee');
                })
                ->whereHas('attendances', function ($query) use ($selectedDate) {
                    $query->whereNotNull('punchin')
                        ->whereDate('date', $selectedDate);
                });

            if ($employeeKeyword !== '') {
                $usersWithAttendanceQuery->where(function ($query) use ($employeeKeyword) {
                    $query->where('name', 'like', '%'.$employeeKeyword.'%')
                        ->orWhere('employee_id', 'like', '%'.$employeeKeyword.'%');
                });
            }

            $usersWithAttendance = $usersWithAttendanceQuery->get();
            $paginatedUsers = $usersWithAttendance->forPage($page, $perPage)->values();
            $userIds = $paginatedUsers->pluck('id')->all();

            $attendanceRecords = Attendance::query()
                ->with(['user.designation'])
                ->whereNotNull('punchin')
                ->whereDate('date', $selectedDate)
                ->whereIn('user_id', $userIds)
                ->orderBy('user_id')
                ->orderBy('punchin')
                ->get();

            $attendances = $attendanceRecords->groupBy('user_id')->map(function ($userAttendances) {
                $firstRecord = $userAttendances->first();
                $user = $firstRecord?->user;
                $sortedPunches = $userAttendances->sortBy('punchin')->values();

                $totalWorkMinutes = 0;
                $completePunches = 0;
                $hasIncompletePunch = false;

                $punches = $sortedPunches->map(function (Attendance $record) use (&$totalWorkMinutes, &$completePunches, &$hasIncompletePunch) {
                    if ($record->punchin && $record->punchout) {
                        $punchIn = Carbon::parse($record->punchin);
                        $punchOut = Carbon::parse($record->punchout);

                        if ($punchOut->lt($punchIn)) {
                            $punchOut->addDay();
                        }

                        $totalWorkMinutes += $punchIn->diffInMinutes($punchOut);
                        $completePunches++;
                    } elseif ($record->punchin && ! $record->punchout) {
                        $hasIncompletePunch = true;
                    }

                    return [
                        'id' => $record->id,
                        'date' => $record->date,
                        'punch_in' => $record->punchin,
                        'punch_out' => $record->punchout,
                        'punchin_location' => $record->punchin_location_array,
                        'punchout_location' => $record->punchout_location_array,
                    ];
                })->values();

                $firstPunch = $sortedPunches->first();
                $lastCompletePunch = $sortedPunches->whereNotNull('punchout')->last();

                return [
                    'id' => 'user-'.($user?->id ?? 'unknown'),
                    'user_id' => (int) ($user?->id ?? 0),
                    'user' => $this->transformUserForAttendanceCards($user),
                    'date' => $firstRecord?->date,
                    'punchin_time' => $firstPunch?->punchin,
                    'punchout_time' => $lastCompletePunch?->punchout,
                    'punchin_location' => $firstPunch?->punchin_location_array,
                    'punchout_location' => $lastCompletePunch?->punchout_location_array,
                    'total_work_minutes' => round($totalWorkMinutes, 2),
                    'punch_count' => $userAttendances->count(),
                    'complete_punches' => $completePunches,
                    'has_incomplete_punch' => $hasIncompletePunch,
                    'first_punch_date' => $firstRecord?->date,
                    'last_punch_date' => $sortedPunches->last()?->date,
                    'punches' => $punches,
                ];
            })->values();

            $totalUsers = $usersWithAttendance->count();
            $lastPage = max(1, (int) ceil($totalUsers / max($perPage, 1)));

            $allEmployeeUsersQuery = User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Employee');
                })
                ->with('designation');

            if ($employeeKeyword !== '') {
                $allEmployeeUsersQuery->where(function ($query) use ($employeeKeyword) {
                    $query->where('name', 'like', '%'.$employeeKeyword.'%')
                        ->orWhere('employee_id', 'like', '%'.$employeeKeyword.'%');
                });
            }

            $allEmployeeUsers = $allEmployeeUsersQuery->get();

            $presentUserIds = User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Employee');
                })
                ->whereHas('attendances', function ($query) use ($selectedDate) {
                    $query->whereNotNull('punchin')
                        ->whereDate('date', $selectedDate);
                })
                ->pluck('id');

            $absentUsers = $allEmployeeUsers->filter(function (User $user) use ($presentUserIds) {
                return ! $presentUserIds->contains($user->id);
            })->values();

            $absentUserIds = $absentUsers->pluck('id')->all();
            $leaveUserColumn = $this->resolveLeavesUserColumn();
            $leaves = collect();

            if (
                $absentUserIds !== []
                && $leaveUserColumn
                && Schema::hasTable('leaves')
                && Schema::hasTable('leave_settings')
            ) {
                $leaves = DB::table('leaves')
                    ->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                    ->select(
                        'leaves.id',
                        "leaves.{$leaveUserColumn} as user_id",
                        'leaves.leave_type',
                        'leave_settings.type as leave_type_name',
                        'leaves.from_date',
                        'leaves.to_date',
                        'leaves.no_of_days',
                        'leaves.status'
                    )
                    ->whereDate('leaves.from_date', '<=', $selectedDate)
                    ->whereDate('leaves.to_date', '>=', $selectedDate)
                    ->whereIn("leaves.{$leaveUserColumn}", $absentUserIds)
                    ->orderBy('leaves.from_date')
                    ->orderBy('leaves.id')
                    ->get()
                    ->map(function ($leave) {
                        return [
                            'id' => (int) ($leave->id ?? 0),
                            'user_id' => (int) ($leave->user_id ?? 0),
                            'leave_type' => (int) ($leave->leave_type ?? 0),
                            'leave_type_name' => $leave->leave_type_name,
                            'from_date' => $leave->from_date,
                            'to_date' => $leave->to_date,
                            'no_of_days' => (int) ($leave->no_of_days ?? 0),
                            'status' => $leave->status,
                        ];
                    })
                    ->values();
            }

            $serializedAbsentUsers = $absentUsers->map(function (User $user) {
                return $this->transformUserForAttendanceCards($user);
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'selected_date' => $selectedDate,
                    'attendances' => $attendances,
                    'absent_users' => $serializedAbsentUsers,
                    'leaves' => $leaves,
                    'pagination' => [
                        'current_page' => $page,
                        'last_page' => $lastPage,
                        'per_page' => $perPage,
                        'total' => $totalUsers,
                    ],
                    'summary' => [
                        'present_count' => $attendances->count(),
                        'absent_count' => $serializedAbsentUsers->count(),
                        'total_count' => $attendances->count() + $serializedAbsentUsers->count(),
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving daily timesheet data.',
            ], 500);
        }
    }

    public function teamLocations(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        if (! $this->isManagerUser($currentUser)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not authorized to access team location data.',
            ], 403);
        }

        $validated = $request->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $selectedDate = (string) ($validated['date'] ?? now()->toDateString());

        try {
            $attendances = Attendance::query()
                ->with(['user.designation', 'user.attendanceType', 'media'])
                ->whereNotNull('punchin')
                ->whereDate('date', $selectedDate)
                ->orderBy('user_id')
                ->orderBy('punchin')
                ->get();

            $attendanceTypeConfigs = $this->buildAttendanceTypeConfigs();

            $locations = $attendances->groupBy('user_id')->map(function ($userPunches) {
                $user = $userPunches->first()?->user;
                $attendanceType = $user?->attendanceType;
                $baseSlug = $attendanceType ? preg_replace('/_\d+$/', '', (string) $attendanceType->slug) : null;
                $requiresPhoto = in_array($baseSlug, ['geo_polygon', 'route_waypoint'], true);

                $cycles = $userPunches->map(function (Attendance $attendance) use ($requiresPhoto) {
                    return [
                        'attendance_id' => $attendance->id,
                        'punchin_location' => $attendance->punchin_location_array,
                        'punchout_location' => $attendance->punchout_location_array,
                        'punchin_time' => $attendance->punchin,
                        'punchout_time' => $attendance->punchout,
                        'punchin_photo_url' => $requiresPhoto ? $attendance->punchin_photo_url : null,
                        'punchout_photo_url' => $requiresPhoto ? $attendance->punchout_photo_url : null,
                        'is_complete' => ! is_null($attendance->punchout),
                    ];
                })->values();

                $lastCycle = $cycles->last();

                return [
                    'user_id' => (int) ($user?->id ?? 0),
                    'name' => $user?->name ?? 'Unknown',
                    'profile_image_url' => $user?->profile_image_url,
                    'designation' => $user?->designation?->title ?? 'N/A',
                    'attendance_type' => $attendanceType ? [
                        'id' => (int) ($attendanceType->id ?? 0),
                        'name' => $attendanceType->name,
                        'slug' => $attendanceType->slug,
                        'base_slug' => $baseSlug,
                    ] : null,
                    'requires_photo' => $requiresPhoto,
                    'status' => ! empty($lastCycle['punchout_time']) ? 'completed' : 'active',
                    'cycles' => $cycles,
                    'punchin_location' => $lastCycle['punchin_location'] ?? null,
                    'punchout_location' => $lastCycle['punchout_location'] ?? null,
                    'punchin_time' => $lastCycle['punchin_time'] ?? null,
                    'punchout_time' => $lastCycle['punchout_time'] ?? null,
                ];
            })->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'date' => $selectedDate,
                    'locations' => $locations,
                    'attendance_type_configs' => $attendanceTypeConfigs,
                    'stats' => $this->resolveTeamLocationStats($locations),
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving team location data.',
            ], 500);
        }
    }

    public function monthlySummary(AttendanceMonthlySummaryRequest $request): JsonResponse
    {
        $currentUser = $request->user();
        $month = (int) $request->input('month', now()->month);
        $year = (int) $request->input('year', now()->year);

        try {
            $rangeStart = Carbon::createFromDate($year, $month, 1)->startOfDay();
            $rangeEnd = $rangeStart->copy()->endOfMonth()->endOfDay();
            $analysisEnd = $rangeEnd->isFuture() ? now()->endOfDay() : $rangeEnd;

            $officeStartTime = '09:00:00';
            $lateGraceMinutes = 15;
            $weekendDays = ['saturday', 'sunday'];

            if (Schema::hasTable('attendance_settings')) {
                $settings = DB::table('attendance_settings')
                    ->select('office_start_time', 'late_mark_after', 'weekend_days')
                    ->first();

                if ($settings) {
                    $officeStartTime = $settings->office_start_time ?: $officeStartTime;
                    $lateGraceMinutes = is_numeric($settings->late_mark_after)
                        ? (int) $settings->late_mark_after
                        : $lateGraceMinutes;
                    $weekendDays = $settings->weekend_days ?? $weekendDays;
                }
            }

            $normalizedWeekendDays = $this->normalizeWeekendDays($weekendDays);

            $holidayDates = [];

            if (Schema::hasTable('holidays')) {
                $holidays = DB::table('holidays')
                    ->select('from_date', 'to_date')
                    ->whereDate('from_date', '<=', $analysisEnd->toDateString())
                    ->whereDate('to_date', '>=', $rangeStart->toDateString())
                    ->get();

                foreach ($holidays as $holiday) {
                    $holidayStart = Carbon::parse($holiday->from_date)->startOfDay();
                    $holidayEnd = Carbon::parse($holiday->to_date)->startOfDay();

                    if ($holidayEnd->lt($rangeStart) || $holidayStart->gt($analysisEnd)) {
                        continue;
                    }

                    $effectiveStart = $holidayStart->copy()->max($rangeStart)->startOfDay();
                    $effectiveEnd = $holidayEnd->copy()->min($analysisEnd)->startOfDay();
                    $dateCursor = $effectiveStart->copy();

                    while ($dateCursor->lte($effectiveEnd)) {
                        $holidayDates[$dateCursor->format('Y-m-d')] = true;
                        $dateCursor->addDay();
                    }
                }
            }

            $workingDays = 0;
            $workingCursor = $rangeStart->copy()->startOfDay();

            while ($workingCursor->lte($analysisEnd)) {
                $dateKey = $workingCursor->format('Y-m-d');

                if (! $this->isWeekendByConfig($workingCursor, $normalizedWeekendDays) && ! isset($holidayDates[$dateKey])) {
                    $workingDays++;
                }

                $workingCursor->addDay();
            }

            $attendanceRecords = Attendance::query()
                ->where('user_id', $currentUser->id)
                ->whereNotNull('punchin')
                ->whereDate('date', '>=', $rangeStart->toDateString())
                ->whereDate('date', '<=', $analysisEnd->toDateString())
                ->orderBy('date')
                ->orderBy('punchin')
                ->get();

            $recordsByDate = $attendanceRecords->groupBy(function (Attendance $record) {
                return Carbon::parse($record->date)->format('Y-m-d');
            });

            $presentDays = 0;
            $lateArrivals = 0;
            $totalWorkMinutes = 0;
            $totalOvertimeMinutes = 0;

            foreach ($recordsByDate as $date => $records) {
                $presentDays++;

                $sortedRecords = $records->sortBy('punchin')->values();
                $firstPunchIn = $sortedRecords->first()?->punchin;

                if ($firstPunchIn) {
                    $lateThreshold = Carbon::parse($date.' '.$officeStartTime)->addMinutes($lateGraceMinutes);
                    $firstPunchInAt = Carbon::parse(
                        $date.' '.Carbon::parse($firstPunchIn)->format('H:i:s')
                    );

                    if ($firstPunchInAt->gt($lateThreshold)) {
                        $lateArrivals++;
                    }
                }

                $dailyMinutes = 0;

                foreach ($sortedRecords as $record) {
                    if (! $record->punchin || ! $record->punchout) {
                        continue;
                    }

                    $recordDate = Carbon::parse($record->date)->format('Y-m-d');
                    $punchInAt = Carbon::parse($recordDate.' '.Carbon::parse($record->punchin)->format('H:i:s'));
                    $punchOutAt = Carbon::parse($recordDate.' '.Carbon::parse($record->punchout)->format('H:i:s'));

                    if ($punchOutAt->lt($punchInAt)) {
                        $punchOutAt->addDay();
                    }

                    $dailyMinutes += $punchInAt->diffInMinutes($punchOutAt);
                }

                $totalWorkMinutes += $dailyMinutes;

                if ($dailyMinutes > 480) {
                    $totalOvertimeMinutes += ($dailyMinutes - 480);
                }
            }

            $leaveDays = 0;
            $leavesUserColumn = $this->resolveLeavesUserColumn();

            if (Schema::hasTable('leaves') && $leavesUserColumn) {
                $approvedLeaves = DB::table('leaves')
                    ->select('from_date', 'to_date')
                    ->where($leavesUserColumn, $currentUser->id)
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->whereDate('from_date', '<=', $analysisEnd->toDateString())
                    ->whereDate('to_date', '>=', $rangeStart->toDateString())
                    ->get();

                $approvedLeaveDates = [];

                foreach ($approvedLeaves as $leave) {
                    $leaveStart = Carbon::parse($leave->from_date)->startOfDay();
                    $leaveEnd = Carbon::parse($leave->to_date)->startOfDay();

                    if ($leaveEnd->lt($rangeStart) || $leaveStart->gt($analysisEnd)) {
                        continue;
                    }

                    $effectiveStart = $leaveStart->copy()->max($rangeStart)->startOfDay();
                    $effectiveEnd = $leaveEnd->copy()->min($analysisEnd)->startOfDay();
                    $leaveCursor = $effectiveStart->copy();

                    while ($leaveCursor->lte($effectiveEnd)) {
                        $leaveDateKey = $leaveCursor->format('Y-m-d');

                        if (! $this->isWeekendByConfig($leaveCursor, $normalizedWeekendDays) && ! isset($holidayDates[$leaveDateKey])) {
                            $approvedLeaveDates[$leaveDateKey] = true;
                        }

                        $leaveCursor->addDay();
                    }
                }

                $leaveDays = count($approvedLeaveDates);
            }

            $absentDays = max(0, $workingDays - $presentDays - $leaveDays);
            $totalHours = round($totalWorkMinutes / 60, 1);
            $averageDailyHours = $presentDays > 0
                ? round($totalHours / $presentDays, 1)
                : 0.0;
            $overtimeHours = round($totalOvertimeMinutes / 60, 1);

            return response()->json([
                'success' => true,
                'data' => [
                    'month' => $rangeStart->format('Y-m'),
                    'summary' => [
                        'working_days' => (int) $workingDays,
                        'present_days' => (int) $presentDays,
                        'absent_days' => (int) $absentDays,
                        'leave_days' => (int) $leaveDays,
                        'late_arrivals' => (int) $lateArrivals,
                        'total_hours' => $totalHours,
                        'average_daily_hours' => $averageDailyHours,
                        'overtime_hours' => $overtimeHours,
                    ],
                ],
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while retrieving attendance monthly summary.',
            ], 500);
        }
    }

    public function punch(PunchAttendanceRequest $request): JsonResponse
    {
        $user = $request->user();
        $attendanceType = $user->attendanceType;

        if (! $attendanceType || ! $attendanceType->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active attendance type assigned to user.',
            ], 422);
        }

        $validation = $this->validateAttendanceType($attendanceType, $request);
        if ($validation['status'] === 'error') {
            return response()->json($validation, $validation['code']);
        }

        $punchService = new AttendancePunchService;
        $result = $punchService->processPunch($user, $request);

        if ($result['status'] === 'error') {
            return response()->json($result, $result['code']);
        }

        return response()->json(array_merge(['success' => true], $result));
    }

    private function transformUserForAttendanceCards(?User $user): array
    {
        if (! $user) {
            return [
                'id' => 0,
                'name' => 'Unknown',
                'employee_id' => null,
                'email' => null,
                'phone' => null,
                'profile_image_url' => null,
                'designation' => [
                    'id' => null,
                    'title' => null,
                ],
            ];
        }

        return [
            'id' => (int) $user->id,
            'name' => $user->name,
            'employee_id' => $user->employee_id,
            'email' => $user->email,
            'phone' => $user->phone,
            'profile_image_url' => $user->profile_image_url,
            'designation' => [
                'id' => $user->designation_id ? (int) $user->designation_id : null,
                'title' => $user->designation?->title,
            ],
        ];
    }

    private function buildAttendanceTypeConfigs(): array
    {
        return AttendanceType::query()
            ->where('is_active', true)
            ->whereNotNull('config')
            ->get()
            ->map(function (AttendanceType $attendanceType) {
                $baseSlug = preg_replace('/_\d+$/', '', (string) $attendanceType->slug);

                if (! in_array($baseSlug, ['geo_polygon', 'route_waypoint'], true)) {
                    return null;
                }

                $config = is_array($attendanceType->config) ? $attendanceType->config : [];
                $hasPolygonData = ! empty($config['polygon']) || ! empty($config['polygons']);
                $hasRouteData = ! empty($config['waypoints']) || ! empty($config['routes']);

                if (! $hasPolygonData && ! $hasRouteData) {
                    return null;
                }

                return [
                    'id' => (int) $attendanceType->id,
                    'name' => $attendanceType->name,
                    'slug' => $attendanceType->slug,
                    'base_slug' => $baseSlug,
                    'config' => $config,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resolveTeamLocationStats($locations): array
    {
        $total = $locations->count();
        $active = $locations->filter(function ($location): bool {
            return ($location['status'] ?? 'active') === 'active';
        })->count();

        return [
            'total' => $total,
            'checked_in' => $active,
            'completed' => max(0, $total - $active),
        ];
    }

    private function isManagerUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    private function normalizeWeekendDays(mixed $weekendDays): array
    {
        if (is_string($weekendDays)) {
            $decodedWeekendDays = json_decode($weekendDays, true);
            $weekendDays = is_array($decodedWeekendDays)
                ? $decodedWeekendDays
                : [$weekendDays];
        }

        if (! is_array($weekendDays) || $weekendDays === []) {
            return ['saturday', 'sunday'];
        }

        $normalizedWeekendDays = array_map(function ($day) {
            return strtolower(trim((string) $day));
        }, $weekendDays);

        $filteredWeekendDays = array_filter($normalizedWeekendDays, function ($day) {
            return $day !== '';
        });

        return array_values(array_unique($filteredWeekendDays));
    }

    private function isWeekendByConfig(Carbon $date, array $weekendDays): bool
    {
        return in_array(strtolower($date->format('l')), $weekendDays, true);
    }

    private function resolveLeavesUserColumn(): ?string
    {
        if (Schema::hasColumn('leaves', 'user_id')) {
            return 'user_id';
        }

        if (Schema::hasColumn('leaves', 'user')) {
            return 'user';
        }

        return null;
    }

    private function validateAttendanceType($attendanceType, Request $request): array
    {
        try {
            $validator = AttendanceValidatorFactory::create($attendanceType, $request);

            return $validator->validate();
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'error',
                'message' => 'Invalid attendance type configuration: '.$exception->getMessage(),
                'code' => 422,
            ];
        } catch (\Throwable $exception) {
            Log::error('Attendance validation error: '.$exception->getMessage());

            return [
                'status' => 'error',
                'message' => 'Validation failed. Please try again.',
                'code' => 500,
            ];
        }
    }
}
