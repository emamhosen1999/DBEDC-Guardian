<?php

namespace App\Policies;

use App\Models\HRM\OffboardingTask;
use App\Models\User;

/**
 * Policy for OffboardingTask (formerly OffboardingStep — renamed to match the
 * actual model). Guards CRUD on individual tasks within an offboarding process.
 */
class OffboardingStepPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hr.offboarding.view');
    }

    public function view(User $user, OffboardingTask $task): bool
    {
        return $user->can('hr.offboarding.view');
    }

    public function create(User $user): bool
    {
        return $user->can('hr.offboarding.create');
    }

    public function update(User $user, OffboardingTask $task): bool
    {
        return $user->can('hr.offboarding.update');
    }

    public function delete(User $user, OffboardingTask $task): bool
    {
        return $user->can('hr.offboarding.delete');
    }
}
