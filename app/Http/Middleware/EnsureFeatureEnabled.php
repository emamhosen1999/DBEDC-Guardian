<?php

namespace App\Http\Middleware;

use App\Services\FeatureFlagService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route gate for unfinished modules: `feature:<flag_key>`.
 *
 * Fails CLOSED — a missing flag row counts as disabled — and answers 404 so a
 * disabled module is indistinguishable from one that does not exist.
 */
class EnsureFeatureEnabled
{
    public function __construct(protected FeatureFlagService $flags) {}

    public function handle(Request $request, Closure $next, string $flag): Response
    {
        abort_unless($this->flags->isEnabled($flag, $request->user(), false), 404);

        return $next($request);
    }
}
