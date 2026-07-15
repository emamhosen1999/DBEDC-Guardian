<?php

namespace App\Http\Middleware;

use App\Models\HRM\BiometricDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorizes ZKTeco ADMS (/iclock/*) push requests.
 *
 * ZKTeco devices identify themselves only by serial number (?SN=), which is
 * printed on the unit and not secret. This middleware layers two protections on
 * top of that, both returning the plain-text "ERROR" body the device expects on
 * failure (a JSON/HTML body would make the device retry forever):
 *
 *   1. Allowlist — the serial MUST match a registered, ACTIVE device row. Always
 *      enforced (a genuine device is always provisioned), so it cannot break
 *      live hardware, but it stops pushes from serials that were never added.
 *
 *   2. Optional per-device shared secret (biometric_devices.adms_token). If a
 *      device has a token configured, the request must present a matching token
 *      via ?token= (config key attendance.adms_token_param) or the
 *      X-Device-Token / X-ADMS-Token header. If the device has NO token
 *      configured, it falls back to allowlist-only so today's devices keep
 *      working. Enforcement of #2 is staged via config('attendance.*'):
 *        - observe mode (default): bad/absent tokens are logged but allowed.
 *        - strict mode: bad/absent tokens are rejected.
 *      Device-initiated user enrollment (table=USERINFO) is locked down harder:
 *      it always requires a valid token when adms_enrollment_requires_token is
 *      on, independent of the observe/strict switch.
 */
class EnsureAdmsDeviceAuthorized
{
    public function handle(Request $request, Closure $next): Response
    {
        $serial = $request->query('SN') ?: $request->header('SN');

        if (! $serial) {
            return $this->reject($request, 400, 'missing serial number', [
                'ip' => $request->ip(),
            ]);
        }

        $device = BiometricDevice::where('serial_number', $serial)->first();

        // 1. Allowlist — registered + active. Always enforced.
        if (! $device || ! $device->is_active) {
            return $this->reject($request, 401, 'unknown or inactive device', [
                'serial' => $serial,
                'ip' => $request->ip(),
            ]);
        }

        // 2. Optional per-device shared secret.
        $expected = $device->adms_token;
        $provided = $this->providedToken($request);
        $hasSecret = filled($expected);
        $tokenValid = $hasSecret
            ? (filled($provided) && hash_equals((string) $expected, (string) $provided))
            : true; // no secret configured -> allowlist-only fallback

        $table = strtoupper((string) $request->query('table'));
        $isEnrollment = $table === 'USERINFO';

        // 3. Enrollment (USERINFO) can create/rewrite users — lock it down hard.
        if ($isEnrollment
            && config('attendance.adms_enrollment_requires_token', true)
            && ! $tokenValid) {
            return $this->reject($request, 401, 'enrollment (USERINFO) requires a valid device token', [
                'serial' => $serial,
                'device_id' => $device->id,
                'has_secret' => $hasSecret,
                'token_present' => filled($provided),
                'ip' => $request->ip(),
            ]);
        }

        // 4. Normal token enforcement for punches / commands (staged).
        if (! $tokenValid) {
            $strict = (bool) config('attendance.adms_strict_auth', false);

            Log::warning(
                $strict
                    ? 'ADMS strict auth: rejected push with bad/absent device token'
                    : 'ADMS observe mode: push with bad/absent device token WOULD be rejected (allowed)',
                [
                    'serial' => $serial,
                    'device_id' => $device->id,
                    'table' => $table ?: null,
                    'token_present' => filled($provided),
                    'strict' => $strict,
                    'ip' => $request->ip(),
                ]
            );

            if ($strict) {
                // Already logged above; do not double-log.
                return new Response('ERROR', 401, ['Content-Type' => 'text/plain']);
            }
        }

        // Expose the decision to the controller for defense-in-depth.
        $request->attributes->set('adms_token_verified', $tokenValid);
        $request->attributes->set('adms_device_id', $device->id);

        return $next($request);
    }

    protected function providedToken(Request $request): ?string
    {
        $param = config('attendance.adms_token_param', 'token');

        return $request->query($param)
            ?: $request->header('X-Device-Token')
            ?: $request->header('X-ADMS-Token')
            ?: null;
    }

    protected function reject(Request $request, int $status, string $reason, array $context = []): Response
    {
        Log::warning('ADMS request rejected: '.$reason, $context);

        // ZKTeco devices expect a plain-text body; anything else triggers retries.
        return new Response('ERROR', $status, ['Content-Type' => 'text/plain']);
    }
}
