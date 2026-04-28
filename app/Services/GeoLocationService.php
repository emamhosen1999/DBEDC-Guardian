<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeoLocationService
{
    /**
     * Get geolocation data for an IP address.
     */
    public function getLocation(string $ip): ?array
    {
        // Skip for localhost/private IPs
        if ($this->isPrivateIp($ip)) {
            return [
                'ip' => $ip,
                'country' => 'Local',
                'country_code' => 'LOCAL',
                'city' => 'Localhost',
                'latitude' => 0,
                'longitude' => 0,
                'is_private' => true,
            ];
        }

        $cacheKey = "geolocation:{$ip}";
        
        // Check cache first (24 hours)
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey);
        }

        // Fetch from API
        $location = $this->fetchFromApi($ip);

        if ($location) {
            Cache::put($cacheKey, $location, 86400); // 24 hours
        }

        return $location;
    }

    /**
     * Fetch location data from geolocation API.
     */
    protected function fetchFromApi(string $ip): ?array
    {
        try {
            $response = Http::timeout(5)->get("http://ip-api.com/json/{$ip}");

            if (! $response->successful()) {
                Log::warning('Geolocation API request failed', [
                    'ip' => $ip,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $data = $response->json();

            if ($data['status'] === 'fail') {
                Log::warning('Geolocation lookup failed', [
                    'ip' => $ip,
                    'message' => $data['message'] ?? 'Unknown error',
                ]);
                return null;
            }

            return [
                'ip' => $ip,
                'country' => $data['countryName'] ?? null,
                'country_code' => $data['countryCode'] ?? null,
                'city' => $data['city'] ?? null,
                'region' => $data['regionName'] ?? null,
                'latitude' => $data['lat'] ?? 0,
                'longitude' => $data['lon'] ?? 0,
                'isp' => $data['isp'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'is_private' => false,
            ];
        } catch (\Exception $e) {
            Log::error('Geolocation service error', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Check if IP is private/local.
     */
    protected function isPrivateIp(string $ip): bool
    {
        return $ip === '127.0.0.1' ||
               $ip === '::1' ||
               str_starts_with($ip, '192.168.') ||
               str_starts_with($ip, '10.') ||
               str_starts_with($ip, '172.16.');
    }

    /**
     * Calculate distance between two coordinates in kilometers.
     */
    public function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
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
     * Check if location is suspicious for a user.
     */
    public function isSuspiciousLocation(int $userId, array $currentLocation): bool
    {
        $cacheKey = "user_locations:{$userId}";
        $knownLocations = Cache::get($cacheKey, []);

        // If no known locations, this is not suspicious (first login)
        if (empty($knownLocations)) {
            return false;
        }

        $distanceThreshold = config('captcha.location.distance_threshold_km', 500);
        $suspiciousCountries = config('captcha.location.suspicious_countries', []);

        // Check if country is in suspicious list
        if (in_array($currentLocation['country_code'] ?? '', $suspiciousCountries)) {
            return true;
        }

        // Check distance from known locations
        foreach ($knownLocations as $known) {
            if ($known['country_code'] !== ($currentLocation['country_code'] ?? '')) {
                return true; // Different country is suspicious
            }

            $distance = $this->calculateDistance(
                $known['latitude'],
                $known['longitude'],
                $currentLocation['latitude'] ?? 0,
                $currentLocation['longitude'] ?? 0
            );

            if ($distance > $distanceThreshold) {
                return true;
            }
        }

        return false;
    }

    /**
     * Record location for user.
     */
    public function recordUserLocation(int $userId, array $location): void
    {
        $cacheKey = "user_locations:{$userId}";
        $knownLocations = Cache::get($cacheKey, []);

        // Add new location
        $knownLocations[] = $location;

        // Keep only last 5 locations
        if (count($knownLocations) > 5) {
            $knownLocations = array_slice($knownLocations, -5);
        }

        Cache::put($cacheKey, $knownLocations, 2592000); // 30 days
    }

    /**
     * Get user's known locations.
     */
    public function getUserKnownLocations(int $userId): array
    {
        return Cache::get("user_locations:{$userId}", []);
    }

    /**
     * Check if location change is suspicious based on velocity.
     */
    public function isImpossibleTravel(int $userId, array $currentLocation, ?array $previousLocation = null): bool
    {
        if (! $previousLocation) {
            return false;
        }

        $cacheKey = "user_last_login_time:{$userId}";
        $lastLoginTime = Cache::get($cacheKey);

        if (! $lastLoginTime) {
            return false;
        }

        $distance = $this->calculateDistance(
            $previousLocation['latitude'] ?? 0,
            $previousLocation['longitude'] ?? 0,
            $currentLocation['latitude'] ?? 0,
            $currentLocation['longitude'] ?? 0
        );

        $timeDiff = now()->diffInHours($lastLoginTime);

        // If distance > 1000km in less than 2 hours, it's impossible travel
        if ($distance > 1000 && $timeDiff < 2) {
            return true;
        }

        // If distance > 500km in less than 1 hour, it's impossible travel
        if ($distance > 500 && $timeDiff < 1) {
            return true;
        }

        return false;
    }

    /**
     * Record last login time for user.
     */
    public function recordLastLoginTime(int $userId): void
    {
        Cache::put("user_last_login_time:{$userId}", now(), 86400); // 24 hours
    }
}
