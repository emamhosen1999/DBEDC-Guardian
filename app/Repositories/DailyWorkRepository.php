<?php

namespace App\Repositories;

use App\Models\DailyWork;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class DailyWorkRepository extends BaseRepository
{
    /**
     * Create a new repository instance
     */
    public function __construct(DailyWork $model)
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

        // Filter by incharge
        if (isset($filters['incharge_id'])) {
            $query->where('incharge_id', $filters['incharge_id']);
        }

        // Filter by assigned user
        if (isset($filters['assigned_id'])) {
            $query->where('assigned_id', $filters['assigned_id']);
        }

        // Filter by status
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // Filter by type
        if (isset($filters['type'])) {
            $query->where('type', $filters['type']);
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

        // Filter by chainage
        if (isset($filters['chainage_from'])) {
            $query->where('chainage_from', '>=', $filters['chainage_from']);
        }

        if (isset($filters['chainage_to'])) {
            $query->where('chainage_to', '<=', $filters['chainage_to']);
        }

        // Filter by department
        if (isset($filters['department_id'])) {
            $query->whereHas('user', function ($q) use ($filters) {
                $q->where('department_id', $filters['department_id']);
            });
        }

        // Filter by project
        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        // Filter by objection status
        if (isset($filters['has_objection'])) {
            if ($filters['has_objection']) {
                $query->whereHas('objections');
            } else {
                $query->whereDoesntHave('objections');
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
     * Get daily works for a specific user
     */
    public function getUserDailyWorks(int $userId, array $filters = []): Collection
    {
        $filters['user_id'] = $userId;

        return $this->all($filters);
    }

    /**
     * Get daily works for a specific date
     *
     * @param  string|Carbon  $date
     */
    public function getDailyWorksForDate($date, array $filters = []): Collection
    {
        $filters['date'] = $date;

        return $this->all($filters);
    }

    /**
     * Get daily works assigned to a user
     */
    public function getAssignedDailyWorks(int $assignedId, array $filters = []): Collection
    {
        $filters['assigned_id'] = $assignedId;

        return $this->all($filters);
    }

    /**
     * Get daily works under a user's supervision
     */
    public function getInchargeDailyWorks(int $inchargeId, array $filters = []): Collection
    {
        $filters['incharge_id'] = $inchargeId;

        return $this->all($filters);
    }

    /**
     * Get daily works by status
     */
    public function getDailyWorksByStatus(string $status, array $filters = []): Collection
    {
        $filters['status'] = $status;

        return $this->all($filters);
    }

    /**
     * Get selectable dates for daily works
     */
    public function getSelectableDates(array $filters = []): Collection
    {
        $query = $this->model
            ->select('date')
            ->distinct()
            ->orderBy('date', 'desc');

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['from_date'])) {
            $query->where('date', '>=', $filters['from_date']);
        }

        if (isset($filters['to_date'])) {
            $query->where('date', '<=', $filters['to_date']);
        }

        return $query->pluck('date');
    }

    /**
     * Get daily works summary statistics
     */
    public function getStatistics(array $filters = []): array
    {
        $query = $this->query();
        $query = $this->applyFilters($query, $filters);

        $total = $query->count();
        $new = (clone $query)->where('status', DailyWork::STATUS_NEW)->count();
        $inProgress = (clone $query)->where('status', DailyWork::STATUS_IN_PROGRESS)->count();
        $completed = (clone $query)->where('status', DailyWork::STATUS_COMPLETED)->count();
        $rejected = (clone $query)->where('status', DailyWork::STATUS_REJECTED)->count();
        $pending = (clone $query)->where('status', DailyWork::STATUS_PENDING)->count();
        $emergency = (clone $query)->where('status', DailyWork::STATUS_EMERGENCY)->count();

        return [
            'total' => $total,
            'new' => $new,
            'in_progress' => $inProgress,
            'completed' => $completed,
            'rejected' => $rejected,
            'pending' => $pending,
            'emergency' => $emergency,
        ];
    }

    /**
     * Update daily work status
     */
    public function updateStatus(int $dailyWorkId, string $status): DailyWork
    {
        $dailyWork = $this->findOrFail($dailyWorkId);
        $dailyWork->status = $status;
        $dailyWork->save();

        return $dailyWork->fresh();
    }

    /**
     * Update daily work incharge
     */
    public function updateIncharge(int $dailyWorkId, ?int $inchargeId): DailyWork
    {
        $dailyWork = $this->findOrFail($dailyWorkId);
        $dailyWork->incharge_id = $inchargeId;
        $dailyWork->save();

        return $dailyWork->fresh();
    }

    /**
     * Update daily work assigned user
     */
    public function updateAssigned(int $dailyWorkId, ?int $assignedId): DailyWork
    {
        $dailyWork = $this->findOrFail($dailyWorkId);
        $dailyWork->assigned_id = $assignedId;
        $dailyWork->save();

        return $dailyWork->fresh();
    }

    /**
     * Get daily works with objections
     */
    public function getDailyWorksWithObjections(array $filters = []): Collection
    {
        $filters['has_objection'] = true;

        return $this->all($filters);
    }

    /**
     * Get recent daily works for a user
     */
    public function getRecentDailyWorks(int $userId, int $limit = 10): Collection
    {
        return $this->model
            ->where('user_id', $userId)
            ->orderBy('date', 'desc')
            ->limit($limit)
            ->get();
    }
}
