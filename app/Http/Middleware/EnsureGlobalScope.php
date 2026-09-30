<?php

namespace App\Http\Middleware;

use App\Services\Access\DepartmentScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for FLEET-WIDE surfaces: `scope.global`.
 *
 * A permission such as `users.update` is also held by department-scoped operators
 * (it edits people inside their DepartmentScope). A surface that acts on the whole
 * company — feature flags, crash-telemetry triage — must therefore not hinge on that
 * permission alone: it additionally requires a GLOBAL actor (DepartmentScope::isGlobal).
 *
 * Fails CLOSED and answers 403, like a missing permission.
 */
class EnsureGlobalScope
{
    public function __construct(protected DepartmentScope $scope) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $this->scope->isGlobal($user),
            403,
            'This action is restricted to company-wide administrators.'
        );

        return $next($request);
    }
}
