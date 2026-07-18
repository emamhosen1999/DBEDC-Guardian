<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\UserDevice;
use App\Services\FeatureFlagService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Presence / liveness beacon for the mobile client.
 *
 *   POST /api/v1/heartbeat            (auth:sanctum + device binding + throttle)
 *
 * NO NEW TABLE. Last-seen is recorded on the two rows this app ALREADY treats
 * as the authority for "when did we last hear from this device/session":
 *
 *   1. user_devices.last_used_at  — the column DeviceAuthService already stamps
 *      on login (registerDevice) and on every verified API request
 *      (verifyApiDeviceRequest). It is also the column the admin fleet
 *      dashboard sorts and displays (Admin\DeviceSessionController::index →
 *      'last_used_at'), so a heartbeat surfaces there with ZERO page changes.
 *
 *   2. user_sessions.last_activity — the tracked API-session row that
 *      DeviceAuthService::trackApiTokenSession writes at login, keyed by
 *      session_id 'api-token:{tokenId}'. Updated ONLY if the row already
 *      exists: the heartbeat must never conjure a session row that login
 *      did not create.
 *
 * Both writes are best-effort — a presence beacon must never 500 the client.
 *
 * Response is deliberately tiny (it runs on a timer, on mobile data):
 *   server_time / server_timestamp — authoritative clock, lets the client
 *     detect its own drift (the punch path already rejects >2h drift).
 *   last_seen_at                   — what we just recorded.
 *   device_tracked                 — false when the caller sent no resolvable
 *     X-Device-ID, i.e. presence was recorded at session level only.
 *   refresh_config                 — true when the client's reported
 *     config_etag no longer matches the FeatureFlagService etag that
 *     GET /api/v1/config serves, i.e. a remote-config re-fetch is warranted.
 *     Absent config_etag ⇒ false, never a spurious fleet-wide refresh storm.
 */
class HeartbeatController extends Controller
{
    use ApiResponse;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => ['sometimes', 'nullable', 'string', 'max:191'],
            'config_etag' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);

        $user = $request->user();
        $now = Carbon::now();

        $deviceId = trim((string) ($request->header('X-Device-ID') ?: ($validated['device_id'] ?? '')));

        $deviceTracked = false;

        if ($deviceId !== '') {
            $deviceTracked = UserDevice::where('user_id', $user->id)
                ->where('device_id', $deviceId)
                ->where('is_active', true)
                ->update(['last_used_at' => $now, 'updated_at' => $now]) > 0;
        }

        $this->touchTrackedSession($user->id, $request, $now);

        return $this->successResponse([
            'server_time' => $now->toIso8601String(),
            'server_timestamp' => $now->getTimestamp(),
            'last_seen_at' => $now->toIso8601String(),
            'device_tracked' => $deviceTracked,
            'refresh_config' => $this->configIsStale($request, trim((string) ($validated['config_etag'] ?? ''))),
        ]);
    }

    /**
     * Should the client re-fetch GET /api/v1/config?
     *
     * Compared against the SAME FeatureFlagService etag that endpoint serves,
     * so the two can never disagree. A client that sends no etag gets false —
     * silence must not trigger a refresh storm across the fleet. A failure to
     * compute the etag also yields false: a degraded flag store must not make
     * every device hammer /config.
     */
    private function configIsStale(Request $request, string $clientEtag): bool
    {
        if ($clientEtag === '') {
            return false;
        }

        try {
            $current = (string) (app(FeatureFlagService::class)->payloadFor($request->user())['etag'] ?? '');
        } catch (\Throwable $e) {
            report($e);

            return false;
        }

        // Tolerate quoted / weak ETag forms, as ConfigController does.
        $normalized = trim(preg_replace('/^W\//i', '', $clientEtag), "\" \t");

        return $current !== '' && ! hash_equals($current, $normalized);
    }

    /**
     * Bump last_activity on the tracked API session for the calling token.
     *
     * The session_id format mirrors DeviceAuthService::buildApiTokenSessionId()
     * ('api-token:{id}'), which is protected — this is the one place that shape
     * is duplicated, and it is scoped to an UPDATE of an existing row so a
     * format drift degrades to "no rows updated", never to a bogus insert.
     */
    private function touchTrackedSession(int $userId, Request $request, Carbon $now): void
    {
        try {
            $token = $request->user()?->currentAccessToken();

            if (! $token instanceof PersonalAccessToken) {
                return; // web/session-guard caller: nothing token-scoped to bump
            }

            DB::table('user_sessions')
                ->where('user_id', $userId)
                ->where('session_id', 'api-token:'.$token->id)
                ->update(['last_activity' => $now, 'updated_at' => $now]);
        } catch (\Throwable $e) {
            report($e); // presence is best-effort: never fail the beacon
        }
    }
}
