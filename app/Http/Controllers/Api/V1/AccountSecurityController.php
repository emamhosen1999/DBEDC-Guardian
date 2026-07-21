<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceAuthService;
use App\Services\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Self-service account security for the mobile app.
 *
 * Everything here is strictly scoped to the AUTHENTICATED user — a user may only
 * ever see and revoke their OWN device sessions. The fleet-wide admin equivalent
 * lives in Admin\DeviceSessionController; this controller deliberately duplicates
 * the small token->device resolution rather than reaching into it, so the admin
 * surface and the self-service surface stay independent.
 */
class AccountSecurityController extends Controller
{
    use ApiResponse;

    /** Prefix DeviceAuthService uses for tracked API-token sessions. */
    protected const API_SESSION_PREFIX = 'api-token:';

    public function __construct(
        protected DeviceAuthService $deviceAuthService,
        protected RefreshTokenService $refreshTokenService,
    ) {}

    /**
     * Change the authenticated user's password.
     *
     * Verifies the current password (guard-agnostic Hash::check so it behaves
     * identically under a real Sanctum token and under Sanctum::actingAs), then
     * enforces the app's default password policy on the new one.
     */
    public function changePassword(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'confirmed', 'different:current_password', Password::defaults()],
        ], [
            'new_password.different' => 'Your new password must be different from your current password.',
        ]);

        if (! Hash::check($validated['current_password'], (string) $user->password)) {
            return $this->errorResponse(
                'The provided password does not match your current password.',
                'INVALID_CURRENT_PASSWORD',
                422,
                ['current_password' => ['The provided password does not match your current password.']]
            );
        }

        $user->forceFill([
            'password' => $validated['new_password'],
        ])->save();

        return $this->successResponse(null, 'Password changed successfully.');
    }

    /**
     * List the authenticated user's own active device sessions.
     */
    public function devices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentDeviceId = $this->currentDeviceId($request, $user);

        $devices = UserDevice::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->get()
            ->map(function (UserDevice $device) use ($currentDeviceId) {
                $deviceId = trim((string) $device->device_id);

                return [
                    'id' => $device->id,
                    'device_id' => $device->device_id,
                    'device_name' => $device->device_name ?: 'Unknown device',
                    'device_type' => $device->device_type,
                    'platform' => $device->platform,
                    'device_model' => $device->device_model,
                    'os_version' => $device->os_version,
                    'app_version' => $device->app_version,
                    'is_trusted' => (bool) $device->is_trusted,
                    'is_current' => $currentDeviceId !== '' && $deviceId === $currentDeviceId,
                    'last_used_at' => optional($device->last_used_at)->toIso8601String(),
                ];
            })
            ->values();

        return $this->successResponse([
            'devices' => $devices,
            'current_device_id' => $currentDeviceId !== '' ? $currentDeviceId : null,
        ]);
    }

    /**
     * Revoke ONE of the authenticated user's own devices.
     *
     * Ownership is enforced: a device belonging to any other user returns 403,
     * never leaking its existence beyond "forbidden".
     */
    public function revokeDevice(Request $request, int $device): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var UserDevice|null $userDevice */
        $userDevice = UserDevice::query()->find($device);

        if ($userDevice === null) {
            return $this->notFoundResponse('Device not found.');
        }

        if ((int) $userDevice->user_id !== (int) $user->id) {
            return $this->forbiddenResponse('You can only revoke your own devices.');
        }

        $deviceId = trim((string) $userDevice->device_id);

        $result = DB::transaction(function () use ($user, $userDevice, $deviceId) {
            return $this->revokeSingleDevice($user, $userDevice, $deviceId);
        });

        return $this->successResponse($result, 'Device signed out successfully.');
    }

    /**
     * Sign the authenticated user out of every device EXCEPT the current one.
     */
    public function signOutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $currentDeviceId = $this->currentDeviceId($request, $user);
        $currentToken = $user->currentAccessToken();
        $currentTokenId = $currentToken instanceof PersonalAccessToken ? (int) $currentToken->id : null;

        $result = DB::transaction(function () use ($user, $currentDeviceId, $currentTokenId) {
            // 1. Access tokens: delete every token except the one making this call.
            $accessTokenQuery = $user->tokens();

            if ($currentTokenId !== null) {
                $accessTokenQuery->where('id', '!=', $currentTokenId);
            }

            $tokenIds = $accessTokenQuery->pluck('id')->map(static fn ($id): int => (int) $id)->all();

            $accessTokensRevoked = 0;

            if ($tokenIds !== []) {
                $accessTokensRevoked = (int) $user->tokens()->whereIn('id', $tokenIds)->delete();

                foreach ($tokenIds as $tokenId) {
                    $this->deviceAuthService->markApiTokenSessionInactive($tokenId);
                }
            }

            // 2. Refresh tokens: revoke every chain not bound to the current device.
            //    With no resolvable current device we leave refresh tokens intact
            //    rather than risk killing the caller's own chain.
            $refreshTokensRevoked = $currentDeviceId !== ''
                ? (int) $this->refreshTokenService->revokeForOtherDevices((int) $user->id, $currentDeviceId)
                : 0;

            // 3. Devices: deactivate every active device except the current one.
            $deviceQuery = UserDevice::query()
                ->where('user_id', $user->id)
                ->where('is_active', true);

            if ($currentDeviceId !== '') {
                $deviceQuery->where('device_id', '!=', $currentDeviceId);
            }

            $devicesRevoked = (int) $deviceQuery->update(['is_active' => false]);

            return [
                'access_tokens_revoked' => $accessTokensRevoked,
                'refresh_tokens_revoked' => $refreshTokensRevoked,
                'devices_revoked' => $devicesRevoked,
            ];
        });

        return $this->successResponse($result, 'Signed out of all other devices.');
    }

    /**
     * Kill a single device's credentials: access tokens, refresh chain, the
     * device record, and the user's current-device binding when it points here.
     * Assumes ownership has already been verified by the caller.
     *
     * @return array<string, int|bool>
     */
    protected function revokeSingleDevice(User $user, UserDevice $userDevice, string $deviceId): array
    {
        $tokenIds = $deviceId !== ''
            ? ($this->tokenIdMapForUser((int) $user->id)[$deviceId] ?? [])
            : [];

        $accessTokensRevoked = 0;

        if ($tokenIds !== []) {
            $accessTokensRevoked = (int) $user->tokens()->whereIn('id', $tokenIds)->delete();

            foreach ($tokenIds as $tokenId) {
                $this->deviceAuthService->markApiTokenSessionInactive((int) $tokenId);
            }
        }

        // An empty device_id would make revokeChainForUserDevice wipe EVERY refresh
        // token the user owns, so only run it for a genuinely device-bound record.
        $refreshTokensRevoked = $deviceId !== ''
            ? (int) $this->refreshTokenService->revokeChainForUserDevice((int) $user->id, $deviceId)
            : 0;

        $this->deviceAuthService->deactivateDevice($user, (int) $userDevice->id);

        $unboundCurrentDevice = false;

        if ($deviceId !== '' && trim((string) $user->current_device_id) === $deviceId) {
            $user->forceFill(['current_device_id' => null])->save();
            $unboundCurrentDevice = true;
        }

        return [
            'access_tokens_revoked' => $accessTokensRevoked,
            'refresh_tokens_revoked' => $refreshTokensRevoked,
            'unbound_current_device' => $unboundCurrentDevice,
        ];
    }

    /**
     * Resolve the device the current request is coming from: prefer the device
     * header the app sends on every call, fall back to the user's bound device.
     */
    protected function currentDeviceId(Request $request, User $user): string
    {
        $headerDeviceId = trim((string) ($request->header('X-Device-ID') ?: $request->input('device_id')));

        if ($headerDeviceId !== '') {
            return $headerDeviceId;
        }

        return trim((string) $user->current_device_id);
    }

    /**
     * Map deviceId => [sanctum token ids] for ONE user, via the tracked API
     * sessions DeviceAuthService writes on every login/refresh.
     *
     * @return array<string, array<int, int>>
     */
    protected function tokenIdMapForUser(int $userId): array
    {
        $rows = DB::table('user_sessions')
            ->select('session_id', 'device_info')
            ->where('user_id', $userId)
            ->where('session_id', 'like', self::API_SESSION_PREFIX.'%')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $tokenId = (int) substr((string) $row->session_id, strlen(self::API_SESSION_PREFIX));

            if ($tokenId <= 0) {
                continue;
            }

            $info = json_decode((string) $row->device_info, true);
            $deviceId = is_array($info) ? trim((string) ($info['device_id'] ?? '')) : '';

            if ($deviceId === '') {
                continue;
            }

            $map[$deviceId][] = $tokenId;
        }

        return $map;
    }
}
