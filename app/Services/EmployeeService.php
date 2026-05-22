<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\EmployeeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

class EmployeeService
{
    /**
     * @var EmployeeRepository
     */
    protected EmployeeRepository $employeeRepository;

    /**
     * Create a new service instance
     *
     * @param EmployeeRepository $employeeRepository
     */
    public function __construct(EmployeeRepository $employeeRepository)
    {
        $this->employeeRepository = $employeeRepository;
    }

    /**
     * Get all employees with optional filters
     *
     * @param array $filters
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAllEmployees(array $filters = [])
    {
        return $this->employeeRepository->all($filters);
    }

    /**
     * Get paginated employees
     *
     * @param int $perPage
     * @param array $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getPaginatedEmployees(int $perPage = 15, array $filters = [])
    {
        return $this->employeeRepository->paginate($perPage, $filters);
    }

    /**
     * Get employee by ID with full relations
     *
     * @param int $id
     * @return User|null
     */
    public function getEmployeeById(int $id): ?User
    {
        return $this->employeeRepository->findWithFullRelations($id);
    }

    /**
     * Create a new employee
     *
     * @param array $data
     * @return User
     * @throws \Exception
     */
    public function createEmployee(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // Hash password if provided
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            // Create employee
            $employee = $this->employeeRepository->create($data);

            // Assign roles if provided
            if (isset($data['roles']) && is_array($data['roles'])) {
                $employee->syncRoles($data['roles']);
            }

            Log::info('Employee created', [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_id,
                'created_by' => auth()->id(),
            ]);

            return $employee;
        });
    }

    /**
     * Update an existing employee
     *
     * @param int $id
     * @param array $data
     * @return User
     * @throws \Exception
     */
    public function updateEmployee(int $id, array $data): User
    {
        return DB::transaction(function () use ($id, $data) {
            $employee = $this->employeeRepository->findOrFail($id);

            // Hash password if provided
            if (isset($data['password'])) {
                $data['password'] = Hash::make($data['password']);
            }

            // Update employee
            $employee->update($data);

            // Sync roles if provided
            if (isset($data['roles']) && is_array($data['roles'])) {
                $employee->syncRoles($data['roles']);
            }

            Log::info('Employee updated', [
                'employee_id' => $employee->id,
                'employee_code' => $employee->employee_id,
                'updated_by' => auth()->id(),
            ]);

            return $employee->fresh();
        });
    }

    /**
     * Delete an employee (soft delete)
     *
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    public function deleteEmployee(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $employee = $this->employeeRepository->findOrFail($id);

            // Check if employee has active records
            if ($this->hasActiveRecords($employee)) {
                throw new \Exception('Cannot delete employee with active records');
            }

            $result = $this->employeeRepository->delete($id);

            Log::info('Employee deleted', [
                'employee_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return $result;
        });
    }

    /**
     * Permanently delete an employee
     *
     * @param int $id
     * @return bool
     * @throws \Exception
     */
    public function forceDeleteEmployee(int $id): bool
    {
        return DB::transaction(function () use ($id) {
            $employee = $this->employeeRepository->query()->withTrashed()->findOrFail($id);

            $result = $employee->forceDelete();

            Log::info('Employee permanently deleted', [
                'employee_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return $result;
        });
    }

    /**
     * Restore a soft-deleted employee
     *
     * @param int $id
     * @return bool
     */
    public function restoreEmployee(int $id): bool
    {
        $employee = $this->employeeRepository->query()->withTrashed()->findOrFail($id);
        $result = $employee->restore();

        Log::info('Employee restored', [
            'employee_id' => $id,
            'restored_by' => auth()->id(),
        ]);

        return $result;
    }

    /**
     * Update employee's department
     *
     * @param int $employeeId
     * @param int $departmentId
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function updateDepartment(int $employeeId, int $departmentId): \Illuminate\Database\Eloquent\Model
    {
        $employee = $this->employeeRepository->findOrFail($employeeId);
        $employee->update(['department_id' => $departmentId]);

        Log::info('Employee department updated', [
            'employee_id' => $employeeId,
            'new_department_id' => $departmentId,
            'updated_by' => auth()->id(),
        ]);

        return $employee;
    }

    /**
     * Check if employee has active records (leaves, attendance, etc.)
     *
     * @param \Illuminate\Database\Eloquent\Model $employee
     * @return bool
     */
    protected function hasActiveRecords(\Illuminate\Database\Eloquent\Model $employee): bool
    {
        // Check for active leaves
        if ($employee->leaves()->where('status', 'approved')->exists()) {
            return true;
        }

        // Check for recent attendance records (last 30 days)
        if ($employee->attendances()
            ->where('date', '>=', now()->subDays(30))
            ->exists()) {
            return true;
        }

        // Check for direct reports
        if ($employee->directReports()->exists()) {
            return true;
        }

        return false;
    }

    /**
     * Get employee statistics
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $total = $this->employeeRepository->query()->count();
        $active = $this->employeeRepository->query()->whereNull('deleted_at')->count();
        $inactive = $total - $active;

        return [
            'total' => $total,
            'active' => $active,
            'inactive' => $inactive,
        ];
    }
}
