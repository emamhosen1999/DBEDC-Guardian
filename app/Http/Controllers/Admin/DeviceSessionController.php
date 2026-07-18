<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\RefreshToken;
use App\Models\User;
use App\Models\UserDevice;
use App\Services\DeviceAuthService;
use App\Services\RefreshTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Fleet-wide admin view of every user's device sessions.
 *
 * The per-user drill-down already exists (DeviceController + Pages/UserDevices);
 * this is the missing top-level dashboard: one paginated, searchable list of all
 * registered devices across all users, each annotated with the live credentials
 * still attached to it (Sanctum access tokens + refresh-token chain) and its last
 * activity, plus a single "revoke" action that kills that device's session.
 *
 * Token -> device linkage: a Sanctum token's NAME is the device name, which is not
 * unique and not a reliable key. The authoritative bridge is the `user_sessions`
 * row that DeviceAuthService::trackApiTokenSession writes on every API login:
 * session_id = "api-token:{tokenId}" and device_info->device_id = the device. We
 * resolve token ids through that map rather than guessing by name.
 */
class DeviceSessionController extends Controller
{
    /** Prefix DeviceAuthService uses for tracked API-token sessions. */
    protected const API_SESSION_PREFIX = 'api-token:';

    public function __construct(
        protected DeviceAuthService $deviceAuthService,
        protected RefreshTokenService $refreshTokenService,
    ) {}

    /**
     * Paginated list of device sessions across all users.
     */
    public function index(Request $request): InertiaResponse|JsonResponse
    {
        $search = trim((string) $request->input('search', ''));
        $status = (string) $request->input('status', 'all');
        $perPage = min(max((int) $request->input('per_page', 15), 5), 100);

        $query = UserDevice::query()->with('user:id,name,email,current_device_id,single_device_login_enabled');

        if ($search !== '') {
            $query->where(function ($outer) use ($search) {
                $outer->whereHas('user', function ($u) use ($search) {
                    $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                })
                    ->orWhere('device_name', 'like', "%{$search}%")
                    ->orWhere('device_id', 'like', "%{$search}%")
                    ->orWhere('platform', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $devices = $query->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $rows = collect($devices->items());
        $userIds = $rows->pluck('user_id')->filter()->unique()->values()->all();

        $tokenIdMap = $this->tokenIdMapForUsers($userIds);
        $tokenStats = $this->accessTokenStats($tokenIdMap);
        $refreshCounts = $this->activeRefreshTokenCounts($userIds);

        $sessions = $rows->map(function (UserDevice $device) use ($tokenIdMap, $tokenStats, $refreshCounts) {
            $key = $this->mapKey((int) $device->user_id, (string) $device->device_id);
            $tokenIds = $tokenIdMap[$key] ?? [];
            $stats = $tokenStats[$key] ?? ['active' => 0, 'last_used_at' => null];
            $refreshActive = $refreshCounts[$key] ?? 0;

            $lastActivity = $this->latestTimestamp([
                $device->last_used_at,
                $stats['last_used_at'],
            ]);

            $user = $device->user;
            $isCurrentDevice = $user !== null
                && trim((string) $user->current_device_id) !== ''
                && trim((string) $user->current_device_id) === trim((string) $device->device_id);

            return [
                'id' => $device->id,
                'device_id' => $device->device_id,
                'device_name' => $device->device_name,
                'device_type' => $device->device_type,
                'platform' => $device->platform,
                'device_model' => $device->device_model,
                'os_version' => $device->os_version,
                'app_version' => $device->app_version,
                'browser' => $device->browser,
                'ip_address' => $device->ip_address,
                'is_active' => (bool) $device->is_active,
                'is_trusted' => (bool) $device->is_trusted,
                'is_current_device' => $isCurrentDevice,
                'device_secret_issued_at' => optional($device->device_secret_issued_at)->toIso8601String(),
                'last_used_at' => optional($device->last_used_at)->toIso8601String(),
                'last_activity_at' => $lastActivity?->toIso8601String(),
                'access_tokens_active' => $stats['active'],
                'access_tokens_tracked' => count($tokenIds),
                'refresh_tokens_active' => $refreshActive,
                // A session is "live" only while the device still holds credentials.
                'has_live_session' => $stats['active'] > 0 || $refreshActive > 0,
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'single_device_login_enabled' => (bool) $user->single_device_login_enabled,
                ] : null,
            ];
        })->values();

        $payload = [
            'sessions' => $sessions,
            'pagination' => [
                'current_page' => $devices->currentPage(),
                'last_page' => $devices->lastPage(),
                'per_page' => $devices->perPage(),
                'total' => $devices->total(),
                'from' => $devices->firstItem(),
                'to' => $devices->lastItem(),
            ],
            'filters' => [
                'search' => $search,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'all',
                'per_page' => $perPage,
            ],
            'summary' => $this->fleetSummary(),
        ];

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json(['success' => true] + $payload);
        }

        return Inertia::render('Admin/DeviceSessions', $payload);
    }

    /**
     * Revoke one device's session.
     *
     * Kills, in a single transaction:
     *  1. every Sanctum access token bound to this device (deleted outright),
     *  2. the tracked `user_sessions` row for each of those tokens (is_current = false),
     *  3. the device's active refresh-token chain (revoked_at stamped),
     *  4. the device record itself (is_active = false),
     *  5. the user's current_device_id binding, when it points at this device.
     *
     * Deliberately scoped to ONE device: DeviceAuthService::terminateUserAccess would
     * log the user out of every device, which is the "reset all devices" action that
     * already exists elsewhere.
     */
    public function revoke(Request $request, int $device): JsonResponse|RedirectResponse
    {
        /** @var UserDevice $userDevice */
        $userDevice = UserDevice::with('user')->findOrFail($device);

        /** @var User|null $user */
        $user = $userDevice->user;

        if ($user === null) {
            abort(404, 'The user for this device no longer exists.');
        }

        $deviceId = trim((string) $userDevice->device_id);

        $result = DB::transaction(function () use ($userDevice, $user, $deviceId) {
            $tokenIds = $deviceId !== ''
                ? ($this->tokenIdMapForUsers([(int) $user->id])[$this->mapKey((int) $user->id, $deviceId)] ?? [])
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
                ? $this->refreshTokenService->revokeChainForUserDevice((int) $user->id, $deviceId)
                : 0;

            $this->deviceAuthService->deactivateDevice($user, (int) $userDevice->id);

            $unboundCurrentDevice = false;

            if ($deviceId !== '' && trim((string) $user->current_device_id) === $deviceId) {
                $user->forceFill(['current_device_id' => null])->save();
                $unboundCurrentDevice = true;
            }

            return [
                'access_tokens_revoked' => $accessTokensRevoked,
                'refresh_tokens_revoked' => (int) $refreshTokensRevoked,
                'unbound_current_device' => $unboundCurrentDevice,
            ];
        });

        $message = sprintf(
            'Revoked %s for %s: %d access token(s) and %d refresh token(s) destroyed.',
            $userDevice->device_name ?: 'device',
            $user->email,
            $result['access_tokens_revoked'],
            $result['refresh_tokens_revoked'],
        );

        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $message,
            ] + $result);
        }

        return back()->with('success', $message);
    }

    /**
     * Map "userId|deviceId" => [sanctum token ids] using the tracked API sessions.
     *
     * @param  array<int, int>  $userIds
     * @return array<string, array<int, int>>
     */
    protected function tokenIdMapForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = DB::table('user_sessions')
            ->select('user_id', 'session_id', 'device_info')
            ->whereIn('user_id', $userIds)
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

            $map[$this->mapKey((int) $row->user_id, $deviceId)][] = $tokenId;
        }

        return $map;
    }

    /**
     * Live access-token counts + last use, keyed the same way as the token id map.
     *
     * @param  array<string, array<int, int>>  $tokenIdMap
     * @return array<string, array{active: int, last_used_at: Carbon|null}>
     */
    protected function accessTokenStats(array $tokenIdMap): array
    {
        $allTokenIds = collect($tokenIdMap)->flatten()->unique()->values()->all();

        if ($allTokenIds === []) {
            return [];
        }

        $tokens = DB::table('personal_access_tokens')
            ->select('id', 'last_used_at', 'expires_at')
            ->whereIn('id', $allTokenIds)
            ->get()
            ->keyBy('id');

        $now = Carbon::now();
        $stats = [];

        foreach ($tokenIdMap as $key => $tokenIds) {
            $active = 0;
            $lastUsed = null;

            foreach ($tokenIds as $tokenId) {
                $token = $tokens->get($tokenId);

                if ($token === null) {
                    continue; // already deleted => not a live session
                }

                $expiresAt = $token->expires_at ? Carbon::parse($token->expires_at) : null;

                if ($expiresAt === null || $expiresAt->greaterThan($now)) {
                    $active++;
                }

                if ($token->last_used_at) {
                    $candidate = Carbon::parse($token->last_used_at);
                    $lastUsed = $lastUsed === null || $candidate->greaterThan($lastUsed) ? $candidate : $lastUsed;
                }
            }

            $stats[$key] = ['active' => $active, 'last_used_at' => $lastUsed];
        }

        return $stats;
    }

    /**
     * Active (unrevoked, unexpired) refresh tokens per "userId|deviceId".
     *
     * @param  array<int, int>  $userIds
     * @return array<string, int>
     */
    protected function activeRefreshTokenCounts(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return RefreshToken::query()
            ->select('user_id', 'device_id')
            ->whereIn('user_id', $userIds)
            ->whereNotNull('device_id')
            ->whereNull('revoked_at')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
            })
            ->get()
            ->groupBy(fn (RefreshToken $token) => $this->mapKey((int) $token->user_id, (string) $token->device_id))
            ->map->count()
            ->all();
    }

    /**
     * Fleet-level counters for the dashboard header.
     *
     * @return array<string, int>
     */
    protected function fleetSummary(): array
    {
        $total = UserDevice::query()->count();
        $active = UserDevice::query()->where('is_active', true)->count();

        return [
            'total_devices' => $total,
            'active_devices' => $active,
            'inactive_devices' => max($total - $active, 0),
            'users_with_devices' => (int) UserDevice::query()->distinct()->count('user_id'),
            'active_refresh_tokens' => RefreshToken::query()
                ->whereNull('revoked_at')
                ->where(function ($q) {
                    $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now());
                })
                ->count(),
        ];
    }

    protected function mapKey(int $userId, string $deviceId): string
    {
        return $userId.'|'.trim($deviceId);
    }

    /**
     * @param  array<int, mixed>  $values
     */
    protected function latestTimestamp(array $values): ?Carbon
    {
        $latest = null;

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $candidate = $value instanceof Carbon ? $value : Carbon::parse($value);

            if ($latest === null || $candidate->greaterThan($latest)) {
                $latest = $candidate;
            }
        }

        return $latest;
    }
}
