<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserDevice;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;

class DeviceAuthService
{
    protected Agent $agent;

    public function __construct()
    {
        $this->agent = new Agent;
    }

    public function generateDeviceToken(string $deviceId, int $userId): string
    {
        $data = $deviceId.'|'.$userId;

        return hash_hmac('sha256', $data, (string) config('app.key'));
    }

    public function verifyDeviceToken(string $deviceId, int $userId, string $storedToken): bool
    {
        $data = $deviceId.'|'.$userId;
        $calculatedToken = hash_hmac('sha256', $data, (string) config('app.key'));

        return hash_equals($storedToken, $calculatedToken);
    }

    public function registerDevice(
        User $user,
        Request $request,
        string $deviceId,
        ?array $deviceSignature = null,
        ?string $deviceName = null
    ): ?UserDevice {
        if (! $this->isValidUuid($deviceId)) {
            Log::warning('Invalid device_id format', [
                'user_id' => $user->id,
                'device_id' => $deviceId,
            ]);

            return null;
        }

        $deviceToken = $this->generateDeviceToken($deviceId, $user->id);
        $deviceInfo = $this->getDeviceInfo($request, $deviceSignature, $deviceName);

        $existingDevice = UserDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        if ($existingDevice) {
            $existingDevice->update([
                'device_token' => $deviceToken,
                'device_name' => $deviceInfo['device_name'],
                'device_type' => $deviceInfo['device_type'],
                'browser' => $deviceInfo['browser'],
                'platform' => $deviceInfo['platform'],
                'device_model' => $deviceInfo['device_model'],
                'device_manufacturer' => $deviceInfo['device_manufacturer'],
                'device_brand' => $deviceInfo['device_brand'],
                'os_version' => $deviceInfo['os_version'],
                'app_version' => $deviceInfo['app_version'],
                'build_version' => $deviceInfo['build_version'],
                'hardware_id' => $deviceInfo['hardware_id'],
                'mac_address' => $deviceInfo['mac_address'],
                'signature_hash' => $deviceInfo['signature_hash'],
                'signature_payload' => $deviceInfo['signature_payload'],
                'is_active' => true,
                'last_used_at' => Carbon::now(),
                'ip_address' => $deviceInfo['ip_address'],
                'user_agent' => $deviceInfo['user_agent'],
            ]);

            return $existingDevice->fresh();
        }

        return UserDevice::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'device_token' => $deviceToken,
            'device_name' => $deviceInfo['device_name'],
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'platform' => $deviceInfo['platform'],
            'device_model' => $deviceInfo['device_model'],
            'device_manufacturer' => $deviceInfo['device_manufacturer'],
            'device_brand' => $deviceInfo['device_brand'],
            'os_version' => $deviceInfo['os_version'],
            'app_version' => $deviceInfo['app_version'],
            'build_version' => $deviceInfo['build_version'],
            'hardware_id' => $deviceInfo['hardware_id'],
            'mac_address' => $deviceInfo['mac_address'],
            'signature_hash' => $deviceInfo['signature_hash'],
            'signature_payload' => $deviceInfo['signature_payload'],
            'ip_address' => $deviceInfo['ip_address'],
            'user_agent' => $deviceInfo['user_agent'],
            'is_active' => true,
            'last_used_at' => Carbon::now(),
        ]);
    }

    public function canLoginFromDevice(User $user, string $deviceId, ?array $deviceSignature = null): array
    {
        if (! $this->isValidUuid($deviceId)) {
            return [
                'allowed' => false,
                'message' => 'Invalid device identifier format.',
                'device' => null,
            ];
        }

        if (! $user->hasSingleDeviceLoginEnabled()) {
            return [
                'allowed' => true,
                'message' => 'Login allowed (single device login disabled).',
                'device' => null,
                'track_only' => true,
            ];
        }

        $registeredDevice = UserDevice::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('last_used_at')
            ->orderByDesc('id')
            ->first();

        if (! $registeredDevice) {
            return [
                'allowed' => true,
                'message' => 'First device registration.',
                'device' => null,
            ];
        }

        if ($registeredDevice->device_id === $deviceId) {
            return [
                'allowed' => true,
                'message' => 'Login from registered device.',
                'device' => $registeredDevice,
            ];
        }

        return [
            'allowed' => false,
            'message' => 'Device mismatch. Account is locked to another device.',
            'device' => $registeredDevice,
        ];
    }

    public function verifyDeviceOnRequest(User $user, Request $request): bool
    {
        return $this->verifyApiDeviceRequest($user, $request);
    }

    public function resetUserDevices(User $user, ?string $reason = null): int
    {
        Log::info('Devices reset for user', [
            'user_id' => $user->id,
            'reason' => $reason,
        ]);

        return UserDevice::where('user_id', $user->id)
            ->update(['is_active' => false]);
    }

    public function enforceSingleDeviceSession(
        User $user,
        UserDevice $allowedDevice,
        ?string $exceptSessionId = null,
        ?int $exceptTokenId = null
    ): void {
        UserDevice::where('user_id', $user->id)
            ->where('id', '!=', $allowedDevice->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
            ]);

        $allowedDevice->update([
            'is_active' => true,
            'last_used_at' => Carbon::now(),
        ]);

        $this->terminateUserAccess($user, $exceptSessionId, $exceptTokenId);
    }

    public function terminateUserAccess(User $user, ?string $exceptSessionId = null, ?int $exceptTokenId = null): void
    {
        $this->invalidateUserWebSessions($user, $exceptSessionId);
        $this->invalidateUserApiTokens($user, $exceptTokenId);
    }

    public function trackApiTokenSession(User $user, Request $request, int $tokenId): void
    {
        try {
            $deviceId = $this->resolveDeviceId($request);
            $signaturePayload = $this->normalizedSignaturePayload($this->extractSignaturePayload($request));
            $deviceFingerprint = $this->signatureHashFromPayload($signaturePayload);

            if ($deviceFingerprint === '') {
                $deviceFingerprint = hash('sha256', implode('|', array_filter([
                    (string) $request->userAgent(),
                    (string) $request->ip(),
                    (string) $deviceId,
                    (string) $tokenId,
                ])));
            }

            DB::table('user_sessions')->updateOrInsert(
                ['session_id' => $this->buildApiTokenSessionId($tokenId)],
                [
                    'user_id' => $user->id,
                    'ip_address' => (string) $request->ip(),
                    'user_agent' => (string) ($request->userAgent() ?? ''),
                    'device_fingerprint' => $deviceFingerprint,
                    'device_info' => json_encode([
                        'channel' => 'api',
                        'token_id' => $tokenId,
                        'device_id' => $deviceId,
                        'platform' => $signaturePayload['platform'] !== '' ? $signaturePayload['platform'] : 'api',
                        'model' => $signaturePayload['model'],
                        'manufacturer' => $signaturePayload['manufacturer'],
                        'brand' => $signaturePayload['brand'],
                        'os_version' => $signaturePayload['os_version'],
                        'app_version' => $signaturePayload['app_version'],
                        'build_version' => $signaturePayload['build_version'],
                        'hardware_id' => $signaturePayload['hardware_id'],
                        'mac_address' => $signaturePayload['mac_address'],
                    ]),
                    'location_info' => json_encode([
                        'country' => 'Unknown',
                        'city' => 'Unknown',
                        'timezone' => config('app.timezone'),
                    ]),
                    'is_current' => true,
                    'last_activity' => now(),
                    'expires_at' => null,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        } catch (\Throwable $exception) {
            Log::warning('Failed to track API token session', [
                'user_id' => $user->id,
                'token_id' => $tokenId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function markApiTokenSessionInactive(int $tokenId): void
    {
        $this->markTrackedSessionsInactive([
            $this->buildApiTokenSessionId($tokenId),
        ]);
    }

    protected function invalidateUserWebSessions(User $user, ?string $exceptSessionId = null): void
    {
        $sessionQuery = DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->id);

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $sessionQuery->where('id', '!=', $exceptSessionId);
        }

        $sessionQuery->delete();

        $trackedSessionQuery = DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->where('is_current', true)
            ->where('session_id', 'not like', 'api-token:%');

        if ($exceptSessionId !== null && $exceptSessionId !== '') {
            $trackedSessionQuery->where('session_id', '!=', $exceptSessionId);
        }

        $trackedSessionQuery->update([
            'is_current' => false,
            'expires_at' => now(),
            'updated_at' => now(),
        ]);
    }

    protected function invalidateUserApiTokens(User $user, ?int $exceptTokenId = null): void
    {
        $tokensToRevokeQuery = $user->tokens();

        if ($exceptTokenId !== null) {
            $tokensToRevokeQuery->where('id', '!=', $exceptTokenId);
        }

        $tokenIdsToRevoke = $tokensToRevokeQuery
            ->pluck('id')
            ->map(static fn (mixed $tokenId): int => (int) $tokenId)
            ->all();

        if ($tokenIdsToRevoke === []) {
            return;
        }

        $tokensDeleteQuery = $user->tokens()->whereIn('id', $tokenIdsToRevoke);
        $tokensDeleteQuery->delete();

        $this->markApiTokenSessionsInactive($tokenIdsToRevoke);
    }

    protected function markApiTokenSessionsInactive(array $tokenIds): void
    {
        $sessionIds = collect($tokenIds)
            ->map(static fn (mixed $tokenId): int => (int) $tokenId)
            ->filter(static fn (int $tokenId): bool => $tokenId > 0)
            ->map(fn (int $tokenId): string => $this->buildApiTokenSessionId($tokenId))
            ->values()
            ->all();

        if ($sessionIds === []) {
            return;
        }

        $this->markTrackedSessionsInactive($sessionIds);
    }

    protected function markTrackedSessionsInactive(array $sessionIds): void
    {
        if ($sessionIds === []) {
            return;
        }

        DB::table('user_sessions')
            ->whereIn('session_id', $sessionIds)
            ->where('is_current', true)
            ->update([
                'is_current' => false,
                'expires_at' => now(),
                'updated_at' => now(),
            ]);
    }

    protected function buildApiTokenSessionId(int $tokenId): string
    {
        return 'api-token:'.$tokenId;
    }

    public function verifyApiDeviceRequest(User $user, Request $request): bool
    {
        $deviceId = $this->resolveDeviceId($request);

        if (! $deviceId || ! $this->isValidUuid($deviceId)) {
            return false;
        }

        $device = UserDevice::where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->where('is_active', true)
            ->first();

        if (! $device) {
            return false;
        }

        if ($user->hasSingleDeviceLoginEnabled()) {
            $lockedDevice = UserDevice::where('user_id', $user->id)
                ->where('is_active', true)
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->first();

            if (! $lockedDevice || (int) $lockedDevice->id !== (int) $device->id) {
                return false;
            }
        }

        $incomingHeaderSignature = trim((string) $request->header('X-Device-Signature'));
        $incomingPayloadSignatureHash = $this->signatureHashFromPayload($this->extractSignaturePayload($request));
        $storedSignatureHash = trim((string) ($device->signature_hash ?? ''));
        $storedSignaturePayload = is_array($device->signature_payload) ? $device->signature_payload : [];
        $storedRawSignature = trim((string) ($storedSignaturePayload['signature'] ?? ''));

        if ($storedRawSignature !== '' && $incomingHeaderSignature !== '' && ! hash_equals($storedRawSignature, $incomingHeaderSignature)) {
            return false;
        }

        if ($incomingHeaderSignature === '' && $incomingPayloadSignatureHash !== '' && $storedSignatureHash !== '' && ! hash_equals($storedSignatureHash, $incomingPayloadSignatureHash)) {
            return false;
        }

        $updatePayload = [
            'last_used_at' => Carbon::now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'is_active' => true,
        ];

        if ($storedRawSignature === '' && $incomingHeaderSignature !== '') {
            $updatedSignaturePayload = $this->normalizedSignaturePayload($storedSignaturePayload);
            $updatedSignaturePayload['signature'] = $incomingHeaderSignature;
            $updatePayload['signature_payload'] = $updatedSignaturePayload;
            $updatePayload['signature_hash'] = $this->signatureHashFromPayload($updatedSignaturePayload);
        } elseif ($storedSignatureHash === '' && $incomingPayloadSignatureHash !== '') {
            $updatePayload['signature_hash'] = $incomingPayloadSignatureHash;
        }

        $device->update($updatePayload);

        return true;
    }

    public function signatureHashFromPayload(?array $signature): string
    {
        $normalized = $this->normalizedSignaturePayload($signature);
        $hasSignal = collect($normalized)
            ->filter(fn (string $value): bool => $value !== '')
            ->isNotEmpty();

        if (! $hasSignal) {
            return '';
        }

        $fingerprint = implode('|', [
            $normalized['signature'],
            $normalized['platform'],
            $normalized['model'],
            $normalized['manufacturer'],
            $normalized['brand'],
            $normalized['hardware_id'],
        ]);

        return hash_hmac('sha256', $fingerprint, (string) config('app.key'));
    }

    protected function getDeviceInfo(Request $request, ?array $deviceSignature = null, ?string $deviceName = null): array
    {
        $this->agent->setUserAgent($request->userAgent());

        $incomingSignaturePayload = $this->normalizedSignaturePayload($deviceSignature);
        $webSignaturePayload = $this->buildWebSignaturePayload($request, (string) ($this->resolveDeviceId($request) ?? ''));
        $signaturePayload = $this->mergeSignaturePayload($webSignaturePayload, $incomingSignaturePayload);
        $isWebClient = Str::lower($signaturePayload['platform']) === 'web';

        $browser = $isWebClient
            ? (string) ($this->agent->browser() ?: 'Web Browser')
            : (string) ($this->agent->browser() ?: 'Mobile API');

        $platform = $isWebClient
            ? (string) ($this->agent->platform() ?: 'Web')
            : ($signaturePayload['platform'] !== '' ? $signaturePayload['platform'] : (string) ($this->agent->platform() ?: 'Unknown'));

        $deviceType = $this->resolveDeviceType($platform);
        $resolvedDeviceName = trim((string) $deviceName);

        if ($resolvedDeviceName === '') {
            if ($isWebClient) {
                $resolvedDeviceName = $this->generateDeviceName($browser, $platform, $deviceType);
            } else {
                $nameParts = array_filter([
                    $signaturePayload['brand'],
                    $signaturePayload['model'],
                ]);

                if ($nameParts !== []) {
                    $resolvedDeviceName = implode(' ', array_unique($nameParts));
                }
            }
        }

        if ($resolvedDeviceName === '') {
            $resolvedDeviceName = $this->generateDeviceName($browser, $platform, $deviceType);
        }

        return [
            'device_name' => $resolvedDeviceName,
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
            'device_model' => $signaturePayload['model'],
            'device_manufacturer' => $signaturePayload['manufacturer'],
            'device_brand' => $signaturePayload['brand'],
            'os_version' => $signaturePayload['os_version'],
            'app_version' => $signaturePayload['app_version'],
            'build_version' => $signaturePayload['build_version'],
            'hardware_id' => $signaturePayload['hardware_id'],
            'mac_address' => $signaturePayload['mac_address'],
            'signature_hash' => $this->signatureHashFromPayload($signaturePayload),
            'signature_payload' => $signaturePayload,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];
    }

    protected function buildWebSignaturePayload(Request $request, string $deviceId = ''): array
    {
        $browser = (string) ($this->agent->browser() ?: 'Web Browser');
        $platform = 'web';
        $osVersion = (string) ($this->agent->platform() ?: 'Web');

        $hardwareSeed = implode('|', [
            $deviceId,
            (string) $request->header('Sec-CH-UA-Platform'),
            (string) $request->header('Sec-CH-UA'),
            (string) $request->header('Sec-CH-UA-Mobile'),
        ]);

        $signatureSeed = implode('|', [
            $browser,
            $osVersion,
            (string) $request->header('Sec-CH-UA-Platform'),
            (string) $request->header('Sec-CH-UA'),
        ]);

        $headerSignature = trim((string) $request->header('X-Device-Signature'));

        if ($headerSignature === '') {
            $headerSignature = hash('sha256', $signatureSeed);
        }

        return [
            'signature' => $headerSignature,
            'platform' => $platform,
            'os_version' => $osVersion,
            'model' => $browser,
            'manufacturer' => 'Web Browser',
            'brand' => $browser,
            'hardware_id' => hash('sha256', $hardwareSeed),
            'app_version' => trim((string) $request->header('X-App-Version')),
            'build_version' => trim((string) $request->header('X-App-Build')),
            'mac_address' => '',
        ];
    }

    protected function mergeSignaturePayload(array $basePayload, array $incomingPayload): array
    {
        $merged = $basePayload;

        foreach ($incomingPayload as $key => $value) {
            $normalized = trim((string) $value);

            if ($normalized !== '') {
                $merged[$key] = $normalized;
            }
        }

        return $merged;
    }

    protected function generateDeviceName(string $browser, string $platform, string $deviceType): string
    {
        return "{$browser} on {$platform} ".ucfirst($deviceType);
    }

    protected function isValidUuid(string $uuid): bool
    {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid);
    }

    public function getUserDevices(User $user)
    {
        return UserDevice::where('user_id', $user->id)
            ->orderBy('last_used_at', 'desc')
            ->get();
    }

    public function deactivateDevice(User $user, int $deviceId): bool
    {
        $device = UserDevice::where('user_id', $user->id)
            ->where('id', $deviceId)
            ->first();

        if (! $device) {
            return false;
        }

        return $device->deactivate();
    }

    protected function resolveDeviceType(string $platform): string
    {
        $platformLower = Str::lower($platform);

        if (Str::contains($platformLower, ['android', 'ios', 'iphone'])) {
            return 'mobile';
        }

        if (Str::contains($platformLower, ['ipad', 'tablet'])) {
            return 'tablet';
        }

        if ($this->agent->isMobile()) {
            return 'mobile';
        }

        if ($this->agent->isTablet()) {
            return 'tablet';
        }

        return 'desktop';
    }

    protected function resolveDeviceId(Request $request): ?string
    {
        $deviceId = trim((string) ($request->header('X-Device-ID') ?: $request->input('device_id')));

        return $deviceId !== '' ? $deviceId : null;
    }

    protected function extractSignaturePayload(Request $request): array
    {
        $payload = $request->input('device_signature');

        return is_array($payload) ? $payload : [];
    }

    protected function normalizedSignaturePayload(?array $signature): array
    {
        $signature = is_array($signature) ? $signature : [];
        $normalize = static fn (mixed $value): string => trim((string) ($value ?? ''));

        return [
            'signature' => $normalize($signature['signature'] ?? ''),
            'platform' => $normalize($signature['platform'] ?? ''),
            'os_version' => $normalize($signature['os_version'] ?? ''),
            'model' => $normalize($signature['model'] ?? ''),
            'manufacturer' => $normalize($signature['manufacturer'] ?? ''),
            'brand' => $normalize($signature['brand'] ?? ''),
            'hardware_id' => $normalize($signature['hardware_id'] ?? ''),
            'app_version' => $normalize($signature['app_version'] ?? ''),
            'build_version' => $normalize($signature['build_version'] ?? ''),
            'mac_address' => $normalize($signature['mac_address'] ?? ''),
        ];
    }
}
