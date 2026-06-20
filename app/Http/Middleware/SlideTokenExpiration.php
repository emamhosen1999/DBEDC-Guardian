<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives mobile Sanctum bearer tokens the SAME sliding idle-timeout window as web
 * sessions. Laravel's database session driver renews a session's idle clock on
 * every request; this middleware does the equivalent for token auth by pushing the
 * current token's expires_at forward by config('session.lifetime') minutes whenever
 * the token is used.
 *
 * The Sanctum guard (laravel/sanctum Guard::supportsTokens) already rejects a token
 * whose expires_at is in the past, returning a guest -> 401. So this middleware only
 * needs to keep an actively-used token alive. Idle tokens simply lapse.
 *
 * Must run AFTER auth:sanctum so the token is resolved.
 */
class SlideTokenExpiration
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        // Only real, persisted tokens that carry an expiry are slid. Guard against
        // both never-expiring tokens (expires_at null) and non-date values such as
        // the Mockery mock injected by Sanctum::actingAs() in tests (expires_at false).
        if ($token instanceof PersonalAccessToken && $token->expires_at instanceof \DateTimeInterface) {
            $idleTimeout = (int) config('session.lifetime');
            $newExpiry = now()->addMinutes($idleTimeout);

            // Throttle DB writes: only slide when more than a minute of drift has
            // accrued. Avoids a write on every single request while keeping the
            // window effectively continuous.
            if ($token->expires_at->diffInSeconds($newExpiry, false) > 60) {
                $token->forceFill(['expires_at' => $newExpiry])->save();
            }
        }

        return $next($request);
    }
}
