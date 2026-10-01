<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While `users.must_change_password` is true (an admin set or reset the password) the account can do
 * nothing but change that password and sign out.
 *
 *   web  -> every page redirects to the change-password page (303 so a POST/PUT also lands on a GET);
 *           XHR/JSON callers get 423 with `PASSWORD_CHANGE_REQUIRED`;
 *   API  -> only /auth/me, /auth/logout and /account/change-password pass; everything else is 423.
 */
class EnforcePasswordChange
{
    private const WEB_ALLOWED = ['account.password.edit', 'account.password.update', 'logout'];

    private const API_ALLOWED = ['api/v1/auth/me', 'api/v1/auth/logout', 'api/v1/account/change-password'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        if ($request->is('api/*')) {
            if ($request->is(self::API_ALLOWED)) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'You must change your password before continuing.',
                'error_code' => 'PASSWORD_CHANGE_REQUIRED',
                'code' => 'password_change_required',
            ], 423);
        }

        if (in_array($request->route()?->getName(), self::WEB_ALLOWED, true)) {
            return $next($request);
        }

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'You must change your password before continuing.',
                'error_code' => 'PASSWORD_CHANGE_REQUIRED',
            ], 423);
        }

        return redirect()->route('account.password.edit', [], 303);
    }
}
