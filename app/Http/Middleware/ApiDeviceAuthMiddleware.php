<?php

namespace App\Http\Middleware;

use App\Services\DeviceAuthService;
use Closure;
use Illuminate\Http\Request;
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

        if (! $user->hasSingleDeviceLoginEnabled()) {
            return $next($request);
        }

        if (! $user->activeDevices()->exists()) {
            return $next($request);
        }

        if (! $this->deviceAuthService->verifyApiDeviceRequest($user, $request)) {
            $currentToken = $user->currentAccessToken();

            if ($currentToken instanceof PersonalAccessToken) {
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
