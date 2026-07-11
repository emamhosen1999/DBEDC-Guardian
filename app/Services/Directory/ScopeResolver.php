<?php

namespace App\Services\Directory;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ScopeResolver
{
    /** Roles that may see the entire directory. */
    private const GLOBAL_ROLES = ['Super Administrator', 'Administrator', 'HR Manager'];

    public function isGlobal(User $requester): bool
    {
        return $requester->hasRole(self::GLOBAL_ROLES);
    }

    /**
     * Narrow the query to the requester's allowed set of users.
     * Mirrors App\Policies\UserPolicy scope rules.
     */
    public function applyBaseScope(Builder $query, User $requester): Builder
    {
        if ($this->isGlobal($requester)) {
            return $query;
        }

        if ($requester->hasRole('Department Manager') && $requester->department_id !== null) {
            return $query->where('department_id', $requester->department_id);
        }

        // Default: only self.
        return $query->where('id', $requester->id);
    }
}
