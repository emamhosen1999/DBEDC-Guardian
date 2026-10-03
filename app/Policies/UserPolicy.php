<?php

namespace App\Policies;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\SelfAdministration;

/**
 * Employee administration, one ability per action and one named permission per ability
 * (employees.update, employees.placement.update, employees.attendance-config.update,
 * employees.compensation.view|update, employees.password.reset, employees.devices.manage,
 * employees.access.manage, employees.delete, employees.restore). The coarse `users.update` /
 * `users.delete` / `users.create` no longer gate any of them.
 *
 * Department scoping is delegated to App\Services\Access\DepartmentScope: global roles reach
 * everyone; anyone else only the departments they administer plus their reporting sub-tree
 * (fail closed — a null department grants nothing). Writes additionally require a non-global
 * actor to outrank the target (canManage), and privileged changes — roles, salary, department,
 * reporting line, password reset, deactivation — are never allowed on oneself.
 */
class UserPolicy
{
    private function scope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    /** checkPermissionTo (not hasPermissionTo): a permission that does not exist is simply "no". */
    private function holds(User $user, string $permission): bool
    {
        return $user->checkPermissionTo($permission);
    }

    /** Governed exception: the holder of access.self-administration acting on himself. */
    private function selfAdmin(User $user, User $model): bool
    {
        return app(SelfAdministration::class)->allows($user, $model);
    }

    private function isSelf(User $user, User $model): bool
    {
        return (string) $user->getKey() === (string) $model->getKey();
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->holds($user, 'users.view') || $this->holds($user, 'employees.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        // Users can always view themselves
        if ($this->isSelf($user, $model)) {
            return true;
        }

        if (! ($this->holds($user, 'users.view') || $this->holds($user, 'employees.view'))) {
            return false;
        }

        return $this->scope()->canActOn($user, $model);
    }

    /**
     * Open someone's profile page: one's own with profile.own.view; anyone else's with the
     * company-wide user directory (`users.view`, the administrator-level read) or with
     * `employees.view` AND scope over them — a department admin reads his department only.
     */
    public function viewProfile(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return $this->holds($user, 'profile.own.view');
        }

        if ($this->holds($user, 'users.view')) {
            return true;
        }

        return $this->holds($user, 'employees.view') && $this->scope()->canActOn($user, $model);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->holds($user, 'employees.create');
    }

    /**
     * Determine whether the user can update the model (the employee edit form). Users can update
     * themselves — which fields is enforced by the controller (SELF_PROTECTED_FIELDS).
     */
    public function update(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return true;
        }

        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        return $this->holds($user, 'employees.update') && $this->scope()->canManage($user, $model);
    }

    /**
     * Edit someone's PROFILE sections (personal, education, experience, photo): one's own with
     * profile.own.update, anyone else's with employees.update AND scope over them (and, for a
     * non-global actor, outranking them).
     */
    public function updateProfile(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return $this->holds($user, 'profile.own.update');
        }

        return $this->holds($user, 'employees.update') && $this->scope()->canManage($user, $model);
    }

    /**
     * Designation, reporting line and work location. Never one's own placement.
     */
    public function updatePlacement(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return $this->selfAdmin($user, $model) && $this->holds($user, 'employees.placement.update');
        }

        return $this->holds($user, 'employees.placement.update') && $this->scope()->canManage($user, $model);
    }

    /**
     * Move an employee to another department. A non-global actor may only move employees they
     * manage, between departments they administer — and never themselves.
     */
    public function transfer(User $user, User $model, ?int $toDepartmentId = null): bool
    {
        if ($this->isSelf($user, $model)) {
            // Self-administration may only move within departments he manages (never a scope escape).
            $managed = $this->scope()->managedDepartmentIds($user);

            return $this->selfAdmin($user, $model) && $this->holds($user, 'employees.update')
                && in_array((int) $model->department_id, $managed, true)
                && $toDepartmentId !== null && in_array($toDepartmentId, $managed, true);
        }

        if (! ($this->holds($user, 'employees.update') && $this->scope()->canManage($user, $model))) {
            return false;
        }

        if ($this->scope()->isGlobal($user)) {
            return true;
        }

        $managed = $this->scope()->managedDepartmentIds($user);

        return in_array((int) $model->department_id, $managed, true)
            && ($toDepartmentId === null || in_array($toDepartmentId, $managed, true));
    }

    /**
     * Attendance method / biometric device rules of an employee: global HR, or a department
     * admin for employees in their scope whom they outrank.
     */
    public function updateAttendanceConfig(User $user, User $model): bool
    {
        if (! $this->holds($user, 'employees.attendance-config.update')) {
            return false;
        }

        if ($this->isSelf($user, $model)) {
            return $this->selfAdmin($user, $model) || $this->scope()->isGlobal($user);
        }

        return $this->scope()->isGlobal($user) || $this->scope()->canManage($user, $model);
    }

    /**
     * See an employee's salary and statutory details: one's own always, anyone else's with
     * employees.compensation.view AND scope over them.
     */
    public function viewCompensation(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return true;
        }

        return $this->holds($user, 'employees.compensation.view')
            && $this->scope()->canActOn($user, $model);
    }

    /**
     * Change an employee's salary and statutory details — never one's own.
     */
    public function updateCompensation(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return $this->selfAdmin($user, $model) && $this->holds($user, 'employees.compensation.update');
        }

        return $this->holds($user, 'employees.compensation.update') && $this->scope()->canManage($user, $model);
    }

    /**
     * Reset another employee's password. Use the profile for one's own.
     */
    public function resetPassword(User $user, User $model): bool
    {
        if ($this->isSelf($user, $model)) {
            return false;
        }

        return $this->holds($user, 'employees.password.reset') && $this->scope()->canManage($user, $model);
    }

    /**
     * Determine whether the user can delete (deactivate — soft delete) the model.
     */
    public function delete(User $user, User $model): bool
    {
        // Cannot delete yourself
        if ($this->isSelf($user, $model)) {
            return false;
        }

        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        return $this->holds($user, 'employees.delete') && $this->scope()->canManage($user, $model);
    }

    /**
     * Determine whether the user can restore a deactivated model.
     */
    public function restore(User $user, User $model): bool
    {
        // Never one's own account, not even under self-administration (self-lockout / reinstatement guard).
        if ($this->isSelf($user, $model)) {
            return false;
        }

        return $this->holds($user, 'employees.restore') && $this->scope()->canManage($user, $model);
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
     * Roles and direct permissions: employees.access.manage, never one's own, and only a Super
     * Administrator may touch another Super Administrator. The role hierarchy (grant only roles
     * below one's own, modify only users below oneself) is enforced by UserManagementService.
     */
    public function updateRoles(User $user, User $model): bool
    {
        // Cannot change your own roles
        if ($this->isSelf($user, $model)) {
            return false;
        }

        if ($model->hasRole('Super Administrator') && ! $user->hasRole('Super Administrator')) {
            return false;
        }

        return $this->holds($user, 'employees.access.manage') && $this->scope()->canManage($user, $model);
    }

    /**
     * Determine whether the user can toggle status (active/inactive).
     */
    public function toggleStatus(User $user, User $model): bool
    {
        // Cannot deactivate yourself
        if ($this->isSelf($user, $model)) {
            return false;
        }

        if (! $this->scope()->canManage($user, $model)) {
            return false;
        }

        return $this->holds($user, 'employees.update');
    }

    /**
     * Device lock, device history and sessions: one's own always, anyone else's with
     * employees.devices.manage and scope over them.
     */
    public function manageDevices(User $user, User $model): bool
    {
        // Users can manage their own devices
        if ($this->isSelf($user, $model)) {
            return true;
        }

        return $this->holds($user, 'employees.devices.manage') && $this->scope()->canManage($user, $model);
    }
}
