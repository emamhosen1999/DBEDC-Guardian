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
     * rejected. This is a conservative, backend-only mitigation — full mock/GPS
     * spoof detection still needs a client `is_mock` flag the app does not yet send.
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
