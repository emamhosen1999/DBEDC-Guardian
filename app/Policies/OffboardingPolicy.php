<?php

namespace App\Policies;

use App\Models\HRM\Offboarding;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Auth\Access\HandlesAuthorization;

class OffboardingPolicy
{
    use HandlesAuthorization;

    public function __construct(private readonly DepartmentScope $scope) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('hr.offboarding.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Offboarding $offboarding): bool
    {
        // Permission + department scope; an employee may read their own record only.
        return $user->can('hr.offboarding.view')
            && $this->scope->canActOn($user, $offboarding->employee_id, allowSelf: true);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('hr.offboarding.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Offboarding $offboarding): bool
    {
        return $user->can('hr.offboarding.update') && $this->scope->canManage($user, $offboarding->employee_id)
            // Never one's own exit, even with access.self-administration (cancelling one's own
            // offboarding is the classic segregation-of-duties abuse).
            && (string) $offboarding->employee_id !== (string) $user->getKey();
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Offboarding $offboarding): bool
    {
        return $user->can('hr.offboarding.delete') && $this->scope->canManage($user, $offboarding->employee_id)
            // Never one's own exit, even with access.self-administration (cancelling one's own
            // offboarding is the classic segregation-of-duties abuse).
            && (string) $offboarding->employee_id !== (string) $user->getKey();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Offboarding $offboarding): bool
    {
        return $user->can('hr.offboarding.delete') && $this->scope->canManage($user, $offboarding->employee_id)
            // Never one's own exit, even with access.self-administration (cancelling one's own
            // offboarding is the classic segregation-of-duties abuse).
            && (string) $offboarding->employee_id !== (string) $user->getKey();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Offboarding $offboarding): bool
    {
        return $user->can('hr.offboarding.delete') && $this->scope->canManage($user, $offboarding->employee_id)
            // Never one's own exit, even with access.self-administration (cancelling one's own
            // offboarding is the classic segregation-of-duties abuse).
            && (string) $offboarding->employee_id !== (string) $user->getKey();
    }
}
