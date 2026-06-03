<?php

namespace App\Http\Controllers;

use App\Traits\HandlesApiExceptions;
use App\Exports\AttendanceAdminExport;
use App\Exports\AttendanceExport;
use App\Models\HRM\Attendance;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Attendance\AttendanceQueryService;
use App\Services\Attendance\AttendanceReportService;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use App\Jobs\ExportAttendanceReport;

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

    public function indexUnified(): \Inertia\Response
    {
        return Inertia::render('Attendance/AttendancePage', [
            'title'            => 'Attendance',
            'attendanceSettings' => \App\Models\HRM\AttendanceSetting::first(),
            'attendanceTypes'    => \App\Models\HRM\AttendanceType::with(['biometricDevices:id,name,serial_number,location'])->get(),
            'departments'        => \App\Models\HRM\Department::active()->get(['id', 'name']),
        ]);
    }

    public function index1(): \Inertia\Response
    {
        return $this->indexUnified();
    }

    public function index2(): \Inertia\Response
    {
        return Inertia::render('AttendanceEmployee', [
            'title' => 'My Attendance',
        ]);
    }

    public function index3(): \Inertia\Response
    {
        return $this->indexUnified();
    }

    public function paginate(Request $request): \Illuminate\Http\JsonResponse
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
            ]);
        } catch (\Exception $e) {
            Log::error('Error in paginate method: '.$e->getMessage());

            return response()->json(['error' => 'An error occurred while fetching attendance data.'], 500);
        }
    }

    public function updateAttendance(Request $request): \Illuminate\Http\JsonResponse
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

        // 1. Get the user's attendance type
        $attendanceType = $user->attendanceType;

        if (! $attendanceType || ! $attendanceType->is_active) {
            return response()->json([
                'status' => 'error',
                'message' => 'No active attendance type assigned to user.',
            ], 422);
        }

        // Validate attendance based on type configuration
        $validation = $this->validateAttendanceType($attendanceType, $request);
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
    private function validateAttendanceType($attendanceType, Request $request)
    {
        try {
            $validator = \App\Services\Attendance\AttendanceValidatorFactory::create($attendanceType, $request);

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

    public function getUserLocationsForDate(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $selectedDate = Carbon::parse($request->query('date'))->format('Y-m-d');
            $locations = $this->attendanceQueryService->getUserLocationsForDate($selectedDate);

            return response()->json(['locations' => $locations]);
        } catch (\Exception $e) {
            Log::error('Failed to get user locations: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve locations.'], 500);
        }
    }

    public function getCurrentUserPunch(): \Illuminate\Http\JsonResponse
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

    public function getAllUsersAttendanceForDate(Request $request): \Illuminate\Http\JsonResponse
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

    public function getCurrentUserAttendanceForDate(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $date = $request->query('date', now()->toDateString());
            $userId = (int) Auth::id();
            $attendance = Attendance::where('user_id', $userId)->whereDate('date', $date)->first();

            return response()->json([
                'attendance' => $attendance,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get current user attendance for date: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve attendance record.'], 500);
        }
    }

    public function getClientIp(Request $request): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'ip' => $request->ip(),
        ]);
    }

    public function getPresentUsersForDate(Request $request): \Illuminate\Http\JsonResponse
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

    public function getAbsentUsersForDate(Request $request): \Illuminate\Http\JsonResponse
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
                        ->whereDate('date', $date);
                })
                ->pluck('id');

            $absentUsers = $allUsers->filter(function (User $user) use ($presentUserIds) {
                return ! $presentUserIds->contains($user->id);
            })->values();

            $absentUserIds = $absentUsers->pluck('id')->all();

            $leaveUserColumn = null;
            if (\Illuminate\Support\Facades\Schema::hasColumn('leaves', 'user_id')) {
                $leaveUserColumn = 'user_id';
            } elseif (\Illuminate\Support\Facades\Schema::hasColumn('leaves', 'employee_id')) {
                $leaveUserColumn = 'employee_id';
            }

            $leaves = collect();
            if (
                $absentUserIds !== []
                && $leaveUserColumn
                && \Illuminate\Support\Facades\Schema::hasTable('leaves')
                && \Illuminate\Support\Facades\Schema::hasTable('leave_settings')
            ) {
                $leaves = \Illuminate\Support\Facades\DB::table('leaves')
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

            return response()->json([
                'absent_users' => $serializedAbsentUsers,
                'leaves' => $leaves,
                'total_absent' => $serializedAbsentUsers->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get absent users: '.$e->getMessage());

            return response()->json(['error' => 'Failed to retrieve absent employees.'], 500);
        }
    }

    public function getMonthlyAttendanceStats(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $currentMonth = (int) $request->get('currentMonth', now()->month);
            $currentYear = (int) $request->get('currentYear', now()->year);
            $userId = $request->get('userId') ? (int) $request->get('userId') : null;
            $isGlobalScope = $userId === null;

            $stats = $this->attendanceReportService->calculateMonthlyStats($currentMonth, $currentYear, $isGlobalScope, $userId);

            return response()->json(['stats' => $stats]);
        } catch (\Exception $e) {
            Log::error('Failed to get monthly attendance stats: '.$e->getMessage());

            return response()->json(['error' => 'Failed to calculate monthly statistics.'], 500);
        }
    }

    public function checkForLocationUpdates($date)
    {
        return response()->json([
            'updated' => false,
            'timestamp' => now()->timestamp,
        ]);
    }

    public function markAsPresent(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => 'required|exists:users,id',
                'date' => 'required|date',
            ]);

            $attendance = Attendance::updateOrCreate(
                ['user_id' => $validated['user_id'], 'date' => $validated['date']],
                ['symbol' => '√', 'punchin' => Carbon::parse($validated['date'])->setHour(9)->setMinute(0)->setSecond(0)]
            );

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

    public function bulkMarkAsPresent(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_ids' => 'required|array',
                'user_ids.*' => 'exists:users,id',
                'date' => 'required|date',
            ]);

            $date = Carbon::parse($validated['date'])->format('Y-m-d');
            $punchin = Carbon::parse($validated['date'])->setHour(9)->setMinute(0)->setSecond(0);

            $attendances = [];
            foreach ($validated['user_ids'] as $userId) {
                $attendances[] = Attendance::updateOrCreate(
                    ['user_id' => $userId, 'date' => $date],
                    ['symbol' => '√', 'punchin' => $punchin]
                );
            }

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
        return response()->json([
            'updated' => false,
            'timestamp' => now()->timestamp,
        ]);
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
                'download_url' => asset('storage/exports/' . $filename),
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
                'download_url' => asset('storage/exports/' . $filename),
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
                'download_url' => asset('storage/exports/' . $filename),
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
                'download_url' => asset('storage/exports/' . $filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'PDF export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    public function updateAttendanceRecord(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $attendance = Attendance::findOrFail($id);

            $validated = $request->validate([
                'punchin' => 'nullable|date_format:Y-m-d H:i:s',
                'punchout' => 'nullable|date_format:Y-m-d H:i:s',
            ]);

            if (!empty($validated['punchin']) && !empty($validated['punchout'])) {
                $punchin = Carbon::parse($validated['punchin']);
                $punchout = Carbon::parse($validated['punchout']);
                if ($punchin->gte($punchout)) {
                    return response()->json([
                        'error' => 'Punch-in time must be before punch-out time',
                    ], 422);
                }
            }

            $attendance->update($validated);

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

    public function addAttendanceRecord(Request $request): \Illuminate\Http\JsonResponse
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

            if (!empty($validated['punchin']) && !empty($validated['punchout'])) {
                $punchin = Carbon::parse($validated['punchin']);
                $punchout = Carbon::parse($validated['punchout']);
                if ($punchin->gte($punchout)) {
                    return response()->json([
                        'error' => 'Punch-in time must be before punch-out time',
                    ], 422);
                }
            }

            $attendance = Attendance::create([
                'user_id' => $validated['user_id'],
                'date' => $validated['date'],
                'punchin' => $validated['punchin'],
                'punchout' => $validated['punchout'],
                'punchin_location' => isset($validated['punchin_location']) ? json_encode($validated['punchin_location']) : null,
                'punchout_location' => isset($validated['punchout_location']) ? json_encode($validated['punchout_location']) : null,
            ]);

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

    public function deleteAttendanceRecord($id): \Illuminate\Http\JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $attendance = Attendance::findOrFail($id);
            $attendance->delete();

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

    public function updateAttendanceStatus(Request $request, $id): \Illuminate\Http\JsonResponse
    {
        $this->authorize('attendance.correct');

        try {
            $attendance = Attendance::findOrFail($id);

            $validated = $request->validate([
                'symbol' => 'required|string|max:10',
            ]);

            $attendance->symbol = $validated['symbol'];
            $attendance->save();

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
}
