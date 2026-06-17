<?php

namespace App\Repositories;

use App\Models\HRM\Leave;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class LeaveRepository extends BaseRepository
{
    /**
     * Create a new repository instance
     */
    public function __construct(Leave $model)
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

        // Filter by status
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by leave type
        if (isset($filters['leave_type'])) {
            $query->where('leave_type', $filters['leave_type']);
        }

        // Filter by date range
        if (isset($filters['from_date'])) {
            $query->where('from_date', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('to_date', '<=', $filters['to_date']);
        }

        // Filter by specific date (leaves that overlap with this date)
        if (isset($filters['date'])) {
            $query->where('from_date', '<=', $filters['date'])
                ->where('to_date', '>=', $filters['date']);
        }

        // Filter by department
        if (isset($filters['department_id'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('department_id', $filters['department_id']);
            });
        }

        // Filter by pending approval
        if (isset($filters['pending_approval'])) {
            $query->where('status', 'pending');
        }

        // Filter by approved
        if (isset($filters['approved'])) {
            $query->where('status', 'approved');
        }

        // Filter by rejected
        if (isset($filters['rejected'])) {
            $query->where('status', 'rejected');
        }

        // Filter by year
        if (isset($filters['year'])) {
            $query->whereYear('from_date', $filters['year']);
        }

        // Filter by month
        if (isset($filters['month'])) {
            $query->whereMonth('from_date', $filters['month']);
        }

        // Apply relationships
        if (isset($filters['with'])) {
            $query->with($filters['with']);
        }

        // Apply ordering
        $orderBy = $filters['order_by'] ?? 'from_date';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query->orderBy($orderBy, $orderDirection);

        return $query;
    }

    /**
     * Get leaves for a specific user
     */
    public function getUserLeaves(int $userId, array $filters = []): Collection
    {
        $filters['user_id'] = $userId;

        return $this->all($filters);
    }

    /**
     * Get leaves for a specific date range
     *
     * @param  string|Carbon  $fromDate
     * @param  string|Carbon  $toDate
     */
    public function getLeavesForDateRange($fromDate, $toDate, array $filters = []): Collection
    {
        $filters['from_date'] = $fromDate;
        $filters['to_date'] = $toDate;

        return $this->all($filters);
    }

    /**
     * Get leaves pending approval
     */
    public function getPendingLeaves(array $filters = []): Collection
    {
        $filters['status'] = 'pending';

        return $this->all($filters);
    }

    /**
     * Get leaves approved
     */
    public function getApprovedLeaves(array $filters = []): Collection
    {
        $filters['status'] = 'approved';

        return $this->all($filters);
    }

    /**
     * Get leaves for a specific month
     */
    public function getMonthlyLeaves(int $month, int $year, array $filters = []): Collection
    {
        $filters['month'] = $month;
        $filters['year'] = $year;

        return $this->all($filters);
    }

    /**
     * Get leave summary for a user
     */
    public function getUserLeaveSummary(int $userId, int $year): array
    {
        $leaves = $this->model
            ->where('user_id', $userId)
            ->whereYear('from_date', $year)
            ->where('status', 'approved')
            ->get();

        $totalLeaves = $leaves->sum(function ($leave) {
            return Carbon::parse($leave->from_date)->diffInDays(Carbon::parse($leave->to_date)) + 1;
        });

        $leavesByType = $leaves->groupBy('leave_type')->map(function ($leaves) {
            return $leaves->sum(function ($leave) {
                return Carbon::parse($leave->from_date)->diffInDays(Carbon::parse($leave->to_date)) + 1;
            });
        });

        return [
            'total_leaves' => $totalLeaves,
            'leaves_by_type' => $leavesByType,
            'leave_count' => $leaves->count(),
        ];
    }

    /**
     * Check if user has leave on a specific date
     *
     * @param  string|Carbon  $date
     */
    public function hasLeaveOnDate(int $userId, $date): bool
    {
        return $this->model
            ->where('user_id', $userId)
            ->where('from_date', '<=', $date)
            ->where('to_date', '>=', $date)
            ->where('status', 'approved')
            ->exists();
    }

    /**
     * Get leave analytics
     */
    public function getLeaveAnalytics(array $filters = []): array
    {
        $query = $this->query();
        $query = $this->applyFilters($query, $filters);

        $total = $query->count();
        $pending = (clone $query)->where('status', 'pending')->count();
        $approved = (clone $query)->where('status', 'approved')->count();
        $rejected = (clone $query)->where('status', 'rejected')->count();

        return [
            'total' => $total,
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
        ];
    }

    /**
     * Get leave calendar data
     */
    public function getLeaveCalendar(int $month, int $year, array $filters = []): Collection
    {
        $query = $this->model
            ->whereMonth('from_date', $month)
            ->whereYear('from_date', $year);

        if (isset($filters['department_id'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('department_id', $filters['department_id']);
            });
        }

        return $query->with('user')->get();
    }

    /**
     * Update leave status
     */
    public function updateStatus(int $leaveId, string $status): Leave
    {
        $leave = $this->findOrFail($leaveId);
        $leave->status = $status;
        $leave->save();

        return $leave->fresh();
    }

    /**
     * Get overlapping leaves for a user
     *
     * @param  string|Carbon  $fromDate
     * @param  string|Carbon  $toDate
     */
    public function getOverlappingLeaves(int $userId, $fromDate, $toDate, ?int $excludeLeaveId = null): Collection
    {
        $query = $this->model
            ->where('user_id', $userId)
            ->where(function ($q) use ($fromDate, $toDate) {
                $q->whereBetween('from_date', [$fromDate, $toDate])
                    ->orWhereBetween('to_date', [$fromDate, $toDate])
                    ->orWhere(function ($q2) use ($fromDate, $toDate) {
                        $q2->where('from_date', '<=', $fromDate)
                            ->where('to_date', '>=', $toDate);
                    });
            });

        if ($excludeLeaveId) {
            $query->where('id', '!=', $excludeLeaveId);
        }

        return $query->get();
    }
}
