<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EmployeeRepository extends BaseRepository
{
    /**
     * Searchable fields for employee queries
     */
    protected array $searchableFields = [
        'name',
        'email',
        'phone',
        'employee_id',
    ];

    /**
     * Create a new repository instance
     *
     * @param User $model
     */
    public function __construct(User $model)
    {
        parent::__construct($model);
    }

    /**
     * Get all employees with basic relations
     *
     * @param array $filters
     * @return Collection
     */
    public function all(array $filters = []): Collection
    {
        $query = $this->query()->withBasicRelations();
        $query = $this->applyFilters($query, $filters);

        return $query->get();
    }

    /**
     * Get paginated employees
     *
     * @param int $perPage
     * @param array $filters
     * @return LengthAwarePaginator
     */
    public function paginate(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query()->withBasicRelations();
        $query = $this->applyFilters($query, $filters);

        return $query->paginate($perPage);
    }

    /**
     * Find employee by ID with full relations
     *
     * @param int $id
     * @return User|null
     */
    public function findWithFullRelations(int $id): ?User
    {
        return $this->query()->withFullRelations()->find($id);
    }

    /**
     * Find employee by employee_id
     *
     * @param string $employeeId
     * @return User|null
     */
    public function findByEmployeeId(string $employeeId): ?User
    {
        return $this->findOneBy('employee_id', $employeeId);
    }

    /**
     * Get employees by department
     *
     * @param int $departmentId
     * @return Collection
     */
    public function getByDepartment(int $departmentId): Collection
    {
        return $this->findBy('department_id', $departmentId);
    }

    /**
     * Get employees by designation
     *
     * @param int $designationId
     * @return Collection
     */
    public function getByDesignation(int $designationId): Collection
    {
        return $this->findBy('designation_id', $designationId);
    }

    /**
     * Get active employees (not soft deleted)
     *
     * @param array $filters
     * @return Collection
     */
    public function getActive(array $filters = []): Collection
    {
        $query = $this->query()->withBasicRelations()->whereNull('deleted_at');
        $query = $this->applyFilters($query, $filters);

        return $query->get();
    }

    /**
     * Get direct reports for a manager
     *
     * @param int $managerId
     * @return Collection
     */
    public function getDirectReports(int $managerId): Collection
    {
        return $this->query()
            ->withBasicRelations()
            ->where('report_to', $managerId)
            ->get();
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
        // Apply search
        if (isset($filters['search'])) {
            $query = $this->applySearch($query, $filters['search'], $this->searchableFields);
        }

        // Apply department filter
        if (isset($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        // Apply designation filter
        if (isset($filters['designation_id'])) {
            $query->where('designation_id', $filters['designation_id']);
        }

        // Apply role filter
        if (isset($filters['role'])) {
            $query->whereHas('roles', function ($q) use ($filters) {
                $q->where('name', $filters['role']);
            });
        }

        // Apply status filter (active/inactive)
        if (isset($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->whereNull('deleted_at');
            } elseif ($filters['status'] === 'inactive') {
                $query->whereNotNull('deleted_at');
            }
        }

        // Apply trashed filter
        if (isset($filters['trashed'])) {
            if ($filters['trashed'] === 'with') {
                $query->withTrashed();
            } elseif ($filters['trashed'] === 'only') {
                $query->onlyTrashed();
            }
        }

        // Apply ordering
        $orderBy = $filters['order_by'] ?? 'created_at';
        $orderDirection = $filters['order_direction'] ?? 'desc';
        $query = $this->applyOrdering($query, $orderBy, $orderDirection);

        return $query;
    }
}
