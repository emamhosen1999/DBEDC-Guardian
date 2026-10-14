<?php

namespace App\Policies;

use App\Models\Jurisdiction;
use App\Models\User;

/**
 * One named permission per action, the same names the routes and the UI use:
 * jurisdiction.view (also prints the map), .create, .update, .delete, and .team.manage for the team.
 * Jurisdictions are project-wide records, not employee-owned rows, so no DepartmentScope applies.
 */
class JurisdictionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('jurisdiction.view');
    }

    public function view(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.view');
    }

    public function print(User $user): bool
    {
        return $user->can('jurisdiction.view');
    }

    public function create(User $user): bool
    {
        return $user->can('jurisdiction.create');
    }

    public function update(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.update');
    }

    public function delete(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.delete');
    }

    public function manageTeam(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.team.manage');
    }

    public function restructure(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.restructure');
    }

    public function retire(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.retire');
    }

    public function restore(User $user, mixed $jurisdiction = null): bool
    {
        return $user->can('jurisdiction.restore');
    }
}
