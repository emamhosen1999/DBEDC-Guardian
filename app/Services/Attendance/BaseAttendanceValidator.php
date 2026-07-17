<?php

namespace App\Services\Attendance;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Base class for attendance validation services
 */
abstract class BaseAttendanceValidator
{
    protected $attendanceType;

    protected $request;

    public function __construct($attendanceType, Request $request)
    {
        $this->attendanceType = $attendanceType;
        $this->request = $request;
    }

    /**
     * Validate attendance based on the specific type
     */
    abstract public function validate(): array;

    /**
     * Calculate distance between two points in meters
     */
    protected function calculateDistance($lat1, $lng1, $lat2, $lng2): float
    {
        $earthRadius = 6371000; // Earth's radius in meters

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Format location data from request
     */
    protected function formatLocation(): ?array
    {
        $lat = $this->request->input('lat');
        $lng = $this->request->input('lng');

        if (! $lat || ! $lng) {
            return null;
        }

        return [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $this->request->input('address', ''),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Create success response
     */
    protected function successResponse(string $message = 'Validation successful', array $data = []): array
    {
        return [
            'status' => 'success',
            'message' => $message,
            'data' => $data,
        ];
    }

    /**
     * Create error response
     */
    protected function errorResponse(string $message, int $code = 422): array
    {
        return [
            'status' => 'error',
            'message' => $message,
            'code' => $code,
        ];
    }

    /**
     * GPS accuracy sanity guard.
     *
     * The client-reported `accuracy` (metres, GeolocationCoordinates.accuracy) is
     * a coarse trust signal: a fix wider than the configured ceiling can not be
     * trusted for a geofence decision. Returns an error response when the value is
     * present and implausible (negative, or worse than the ceiling); returns null
     * when absent/non-numeric so OLDER clients that never send accuracy are never
     * rejected. This is a conservative, backend-only mitigation; the complementary
     * client-reported mock-provider signal is handled by mockLocationError().
     */
    protected function gpsAccuracyError(): ?array
    {
        $raw = $this->request->input('accuracy');

        // Absent or unparseable: never punish an older/odd client — treat as no signal.
        if ($raw === null || $raw === '' || ! is_numeric($raw)) {
            return null;
        }

        $accuracy = (float) $raw;
        $max = (float) config('attendance.max_gps_accuracy_meters', 1000);

        $outOfRange = $accuracy < 0 || ($max > 0 && $accuracy > $max);
        if (! $outOfRange) {
            return null;
        }

        $this->logRejection('gps accuracy out of trusted range', [
            'accuracy' => $accuracy,
            'max_gps_accuracy_meters' => $max,
            'lat' => $this->request->input('lat'),
            'lng' => $this->request->input('lng'),
        ]);

        return $this->errorResponse(
            'Your device reported an unreliable GPS signal (±'.round($accuracy).'m). Please move to an open area and try again.'
        );
    }

    /**
     * Mock-location (fake GPS) guard.
     *
     * Android surfaces whether a fix came from a mock provider
     * (expo-location LocationObject.mocked); the app forwards it as the `is_mocked`
     * punch field. Returns an error response only when the client EXPLICITLY reports
     * a mocked fix and `attendance.reject_mock_location` is on. Returns null when the
     * field is absent or unparseable, so an older client that cannot report it (and
     * iOS, which does not expose the signal) is NEVER rejected for this reason — the
     * control degrades safely during a staged app rollout.
     *
     * Note this is a client-asserted signal: a fully compromised device can lie by
     * omitting the flag. It raises the cost of casual fake-GPS apps, which report
     * mocked=true honestly; it is not a defence against a determined attacker.
     */
    protected function mockLocationError(): ?array
    {
        if (! config('attendance.reject_mock_location', true)) {
            return null;
        }

        $raw = $this->request->input('is_mocked');

        // Absent/unparseable = no signal. Only an explicit, affirmative "this fix was
        // mocked" rejects; anything else (null, '', false, 0, 'false') passes through.
        $isMocked = filter_var($raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($isMocked !== true) {
            return null;
        }

        $this->logRejection('client reported a mock (fake GPS) location', [
            'is_mocked' => true,
            'lat' => $this->request->input('lat'),
            'lng' => $this->request->input('lng'),
            'accuracy' => $this->request->input('accuracy'),
        ]);

        return $this->errorResponse(
            'Your device reported a simulated (mock) GPS location. Please turn off any fake-location app or developer mock-location setting and try again.'
        );
    }

    /**
     * Auditable, non-blocking rejection log. Captures who/where for forensics
     * without ever throwing (a log-driver failure must not deny a punch).
     */
    protected function logRejection(string $reason, array $context = []): void
    {
        try {
            Log::warning('Attendance punch rejected: '.$reason, array_merge([
                'user_id' => $this->request->user()?->id,
                'ip' => $this->request->ip(),
                'attendance_type' => $this->attendanceType->slug ?? null,
            ], $context));
        } catch (\Throwable) {
            // Logging is best-effort: never let it affect the validation outcome.
        }
    }
}
