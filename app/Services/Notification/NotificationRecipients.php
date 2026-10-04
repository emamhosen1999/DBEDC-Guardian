<?php

namespace App\Services\Notification;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Who should hear about an event: holders of a PERMISSION (never a role name - role names are being
 * phased out of authorization and the catalog changes), optionally narrowed to people whose
 * DepartmentScope reaches the subject employee, minus the actor and anyone inactive.
 *
 * Soft-deleted users are excluded by the User model's scope; users.is_active = false (locked or
 * deactivated accounts) is excluded here.
 */
class NotificationRecipients
{
    public function __construct(private DepartmentScope $scope) {}

    /**
     * @param  string|array<int, string>  $permissions  any one of these grants receipt
     * @param  User|string|null  $subject  employee the event is about; when given, only recipients who may act
     *                                     on that employee (DepartmentScope::canActOn) are kept
     * @param  User|string|null  $actor  who caused the event; never notified of their own action
     * @return Collection<int, User>
     */
    public function forPermission(string|array $permissions, User|string|null $subject = null, User|string|null $actor = null): Collection
    {
        // User::permission() throws for an unknown permission name; an unseeded name means "nobody holds it".
        $existing = Permission::query()->whereIn('name', (array) $permissions)->pluck('name')->all();
        if ($existing === []) {
            return collect();
        }

        $candidates = User::permission($existing)
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->with('notificationPreferences')
            ->get();

        return $this->narrow($candidates, $subject, $actor);
    }

    /**
     * The company-wide administrators as DepartmentScope defines them (its single GLOBAL_ROLES list), for
     * oversight notices that must reach every global admin whatever permissions their role carries.
     *
     * @return Collection<int, User>
     */
    public function globalAdmins(User|string|null $actor = null): Collection
    {
        // User::role() throws for an unknown role name; a missing one simply has no holders.
        $roles = Role::query()->whereIn('name', DepartmentScope::GLOBAL_ROLES)->pluck('name')->all();
        if ($roles === []) {
            return collect();
        }

        $candidates = User::role($roles)
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->with('notificationPreferences')
            ->get();

        return $this->narrow($candidates, null, $actor);
    }

    /**
     * Explicit people (a report_to manager, an approver id) get the same actor/inactive/scope filtering.
     *
     * @param  iterable<int, string|int|null>  $employeeIds
     * @return Collection<int, User>
     */
    public function forEmployees(iterable $employeeIds, User|string|null $subject = null, User|string|null $actor = null): Collection
    {
        $ids = collect($employeeIds)->filter()->map(fn ($id) => (string) $id)->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $candidates = User::whereIn('employee_id', $ids)
            ->where(fn ($q) => $q->whereNull('is_active')->orWhere('is_active', true))
            ->with('notificationPreferences')
            ->get();

        return $this->narrow($candidates, $subject, $actor);
    }

    /** @return Collection<int, User> */
    private function narrow(Collection $candidates, User|string|null $subject, User|string|null $actor): Collection
    {
        $actorId = $actor instanceof User ? (string) $actor->employee_id : ($actor !== null ? (string) $actor : null);

        return $candidates
            ->reject(fn (User $u) => $actorId !== null && (string) $u->employee_id === $actorId)
            ->when($subject !== null, fn (Collection $c) => $c->filter(fn (User $u) => $this->scope->canActOn($u, $subject)))
            ->values();
    }
}
