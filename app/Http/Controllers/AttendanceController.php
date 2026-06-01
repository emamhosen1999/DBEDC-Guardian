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
        ]);
    }

    public function paginate(Request $request): \Illuminate\Http\JsonResponse
    {
        try {
            $perPage = (int) $request->get('perPage', 20);
            $page = (int) $request->get('page', 1);
            $employee = $request->get('employee');
            $currentMonth = (int) $request->get('currentMonth');
            $currentYear = (int) $request->get('currentYear');

            $users = $this->attendanceReportService->getEmployeeUsersWithAttendanceAndLeaves($currentYear, $currentMonth);
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

            $sortedAttendances = $attendances->sortBy('user_id')->values();
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
            $date = $request->query('date', now()->toDateString());
            $data = $this->attendanceQueryService->getDailyTimesheet($date, [
                'per_page' => $request->query('perPage', 25),
                'page' => $request->query('page', 1),
            ]);

            return response()->json($data);
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
            $users = $this->attendanceQueryService->getAbsentUsersForDate($date);

            return response()->json([
                'users' => $users,
                'count' => count($users),
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

            return \Maatwebsite\Excel\Facades\Excel::download(new AttendanceExport($date), 'Daily_Timesheet_'.date('Y_m_d', strtotime($date)).'.xlsx');
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
            $rows = (new AttendanceExport($date))->collection();
            $pdf = PDF::loadView('attendance_pdf', [
                'title' => 'Daily Timesheet - '.date('F d, Y', strtotime($date)),
                'generatedOn' => now()->format('F d, Y h:i A'),
                'rows' => $rows,
            ])->setPaper('a4', 'landscape');

            return $pdf->download('Daily_Timesheet_'.date('Y_m_d', strtotime($date)).'.pdf');
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

            return (new AttendanceAdminExport)->export($month);
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
            $to = $from->copy()->endOfMonth();
            $monthName = $from->format('F Y');

            $users = User::with(['attendances', 'leaves'])->role('Employee')->whereNull('deleted_at')->get();
            $leaveTypes = LeaveSetting::all();
            $holidays = $this->attendanceReportService->getHolidaysForMonth($from->year, $from->month);

            $attendanceData = [];
            foreach ($users as $user) {
                $attendanceData[] = $this->attendanceReportService->getUserAttendanceData($user, $from->year, $from->month, $holidays, collect($leaveTypes));
            }

            $pdf = PDF::loadView('attendance_admin_pdf', [
                'monthName' => $monthName,
                'from' => $from,
                'to' => $to,
                'users' => $users,
                'attendanceData' => $attendanceData,
                'leaveTypes' => $leaveTypes,
            ])->setPaper('a4', 'landscape');

            return $pdf->download('DBEDC_Attendance_'.$monthName.'.pdf');
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
