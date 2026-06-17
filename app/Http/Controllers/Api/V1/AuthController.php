<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\MobileLoginRequest;
use App\Http\Resources\UserResource;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\DeviceAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly DeviceAuthService $deviceAuthService) {}

    public function login(MobileLoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $user = User::query()->where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            return $this->errorResponse(
                'The provided credentials are incorrect.',
                'INVALID_CREDENTIALS',
                401
            );
        }

        $deviceId = (string) ($credentials['device_id'] ?? '');
        $deviceName = trim((string) ($credentials['device_name'] ?? ''));
        $deviceSignature = is_array($credentials['device_signature'] ?? null)
            ? $credentials['device_signature']
            : [];

        $deviceCheck = $this->deviceAuthService->canLoginFromDevice($user, $deviceId, $deviceSignature);

        if (! $deviceCheck['allowed']) {
            $blockedDevice = $deviceCheck['device'] ?? null;

            return response()->json([
                'success' => false,
                'message' => $deviceCheck['message'],
                'error_code' => 'DEVICE_LOCKED',
                'code' => 'device_locked',
                'data' => [
                    'blocked_device_info' => $blockedDevice ? [
                        'device_name' => $blockedDevice->device_name,
                        'platform' => $blockedDevice->platform,
                        'model' => $blockedDevice->device_model,
                        'os_version' => $blockedDevice->os_version,
                        'last_used_at' => $blockedDevice->last_used_at,
                    ] : null,
                    'reset_hint' => 'Ask your administrator to reset this user device lock from the web user management page.',
                ],
            ], 403);
        }

        $device = $this->deviceAuthService->registerDevice(
            $user,
            $request,
            $deviceId,
            $deviceSignature,
            $deviceName !== '' ? $deviceName : null
        );

        if (! $device) {
            return $this->errorResponse(
                'Failed to register this device for secure login.',
                'DEVICE_REGISTRATION_FAILED',
                422
            );
        }

        $tokenName = $deviceName !== '' ? $deviceName : ($device->device_name ?: 'mobile-app');
        $newAccessToken = $user->createToken($tokenName);
        $token = $newAccessToken->plainTextToken;
        $currentTokenId = $newAccessToken->accessToken?->id;

        if ($user->hasSingleDeviceLoginEnabled() && $currentTokenId !== null) {
            $this->deviceAuthService->enforceSingleDeviceSession(
                $user,
                $device,
                null,
                (int) $currentTokenId
            );
        }

        if ($currentTokenId !== null) {
            $this->deviceAuthService->trackApiTokenSession($user, $request, (int) $currentTokenId);
        }

        $user->loadMissing([
            'department',
            'designation',
            'attendanceType',
            'roles',
            'currentDevice',
            'reportsTo',
        ]);

        return $this->successResponse([
            'token' => $token,
            'token_type' => 'Bearer',
            'user' => new UserResource($user),
            'active_device' => [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'device_name' => $device->device_name,
                'platform' => $device->platform,
                'model' => $device->device_model,
                'os_version' => $device->os_version,
                'last_used_at' => $device->last_used_at,
            ],
        ], 'Login successful.');
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->loadMissing([
            'department',
            'designation',
            'attendanceType',
            'roles',
            'currentDevice',
            'reportsTo',
        ]);

        return $this->successResponse(new UserResource($user));
    }

    public function logout(Request $request): JsonResponse
    {
        $accessToken = $request->user()?->currentAccessToken();

        if ($accessToken instanceof PersonalAccessToken) {
            $this->deviceAuthService->markApiTokenSessionInactive((int) $accessToken->id);
            $accessToken->delete();
        }

        return $this->successResponse(null, 'Logged out successfully.');
    }
}
