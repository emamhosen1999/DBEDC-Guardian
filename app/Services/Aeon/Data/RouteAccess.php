<?php

declare(strict_types=1);

namespace App\Services\Aeon\Data;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * Evaluates a route's permission middleware for a user, so Aeon only offers forms and
 * navigation to pages the user could open anyway. Submission is still guarded by the route's own
 * middleware and controller policy; this keeps Aeon from advertising routes the user can't use.
 *
 * Understands permission:, role:, role_or_permission: (comma/pipe separated, any-of) and
 * can:<ability> without a model. Other middleware (auth, verified, throttle, ...) is not an
 * authorization rule and is ignored; per-record policies run when the form is submitted.
 */
class RouteAccess
{
    /**
     * @param  array<int, mixed>  $middleware  a route's gathered middleware
     */
    public static function allows(array $middleware, ?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        foreach ($middleware as $entry) {
            if (! is_string($entry) || ! str_contains($entry, ':')) {
                continue;
            }

            [$name, $params] = explode(':', $entry, 2);
            $values = array_values(array_filter(array_map('trim', preg_split('/[,|]/', $params) ?: [])));

            $ok = match ($name) {
                'permission' => self::anyPermission($user, $values),
                'role' => $user->hasAnyRole($values),
                'role_or_permission' => $user->hasAnyRole($values) || self::anyPermission($user, $values),
                'can' => count($values) !== 1 || $user->can($values[0]),
                default => true,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    /**
     * May the user open this page URL (GET)? An unmatched path counts as not available.
     */
    public static function canOpen(string $url, ?User $user): bool
    {
        try {
            $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
            $route = app('router')->getRoutes()->match(Request::create($path, 'GET'));
        } catch (\Throwable) {
            return false;
        }

        return self::allows(self::middlewareOf($route), $user);
    }

    /**
     * Middleware strings of a router route (closures are dropped: they cannot be evaluated here).
     *
     * @return array<int, string>
     */
    public static function middlewareOf(Route $route): array
    {
        return array_values(array_filter($route->gatherMiddleware(), 'is_string'));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private static function anyPermission(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
