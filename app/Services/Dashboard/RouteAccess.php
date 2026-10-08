<?php

namespace App\Services\Dashboard;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * "Can this viewer open that page?", answered from the route's OWN middleware.
 *
 * The dashboard must never render a figure that links to a page the viewer would
 * get a 403 on. Rather than duplicating each page's permission in the widget (and
 * letting the two drift), this reads the `permission:` / `role:` /
 * `role_or_permission:` middleware the route is actually registered with. Every
 * permission middleware must pass; inside one, any listed permission is enough —
 * the same semantics as Spatie's middleware (which resolves through the Gate, so
 * a Super Administrator passes every permission).
 */
class RouteAccess
{
    public function canOpen(User $viewer, ?string $routeName): bool
    {
        if ($routeName === null || $routeName === '') {
            return false;
        }

        $route = Route::getRoutes()->getByName($routeName);
        if ($route === null) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware) || ! str_contains($middleware, ':')) {
                continue;
            }

            [$alias, $arguments] = explode(':', $middleware, 2);
            $names = array_values(array_filter(array_map('trim', preg_split('/[|,]/', $arguments) ?: [])));

            $passes = match ($alias) {
                'permission' => $viewer->canAny($names),
                'role' => $viewer->hasAnyRole($names),
                'role_or_permission' => $viewer->canAny($names) || $viewer->hasAnyRole($names),
                default => true, // auth, verified, throttle, feature:* … not an authorization question
            };

            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    /**
     * The first route in $candidates the viewer can open, or null.
     *
     * @param  array<int, string>  $candidates  route names, most specific first
     */
    public function firstOpenable(User $viewer, array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if ($this->canOpen($viewer, $name)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Relative URL for a route name, or null when it does not exist / needs params
     * the caller did not give. Relative on purpose: the SPA and the mobile web build
     * resolve it against their own origin.
     *
     * @param  array<string, mixed>  $parameters
     */
    public function url(string $routeName, array $parameters = []): ?string
    {
        try {
            return route($routeName, $parameters, false);
        } catch (\Throwable) {
            return null;
        }
    }
}
