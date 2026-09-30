<?php

namespace App\Services\Directory;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Directory-facing adapter over the shared DepartmentScope service, kept so the
 * directory query (and its tests) keep their constructor contract. All scope
 * rules live in App\Services\Access\DepartmentScope.
 */
class ScopeResolver
{
    private DepartmentScope $scope;

    public function __construct(?DepartmentScope $scope = null)
    {
        $this->scope = $scope ?? app(DepartmentScope::class);
    }

    public function isGlobal(User $requester): bool
    {
        return $this->scope->isGlobal($requester);
    }

    /**
     * Narrow the query to the requester's allowed set of users.
     */
    public function applyBaseScope(Builder $query, User $requester): Builder
    {
        return $this->scope->applyToUsers($query, $requester);
    }
}
