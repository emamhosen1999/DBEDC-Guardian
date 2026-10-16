<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        // A signed-in mobile client loads several resources per screen, and one person may be on the phone and the
        // web at once (one shared budget), so 60 a minute ran out in normal use. Guests share a site's IP at shift
        // start; login has its own per-account lockout. Expensive endpoints keep their own tighter route limits.
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(180)->by('user:'.$request->user()->getKey())
                : Limit::perMinute(120)->by('ip:'.$request->ip());
        });

        // Viewing attachment images (scan previews, signed documents) has its own budget, so a page of document
        // images can never starve the rest of the app.
        RateLimiter::for('attachment-views', function (Request $request) {
            return Limit::perMinute(300)->by('attachment-views:'.($request->user()?->getKey() ?: $request->ip()));
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
