<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\User;
use App\Services\Access\DepartmentScope;

/**
 * Mobile-facing adapter over App\Services\Access\DepartmentScope, so the mobile
 * "team" and the web employee scope are the SAME set.
 *
 * A user manages a team if ANY of these hold:
 *   - they are global / admin-like — always true;
 *   - someone reports to them (users.report_to points at them);
 *   - they administer a department — department head (manager_id), Department
 *     Manager role / department.admin permission over their own department, or an
 *     active admin/acting user_department_scopes grant;
 *   - they hold a team/approval permission (leave / time-off approval, etc.).
 *
 * The team is computed per request, so an acting grant that expires stops
 * applying on the very next call even before the device re-bootstraps.
 */
trait ResolvesTeamMembers
{
    /**
     * Permissions that, on their own, make a user a manager. Checked with
     * hasAnyPermission(), which is safe when a permission is not registered.
     *
     * @var array<int, string>
     */
    protected static array $managerPermissions = [
        'leaves.approve',
        'hr.timeoff.approve',
    ];

    protected function departmentScope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    protected function isManagerUser(User $user): bool
    {
        if ($this->isAdminLikeUser($user)) {
            return true;
        }

        if ($user->directReports()->exists()) {
            return true;
        }

        if ($this->isDepartmentHead($user)) {
            return true;
        }

        return $user->hasAnyPermission(static::$managerPermissions);
    }

    protected function isAdminLikeUser(User $user): bool
    {
        // Legacy role names kept alongside the canonical global list.
        return $this->departmentScope()->isGlobal($user) || $user->hasRole(['Super Admin', 'Admin']);
    }

    /**
     * Administers at least one department right now (head, Department Manager /
     * department.admin over own department, or an active scope grant).
     */
    protected function isDepartmentHead(User $user): bool
    {
        return $this->departmentScope()->managedDepartmentIds($user) !== [];
    }

    /**
     * The team a manager may see — the same set the web scopes to: everyone for a
     * global actor; otherwise the reporting sub-tree UNION members of every managed
     * department. The manager's own id is never included.
     *
     * @return array<int, string>
     */
    protected function resolveTeamMemberIds(User $user): array
    {
        $uid = (string) $user->getKey();

        return $this->departmentScope()
            ->applyToUsers(User::query(), $user)
            ->where('employee_id', '!=', $uid)
            ->pluck('employee_id')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Members of every department this user administers, excluding the user.
     *
     * @return array<int, string>
     */
    protected function departmentMemberIds(User $user): array
    {
        $departmentIds = $this->departmentScope()->managedDepartmentIds($user);

        if ($departmentIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('department_id', $departmentIds)
            ->where('employee_id', '!=', (string) $user->getKey())
            ->pluck('employee_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Walk the report_to hierarchy and collect all descendant user IDs.
     *
     * @return array<int, string>
     */
    protected function collectDescendantIds(string|int $rootId, int $maxDepth = 10): array
    {
        return $this->departmentScope()->descendantIds($rootId, $maxDepth);
    }
}
