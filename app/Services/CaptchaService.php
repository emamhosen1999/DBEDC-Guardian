<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class CaptchaService
{
    protected GeoLocationService $geoLocationService;

    public function __construct(GeoLocationService $geoLocationService)
    {
        $this->geoLocationService = $geoLocationService;
    }
    /**
     * Verify CAPTCHA response.
     */
    public function verify(string $token, string $action = 'login'): array
    {
        if (! config('captcha.enabled')) {
            return ['success' => true, 'score' => 1.0];
        }

        $provider = config('captcha.provider');

        return match ($provider) {
            'recaptcha_v3' => $this->verifyRecaptchaV3($token, $action),
            'hcaptcha' => $this->verifyHcaptcha($token),
            'turnstile' => $this->verifyTurnstile($token),
            default => ['success' => true, 'score' => 1.0],
        };
    }

    /**
     * Verify reCAPTCHA v3.
     */
    protected function verifyRecaptchaV3(string $token, string $action): array
    {
        $secret = config('captcha.recaptcha_v3.secret_key');
        $threshold = config('captcha.recaptcha_v3.threshold', 0.5);

        if (! $secret) {
            Log::warning('reCAPTCHA secret key not configured');
            return ['success' => true, 'score' => 1.0];
        }

        try {
            $response = Http::asForm()->post('https://www.google.com/recaptcha/api/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

            $result = $response->json();

            if ($result['success'] && $result['score'] >= $threshold) {
                return ['success' => true, 'score' => $result['score']];
            }

            Log::warning('reCAPTCHA verification failed', [
                'success' => $result['success'] ?? false,
                'score' => $result['score'] ?? 0,
                'error_codes' => $result['error-codes'] ?? [],
            ]);

            return ['success' => false, 'score' => $result['score'] ?? 0];
        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification error', ['error' => $e->getMessage()]);
            return ['success' => true, 'score' => 1.0]; // Allow on error
        }
    }

    /**
     * Verify hCaptcha.
     */
    protected function verifyHcaptcha(string $token): array
    {
        $secret = config('captcha.hcaptcha.secret_key');

        if (! $secret) {
            Log::warning('hCaptcha secret key not configured');
            return ['success' => true, 'score' => 1.0];
        }

        try {
            $response = Http::asForm()->post('https://hcaptcha.com/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

            $result = $response->json();

            return ['success' => $result['success'] ?? false, 'score' => 1.0];
        } catch (\Exception $e) {
            Log::error('hCaptcha verification error', ['error' => $e->getMessage()]);
            return ['success' => true, 'score' => 1.0];
        }
    }

    /**
     * Verify Turnstile.
     */
    protected function verifyTurnstile(string $token): array
    {
        $secret = config('captcha.turnstile.secret_key');

        if (! $secret) {
            Log::warning('Turnstile secret key not configured');
            return ['success' => true, 'score' => 1.0];
        }

        try {
            $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
                'secret' => $secret,
                'response' => $token,
            ]);

            $result = $response->json();

            return ['success' => $result['success'] ?? false, 'score' => 1.0];
        } catch (\Exception $e) {
            Log::error('Turnstile verification error', ['error' => $e->getMessage()]);
            return ['success' => true, 'score' => 1.0];
        }
    }

    /**
     * Check if CAPTCHA should be triggered for this request.
     */
    public function shouldTrigger(Request $request): bool
    {
        if (! config('captcha.enabled')) {
            return false;
        }

        $ip = $request->ip();
        $cacheKey = "captcha_needed:{$ip}";

        // Check if already determined for this IP
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        $shouldTrigger = $this->evaluateRiskFactors($request);
        
        // Cache for 5 minutes
        Cache::put($cacheKey, $shouldTrigger, 300);

        return $shouldTrigger;
    }

    /**
     * Evaluate risk factors to determine if CAPTCHA is needed.
     */
    protected function evaluateRiskFactors(Request $request): bool
    {
        $factors = 0;
        $triggers = config('captcha.triggers');

        // Check failed attempts
        if ($this->hasExceededFailedAttempts($request, $triggers['failed_attempts_threshold'])) {
            $factors++;
        }

        // Check for new device
        if ($triggers['new_device'] && $this->isNewDevice($request)) {
            $factors++;
        }

        // Check for unusual location
        if ($triggers['unusual_location'] && $this->isUnusualLocation($request)) {
            $factors++;
        }

        // Check if outside business hours
        if ($triggers['outside_business_hours'] && $this->isOutsideBusinessHours($triggers)) {
            $factors++;
        }

        // Trigger if 2+ risk factors
        return $factors >= 2;
    }

    /**
     * Check if IP has exceeded failed attempts threshold.
     */
    protected function hasExceededFailedAttempts(Request $request, int $threshold): bool
    {
        $ip = $request->ip();
        $cacheKey = "failed_attempts:{$ip}";
        $attempts = Cache::get($cacheKey, 0);

        return $attempts >= $threshold;
    }

    /**
     * Check if this is a new device.
     */
    protected function isNewDevice(Request $request): bool
    {
        $userAgent = $request->userAgent();
        $ip = $request->ip();
        
        if (! $userAgent) {
            return true;
        }

        $cacheKey = "known_devices:{$ip}";
        $knownDevices = Cache::get($cacheKey, []);

        return ! in_array(md5($userAgent), $knownDevices);
    }

    /**
     * Check if login is from unusual location.
     */
    protected function isUnusualLocation(Request $request): bool
    {
        $location = $this->geoLocationService->getLocation($request->ip());
        
        if (! $location || $location['is_private']) {
            return false;
        }

        // Check if country is suspicious
        $suspiciousCountries = config('captcha.location.suspicious_countries', []);
        if (in_array($location['country_code'] ?? '', $suspiciousCountries)) {
            return true;
        }

        // For anonymous requests (not logged in yet), we can't check user history
        // This will be checked in LoginController after authentication
        return false;
    }

    /**
     * Check if current time is outside business hours.
     */
    protected function isOutsideBusinessHours(array $triggers): bool
    {
        $start = $triggers['business_hours_start'] ?? '06:00';
        $end = $triggers['business_hours_end'] ?? '18:00';
        
        $now = now();
        $startTime = now()->setTimeFromTimeString($start);
        $endTime = now()->setTimeFromTimeString($end);

        return $now->lt($startTime) || $now->gt($endTime);
    }

    /**
     * Increment failed attempt counter.
     */
    public function incrementFailedAttempts(Request $request): void
    {
        $ip = $request->ip();
        $cacheKey = "failed_attempts:{$ip}";
        
        Cache::increment($cacheKey);
        Cache::put($cacheKey, Cache::get($cacheKey, 0), 3600); // 1 hour expiry
    }

    /**
     * Reset failed attempt counter.
     */
    public function resetFailedAttempts(Request $request): void
    {
        $ip = $request->ip();
        Cache::forget("failed_attempts:{$ip}");
        Cache::forget("captcha_needed:{$ip}");
    }

    /**
     * Register device as known.
     */
    public function registerDevice(Request $request): void
    {
        $userAgent = $request->userAgent();
        $ip = $request->ip();

        if (! $userAgent) {
            return;
        }

        $cacheKey = "known_devices:{$ip}";
        $knownDevices = Cache::get($cacheKey, []);
        $deviceHash = md5($userAgent);

        if (! in_array($deviceHash, $knownDevices)) {
            $knownDevices[] = $deviceHash;
            Cache::put($cacheKey, $knownDevices, 86400); // 24 hours
        }
    }
}
