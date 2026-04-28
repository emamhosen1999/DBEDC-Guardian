<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;

class AnomalyDetectionService
{
    /**
     * Analyze authentication event for anomalies.
     */
    public function analyzeAuthenticationEvent(array $eventData): array
    {
        $riskScore = 0;
        $riskFactors = [];

        // Check for rapid multiple failed attempts
        if ($this->hasRapidFailedAttempts($eventData)) {
            $riskScore += 30;
            $riskFactors[] = 'rapid_failed_attempts';
        }

        // Check for unusual access time
        if ($this->isUnusualAccessTime($eventData)) {
            $riskScore += 15;
            $riskFactors[] = 'unusual_access_time';
        }

        // Check for multiple IP addresses in short time
        if ($this->hasMultipleIps($eventData)) {
            $riskScore += 25;
            $riskFactors[] = 'multiple_ips';
        }

        // Check for multiple devices
        if ($this->hasMultipleDevices($eventData)) {
            $riskScore += 20;
            $riskFactors[] = 'multiple_devices';
        }

        // Check for velocity anomaly (impossible travel)
        if ($this->hasVelocityAnomaly($eventData)) {
            $riskScore += 40;
            $riskFactors[] = 'velocity_anomaly';
        }

        return [
            'risk_score' => min($riskScore, 100),
            'risk_level' => $this->getRiskLevel($riskScore),
            'risk_factors' => $riskFactors,
            'action_required' => $riskScore >= 70,
        ];
    }

    /**
     * Check for rapid failed attempts.
     */
    protected function hasRapidFailedAttempts(array $eventData): bool
    {
        $ip = $eventData['ip'] ?? null;
        $email = $eventData['email'] ?? null;

        if (! $ip || ! $email) {
            return false;
        }

        $cacheKey = "failed_attempts:{$email}:{$ip}";
        $attempts = Cache::get($cacheKey, 0);

        return $attempts >= 5;
    }

    /**
     * Check for unusual access time.
     */
    protected function isUnusualAccessTime(array $eventData): bool
    {
        $eventTime = $eventData['timestamp'] ?? now();
        
        if (! $eventTime instanceof \Carbon\Carbon) {
            $eventTime = now()->parse($eventTime);
        }

        $hour = $eventTime->hour;

        // Unusual: before 6 AM or after 10 PM
        return $hour < 6 || $hour > 22;
    }

    /**
     * Check for multiple IP addresses in short time.
     */
    protected function hasMultipleIps(array $eventData): bool
    {
        $email = $eventData['email'] ?? null;
        $currentIp = $eventData['ip'] ?? null;

        if (! $email || ! $currentIp) {
            return false;
        }

        $cacheKey = "user_ips:{$email}";
        $recentIps = Cache::get($cacheKey, []);

        // Add current IP
        $recentIps[] = $currentIp;

        // Keep only last 10 IPs from last hour
        $recentIps = array_slice(array_unique($recentIps), -10);
        Cache::put($cacheKey, $recentIps, 3600);

        // If more than 3 unique IPs in last hour, it's suspicious
        return count(array_unique($recentIps)) > 3;
    }

    /**
     * Check for multiple devices.
     */
    protected function hasMultipleDevices(array $eventData): bool
    {
        $email = $eventData['email'] ?? null;
        $deviceId = $eventData['device_id'] ?? null;

        if (! $email || ! $deviceId) {
            return false;
        }

        $cacheKey = "user_devices:{$email}";
        $recentDevices = Cache::get($cacheKey, []);

        // Add current device
        $recentDevices[] = $deviceId;

        // Keep only last 5 devices from last hour
        $recentDevices = array_slice(array_unique($recentDevices), -5);
        Cache::put($cacheKey, $recentDevices, 3600);

        // If more than 2 unique devices in last hour, it's suspicious
        return count(array_unique($recentDevices)) > 2;
    }

    /**
     * Check for velocity anomaly (impossible travel).
     */
    protected function hasVelocityAnomaly(array $eventData): bool
    {
        $email = $eventData['email'] ?? null;
        $currentLocation = $eventData['location'] ?? null;

        if (! $email || ! $currentLocation) {
            return false;
        }

        $cacheKey = "user_last_location:{$email}";
        $lastLocation = Cache::get($cacheKey);

        if (! $lastLocation) {
            Cache::put($cacheKey, $currentLocation, 3600);
            return false;
        }

        $cacheTimeKey = "user_last_location_time:{$email}";
        $lastTime = Cache::get($cacheTimeKey);

        if (! $lastTime) {
            Cache::put($cacheTimeKey, now(), 3600);
            return false;
        }

        $timeDiff = now()->diffInMinutes($lastTime);

        // Calculate distance
        $distance = $this->calculateDistance(
            $lastLocation['latitude'] ?? 0,
            $lastLocation['longitude'] ?? 0,
            $currentLocation['latitude'] ?? 0,
            $currentLocation['longitude'] ?? 0
        );

        // Update cache
        Cache::put($cacheKey, $currentLocation, 3600);
        Cache::put($cacheTimeKey, now(), 3600);

        // If distance > 500km in less than 30 minutes, it's impossible
        return $distance > 500 && $timeDiff < 30;
    }

    /**
     * Calculate distance between two coordinates in kilometers.
     */
    protected function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get risk level based on score.
     */
    protected function getRiskLevel(int $score): string
    {
        if ($score >= 70) {
            return 'critical';
        } elseif ($score >= 50) {
            return 'high';
        } elseif ($score >= 30) {
            return 'medium';
        } elseif ($score >= 10) {
            return 'low';
        }
        return 'none';
    }

    /**
     * Handle high-risk event.
     */
    public function handleHighRiskEvent(array $eventData, array $analysis): void
    {
        $email = $eventData['email'] ?? null;

        if (! $email) {
            return;
        }

        // Log the anomaly
        Log::warning('High-risk authentication anomaly detected', [
            'email' => $email,
            'risk_score' => $analysis['risk_score'],
            'risk_factors' => $analysis['risk_factors'],
            'event_data' => $eventData,
        ]);

        // Auto-block account if critical risk
        if ($analysis['risk_level'] === 'critical') {
            $this->autoBlockAccount($email);
        }

        // Alert administrators
        $this->alertAdministrators($eventData, $analysis);
    }

    /**
     * Auto-block account.
     */
    protected function autoBlockAccount(string $email): void
    {
        $user = User::where('email', $email)->first();

        if ($user) {
            $user->update([
                'account_locked_at' => now(),
                'locked_reason' => 'Automatic security lock due to suspicious activity',
                'active' => false,
            ]);

            Log::info('Account auto-locked due to security anomaly', ['email' => $email]);
        }
    }

    /**
     * Alert administrators about anomaly.
     */
    protected function alertAdministrators(array $eventData, array $analysis): void
    {
        try {
            $adminEmails = User::role('Super Administrator')
                ->orWhere->role('Administrator')
                ->pluck('email')
                ->toArray();

            if (empty($adminEmails)) {
                return;
            }

            $emailData = [
                'event_data' => $eventData,
                'analysis' => $analysis,
                'timestamp' => now(),
            ];

            // Queue email to administrators
            foreach ($adminEmails as $adminEmail) {
                Mail::to($adminEmail)->send(new \App\Mail\SecurityAnomalyAlert($emailData));
            }
        } catch (\Exception $e) {
            Log::error('Failed to send security anomaly alert', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get user's authentication risk history.
     */
    public function getUserRiskHistory(string $email, int $days = 7): array
    {
        return DB::table('authentication_events')
            ->where('email', $email)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($event) {
                return [
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'ip' => $event->ip_address,
                    'risk_level' => $event->risk_level ?? 'none',
                    'timestamp' => $event->created_at,
                ];
            })
            ->toArray();
    }
}
