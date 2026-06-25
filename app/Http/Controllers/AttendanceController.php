<?php

namespace App\Http\Controllers;


use App\Jobs\ExportAttendanceReport;
use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Attendance\AttendanceQueryService;
use App\Services\Attendance\AttendanceReportService;
use App\Services\Attendance\AttendanceValidatorFactory;
use App\Traits\HandlesApiExceptions;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    use HandlesApiExceptions;

    protected AttendanceReportService $attendanceReportService;

    protected AttendancePunchService $attendancePunchService;

    protected AttendanceQueryService $attendanceQueryService;

    public function __construct(
        AttendanceReportService $attendanceReportService,
        AttendancePunchService $attendancePunchService,
        AttendanceQueryService $attendanceQueryService
    ) {
        $this->attendanceReportService = $attendanceReportService;
        $this->attendancePunchService = $attendancePunchService;
        $this->attendanceQueryService = $attendanceQueryService;
    }

    public function indexUnified(): Response
    {
        return Inertia::render('Attendance/AttendancePage', [
            'title' => 'Attendance',
            'attendanceSettings' => AttendanceSetting::first(),
            'attendanceTypes' => AttendanceType::with(['biometricDevices:id,name,serial_number,location'])->get(),
            'departments' => Department::active()->get(['id', 'name']),
            // Map to plain arrays so Inertia serialization does not invoke the models'
            // toArray() overrides/appended accessors (e.g. Designation appends department_name,
            // which lazy-loads `department` and 500s under preventLazyLoading in dev / N+1s in prod).
            'employees' => User::role('Employee')
                ->select('id', 'name', 'department_id', 'designation_id')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'department_id' => $u->department_id,
                    'designation_id' => $u->designation_id,
                ])
                ->values(),
            'designations' => Designation::select('id', 'title')
                ->orderBy('title')
                ->get()
                ->map(fn ($d) => ['id' => $d->id, 'title' => $d->title])
                ->values(),
        ]);
    }

    public function index1(): Response
    {
        return $this->indexUnified();
    }

    public function index2(): Response
    {
        return Inertia::render('AttendanceEmployee', [
            'title' => 'My Attendance',
            // Map to plain arrays so Inertia serialization does not invoke the models'
            // toArray() overrides/appended accessors (e.g. Designation appends department_name,
            // which lazy-loads `department` and 500s under preventLazyLoading in dev / N+1s in prod).
            'employees' => User::role('Employee')
                ->select('id', 'name', 'department_id', 'designation_id')
                ->orderBy('name')
                ->get()
                ->map(fn ($u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'department_id' => $u->department_id,
                    'designation_id' => $u->designation_id,
                ])
                ->values(),
        ]);
    }

    public function index3(): Response
    {
        return $this->indexUnified();
    }

    public function paginate(Request $request): JsonResponse
    {
        try {
            $perPage = (int) $request->get('perPage', 20);
            $page = (int) $request->get('page', 1);
            $employee = $request->get('employee');
            $currentMonth = (int) $request->get('currentMonth');
            $currentYear = (int) $request->get('currentYear');
            $departmentId = $request->get('department_id') ? (int) $request->get('department_id') : null;

            $users = $this->attendanceReportService->getEmployeeUsersWithAttendanceAndLeaves($currentYear, $currentMonth, $departmentId);
            $leaveTypes = LeaveSetting::all();
            $holidays = $this->attendanceReportService->getHolidaysForMonth($currentYear, $currentMonth);
            $leaveCountsArray = $this->attendanceReportService->getLeaveCountsArray($currentYear, $currentMonth);

            // Map user data to attendance
            $attendances = $users->map(function ($user) use ($currentYear, $currentMonth, $holidays, $leaveTypes) {
                return $this->attendanceReportService->getUserAttendanceData($user, $currentYear, $currentMonth, $holidays, $leaveTypes);
            });

            // Filter first (before pagination)
            if (! empty($employee)) {
                $attendances = $attendances->filter(function ($attendance) use ($employee) {
                    return stripos($attendance['name'], $employee) !== false;
                });
                $page = 1; // Reset page if filtered
            }

            // Preserving the designation hierarchy sorting order from service
            $sortedAttendances = $attendances->values();
            $total = $sortedAttendances->count();
            $lastPage = ceil($total / $perPage);
            $paginated = $sortedAttendances->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'data' => $paginated,
                'total' => $total,
                'page' => $page,
                'last_page' => $lastPage,
                'leaveTypes' => $leaveTypes,
                'leaveCounts' => $leaveCountsArray,
                'settings' => AttendanceSetting::first(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error in paginate method: '.$e->getMessage());

            return response()->json(['error' => 'An error occurred while fetching attendance data.'], 500);
        }
    }

    public function updateAttendance(Request $request): JsonResponse
    {
        try {
            // Validate the incoming request data
            $validatedData = $request->validate([
                'user_id' => 'required|integer',
                'date' => 'required|date',
                'symbol' => 'required|string|max:255',
            ]);

            $userId = $validatedData['user_id'];
            $date = $validatedData['date'];
            $symbol = $validatedData['symbol'];

            // Check if the attendance record already exists
            $attendance = Attendance::where('user_id', $userId)->whereDate('date', $date)->first();

            // If the record doesn't exist, create a new one
            if (! $attendance) {
                $attendance = new Attendance;
                $attendance->user_id = $userId;
                $attendance->date = $date;
            }

            $attendance->symbol = $symbol;
            $attendance->save();

            return response()->json(['message' => 'Attendance updated successfully']);
        } catch (\Exception $e) {
            return response()->json(['error' => $this->safeExceptionMessage($e, 'Failed to update attendance.')], 500);
        }
    }

    public function punch(Request $request)
    {
        $user = Auth::user();

        // 1. Resolve the effective SET of allowed attendance methods (override → location).
        $attendanceTypes = $user->resolvedAttendanceTypes();

        if ($attendanceTypes->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active attendance type assigned to user.',
            ], 422);
        }

        // 2. Multi-method validation: punch is valid if ANY allowed method validates.
        $validation = $this->validateAnyAttendanceType($attendanceTypes, $request);
        if ($validation['status'] === 'error') {
            return response()->json($validation, $validation['code']);
        }

        // 3. Process the punch using the service
        $result = $this->attendancePunchService->processPunch($user, $request);

        if ($result['status'] === 'error') {
            return response()->json($result, $result['code']);
        }

        return response()->json($result);
    }

    /**
     * Validate attendance based on type configuration
     */
    /**
     * Multi-method (OR) validation: succeeds on the first attendance type that validates.
     * If all fail, returns the most relevant error (highest non-422 code, else last).
     */
    private function validateAnyAttendanceType($attendanceTypes, Request $request)
    {
        $errors = [];
        foreach ($attendanceTypes as $attendanceType) {
            if (! $attendanceType || ! $attendanceType->is_active) {
                continue;
            }
            $result = $this->validateAttendanceType($attendanceType, $request);
            if (($result['status'] ?? null) === 'success') {
                return $result;
            }
            $errors[] = $result;
        }

        if (empty($errors)) {
            return ['status' => 'error', 'message' => 'No active attendance type assigned to user.', 'code' => 422];
        }

        // Prefer a meaningful failure (e.g. 403 device/location) over a generic 422.
        usort($errors, fn ($a, $b) => ($b['code'] ?? 0) <=> ($a['code'] ?? 0));

        return $errors[0];
    }

    private function validateAttendanceType($attendanceType, Request $request)
    {
        try {
            $validator = AttendanceValidatorFactory::create($attendanceType, $request);

            return $validator->validate();
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 'error',
                'message' => 'Invalid attendance type configuration: '.$e->getMessage(),
                'code' => 422,
            ];
        } catch (\Exception $e) {
            Log::error('Attendance validation error: '.$e->getMessage());

            return [
                'status' => 'error',
                'message' => 'Validation failed. Please try again.',
                'code' => 500,
            ];
        }
    }

    public function getUserLocationsForDate(Request $request): JsonResponse
    {
        try {
            $selectedDate = Carbon::parse($request->query('date'))->format('Y-m-d');
            $locations = $this->attendanceQueryService->getUserLocationsForDate($selectedDate);

            $attendanceTypeConfigs = AttendanceType::all()->map(function ($type) {
                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'slug' => $type->slug,
                    'base_slug' => preg_replace('/_\d+$/', '', (string) $type->slug),
                    'config' => $type->config ?? [],
                ];
            });

            return response()->json([
                'success' => true,
                'locations' => $locations,
                'attendance_type_configs' => $attendanceTypeConfigs,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get user locations: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve locations.'], 500);
        }
    }

    public function getCurrentUserPunch(): JsonResponse
    {
        try {
            $userId = (int) Auth::id();
            $data = $this->attendanceQueryService->getTodayAttendance($userId);

            return response()->json($data);
        } catch (\Exception $e) {
            Log::error('Failed to get current user punch: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve today\'s punch data.'], 500);
        }
    }

    public function getAllUsersAttendanceForDate(Request $request): JsonResponse
    {
        try {
            $selectedDate = $request->query('date', now()->toDateString());
            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('perPage', 25);
            $employeeKeyword = trim((string) $request->query('employee', ''));

            $usersWithAttendanceQuery = User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Employee');
                })
                ->whereHas('attendances', function ($query) use ($selectedDate) {
                    $query->whereNotNull('punchin')
                        ->where('policy_status', '!=', 'rejected')
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
                ->where('policy_status', '!=', 'rejected')
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
                    'user' => [
                        'id' => (int) ($user?->id ?? 0),
                        'name' => $user?->name,
                        'employee_id' => $user?->employee_id,
                        'phone' => $user?->phone,
                        'profile_image' => $user?->profile_image,
                        'profile_image_url' => $user?->profile_image_url,
                        'designation' => [
                            'id' => $user?->designation_id ? (int) $user->designation_id : null,
                            'title' => $user?->designation?->title,
                        ],
                    ],
                    'date' => $firstRecord?->date,
                    'punchin_time' => $firstPunch?->punchin,
                    'punchin_id' => $firstPunch?->id,
                    'punchout_time' => $lastCompletePunch?->punchout,
                    'punchout_id' => $sortedPunches->last()?->id,
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

            return response()->json([
                'attendances' => $attendances,
                'total' => $totalUsers,
                'last_page' => $lastPage,
                'current_page' => $page,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get all users attendance: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve daily timesheet.'], 500);
        }
    }

    public function getCurrentUserAttendanceForDate(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date', now()->toDateString());
            $userId = (int) Auth::id();

            $page = (int) $request->query('page', 1);
            $perPage = (int) $request->query('perPage', 10);

            $filters = [
                'page' => $page,
                'per_page' => $perPage,
                'date' => $date,
                'scope' => 'self',
                'currentMonth' => $request->query('currentMonth'),
                'currentYear' => $request->query('currentYear'),
                'employee' => $request->query('employee'),
            ];

            $result = $this->attendanceQueryService->getAttendanceHistory($userId, $filters);

            return response()->json([
                'attendances' => $result['attendances'] ?? [],
                'total' => $result['pagination']['total'] ?? count($result['attendances'] ?? []),
                'current_page' => $result['pagination']['current_page'] ?? $page,
                'last_page' => $result['pagination']['last_page'] ?? 1,
                'per_page' => $result['pagination']['per_page'] ?? $perPage,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get current user attendance for date: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve attendance record.'], 500);
        }
    }

    public function getClientIp(Request $request): JsonResponse
    {
        return response()->json([
            'ip' => $request->ip(),
        ]);
    }

    public function getPresentUsersForDate(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date', now()->toDateString());
            $users = $this->attendanceQueryService->getPresentUsersForDate($date);

            return response()->json([
                'users' => $users,
                'count' => count($users),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get present users: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve present employees.'], 500);
        }
    }

    public function getAbsentUsersForDate(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date', now()->toDateString());
            $employeeKeyword = trim((string) $request->query('employee', ''));

            $allUsersQuery = User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Employee');
                });

            if ($employeeKeyword !== '') {
                $allUsersQuery->where(function ($query) use ($employeeKeyword) {
                    $query->where('name', 'like', '%'.$employeeKeyword.'%')
                        ->orWhere('employee_id', 'like', '%'.$employeeKeyword.'%');
                });
            }

            $allUsers = $allUsersQuery->get();

            $presentUserIds = User::query()
                ->whereHas('roles', function ($query) {
                    $query->where('name', 'Employee');
                })
                ->whereHas('attendances', function ($query) use ($date) {
                    $query->whereNotNull('punchin')
                        ->where('policy_status', '!=', 'rejected')
                        ->whereDate('date', $date);
                })
                ->pluck('id');

            $absentUsers = $allUsers->filter(function (User $user) use ($presentUserIds) {
                return ! $presentUserIds->contains($user->id);
            })->values();

            $scheduleResolver = app(\App\Services\Attendance\Contracts\ScheduleResolver::class);
            $parsedDate = Carbon::parse($date);
            $now = Carbon::now();

            $absentCollection = collect();
            $offCollection = collect();
            $upcomingCollection = collect();

            foreach ($absentUsers as $user) {
                $schedule = $scheduleResolver->resolve($user->id, $parsedDate);
                if ($schedule->isWorkingDay) {
                    $isUpcoming = $parsedDate->isFuture() || ($parsedDate->isToday() && $now->lt($schedule->start));
                    if ($isUpcoming) {
                        $user->shift_start_time = $schedule->start->format('g:i A');
                        $upcomingCollection->push($user);
                    } else {
                        $absentCollection->push($user);
                    }
                } else {
                    $offCollection->push($user);
                }
            }

            $absentUsers = $absentCollection;
            $offUsers = $offCollection;
            $upcomingUsers = $upcomingCollection;

            $absentUserIds = $absentUsers->pluck('id')->all();

            $leaveUserColumn = null;
            if (Schema::hasColumn('leaves', 'user_id')) {
                $leaveUserColumn = 'user_id';
            } elseif (Schema::hasColumn('leaves', 'user')) {
                $leaveUserColumn = 'user';
            } elseif (Schema::hasColumn('leaves', 'employee_id')) {
                $leaveUserColumn = 'employee_id';
            }

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
                    ->whereDate('leaves.from_date', '<=', $date)
                    ->whereDate('leaves.to_date', '>=', $date)
                    ->whereIn("leaves.{$leaveUserColumn}", $absentUserIds)
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
                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_image' => $user->profile_image,
                    'profile_image_url' => $user->profile_image_url,
                ];
            })->values();

            $serializedOffUsers = $offUsers->map(function (User $user) {
                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_image' => $user->profile_image,
                    'profile_image_url' => $user->profile_image_url,
                ];
            })->values();

            $serializedUpcomingUsers = $upcomingUsers->map(function (User $user) {
                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'employee_id' => $user->employee_id,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'profile_image' => $user->profile_image,
                    'profile_image_url' => $user->profile_image_url,
                    'shift_start_time' => $user->shift_start_time,
                ];
            })->values();

            return response()->json([
                'absent_users' => $serializedAbsentUsers,
                'off_users' => $serializedOffUsers,
                'upcoming_users' => $serializedUpcomingUsers,
                'leaves' => $leaves,
                'total_absent' => $serializedAbsentUsers->count(),
                'total_off' => $serializedOffUsers->count(),
                'total_upcoming' => $serializedUpcomingUsers->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get absent users: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve absent employees.'], 500);
        }
    }

    public function getMonthlyAttendanceStats(Request $request): JsonResponse
    {
        try {
            $currentMonth = (int) $request->get('currentMonth', now()->month);
            $currentYear = (int) $request->get('currentYear', now()->year);
            $userId = $request->get('userId') ? (int) $request->get('userId') : null;
            $routeName = $request->route()?->getName();
            if ($routeName === 'attendance.myMonthlyStats' || ! Auth::user()->can('attendance.view')) {
                $userId = Auth::id();
            }
            $isGlobalScope = $userId === null;

            $stats = $this->attendanceReportService->calculateMonthlyStats($currentMonth, $currentYear, $isGlobalScope, $userId);

            return response()->json([
                'stats' => $stats,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get monthly attendance stats: '.$e->getMessage());

            return response()->json(['error' => 'Failed to calculate monthly statistics.'], 500);
        }
    }

    public function getDailyOverviewStats(Request $request): JsonResponse
    {
        try {
            $date = $request->query('date', now()->toDateString());
            $departmentId = $request->query('department_id') ? (int) $request->query('department_id') : null;

            $totalEmployeesQuery = User::query()->whereHas('roles', function ($q) {
                $q->where('name', 'Employee');
            });
            if ($departmentId) {
                $totalEmployeesQuery->where('department_id', $departmentId);
            }
            $totalEmployees = $totalEmployeesQuery->count();

            $presentUsersIds = Attendance::whereDate('date', $date)
                ->whereNotNull('punchin')
                ->where('policy_status', '!=', 'rejected')
                ->pluck('user_id')
                ->unique();

            $presentCount = $presentUsersIds->count();

            $statusService = app(\App\Services\Attendance\AttendanceStatusService::class);
            $resolver = app(\App\Services\Attendance\Contracts\ScheduleResolver::class);

            $dayPunches = Attendance::whereDate('date', $date)
                ->whereNotNull('punchin')
                ->where('policy_status', '!=', 'rejected')
                ->get()
                ->groupBy('user_id');

            $lateCount = 0;
            foreach ($dayPunches as $userId => $punches) {
                $shift = $resolver->resolve((int) $userId, \Carbon\Carbon::parse($date));
                $day = $statusService->resolve($punches, $shift);
                if ($day->late_minutes > 0) {
                    $lateCount++;
                }
            }

            $onLeaveCount = 0;
            if (Schema::hasTable('leaves')) {
                $leaveUserColumn = Schema::hasColumn('leaves', 'user_id') ? 'user_id' : 'employee_id';
                $onLeaveCount = DB::table('leaves')
                    ->whereDate('from_date', '<=', $date)
                    ->whereDate('to_date', '>=', $date)
                    ->whereRaw('LOWER(status) = ?', ['approved'])
                    ->pluck($leaveUserColumn)
                    ->unique()
                    ->count();
            }

            $absentCount = max(0, $totalEmployees - $presentCount - $onLeaveCount);

            return response()->json([
                'present' => $presentCount,
                'absent' => $absentCount,
                'late' => $lateCount,
                'on_leave' => $onLeaveCount,
                'total' => $totalEmployees,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get daily overview stats: '.$e->getMessage());

            return response()->json(['error' => 'Failed to calculate daily statistics.'], 500);
        }
    }

    public function checkForLocationUpdates($date)
    {
        try {
            $lastUpdate = Attendance::whereDate('date', $date)->max('updated_at');
            $lastUpdateTime = $lastUpdate ? Carbon::parse($lastUpdate) : null;

            return response()->json([
                'success' => true,
                'has_updates' => $lastUpdate !== null,
                'last_updated' => $lastUpdateTime?->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check for location updates: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to check for location updates.',
            ], 500);
        }
    }

    public function markAsPresent(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'date' => 'required|date',
            ]);

            $audit = app(AttendanceAuditService::class);
            $attendance = null;

            DB::transaction(function () use ($validated, $audit, $request, &$attendance) {
                $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)
                    ->resolve((int) $validated['user_id'], \Carbon\Carbon::parse($validated['date']));
                $punchin = $shift->start;
                $attendance = Attendance::updateOrCreate(
                    ['user_id' => $validated['user_id'], 'date' => $validated['date']],
                    ['symbol' => '√', 'punchin' => $punchin]
                );
                $audit->record('mark_present', $attendance->id, null, $attendance->only(['punchin', 'symbol', 'date', 'user_id']), $request->input('reason'), $request);
            });

            return response()->json([
                'success' => true,
                'message' => 'Employee marked as present successfully',
                'attendance' => $attendance,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to mark user as present: '.$e->getMessage());

            return response()->json(['error' => 'Failed to mark present.'], 500);
        }
    }

    public function bulkMarkAsPresent(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'exists:users,id',
                'date' => 'required|date',
            ]);

            $date = Carbon::parse($validated['date'])->format('Y-m-d');
            $audit = app(AttendanceAuditService::class);

            $attendances = [];
            DB::transaction(function () use ($validated, $date, $audit, $request, &$attendances) {
                foreach ($validated['user_ids'] as $userId) {
                    $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)
                        ->resolve((int) $userId, \Carbon\Carbon::parse($date));
                    $punchin = $shift->start;
                    $attendance = Attendance::updateOrCreate(
                        ['user_id' => $userId, 'date' => $date],
                        ['symbol' => '√', 'punchin' => $punchin]
                    );
                    $audit->record('mark_present', $attendance->id, null, $attendance->only(['punchin', 'symbol', 'date', 'user_id']), $request->input('reason'), $request);
                    $attendances[] = $attendance;
                }
            });

            return response()->json([
                'success' => true,
                'message' => 'Employees marked as present successfully',
                'count' => count($attendances),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to bulk mark as present: '.$e->getMessage());

            return response()->json(['error' => 'Failed to bulk mark present.'], 500);
        }
    }

    public function checkTimesheetUpdates($date, $month = null)
    {
        try {
            $query = Attendance::query()->whereDate('date', $date);

            if ($month) {
                $year = (int) substr($month, 0, 4);
                $monthNumber = (int) substr($month, 5, 2);

                $query->orWhere(function ($orQuery) use ($year, $monthNumber) {
                    $orQuery->whereYear('date', $year)
                        ->whereMonth('date', $monthNumber);
                });
            }

            $lastUpdate = $query->max('updated_at');
            $hasRecords = Attendance::query()->whereDate('date', $date)->exists();

            return response()->json([
                'success' => true,
                'has_updates' => $lastUpdate !== null,
                'has_records' => $hasRecords,
                'last_updated' => $lastUpdate ? Carbon::parse($lastUpdate)->toIso8601String() : null,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to check for timesheet updates: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Failed to check for timesheet updates.',
            ], 500);
        }
    }

    public function exportDailyTimesheet(Request $request)
    {
        $type = $request->query('type', 'excel');

        if ($type === 'pdf') {
            return $this->exportPdf($request);
        }

        return $this->exportExcel($request);
    }

    public function exportMonthlyCalendar(Request $request)
    {
        $type = $request->query('type', 'excel');

        if ($type === 'pdf') {
            return $this->exportAdminPdf($request);
        }

        return $this->exportAdminExcel($request);
    }

    public function checkExportStatus($filename)
    {
        $exists = Storage::disk('public')->exists('exports/'.$filename);
        if ($exists) {
            return response()->json(['status' => 'ready', 'url' => asset('storage/exports/'.$filename)]);
        }

        return response()->json(['status' => 'processing'], 202);
    }

    public function exportExcel(Request $request)
    {
        try {
            $date = $request->input('date');
            $filename = 'Daily_Timesheet_'.date('Y_m_d', strtotime($date)).'_'.time().'.xlsx';

            ExportAttendanceReport::dispatch('daily_excel', $date, null, Auth::id(), $filename);

            return response()->json([
                'success' => true,
                'queued' => true,
                'filename' => $filename,
                'download_url' => asset('storage/exports/'.$filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Excel export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    public function exportPdf(Request $request)
    {
        try {
            $date = $request->input('date');
            $filename = 'Daily_Timesheet_'.date('Y_m_d', strtotime($date)).'_'.time().'.pdf';

            ExportAttendanceReport::dispatch('daily_pdf', $date, null, Auth::id(), $filename);

            return response()->json([
                'success' => true,
                'queued' => true,
                'filename' => $filename,
                'download_url' => asset('storage/exports/'.$filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'PDF export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    public function exportAdminExcel(Request $request)
    {
        try {
            $month = $request->get('month');
            $filename = 'DBEDC_Attendance_'.$month.'_'.time().'.xlsx';

            ExportAttendanceReport::dispatch('monthly_excel', null, $month, Auth::id(), $filename);

            return response()->json([
                'success' => true,
                'queued' => true,
                'filename' => $filename,
                'download_url' => asset('storage/exports/'.$filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Excel export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    public function exportAdminPdf(Request $request)
    {
        try {
            $month = $request->get('month');
            $from = Carbon::parse($month.'-01');
            $monthName = $from->format('F Y');
            $filename = 'DBEDC_Attendance_'.$monthName.'_'.time().'.pdf';

            ExportAttendanceReport::dispatch('monthly_pdf', null, $month, Auth::id(), $filename);

            return response()->json([
                'success' => true,
                'queued' => true,
                'filename' => $filename,
                'download_url' => asset('storage/exports/'.$filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'PDF export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    public function updateAttendanceRecord(Request $request, $id): JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $attendance = Attendance::findOrFail($id);

            $validated = $request->validate([
                'punchin' => 'nullable|date_format:Y-m-d H:i:s',
                'punchout' => 'nullable|date_format:Y-m-d H:i:s',
            ]);

            if (! empty($validated['punchin']) && ! empty($validated['punchout'])) {
                $punchin = Carbon::parse($validated['punchin']);
                $punchout = Carbon::parse($validated['punchout']);
                if ($punchin->gte($punchout)) {
                    return response()->json([
                        'error' => 'Punch-in time must be before punch-out time',
                    ], 422);
                }
            }

            $audit = app(AttendanceAuditService::class);
            $before = $attendance->only(['punchin', 'punchout', 'symbol', 'date']);

            DB::transaction(function () use ($attendance, $validated, $audit, $before, $id, $request) {
                $attendance->update($validated);
                $audit->record('update', (int) $id, $before, $attendance->only(['punchin', 'punchout', 'symbol', 'date']), $request->input('reason'), $request);
            });

            return response()->json([
                'success' => true,
                'message' => 'Attendance record updated successfully',
                'attendance' => $attendance->fresh(['user']),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating attendance record', [
                'error' => $e->getMessage(),
                'attendance_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to update attendance record',
            ], 500);
        }
    }

    public function addAttendanceRecord(Request $request): JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'date' => 'required|date',
                'punchin' => 'nullable|date_format:Y-m-d H:i:s',
                'punchout' => 'nullable|date_format:Y-m-d H:i:s',
                'punchin_location' => 'nullable|array',
                'punchout_location' => 'nullable|array',
            ]);

            if (! empty($validated['punchin']) && ! empty($validated['punchout'])) {
                $punchin = Carbon::parse($validated['punchin']);
                $punchout = Carbon::parse($validated['punchout']);
                if ($punchin->gte($punchout)) {
                    return response()->json([
                        'error' => 'Punch-in time must be before punch-out time',
                    ], 422);
                }
            }

            $audit = app(AttendanceAuditService::class);
            $attendance = null;

            DB::transaction(function () use ($validated, $audit, $request, &$attendance) {
                $attendance = Attendance::create([
                    'user_id' => $validated['user_id'],
                    'date' => $validated['date'],
                    'punchin' => $validated['punchin'],
                    'punchout' => $validated['punchout'],
                    'punchin_location' => isset($validated['punchin_location']) ? json_encode($validated['punchin_location']) : null,
                    'punchout_location' => isset($validated['punchout_location']) ? json_encode($validated['punchout_location']) : null,
                ]);
                $audit->record('create', $attendance->id, null, $attendance->only(['punchin', 'punchout', 'symbol', 'date', 'user_id']), $request->input('reason'), $request);
            });

            return response()->json([
                'success' => true,
                'message' => 'Attendance record added successfully',
                'attendance' => $attendance->fresh(['user']),
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error adding attendance record', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'error' => 'Failed to add attendance record',
            ], 500);
        }
    }

    public function deleteAttendanceRecord(Request $request, $id): JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $attendance = Attendance::findOrFail($id);
            $audit = app(AttendanceAuditService::class);
            $before = $attendance->only(['punchin', 'punchout', 'symbol', 'date', 'user_id']);

            DB::transaction(function () use ($attendance, $audit, $before, $id, $request) {
                $attendance->delete();
                $audit->record('delete', (int) $id, $before, null, $request->input('reason'), $request);
            });

            return response()->json([
                'success' => true,
                'message' => 'Attendance record deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting attendance record', [
                'error' => $e->getMessage(),
                'attendance_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to delete attendance record',
            ], 500);
        }
    }

    public function updateAttendanceStatus(Request $request, $id): JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $attendance = Attendance::findOrFail($id);

            $validated = $request->validate([
                'symbol' => 'required|string|max:10',
            ]);

            $audit = app(AttendanceAuditService::class);
            $before = $attendance->only(['symbol', 'date', 'user_id']);

            DB::transaction(function () use ($attendance, $validated, $audit, $before, $id, $request) {
                $attendance->symbol = $validated['symbol'];
                $attendance->save();
                $audit->record('status', (int) $id, $before, $attendance->only(['symbol', 'date', 'user_id']), $request->input('reason'), $request);
            });

            return response()->json([
                'success' => true,
                'message' => 'Attendance status updated successfully',
                'attendance' => $attendance->fresh(['user']),
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating attendance status', [
                'error' => $e->getMessage(),
                'attendance_id' => $id,
            ]);

            return response()->json([
                'error' => 'Failed to update attendance status',
            ], 500);
        }
    }

    public function auditHistory(int $id): JsonResponse
    {
        $logs = \App\Models\HRM\AttendanceAuditLog::with('actor:id,name')
            ->where('attendance_id', $id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'before' => $log->before,
                'after' => $log->after,
                'reason' => $log->reason,
                'ip' => $log->ip,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['logs' => $logs]);
    }
}
