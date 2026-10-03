<?php

namespace App\Services\Access;

use App\Models\HRM\Department;
use App\Models\User;
use App\Models\UserDepartmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

/**
 * The single source of truth for "which employees may this actor see / act on".
 *
 * An actor is either GLOBAL (company-wide roles — no restriction) or scoped to:
 *   - the departments they MANAGE:
 *       * active user_department_scopes grants (admin / acting, time-boxed at query time),
 *       * departments whose manager_id is the actor,
 *       * their own department_id, when they hold the "Department Manager" role or
 *         the `department.admin` permission;
 *   - their report_to sub-tree (direct + indirect reports);
 *   - themselves.
 * Anything else FAILS CLOSED: a non-global actor with no managed department and no
 * reports sees only their own record — never "everyone" because department_id is null.
 *
 * Bound as a scoped (per-request / per-job) instance; per-user results are memoized
 * for that lifetime only. Call forget() after changing a user's grants mid-request.
 */
class DepartmentScope
{
    /** Roles whose scope is company-wide. The ONE place this list lives. */
    public const GLOBAL_ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    /** Role that implicitly administers its holder's own department. */
    public const DEPARTMENT_HEAD_ROLE = 'Department Manager';

    /** Permission that implicitly administers its holder's own department. */
    public const DEPARTMENT_ADMIN_PERMISSION = 'department.admin';

    /** Permission to grant / revoke user_department_scopes. */
    public const MANAGE_SCOPES_PERMISSION = 'department.scopes.manage';

    /** Company-wide attendance CONFIGURATION (shift definitions, policies, devices, coverage rules). */
    public const ATTENDANCE_SETTINGS_PERMISSION = 'attendance.settings';

    /** Reporting-tree walk guards (circular report_to chains, runaway orgs). */
    private const MAX_TREE_DEPTH = 10;

    private const MAX_TREE_SIZE = 500;

    /** @var array<string, array<int, int>> */
    private array $departmentMemo = [];

    /** @var array<string, array<int, string>> */
    private array $permissionMemo = [];

    /** @var array<string, array<int, string>> */
    private array $elevatedMemo = [];

    /** @var array<string, array<int, string>> */
    private array $subtreeMemo = [];

    /** @var array<string, array<int, string>> */
    private array $ancestorMemo = [];

    private ?bool $scopesTableExists = null;

    // ──────────────────────────────────────────────
    //  Who the actor is
    // ──────────────────────────────────────────────

    public function isGlobal(User $user): bool
    {
        return $user->hasRole(self::GLOBAL_ROLES);
    }

    public function canSeeAll(User $user): bool
    {
        return $this->isGlobal($user);
    }

    /**
     * Is the actor company-wide for per-employee ATTENDANCE administration — rosters, shift
     * assignments, swap decisions and coverage? Global roles are; so is anyone holding
     * `attendance.settings`: configuring shifts, policies, devices and coverage rules is by
     * nature not department-bounded (and already exposes every employee's raw punches), so it
     * cannot sensibly be paired with a per-department roster restriction.
     *
     * `attendance.roster.manage` alone — what a department admin holds — NEVER widens scope.
     */
    public function isAttendanceAdmin(User $user): bool
    {
        return $this->isGlobal($user) || $user->checkPermissionTo(self::ATTENDANCE_SETTINGS_PERMISSION);
    }

    /**
     * visibleEmployeeIds() for the roster / shift / swap / coverage modules. NULL means
     * unrestricted (attendance administrator) — the same NULL-vs-empty contract.
     *
     * @return array<int, string>|null
     */
    public function visibleAttendanceEmployeeIds(User $actor): ?array
    {
        return $this->isAttendanceAdmin($actor) ? null : $this->visibleEmployeeIds($actor);
    }

    /**
     * Departments this user administers right now (never includes expired or
     * not-yet-started grants).
     *
     * @return array<int, int>
     */
    public function managedDepartmentIds(User $user): array
    {
        $key = $this->key($user);
        if (isset($this->departmentMemo[$key])) {
            return $this->departmentMemo[$key];
        }

        $ids = Department::query()->where('manager_id', $key)->pluck('id')->all();

        if ($this->scopesTableExists()) {
            $ids = array_merge($ids, UserDepartmentScope::query()
                ->active()
                ->where('user_id', $key)
                ->pluck('department_id')
                ->all());
        }

        if ($user->department_id !== null
            && ($user->hasRole(self::DEPARTMENT_HEAD_ROLE) || $user->checkPermissionTo(self::DEPARTMENT_ADMIN_PERMISSION))) {
            $ids[] = $user->department_id;
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return $this->departmentMemo[$key] = $ids;
    }

    /**
     * Departments a NON-global actor may see and pick from: the ones they administer
     * plus their own (so their own record still renders with its department). Global
     * actors are unrestricted — callers branch on isGlobal() first and never call this
     * for them.
     *
     * @return array<int, int>
     */
    public function visibleDepartmentIds(User $user): array
    {
        $ids = $this->managedDepartmentIds($user);

        if ($user->department_id !== null) {
            $ids[] = (int) $user->department_id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * Everyone below the user in the report_to tree (direct + indirect), excluding
     * the user. Active users only.
     *
     * @return array<int, string>
     */
    public function reportingSubtreeIds(User $user): array
    {
        $key = $this->key($user);

        return $this->subtreeMemo[$key] ??= $this->descendantIds($key);
    }

    /**
     * Everyone above the user in the report_to chain (manager, manager's manager, ...), nearest
     * first; stops at a circular chain or MAX_TREE_DEPTH.
     *
     * @return array<int, string>
     */
    public function ancestorIds(User $user): array
    {
        $key = $this->key($user);
        if (isset($this->ancestorMemo[$key])) {
            return $this->ancestorMemo[$key];
        }

        $chain = [];
        $next = $user->report_to;
        for ($depth = 0; $next !== null && $next !== '' && $depth < self::MAX_TREE_DEPTH; $depth++) {
            $id = (string) $next;
            if ($id === $key || in_array($id, $chain, true)) {
                break;
            }
            $chain[] = $id;
            $next = User::withTrashed()->whereKey($id)->value('report_to');
        }

        return $this->ancestorMemo[$key] = $chain;
    }

    /**
     * Walk the report_to hierarchy from $rootId and collect descendant employee_ids.
     * Depth-capped and size-capped against circular references and runaway queries.
     *
     * @return array<int, string>
     */
    public function descendantIds(string|int $rootId, int $maxDepth = self::MAX_TREE_DEPTH): array
    {
        $collected = [];
        $currentLevelIds = [(string) $rootId];
        $visited = [(string) $rootId => true];

        for ($depth = 0; $depth < $maxDepth; $depth++) {
            $children = User::query()
                ->whereIn('report_to', $currentLevelIds)
                ->pluck('employee_id')
                ->map(fn ($id) => (string) $id)
                ->reject(fn (string $id) => isset($visited[$id]))
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

            if (count($collected) >= self::MAX_TREE_SIZE) {
                break;
            }
        }

        return $collected;
    }

    // ──────────────────────────────────────────────
    //  Query helpers
    // ──────────────────────────────────────────────

    /**
     * Narrow a USERS query to the actor's visible set. Global: no-op. Otherwise
     * managed departments ∪ reporting sub-tree ∪ self; with neither, only self.
     *
     * @param  Builder  $query  a query over App\Models\User (key column = employee_id)
     * @param  string  $column  the department column to match (qualify it when joining)
     */
    public function applyToUsers(Builder $query, User $actor, string $column = 'department_id'): Builder
    {
        if ($this->isGlobal($actor)) {
            return $query;
        }

        $keyColumn = $query->qualifyColumn($query->getModel()->getKeyName());
        $departmentIds = $this->managedDepartmentIds($actor);
        $personIds = array_values(array_unique(array_merge(
            [$this->key($actor)],
            $this->reportingSubtreeIds($actor),
        )));

        return $query->where(function (Builder $scoped) use ($column, $keyColumn, $departmentIds, $personIds): void {
            $scoped->whereIn($keyColumn, $personIds);
            if ($departmentIds !== []) {
                $scoped->orWhereIn($column, $departmentIds);
            }
        });
    }

    /**
     * Narrow a DEPARTMENTS query to the departments the actor may see and pick from: everything for
     * a global actor, otherwise the ones they administer plus their own (none -> no rows).
     *
     * @param  string  $column  the department id column to match (qualify it when joining)
     */
    public function applyToDepartments(Builder $query, User $actor, string $column = 'id'): Builder
    {
        if ($this->isGlobal($actor)) {
            return $query;
        }

        return $query->whereIn($column, $this->visibleDepartmentIds($actor));
    }

    /**
     * Narrow a query over any table keyed by an employee FK (offboardings.employee_id,
     * onboardings.employee_id, assets.assignee_id, payrolls.user_id, leaves.user_id,
     * final_settlements.employee_id, ...) to records of employees the actor can see.
     * Former (soft-deleted) employees stay visible to their department's admin.
     * Non-global actors never see rows whose FK is null (fail closed).
     */
    public function applyToEmployeeOwned(Builder $query, User $actor, string $employeeFk = 'employee_id'): Builder
    {
        if ($this->isGlobal($actor)) {
            return $query;
        }

        return $query->whereIn($employeeFk, $this->visibleUserIdsQuery($actor));
    }

    /**
     * The employee_ids the actor may see, for services that filter by an explicit id
     * list. NULL means unrestricted (global actor) — callers must treat NULL as "no
     * filter" and an empty array as "nobody" (never conflate the two).
     *
     * @return array<int, string>|null
     */
    public function visibleEmployeeIds(User $actor): ?array
    {
        if ($this->isGlobal($actor)) {
            return null;
        }

        return $this->applyToUsers(User::query()->select('employee_id'), $actor)
            ->pluck('employee_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Sub-query selecting the employee_ids the actor may see (soft-deleted included).
     */
    public function visibleUserIdsQuery(User $actor): Builder
    {
        return $this->applyToUsers(User::withTrashed()->select('employee_id'), $actor);
    }

    // ──────────────────────────────────────────────
    //  Per-record checks
    // ──────────────────────────────────────────────

    /**
     * May the actor operate on this employee's record at all (scope only — pair with
     * outranks() / canManage() for writes)? Acting on oneself is allowed only when
     * the caller opts in.
     */
    public function canActOn(User $actor, User|string $target, bool $allowSelf = false): bool
    {
        $targetUser = $this->resolveUser($target);
        if ($targetUser === null) {
            return false;
        }

        if ($this->key($actor) === $this->key($targetUser)) {
            // Governed exception: a holder of access.self-administration acts on himself like on his staff.
            return $allowSelf || app(SelfAdministration::class)->allows($actor, $targetUser);
        }

        if ($this->isGlobal($actor)) {
            return true;
        }

        if ($targetUser->department_id !== null
            && in_array((int) $targetUser->department_id, $this->managedDepartmentIds($actor), true)) {
            return true;
        }

        return in_array($this->key($targetUser), $this->reportingSubtreeIds($actor), true);
    }

    /**
     * Write-side check: in scope AND, for a non-global actor, strictly outranking the
     * target — a department admin must never reset the password of (or otherwise edit)
     * an Administrator who happens to sit in their department.
     */
    public function canManage(User $actor, User|string $target, bool $allowSelf = false): bool
    {
        $targetUser = $this->resolveUser($target);
        if ($targetUser === null || ! $this->canActOn($actor, $targetUser, $allowSelf)) {
            return false;
        }

        if ($this->key($actor) === $this->key($targetUser) || $this->isGlobal($actor)) {
            return true;
        }

        // Authority never runs up one's own reporting line (supervisory-organization model): a
        // department head does not manage his own manager, however equal their roles.
        if (in_array($this->key($targetUser), $this->ancestorIds($actor), true)) {
            return false;
        }

        // A target inside the actor's managed departments is judged by the delegation SUBSET rule
        // (Entra delegated-admin model): peers and everyone below are manageable, anyone holding more
        // than the actor stays protected. Outside them (reporting-subtree only) strict outranking applies.
        if ($targetUser->department_id !== null
            && in_array((int) $targetUser->department_id, $this->managedDepartmentIds($actor), true)) {
            return $this->withinDelegation($actor, $targetUser);
        }

        return $this->outranks($actor, $targetUser);
    }

    /**
     * (b) the target's ELEVATED permissions (everything beyond the base and department-default roles
     * every employee may carry) are a subset of the actor's effective permissions, and (c) the
     * departments the target administers are a subset of the actor's. A global-role target is never
     * delegable. Comparing elevated rather than all permissions keeps ordinary staff manageable even
     * when the admin does not hold their self-service/functional roles, while a peer holding anything
     * administrative the actor lacks stays protected.
     */
    public function withinDelegation(User $actor, User $target): bool
    {
        if ($this->isGlobal($target)) {
            return false;
        }

        // (c) never reach beyond the actor's own departments through the target's grants.
        if (array_diff($this->managedDepartmentIds($target), $this->managedDepartmentIds($actor)) !== []) {
            return false;
        }

        // Lower-ranked staff (role hierarchy) are manageable as before; a peer at the same or a higher
        // level is manageable only when everything elevated it holds, the actor already holds.
        return $this->outranks($actor, $target)
            || array_diff($this->elevatedPermissions($target), $this->effectivePermissions($actor)) === [];
    }

    /** @return array<int, string> */
    private function effectivePermissions(User $user): array
    {
        $key = $this->key($user);

        return $this->permissionMemo[$key] ??= $this->withAccessLoaded($user)->getAllPermissions()->pluck('name')->unique()->values()->all();
    }

    /** Eager-load roles, their permissions and direct permissions once (no lazy loading / N+1). */
    private function withAccessLoaded(User $user): User
    {
        return $user->loadMissing(['roles.permissions', 'permissions']);
    }

    /**
     * Permissions granted by any role other than the base role and the department default roles
     * (the ordinary roles every employee may carry), plus direct permissions.
     *
     * @return array<int, string>
     */
    private function elevatedPermissions(User $user): array
    {
        $key = $this->key($user);

        return $this->elevatedMemo[$key] ??= (function () use ($user): array {
            $ordinary = array_merge(User::BASE_ROLES, app(DepartmentDefaultRoles::class)->allManaged());
            $user = $this->withAccessLoaded($user);

            return $user->roles
                ->reject(fn ($role) => in_array($role->name, $ordinary, true))
                ->flatMap(fn ($role) => $role->permissions->pluck('name'))
                ->merge($user->getDirectPermissions()->pluck('name'))
                ->unique()->values()->all();
        })();
    }

    /**
     * Is the actor's most powerful role strictly more powerful than the target's?
     * (roles.hierarchy_level: lower = more powerful.) A target with no role is
     * outranked by anyone holding a role; an actor with no role outranks no one.
     */
    public function outranks(User $actor, User $target): bool
    {
        $actorLevel = $this->bestRoleLevel($actor);
        if ($actorLevel === null) {
            return false;
        }

        $targetLevel = $this->bestRoleLevel($target);

        return $targetLevel === null || $actorLevel < $targetLevel;
    }

    /**
     * The user's most powerful role level, or null when they hold no role.
     */
    public function bestRoleLevel(User $user): ?int
    {
        // A directory page evaluates this for every row: use the roles already eager-loaded on the
        // model (the same view of its roles Spatie's own checks use) instead of one query per row.
        $level = $user->relationLoaded('roles')
            ? $user->roles->min('hierarchy_level')
            : $user->roles()->min('hierarchy_level');

        return $level === null ? null : (int) $level;
    }

    /**
     * Drop memoized results (one user, or everyone) — call after changing grants,
     * department heads or report_to within the same request.
     */
    public function forget(User|string|null $user = null): void
    {
        $this->ancestorMemo = []; // one report_to change re-shapes every chain below it

        if ($user === null) {
            $this->departmentMemo = [];
            $this->subtreeMemo = [];
            $this->permissionMemo = [];
            $this->elevatedMemo = [];

            return;
        }

        $key = $user instanceof User ? $this->key($user) : (string) $user;
        unset($this->departmentMemo[$key], $this->subtreeMemo[$key], $this->permissionMemo[$key], $this->elevatedMemo[$key]);
    }

    private function resolveUser(User|string $target): ?User
    {
        return $target instanceof User ? $target : User::withTrashed()->find($target);
    }

    private function key(User $user): string
    {
        return (string) $user->getKey();
    }

    private function scopesTableExists(): bool
    {
        return $this->scopesTableExists ??= Schema::hasTable('user_department_scopes');
    }
}
