<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\HRM\Department;
use App\Models\User;

/**
 * Resolves whether a user is a manager, and the set of team members they may
 * see, from durable RELATIONSHIPS and PERMISSIONS rather than a hard-coded
 * role-name whitelist.
 *
 * A user manages a team if ANY of these hold:
 *   - they are admin-like (super-admin / admin / HR manager) — always true;
 *   - someone reports to them (users.report_to points at them);
 *   - they are a department head — assigned as a department's manager_id, or
 *     they hold the "Department Manager" role over their own department;
 *   - they hold a team/approval permission (leave / time-off approval, etc.).
 *
 * The team scope is the UNION of their reporting sub-tree and — for a
 * department head — the members of the department(s) they head. This is
 * computed server-side; the client is never trusted to assert manager-ness.
 */
trait ResolvesTeamMembers
{
    /**
     * Permissions that, on their own, make a user a manager. Any team/approval
     * capability qualifies. Checked with hasAnyPermission(), which is safe when
     * a permission is not registered (returns false instead of throwing).
     *
     * @var array<int, string>
     */
    protected static array $managerPermissions = [
        'leaves.approve',
        'hr.timeoff.approve',
    ];

    protected function isManagerUser(User $user): bool
    {
        // Admins and super-admins always manage.
        if ($this->isAdminLikeUser($user)) {
            return true;
        }

        // Someone reports to them directly.
        if ($user->directReports()->whereNull('deleted_at')->exists()) {
            return true;
        }

        // Head of a department (explicit assignment or Department Manager role).
        if ($this->isDepartmentHead($user)) {
            return true;
        }

        // Holds a team/approval permission.
        if ($user->hasAnyPermission(static::$managerPermissions)) {
            return true;
        }

        return false;
    }

    protected function isAdminLikeUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Super Administrator',
            'Administrator',
        ]);
    }

    /**
     * A department head is either explicitly assigned as a department's
     * manager_id, or holds the "Department Manager" role over their own
     * department. Both are durable data assignments, not a bare role list.
     */
    protected function isDepartmentHead(User $user): bool
    {
        if (Department::query()->where('manager_id', $user->id)->exists()) {
            return true;
        }

        return $user->department_id !== null && $user->hasRole('Department Manager');
    }

    /**
     * The team a manager may see: the UNION of everyone below them in the
     * reporting tree AND — if they head a department — that department's
     * members. The manager's own id is never included.
     *
     * @return array<int, int>
     */
    protected function resolveTeamMemberIds(User $user): array
    {
        $ids = $this->collectDescendantIds($user->id);

        if ($this->isDepartmentHead($user)) {
            $ids = array_merge($ids, $this->departmentMemberIds($user));
        }

        // De-duplicate and drop the manager's own id.
        $unique = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id !== (int) $user->id) {
                $unique[$id] = true;
            }
        }

        return array_keys($unique);
    }

    /**
     * Members of every department this user heads — via an explicit manager_id
     * assignment, plus their own department when they hold the Department
     * Manager role.
     *
     * @return array<int, int>
     */
    protected function departmentMemberIds(User $user): array
    {
        $departmentIds = Department::query()
            ->where('manager_id', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($user->department_id !== null && $user->hasRole('Department Manager')) {
            $departmentIds[] = (int) $user->department_id;
        }

        $departmentIds = array_values(array_unique($departmentIds));

        if ($departmentIds === []) {
            return [];
        }

        return User::query()
            ->whereNull('deleted_at')
            ->whereIn('department_id', $departmentIds)
            ->where('id', '!=', $user->id)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Walk the report_to hierarchy and collect all descendant user IDs.
     * Depth-capped at 10 levels and 500 users to guard against circular
     * references and runaway queries in very large orgs.
     *
     * @return array<int, int>
     */
    protected function collectDescendantIds(int $rootId, int $maxDepth = 10): array
    {
        $collected = [];
        $currentLevelIds = [$rootId];
        $visited = [$rootId => true];

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $children = User::query()
                ->whereNull('deleted_at')
                ->whereIn('report_to', $currentLevelIds)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => ! isset($visited[$id]))
                ->values()
                ->all();

            if ($children === []) {
                break;
            }

            foreach ($children as $childId) {
                $visited[$childId] = true;
                $collected[] = $childId;
            }

            $currentLevelIds = $children;

            if (count($collected) >= 500) {
                break;
            }
        }

        return $collected;
    }
}
