<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireMfa
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $next($request);
        }

        // Check if user has privileged role that requires MFA
        if ($this->requiresMfa($user)) {
            // Check if MFA is already configured
            if (! $this->hasMfaEnabled($user)) {
                // Check if user is in grace period
                if ($this->isInGracePeriod($user)) {
                    // Allow access but show warning
                    session()->flash('mfa_warning', true);
                    return $next($request);
                }

                // Redirect to MFA setup
                session()->flash('mfa_required', true);
                return redirect()->route('mfa.setup');
            }
        }

        return $next($request);
    }

    /**
     * Check if user has a role that requires MFA.
     */
    protected function requiresMfa($user): bool
    {
        $privilegedRoles = config('auth.mfa.enforced_roles', ['Super Administrator', 'Administrator']);

        // Check if user has privileged role
        if ($user->roles()->whereIn('name', $privilegedRoles)->exists()) {
            return true;
        }

        // Check if user has financial permissions
        $financialPermissions = config('auth.mfa.enforced_permissions', ['finance.*', 'payroll.*', 'accounts.*', 'salary.*', 'payment.*']);

        foreach ($financialPermissions as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has MFA enabled.
     */
    protected function hasMfaEnabled($user): bool
    {
        return ! empty($user->two_factor_secret) && 
               ! empty($user->two_factor_confirmed_at);
    }

    /**
     * Check if user is within MFA setup grace period.
     */
    protected function isInGracePeriod($user): bool
    {
        $gracePeriodDays = config('auth.mfa.grace_period_days', 7);
        
        if ($user->two_factor_confirmed_at) {
            return false;
        }

        // If account was created recently, allow grace period
        if ($user->created_at->diffInDays(now()) <= $gracePeriodDays) {
            return true;
        }

        return false;
    }
}
