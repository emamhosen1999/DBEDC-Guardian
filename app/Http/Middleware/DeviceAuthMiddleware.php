<?php

namespace App\Http\Middleware;

use App\Services\DeviceAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class DeviceAuthMiddleware
{
    protected DeviceAuthService $deviceAuthService;

    public function __construct(DeviceAuthService $deviceAuthService)
    {
        $this->deviceAuthService = $deviceAuthService;
    }

    /**
     * Handle an incoming request.
     * Verifies that authenticated users are using their registered device.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip for login/register routes
        if (
            $request->routeIs('login')
            || $request->routeIs('register')
            || $request->routeIs('password.*')
            || $request->routeIs('verification.*')
        ) {
            return $next($request);
        }

        // Only check for authenticated users
        if (! Auth::check()) {
            return $next($request);
        }

        $user = Auth::user();

        // Skip device verification if single device login is disabled for this user
        if (! $user->hasSingleDeviceLoginEnabled()) {
            return $next($request);
        }

        // Get device_id from header or input
        $deviceId = $request->header('X-Device-ID')
            ?? $request->input('device_id')
            ?? $request->session()->get('device_id');

        if ($deviceId) {
            $request->merge([
                'device_id' => $deviceId,
            ]);

            $request->session()->put('device_id', $deviceId);
        }

        // If no device_id is provided, log warning but allow (for backward compatibility during migration)
        if (! $deviceId) {
            Log::warning('No device_id provided in authenticated request', [
                'user_id' => $user->id,
                'route' => $request->path(),
            ]);

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'error' => 'Device verification required. Please login again.',
                    'reason' => 'missing_device_id',
                ], 403);
            }

            return redirect()
                ->route('login')
                ->with('device_blocked', true)
                ->with('device_message', 'Device verification is required. Please sign in again from this device.');
        }

        // Verify device
        $isValid = $this->deviceAuthService->verifyDeviceOnRequest($user, $request);

        if (! $isValid) {
            Log::warning('Invalid device verification', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
                'route' => $request->path(),
            ]);

            // Log out the user
            Auth::logout();

            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson() || $request->wantsJson()) {
                return response()->json([
                    'error' => 'Device verification failed. Please login again.',
                    'reason' => 'invalid_device',
                ], 403);
            }

            return redirect()
                ->route('login')
                ->with('device_blocked', true)
                ->with('device_message', 'Your session is no longer valid on this device. Please sign in again.');
        }

        return $next($request);
    }
}
