<?php

namespace App\Services\HRM;

use App\Models\User;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class HRMAuditService
{
    /**
     * Log user creation
     */
    public function logUserCreation(User $user, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'user_created',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'new_values' => [
                'name' => $user->name,
                'email' => $user->email,
                'department_id' => $user->department_id,
                'designation_id' => $user->designation_id,
            ],
            'description' => "User '{$user->name}' was created",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log user update
     */
    public function logUserUpdate(User $user, array $oldValues, array $newValues, array $data = []): void
    {
        $changedFields = array_keys(array_diff_assoc($oldValues, $newValues));
        
        $description = "User '{$user->name}' was updated";
        if (!empty($changedFields)) {
            $fields = implode(', ', $changedFields);
            $description .= " (changed: {$fields})";
        }

        $this->log([
            'user_id' => Auth::id(),
            'action' => 'user_updated',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => $description,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log user deletion
     */
    public function logUserDeletion(User $user, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'user_deleted',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'old_values' => [
                'name' => $user->name,
                'email' => $user->email,
                'department_id' => $user->department_id,
                'designation_id' => $user->designation_id,
            ],
            'description' => "User '{$user->name}' was deleted",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log role assignment change
     */
    public function logRoleAssignment(User $user, array $oldRoles, array $newRoles, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'role_assignment_changed',
            'entity_type' => 'user',
            'entity_id' => $user->id,
            'old_values' => ['roles' => $oldRoles],
            'new_values' => ['roles' => $newRoles],
            'description' => "Roles for user '{$user->name}' were changed",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log department creation
     */
    public function logDepartmentCreation(Department $department, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'department_created',
            'entity_type' => 'department',
            'entity_id' => $department->id,
            'new_values' => [
                'name' => $department->name,
                'code' => $department->code,
                'manager_id' => $department->manager_id,
            ],
            'description' => "Department '{$department->name}' was created",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log department update
     */
    public function logDepartmentUpdate(Department $department, array $oldValues, array $newValues, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'department_updated',
            'entity_type' => 'department',
            'entity_id' => $department->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => "Department '{$department->name}' was updated",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log department deletion
     */
    public function logDepartmentDeletion(Department $department, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'department_deleted',
            'entity_type' => 'department',
            'entity_id' => $department->id,
            'old_values' => [
                'name' => $department->name,
                'code' => $department->code,
            ],
            'description' => "Department '{$department->name}' was deleted",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log designation creation
     */
    public function logDesignationCreation(Designation $designation, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'designation_created',
            'entity_type' => 'designation',
            'entity_id' => $designation->id,
            'new_values' => [
                'title' => $designation->title,
                'department_id' => $designation->department_id,
            ],
            'description' => "Designation '{$designation->title}' was created",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log designation update
     */
    public function logDesignationUpdate(Designation $designation, array $oldValues, array $newValues, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'designation_updated',
            'entity_type' => 'designation',
            'entity_id' => $designation->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'description' => "Designation '{$designation->title}' was updated",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Log designation deletion
     */
    public function logDesignationDeletion(Designation $designation, array $data = []): void
    {
        $this->log([
            'user_id' => Auth::id(),
            'action' => 'designation_deleted',
            'entity_type' => 'designation',
            'entity_id' => $designation->id,
            'old_values' => [
                'title' => $designation->title,
                'department_id' => $designation->department_id,
            ],
            'description' => "Designation '{$designation->title}' was deleted",
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            ...$data,
        ]);
    }

    /**
     * Generic log method
     */
    protected function log(array $data): void
    {
        \Log::info('HRM Audit Log', $data);
    }
}
