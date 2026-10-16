<?php

namespace App\Support\Navigation;

use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Where a signed-in person lands: the first home page they may open, never a 403.
 *
 * Someone holding only function roles (e.g. a director without the base Employee role) has no
 * core.dashboard.view; sending them to /dashboard after login showed "403 no permission".
 */
final class LandingPage
{
    /** [permission (null = every signed-in person), route name], in order of preference. */
    private const CANDIDATES = [
        ['core.dashboard.view', 'dashboard'],
        ['om.dashboard.view', 'om.dashboard'],
        [null, 'employee-dashboard'],
    ];

    public static function for(?User $user): string
    {
        foreach (self::CANDIDATES as [$permission, $route]) {
            if (Route::has($route) && ($permission === null || $user?->can($permission))) {
                return route($route);
            }
        }

        return route('employee-dashboard');
    }
}
