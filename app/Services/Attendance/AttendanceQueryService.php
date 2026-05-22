<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use App\Repositories\AttendanceRepository;
use Carbon\Carbon;

class AttendanceQueryService
{
    /**
     * @var AttendanceRepository
     */
    private AttendanceRepository $attendanceRepository;

    /**
     * Create a new service instance
     *
     * @param AttendanceRepository $attendanceRepository
     */
    public function __construct(AttendanceRepository $attendanceRepository)
    {
        $this->attendanceRepository = $attendanceRepository;
    }

    /**
     * Get today's attendance for a user
     *
     * @param int $userId
     * @return array
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

        return [
            'punches' => $punches,
            'total_production_time' => gmdate('H:i:s', $totalProductionTime),
        ];
    }

    /**
     * Get attendance history with pagination
     *
     * @param int $userId
     * @param array $filters
     * @return array
     */
    public function getAttendanceHistory(int $userId, array $filters = []): array
    {
        $filters['user_id'] = $userId;
        $perPage = $filters['per_page'] ?? 10;
        $page = $filters['page'] ?? 1;

        $paginator = $this->attendanceRepository->paginate($perPage, $filters);

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

    /**
     * Get monthly attendance summary
     *
     * @param int $userId
     * @param int $month
     * @param int $year
     * @return array
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
     *
     * @param string $date
     * @param array $filters
     * @return array
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
     *
     * @param string $date
     * @param array $filters
     * @return array
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
     *
     * @param string $date
     * @param array $filters
     * @return array
     */
    public function getUserLocationsForDate(string $date, array $filters = []): array
    {
        $attendances = $this->attendanceRepository->all([
            'date' => $date,
            'with' => ['user'],
        ]);
        
        return $attendances->filter(function ($attendance) {
            return $attendance->punchin_location_array !== null;
        })->map(function ($attendance) {
            return [
                'user_id' => $attendance->user_id,
                'user_name' => $attendance->user->name,
                'location' => $attendance->punchin_location_array,
                'punchin_time' => $attendance->punchin->format('H:i:s'),
            ];
        })->values()->toArray();
    }

    /**
     * Get daily timesheet data
     *
     * @param string $date
     * @param array $filters
     * @return array
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
