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
     */
    public function __construct(Attendance $model)
    {
        parent::__construct($model);
    }

    /**
     * Apply filters to query
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        // Filter by user
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        // Filter by team member IDs
        if (isset($filters['team_member_ids'])) {
            $query->whereIn('user_id', $filters['team_member_ids']);
        }

        // Filter by employee keyword (name or employee_id)
        if (! empty($filters['employee'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['employee'].'%')
                    ->orWhere('employee_id', 'like', '%'.$filters['employee'].'%');
            });
        }

        // Filter by date range
        if (isset($filters['from_date'])) {
            $query->where('date', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('date', '<=', $filters['to_date']);
        }

        // Filter by month and year, prioritizing them over date parameter
        if (!empty($filters['currentMonth']) && !empty($filters['currentYear'])) {
            $query->whereYear('date', $filters['currentYear'])
                  ->whereMonth('date', $filters['currentMonth']);
        } elseif (isset($filters['date'])) {
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
     * @param  string|Carbon  $date
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
     */
    public function getTodayAttendance(int $userId): Collection
    {
        return $this->getUserAttendanceForDate($userId, Carbon::today());
    }

    /**
     * Get users present on a specific date
     *
     * @param  string|Carbon  $date
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
     * @param  string|Carbon  $date
     */
    public function getAbsentUsersForDate($date, array $filters = []): Collection
    {
        // This is a complex query - for now return empty collection
        // In a real implementation, you'd need to get all active users
        // and filter out those with attendance records
        return new Collection;
    }

    /**
     * Get attendance summary for a date range
     *
     * @param  string|Carbon  $fromDate
     * @param  string|Carbon  $toDate
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

        $officeStartTime = '09:00:00';
        $lateGraceMinutes = 15;
        if (\Illuminate\Support\Facades\Schema::hasTable('attendance_settings')) {
            $settings = \Illuminate\Support\Facades\DB::table('attendance_settings')
                ->select('office_start_time', 'late_mark_after')
                ->first();
            if ($settings) {
                $officeStartTime = $settings->office_start_time ?: $officeStartTime;
                $lateGraceMinutes = is_numeric($settings->late_mark_after)
                    ? (int) $settings->late_mark_after
                    : $lateGraceMinutes;
            }
        }

        $lateDays = 0;
        $grouped = $attendances->groupBy('date');
        foreach ($grouped as $date => $dayRecords) {
            $firstPunch = $dayRecords->sortBy('punchin')->first();
            if ($firstPunch && $firstPunch->punchin) {
                $punchInTime = Carbon::parse($firstPunch->punchin)->format('H:i:s');
                $lateThreshold = Carbon::parse($date . ' ' . $officeStartTime)->addMinutes($lateGraceMinutes);
                $punchInAt = Carbon::parse($date . ' ' . $punchInTime);
                if ($punchInAt->gt($lateThreshold)) {
                    $lateDays++;
                }
            }
        }

        return [
            'total_days' => $totalDays,
            'present_days' => $presentDays,
            'absent_days' => $absentDays,
            'late_days' => $lateDays,
        ];
    }

    /**
     * Get attendance for a month
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
     */
    public function getLatestAttendance(int $userId): ?Attendance
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->first();
    }
}
