<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\Leave;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

class AttendanceQueryService
{
    private AttendanceRepository $attendanceRepository;

    /**
     * Create a new service instance
     */
    public function __construct(AttendanceRepository $attendanceRepository)
    {
        $this->attendanceRepository = $attendanceRepository;
    }

    /**
     * Get today's attendance for a user
     */
    public function getTodayAttendance(int $userId): array
    {
        $attendances = $this->attendanceRepository->getTodayAttendance($userId);

        $totalProductionTime = 0;
        $punches = $attendances->map(function (Attendance $attendance) use (&$totalProductionTime) {
            $punchInTime = Carbon::parse($attendance->punchin);
            $punchOutTime = $attendance->punchout ? Carbon::parse($attendance->punchout) : Carbon::now();

            if ($punchOutTime->lt($punchInTime)) {
                $punchOutTime->addDay();
            }

            $duration = $punchInTime->diffInSeconds($punchOutTime);
            $totalProductionTime += $duration;

            return [
                'date' => $attendance->date->format('Y-m-d'),
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
                'punchout_location' => $attendance->punchout_location_array,
                'duration' => gmdate('H:i:s', $duration),
            ];
        })->values();

        // Check if user is on leave today
        $isUserOnLeave = false;
        if (Schema::hasTable('leaves')) {
            $isUserOnLeave = Leave::query()
                ->where('user_id', $userId)
                ->whereDate('from_date', '<=', Carbon::today())
                ->whereDate('to_date', '>=', Carbon::today())
                ->whereRaw('LOWER(status) = ?', ['approved'])
                ->exists();
        }

        return [
            'punches' => $punches,
            'total_production_time' => gmdate('H:i:s', $totalProductionTime),
            'isUserOnLeave' => $isUserOnLeave,
        ];
    }

    /**
     * Get attendance history with pagination
     */
    public function getAttendanceHistory(int $userId, array $filters = []): array
    {
        if (($filters['scope'] ?? 'self') !== 'team') {
            $filters['user_id'] = $userId;
        } else {
            unset($filters['user_id']);
        }
        $filters['with'] = ['user.designation'];
        $perPage = $filters['per_page'] ?? 10;
        $page = $filters['page'] ?? 1;

        $paginator = $this->attendanceRepository->paginate($perPage, $filters);

        return [
            'attendances' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }

    /**
     * Get monthly attendance summary
     */
    public function getMonthlySummary(int $userId, int $month, int $year): array
    {
        $summary = $this->attendanceRepository->getAttendanceSummary(
            $userId,
            Carbon::create($year, $month, 1)->startOfMonth(),
            Carbon::create($year, $month, 1)->endOfMonth()
        );

        return $summary;
    }

    /**
     * Get present users for a specific date
     */
    public function getPresentUsersForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->getPresentUsersForDate($date, $filters);

        return $attendances->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'user' => [
                    'id' => $attendance->user->id,
                    'name' => $attendance->user->name,
                    'employee_id' => $attendance->user->employee_id,
                ],
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
            ];
        })->toArray();
    }

    /**
     * Get absent users for a specific date
     */
    public function getAbsentUsersForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->getAbsentUsersForDate($date, $filters);

        return $attendances->map(function ($attendance) {
            return [
                'id' => $attendance->id,
                'user_id' => $attendance->user_id,
                'user' => [
                    'id' => $attendance->user->id,
                    'name' => $attendance->user->name,
                    'employee_id' => $attendance->user->employee_id,
                ],
            ];
        })->toArray();
    }

    /**
     * Get user locations for a specific date
     */
    public function getUserLocationsForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->all([
            'date' => $date,
            'with' => ['user.designation'],
        ]);

        return $attendances->filter(function ($attendance) {
            return $attendance->punchin_location_array !== null;
        })->map(function ($attendance) {
            return [
                'user_id' => $attendance->user_id,
                'user_name' => $attendance->user->name,
                'name' => $attendance->user->name,
                'profile_image_url' => $attendance->user->profile_image_url,
                'designation' => $attendance->user->designation?->title ?? 'Employee',
                'location' => $attendance->punchin_location_array,
                'punchin_location' => $attendance->punchin_location_array,
                'punchout_location' => $attendance->punchout_location_array,
                'punchin_time' => $attendance->punchin->format('H:i:s'),
                'punchout_time' => $attendance->punchout?->format('H:i:s'),
            ];
        })->values()->toArray();
    }

    /**
     * Get daily timesheet data
     */
    public function getDailyTimesheet(string $date, array $filters = []): array
    {
        $perPage = $filters['per_page'] ?? 25;
        $page = $filters['page'] ?? 1;

        $paginator = $this->attendanceRepository->paginate($perPage, array_merge($filters, ['date' => $date]));

        return [
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ];
    }
}
