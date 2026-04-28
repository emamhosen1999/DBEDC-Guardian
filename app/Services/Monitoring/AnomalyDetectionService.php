<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Log;

class AnomalyDetectionService
{
    /**
     * Check for suspicious login patterns
     */
    public function detectSuspiciousLogin(string $email, string $ip, string $userAgent): array
    {
        $anomalies = [];

        // Check for multiple failed login attempts
        $failedAttempts = Cache::get("failed_login_{$ip}", 0);
        if ($failedAttempts >= 5) {
            $anomalies[] = [
                'type' => 'brute_force',
                'severity' => 'high',
                'message' => "Multiple failed login attempts from IP: {$ip}",
                'ip' => $ip,
            ];
        }

        // Check for login from unusual location (simplified check)
        $previousIps = Cache::get("user_ips_{$email}", []);
        if (! empty($previousIps) && ! in_array($ip, $previousIps) && count($previousIps) > 0) {
            $anomalies[] = [
                'type' => 'unusual_location',
                'severity' => 'medium',
                'message' => "Login from new IP address for user: {$email}",
                'ip' => $ip,
            ];
        }

        // Check for rapid successive logins
        $recentLogins = Cache::get("recent_logins_{$email}", []);
        $now = now();
        $recentCount = collect($recentLogins)->filter(function ($time) use ($now) {
            return $now->diffInMinutes($time) < 5;
        })->count();

        if ($recentCount >= 3) {
            $anomalies[] = [
                'type' => 'rapid_login',
                'severity' => 'medium',
                'message' => "Rapid successive logins for user: {$email}",
                'ip' => $ip,
            ];
        }

        return $anomalies;
    }

    /**
     * Check for unusual API activity
     */
    public function detectUnusualApiActivity(string $endpoint, string $method, int $userId): array
    {
        $anomalies = [];

        // Check for unusually high request rate
        $key = "api_rate_{$userId}_{$endpoint}";
        $requestCount = Cache::get($key, 0);

        if ($requestCount > 100) {
            $anomalies[] = [
                'type' => 'high_request_rate',
                'severity' => 'high',
                'message' => "Unusually high request rate for endpoint: {$endpoint}",
                'user_id' => $userId,
                'endpoint' => $endpoint,
                'count' => $requestCount,
            ];
        }

        // Check for access to unusual endpoints
        $userEndpoints = Cache::get("user_endpoints_{$userId}", []);
        $suspiciousKeywords = ['admin', 'delete', 'export'];
        $isUnusual = false;
        foreach ($suspiciousKeywords as $keyword) {
            if (str_contains($endpoint, $keyword)) {
                $isUnusual = true;
                break;
            }
        }
        if (! in_array($endpoint, $userEndpoints) && $isUnusual) {
            $anomalies[] = [
                'type' => 'unusual_endpoint_access',
                'severity' => 'medium',
                'message' => "Access to unusual endpoint: {$endpoint}",
                'user_id' => $userId,
                'endpoint' => $endpoint,
            ];
        }

        return $anomalies;
    }

    /**
     * Check for data exfiltration patterns
     */
    public function detectDataExfiltration(int $userId, string $action, int $recordCount): array
    {
        $anomalies = [];

        // Check for large data exports
        if ($action === 'export' && $recordCount > 1000) {
            $anomalies[] = [
                'type' => 'large_export',
                'severity' => 'medium',
                'message' => "Large data export detected: {$recordCount} records",
                'user_id' => $userId,
                'record_count' => $recordCount,
            ];
        }

        // Check for rapid data access
        $key = "data_access_{$userId}";
        $accessCount = Cache::get($key, 0);

        if ($accessCount > 50) {
            $anomalies[] = [
                'type' => 'rapid_data_access',
                'severity' => 'medium',
                'message' => "Rapid data access detected",
                'user_id' => $userId,
                'access_count' => $accessCount,
            ];
        }

        return $anomalies;
    }

    /**
     * Log detected anomaly
     */
    public function logAnomaly(array $anomaly): void
    {
        Log::warning('Security Anomaly Detected', [
            'type' => $anomaly['type'],
            'severity' => $anomaly['severity'],
            'message' => $anomaly['message'],
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'user_id' => Auth::id(),
            'timestamp' => now(),
            ...$anomaly,
        ]);
    }

    /**
     * Increment request rate counter
     */
    public function incrementRequestRate(string $key, int $ttl = 60): void
    {
        Cache::increment($key);
        Cache::put($key, Cache::get($key, 0), now()->addSeconds($ttl));
    }

    /**
     * Reset request rate counter
     */
    public function resetRequestRate(string $key): void
    {
        Cache::forget($key);
    }
}
