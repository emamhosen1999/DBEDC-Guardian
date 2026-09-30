<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\DepartmentScope;

/**
 * Department scoping is delegated to App\Services\Access\DepartmentScope: global
 * roles reach everyone; anyone else only the departments they administer plus
 * their reporting sub-tree (fail closed — a null department grants nothing).
 * Writes additionally require a non-global actor to outrank the target.
 */
class UserPolicy
{
    private function scope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('users.view') || $user->hasPermissionTo('employees.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // Users can always view themselves
        if ($user->id === $model->id) {
            return true;
        }

        if (! ($user->hasPermissionTo('users.view') || $user->hasPermissionTo('employees.view'))) {
            return false;
        }

        return $this->scope()->canActOn($user, $model);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('users.create') || $user->hasPermissionTo('employees.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        // Users can update themselves (limited fields — enforced by the controller)
        if ($user->id === $model->id) {
            return true;
        }

        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        if (! ($user->hasPermissionTo('users.update') || $user->hasPermissionTo('employees.update'))) {
            return false;
        }

        return $this->scope()->canManage($user, $model);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Cannot delete yourself
        if ($user->id === $model->id) {
            return false;
        }

        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        return $user->hasPermissionTo('users.delete') && $this->scope()->canManage($user, $model);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return $user->hasPermissionTo('users.delete') && $this->scope()->canManage($user, $model);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        // Only super administrators can permanently delete
        return $user->hasRole('Super Administrator');
    }

    /**
     * Determine whether the user can update roles.
     */
    public function updateRoles(User $user, User $model): bool
    {
        // Cannot change your own roles
        if ($user->id === $model->id) {
            return false;
        }

        // Only a Super Administrator may grant or revoke roles on another
        // Super Administrator.
        if ($model->hasRole('Super Administrator') && ! $user->hasRole('Super Administrator')) {
            return false;
        }

        return $user->hasPermissionTo('users.update') &&
               $user->hasRole(['Super Administrator', 'Administrator']);
    }

    /**
     * Determine whether the user can toggle status (active/inactive).
     */
    public function toggleStatus(User $user, User $model): bool
    {
        // Cannot deactivate yourself
        if ($user->id === $model->id) {
            return false;
        }

        if (! $this->scope()->canManage($user, $model)) {
            return false;
        }

        return $user->hasPermissionTo('users.update') || $user->hasPermissionTo('employees.update');
    }

    /**
     * Determine whether the user can manage devices.
     */
    public function manageDevices(User $user, User $model): bool
    {
        // Users can manage their own devices
        if ($user->id === $model->id) {
            return true;
        }

        if (! $this->scope()->canManage($user, $model)) {
            return false;
        }

        return $user->hasPermissionTo('users.update') || $user->hasPermissionTo('employees.update');
    }

    /**
     * Determine whether the user can update department.
     */
    public function updateDepartment(User $user, User $model): bool
    {
        // HR managers and admins can update departments
        return $this->scope()->isGlobal($user) && $user->hasPermissionTo('users.update');
    }

    /**
     * Determine whether the user can update designation.
     */
    public function updateDesignation(User $user, User $model): bool
    {
        // HR managers and admins can update designations
        return $this->scope()->isGlobal($user) && $user->hasPermissionTo('users.update');
    }

    /**
     * Determine whether the user can update attendance type: global HR, or a
     * department admin for employees in their scope whom they outrank.
     */
    public function updateAttendanceType(User $user, User $model): bool
    {
        if (! $user->hasPermissionTo('users.update')) {
            return false;
        }

        return $this->scope()->isGlobal($user) || $this->scope()->canManage($user, $model);
    }
}
