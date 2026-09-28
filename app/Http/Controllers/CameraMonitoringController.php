<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Camera Monitoring Controller
 *
 * Provides a secure, permission-gated camera viewer with:
 * - WebRTC WHEP proxy (sub-second latency live video via MediaMTX)
 * - HLS fallback proxy (broader browser compatibility)
 * - ONVIF snapshot proxy (single-frame fallback)
 * - Camera health monitoring
 *
 * Camera: HOLOWITS P4-4R(2.8)A, Firmware SDC 11.1.1
 * Streams: H.264 2560x1440 (main) + 720x576 (sub)
 * Gateway: MediaMTX RTSP→WebRTC/HLS
 *
 * Security:
 * - Requires 'monitoring.camera.view' permission
 * - Never exposes internal IP (11.151.14.67) to frontend
 * - ONVIF/RTSP credentials stay server-side only
 * - WebRTC/HLS served through reverse proxy
 */
class CameraMonitoringController extends Controller
{
    private const CAMERA_PUBLIC_URL = 'https://camera.dhakabypass.com';

    /**
     * MediaMTX local endpoints (server-side only).
     * These are proxied to the frontend — the browser never sees them directly.
     */
    private const MEDIAMTX_API = 'http://127.0.0.1:9997';
    private const MEDIAMTX_WHEP = 'http://127.0.0.1:8889';
    private const MEDIAMTX_HLS = 'http://127.0.0.1:8888';

    /**
     * ONVIF snapshot endpoints via Cloudflare Tunnel.
     */
    private const SNAPSHOT_URLS = [
        'main' => 'https://camera.dhakabypass.com/onvif/Snapshot?ch=101&Media=1',
        'sub'  => 'https://camera.dhakabypass.com/onvif/Snapshot?ch=101&Media=2',
    ];

    /**
     * Display the camera monitoring viewer page.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('Operations/CameraMonitoring', [
            'cameraUrl'    => self::CAMERA_PUBLIC_URL,
            'cameraStatus' => $this->probeCameraStatus(),
            'deviceInfo'   => $this->getCachedDeviceInfo(),
            'streamConfig' => [
                // Frontend uses these relative proxy paths (no internal IPs)
                'whepUrl'     => '/om/camera/webrtc/whep',
                'hlsUrl'      => '/om/camera/hls/index.m3u8',
                'snapshotUrl' => '/om/camera/snapshot/main',
                'streams'     => [
                    'main' => ['label' => 'HD 4MP (2560×1440)', 'path' => 'cam-main'],
                    'sub'  => ['label' => 'SD (720×576)', 'path' => 'cam-sub'],
                ],
                'defaultStream' => 'main',
            ],
        ]);
    }

    /**
     * Health status endpoint.
     */
    public function status(Request $request)
    {
        $cameraStatus = $this->probeCameraStatus();
        $gatewayStatus = $this->probeMediaMTXStatus();

        return response()->json([
            'camera'  => $cameraStatus,
            'gateway' => $gatewayStatus,
        ]);
    }

    // ─── WebRTC WHEP Proxy ──────────────────────────────────────────────

    /**
     * Proxy WebRTC WHEP POST (SDP offer → answer).
     * Browser sends SDP offer, MediaMTX returns SDP answer.
     *
     * @param string $stream 'cam-main' or 'cam-sub'
     */
    public function whepPost(Request $request, string $stream = 'cam-main')
    {
        $stream = $this->validateStreamPath($stream);

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => $request->header('Content-Type', 'application/sdp'),
                ])
                ->withBody($request->getContent(), $request->header('Content-Type', 'application/sdp'))
                ->post(self::MEDIAMTX_WHEP . "/{$stream}/whep");

            return response($response->body(), $response->status())
                ->withHeaders($this->filterProxyHeaders($response->headers()));

        } catch (\Throwable $e) {
            Log::error('[CameraMonitoring] WHEP proxy failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'WebRTC negotiation failed'], 502);
        }
    }

    /**
     * Proxy WebRTC WHEP PATCH (ICE candidates).
     */
    public function whepPatch(Request $request, string $stream = 'cam-main')
    {
        $stream = $this->validateStreamPath($stream);

        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => $request->header('Content-Type', 'application/trickle-ice-sdpfrag'),
                ])
                ->withBody($request->getContent(), $request->header('Content-Type'))
                ->patch(self::MEDIAMTX_WHEP . "/{$stream}/whep");

            return response($response->body(), $response->status())
                ->withHeaders($this->filterProxyHeaders($response->headers()));

        } catch (\Throwable $e) {
            return response()->json(['error' => 'ICE candidate relay failed'], 502);
        }
    }

    /**
     * Proxy WebRTC WHEP DELETE (teardown session).
     */
    public function whepDelete(Request $request, string $stream = 'cam-main')
    {
        $stream = $this->validateStreamPath($stream);

        try {
            $response = Http::timeout(5)
                ->delete(self::MEDIAMTX_WHEP . "/{$stream}/whep");

            return response($response->body(), $response->status());
        } catch (\Throwable $e) {
            return response('', 200); // Graceful teardown
        }
    }

    // ─── HLS Proxy ──────────────────────────────────────────────────────

    /**
     * Proxy HLS playlist and segments from MediaMTX.
     */
    public function hlsProxy(Request $request, string $stream = 'cam-main', string $file = 'index.m3u8')
    {
        $stream = $this->validateStreamPath($stream);

        try {
            $url = self::MEDIAMTX_HLS . "/{$stream}/{$file}";
            $response = Http::timeout(10)->get($url);

            if (!$response->successful()) {
                return response()->json(['error' => 'HLS segment unavailable'], $response->status());
            }

            $contentType = $response->header('Content-Type', 'application/vnd.apple.mpegurl');

            return response($response->body(), 200)
                ->header('Content-Type', $contentType)
                ->header('Cache-Control', 'no-store, no-cache')
                ->header('Access-Control-Allow-Origin', '*');

        } catch (\Throwable $e) {
            Log::error('[CameraMonitoring] HLS proxy failed', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'HLS stream unavailable'], 502);
        }
    }

    // ─── ONVIF Snapshot Proxy ───────────────────────────────────────────

    /**
     * Server-side ONVIF snapshot proxy (fallback when MediaMTX is down).
     */
    public function snapshot(Request $request, string $profile = 'main')
    {
        $profile = in_array($profile, ['main', 'sub']) ? $profile : 'main';
        $snapshotUrl = self::SNAPSHOT_URLS[$profile];

        $username = config('services.camera.onvif_username', 'admin');
        $password = config('services.camera.onvif_password', 'admin123456');

        try {
            $ch = curl_init($snapshotUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPAUTH => CURLAUTH_DIGEST | CURLAUTH_BASIC,
                CURLOPT_USERPWD => "{$username}:{$password}",
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 2,
            ]);

            $imageData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);

            if ($httpCode !== 200 || empty($imageData)) {
                return response()->json(['error' => 'Snapshot unavailable'], 502);
            }

            $mimeType = str_starts_with($imageData, "\xFF\xD8\xFF") ? 'image/jpeg' : 'image/jpeg';

            return response($imageData, 200)
                ->header('Content-Type', $mimeType)
                ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
                ->header('X-Frame-Options', 'DENY')
                ->header('X-Content-Type-Options', 'nosniff');

        } catch (\Throwable $e) {
            return response()->json(['error' => 'Snapshot proxy failed'], 500);
        }
    }

    // ─── Private Helpers ────────────────────────────────────────────────

    private function validateStreamPath(string $stream): string
    {
        return in_array($stream, ['cam-main', 'cam-sub']) ? $stream : 'cam-main';
    }

    private function filterProxyHeaders(array $headers): array
    {
        $allowed = ['Content-Type', 'Location', 'ETag', 'Accept-Patch'];
        $filtered = [];
        foreach ($headers as $key => $values) {
            if (in_array($key, $allowed, true)) {
                $filtered[$key] = is_array($values) ? $values[0] : $values;
            }
        }
        return $filtered;
    }

    private function probeMediaMTXStatus(): array
    {
        try {
            $response = Http::timeout(3)->get(self::MEDIAMTX_API . '/v3/paths/list');

            if ($response->successful()) {
                $data = $response->json();
                $paths = $data['items'] ?? [];
                $activePaths = [];

                foreach ($paths as $path) {
                    $activePaths[] = [
                        'name'    => $path['name'] ?? 'unknown',
                        'ready'   => $path['ready'] ?? false,
                        'readers' => $path['readers'] ?? 0,
                    ];
                }

                return [
                    'online' => true,
                    'paths'  => $activePaths,
                    'checked_at' => now()->toISOString(),
                ];
            }

            return ['online' => false, 'error' => "HTTP {$response->status()}", 'checked_at' => now()->toISOString()];

        } catch (\Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage(), 'checked_at' => now()->toISOString()];
        }
    }

    private function probeCameraStatus(): array
    {
        try {
            $ch = curl_init(self::CAMERA_PUBLIC_URL);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $totalTime = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000);
            $error = curl_error($ch);
            curl_close($ch);

            return [
                'online' => $httpCode >= 200 && $httpCode < 500,
                'http_code' => $httpCode,
                'latency_ms' => $totalTime,
                'error' => $error ?: null,
                'checked_at' => now()->toISOString(),
            ];
        } catch (\Throwable $e) {
            return ['online' => false, 'http_code' => 0, 'latency_ms' => null,
                    'error' => $e->getMessage(), 'checked_at' => now()->toISOString()];
        }
    }

    private function getCachedDeviceInfo(): array
    {
        return Cache::remember('camera_device_info', 3600, function () {
            return $this->fetchDeviceInfoViaOnvif();
        });
    }

    private function fetchDeviceInfoViaOnvif(): array
    {
        try {
            $username = config('services.camera.onvif_username', 'admin');
            $password = config('services.camera.onvif_password', 'admin123456');
            $envelope = $this->buildWsseEnvelope('<tds:GetDeviceInformation/>', $username, $password);

            $ch = curl_init('https://camera.dhakabypass.com/onvif/device_service');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $envelope,
                CURLOPT_HTTPHEADER => ['Content-Type: application/soap+xml; charset=utf-8'],
                CURLOPT_TIMEOUT => 8, CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $response = curl_exec($ch);
            curl_close($ch);

            $info = [];
            foreach (['Manufacturer', 'Model', 'FirmwareVersion', 'SerialNumber', 'HardwareId'] as $field) {
                if (preg_match("/<tds:{$field}>([^<]+)<\/tds:{$field}>/", $response, $m)) {
                    $info[$field] = $m[1];
                }
            }
            return $info ?: ['error' => 'Parse failed'];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function buildWsseEnvelope(string $body, string $username, string $password): string
    {
        $nonceRaw = random_bytes(16);
        $nonceB64 = base64_encode($nonceRaw);
        $created = gmdate('Y-m-d\TH:i:s.000\Z');
        $digest = base64_encode(sha1($nonceRaw . $created . $password, true));

        return '<?xml version="1.0" encoding="utf-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope"
            xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
  <s:Header>
    <Security xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-secext-1.0.xsd" s:mustUnderstand="true">
      <UsernameToken>
        <Username>' . $username . '</Username>
        <Password Type="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-username-token-profile-1.0#PasswordDigest">' . $digest . '</Password>
        <Nonce EncodingType="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-soap-message-security-1.0#Base64Binary">' . $nonceB64 . '</Nonce>
        <Created xmlns="http://docs.oasis-open.org/wss/2004/01/oasis-200401-wss-wssecurity-utility-1.0.xsd">' . $created . '</Created>
      </UsernameToken>
    </Security>
  </s:Header>
  <s:Body>' . $body . '</s:Body>
</s:Envelope>';
    }
}
