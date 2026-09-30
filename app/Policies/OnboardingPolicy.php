<?php

namespace App\Policies;

use App\Models\HRM\Onboarding;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Auth\Access\HandlesAuthorization;

class OnboardingPolicy
{
    use HandlesAuthorization;

    public function __construct(private readonly DepartmentScope $scope) {}

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('hr.onboarding.view');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Onboarding $onboarding): bool
    {
        // Permission + department scope; an employee may read their own record only.
        return $user->can('hr.onboarding.view')
            && $this->scope->canActOn($user, $onboarding->employee_id, allowSelf: true);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->can('hr.onboarding.create');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Onboarding $onboarding): bool
    {
        return $user->can('hr.onboarding.update') && $this->scope->canManage($user, $onboarding->employee_id);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Onboarding $onboarding): bool
    {
        return $user->can('hr.onboarding.delete') && $this->scope->canManage($user, $onboarding->employee_id);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Onboarding $onboarding): bool
    {
        return $user->can('hr.onboarding.delete') && $this->scope->canManage($user, $onboarding->employee_id);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Onboarding $onboarding): bool
    {
        return $user->can('hr.onboarding.delete') && $this->scope->canManage($user, $onboarding->employee_id);
    }
}
