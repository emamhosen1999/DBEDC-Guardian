<?php

namespace App\Http\Middleware;

use App\Services\DeviceAuthService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

class ApiDeviceAuthMiddleware
{
    public function __construct(private readonly DeviceAuthService $deviceAuthService) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication is required.',
            ], 401);
        }

        // --- New HMAC device-binding layer (staged behind a flag) -----------
        $binding = $this->deviceAuthService->verifyDeviceBinding($user, $request);
        $strict = (bool) config('security.device_binding.strict', false);

        if ($binding['enrolled']) {
            if ($binding['valid']) {
                // Authentic, current device — strictly stronger than the legacy
                // FNV/TOFU check, so accept immediately.
                return $next($request);
            }

            Log::warning(
                $strict
                    ? 'Device binding strict: rejected request with invalid HMAC signature'
                    : 'Device binding observe: request with invalid HMAC signature WOULD be rejected (allowed)',
                [
                    'user_id' => $user->id,
                    'reason' => $binding['reason'],
                    'strict' => $strict,
                    'path' => $request->path(),
                    'ip' => $request->ip(),
                ]
            );

            if ($strict) {
                $currentToken = $user->currentAccessToken();

                if ($currentToken instanceof PersonalAccessToken) {
                    $this->deviceAuthService->markApiTokenSessionInactive((int) $currentToken->id);
                    $currentToken->delete();
                }

                return response()->json([
                    'success' => false,
                    'message' => 'Device verification failed. This account is locked to another device.',
                    'code' => 'invalid_device',
                ], 401);
            }

            // Observe mode: fall through and allow.
        }
        // --- End new layer. Not enrolled → legacy path (unchanged). ---------

        if (! $user->hasSingleDeviceLoginEnabled()) {
            return $next($request);
        }

        if (! $user->activeDevices()->exists()) {
            return $next($request);
        }

        if (! $this->deviceAuthService->verifyApiDeviceRequest($user, $request)) {
            $currentToken = $user->currentAccessToken();

            if ($currentToken instanceof PersonalAccessToken) {
                $this->deviceAuthService->markApiTokenSessionInactive((int) $currentToken->id);
                $currentToken->delete();
            }

            return response()->json([
                'success' => false,
                'message' => 'Device verification failed. This account is locked to another device.',
                'code' => 'invalid_device',
            ], 401);
        }

        return $next($request);
    }
}
