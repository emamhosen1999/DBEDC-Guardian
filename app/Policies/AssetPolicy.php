<?php

namespace App\Policies;

use App\Models\HRM\Asset;
use App\Models\User;
use App\Services\Access\DepartmentScope;

/**
 * Company assets follow the department scope of their CURRENT ASSIGNEE. Unassigned
 * (pool) assets are visible to anyone with hr.assets.view so a department admin can
 * assign from the pool, but only company-wide roles may edit or delete pool assets.
 */
class AssetPolicy
{
    public function __construct(private readonly DepartmentScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->can('hr.assets.view');
    }

    public function view(User $user, Asset $asset): bool
    {
        if (! $user->can('hr.assets.view')) {
            return false;
        }

        return $asset->assignee_id === null
            ? $asset->status === Asset::STATUS_AVAILABLE || $this->scope->isGlobal($user)
            : $this->scope->canActOn($user, $asset->assignee_id, allowSelf: true);
    }

    public function create(User $user): bool
    {
        return $user->can('hr.assets.manage');
    }

    /** Edit metadata, mark returned or delete: current assignee in scope; pool assets: global only. */
    public function update(User $user, Asset $asset): bool
    {
        return $user->can('hr.assets.manage') && $this->assigneeManageable($user, $asset);
    }

    public function return(User $user, Asset $asset): bool
    {
        return $this->update($user, $asset);
    }

    public function delete(User $user, Asset $asset): bool
    {
        return $this->update($user, $asset);
    }

    /**
     * Hand the asset to $employee: the target must be manageable by the actor and the
     * asset either in the pool or currently held by someone the actor manages.
     */
    public function assign(User $user, Asset $asset, User|string|null $employee = null): bool
    {
        if (! $user->can('hr.assets.manage')) {
            return false;
        }

        if ($employee !== null) {
            $target = $employee instanceof User ? $employee : User::withTrashed()->find($employee);
            if (! $target || $target->trashed() || ! $this->scope->canManage($user, $target)) {
                return false;
            }
        }

        return $asset->assignee_id === null || $this->assigneeManageable($user, $asset);
    }

    private function assigneeManageable(User $user, Asset $asset): bool
    {
        if ($this->scope->isGlobal($user)) {
            return true;
        }

        return $asset->assignee_id !== null && $this->scope->canManage($user, $asset->assignee_id);
    }
}
