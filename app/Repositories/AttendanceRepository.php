<?php

namespace App\Repositories;

use App\Models\HRM\Attendance;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class AttendanceRepository extends BaseRepository
{
    /**
     * Create a new repository instance
     *
     * @param Attendance $model
     */
    public function __construct(Attendance $model)
    {
        parent::__construct($model);
    }

    /**
     * Apply filters to query
     *
     * @param Builder $query
     * @param array $filters
     * @return Builder
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        // Filter by user
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // Filter by date range
        if (isset($filters['from_date'])) {
            $query->where('date', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('date', '<=', $filters['to_date']);
        }

        // Filter by specific date
        if (isset($filters['date'])) {
            $query->where('date', $filters['date']);
        }

        // Filter by department
        if (isset($filters['department_id'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('department_id', $filters['department_id']);
            });
        }

        // Filter by designation
        if (isset($filters['designation_id'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('designation_id', $filters['designation_id']);
            });
        }

        // Filter by punch status (present/absent)
        if (isset($filters['status'])) {
            if ($filters['status'] === 'present') {
                $query->whereNotNull('punchin');
            } elseif ($filters['status'] === 'absent') {
                $query->whereNull('punchin');
            }
        }

        // Filter by late arrival
        if (isset($filters['is_late'])) {
            $query->where('is_late', $filters['is_late']);
        }

        // Apply relationships
        if (isset($filters['with'])) {
            $query->with($filters['with']);
        }

        // Apply ordering
        $orderBy = $filters['order_by'] ?? 'date';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query;
    }

    /**
     * Get attendance for a specific user on a specific date
     *
     * @param int $userId
     * @param string|Carbon $date
     * @return Collection
     */
    public function getUserAttendanceForDate(int $userId, $date): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->whereDate('date', $date)
            ->orderBy('punchin')
            ->get();
    }

    /**
     * Get today's attendance for a user
     *
     * @param int $userId
     * @return Collection
     */
    public function getTodayAttendance(int $userId): Collection
    {
        return $this->getUserAttendanceForDate($userId, Carbon::today());
    }

    /**
     * Get users present on a specific date
     *
     * @param string|Carbon $date
     * @param array $filters
     * @return Collection
     */
    public function getPresentUsersForDate($date, array $filters = []): Collection
    {
        $query = $this->model
            ->whereNotNull('punchin')
            ->whereDate('date', $date);

        if (isset($filters['department_id'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('department_id', $filters['department_id']);
            });
        }

        return $query->with('user')->get();
    }

    /**
     * Get users absent on a specific date
     *
     * @param string|Carbon $date
     * @param array $filters
     * @return Collection
     */
    public function getAbsentUsersForDate($date, array $filters = []): Collection
    {
        // This is a complex query - for now return empty collection
        // In a real implementation, you'd need to get all active users
        // and filter out those with attendance records
        return new Collection();
    }

    /**
     * Get attendance summary for a date range
     *
     * @param int $userId
     * @param string|Carbon $fromDate
     * @param string|Carbon $toDate
     * @return array
     */
    public function getAttendanceSummary(int $userId, $fromDate, $toDate): array
    {
        $attendances = $this->model
            ->where('user_id', $userId)
            ->whereBetween('date', [$fromDate, $toDate])
            ->get();

        $totalDays = $attendances->count();
        $presentDays = $attendances->whereNotNull('punchin')->count();
        $absentDays = $totalDays - $presentDays;
        $lateDays = $attendances->where('is_late', true)->count();

        return [
            'total_days' => $totalDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'late_days' => $lateDays,
        ];
    }

    /**
     * Get attendance for a month
     *
     * @param int $userId
     * @param int $month
     * @param int $year
     * @return Collection
     */
    public function getMonthlyAttendance(int $userId, int $month, int $year): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->whereYear('date', $year)
            ->whereMonth('date', $month)
            ->orderBy('date')
            ->get();
    }

    /**
     * Check if user has punched in today
     *
     * @param int $userId
     * @return bool
     */
    public function hasPunchedInToday(int $userId): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('punchin')
            ->exists();
    }

    /**
     * Check if user has punched out today
     *
     * @param int $userId
     * @return bool
     */
    public function hasPunchedOutToday(int $userId): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->whereDate('date', Carbon::today())
            ->whereNotNull('punchout')
            ->exists();
    }

    /**
     * Get latest attendance record for a user
     *
     * @param int $userId
     * @return Attendance|null
     */
    public function getLatestAttendance(int $userId): ?Attendance
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->first();
    }
}
