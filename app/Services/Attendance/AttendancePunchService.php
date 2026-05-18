<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling attendance punch operations
 */
class AttendancePunchService
{
    /**
     * Process punch in/out for a user
     */
    public function processPunch($user, Request $request): array
    {
        try {
            // Use device-provided punch_time when available (biometric batch sync).
            // Fallback to now() for manual/web punches that omit the field.
            $punchTime = $request->input('punch_time')
                ? Carbon::parse($request->input('punch_time'))
                : Carbon::now();
            $punchDate = $punchTime->copy()->startOfDay();

            // Honour explicit check_type sent by biometric devices (ZKTeco: in/out/break_*).
            // Absent check_type falls back to the original toggle behaviour (for manual punches).
            $checkType = $request->input('check_type');

            $existingAttendance = $this->getExistingAttendance($user->id, $punchDate);

            $isOutPunch = in_array($checkType, ['out', 'break_out', 'ot_out']);
            $isInPunch  = in_array($checkType, ['in',  'break_in',  'ot_in']);

            if ($isOutPunch) {
                if ($existingAttendance && ! $existingAttendance->punchout) {
                    return $this->punchOut($existingAttendance, $request, $user, $punchTime);
                }
                return [
                    'status'  => 'error',
                    'message' => 'No open attendance record to punch out from.',
                    'code'    => 422,
                ];
            }

            if ($isInPunch && $existingAttendance && ! $existingAttendance->punchout) {
                return [
                    'status'  => 'error',
                    'message' => 'Already punched in for this period.',
                    'code'    => 422,
                ];
            }

            // No explicit check_type (manual toggle) or explicit 'in': decide by existing record.
            if (! $isInPunch && $existingAttendance && ! $existingAttendance->punchout) {
                return $this->punchOut($existingAttendance, $request, $user, $punchTime);
            }

            return $this->punchIn($user, $punchDate, $request, $punchTime);

        } catch (\Exception $e) {
            Log::error('Attendance punch error: '.$e->getMessage(), [
                'user_id' => $user->id,
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'status' => 'error',
                'message' => 'Failed to record attendance. Please try again.',
                'code' => 500,
            ];
        }
    }

    /**
     * Get existing attendance for user and date
     */
    private function getExistingAttendance(int $userId, Carbon $date): ?Attendance
    {
        return Attendance::where('user_id', $userId)
            ->whereDate('date', $date)
            ->latest()
            ->first();
    }

    /**
     * Process punch out
     */
    private function punchOut(Attendance $attendance, Request $request, $user, Carbon $punchTime): array
    {
        $attendance->update([
            'punchout' => $punchTime,
            'punchout_location' => $this->formatLocation($request),
        ]);

        // Handle photo upload for polygon/route types
        $this->handlePhotoUpload($attendance, $request, 'punchout_photo', $user);

        return [
            'status' => 'success',
            'message' => 'Successfully punched out!',
            'action' => 'punch_out',
            'attendance_id' => $attendance->id,
        ];
    }

    /**
     * Process punch in
     */
    private function punchIn($user, Carbon $date, Request $request, Carbon $punchTime): array
    {
        $attendance = Attendance::create([
            'user_id' => $user->id,
            'date' => $date,
            'punchin' => $punchTime,
            'punchin_location' => $this->formatLocation($request),
        ]);

        // Handle photo upload for polygon/route types
        $this->handlePhotoUpload($attendance, $request, 'punchin_photo', $user);

        return [
            'status' => 'success',
            'message' => 'Successfully punched in!',
            'action' => 'punch_in',
            'attendance_id' => $attendance->id,
        ];
    }

    /**
     * Handle photo upload using Media Library
     */
    private function handlePhotoUpload(Attendance $attendance, Request $request, string $collection, $user): void
    {
        $photoData = $request->input('photo');

        if (! $photoData) {
            return;
        }

        try {
            // Check if user's attendance type requires photo (polygon or route)
            $attendanceType = $user->attendanceType;
            if (! $attendanceType) {
                return;
            }

            $baseSlug = preg_replace('/_\d+$/', '', $attendanceType->slug);
            if (! in_array($baseSlug, ['geo_polygon', 'route_waypoint'])) {
                return;
            }

            // Decode base64 image
            if (preg_match('/^data:image\/(\w+);base64,/', $photoData, $matches)) {
                $extension = $matches[1];
                $photoData = substr($photoData, strpos($photoData, ',') + 1);
                $photoData = base64_decode($photoData);

                if ($photoData === false) {
                    Log::warning('Failed to decode base64 photo data');

                    return;
                }

                // Generate unique filename
                $filename = 'attendance_'.$attendance->id.'_'.$collection.'_'.time().'.'.$extension;
                $tempPath = storage_path('app/temp/'.$filename);

                // Ensure temp directory exists
                if (! file_exists(storage_path('app/temp'))) {
                    mkdir(storage_path('app/temp'), 0755, true);
                }

                // Save temporarily
                file_put_contents($tempPath, $photoData);

                // Add to media collection
                $attendance->addMedia($tempPath)
                    ->usingFileName($filename)
                    ->toMediaCollection($collection);

                Log::info('Photo uploaded successfully', [
                    'attendance_id' => $attendance->id,
                    'collection' => $collection,
                ]);
            }
        } catch (\Exception $e) {
            Log::error('Photo upload failed: '.$e->getMessage(), [
                'attendance_id' => $attendance->id,
                'collection' => $collection,
            ]);
        }
    }

    /**
     * Format location data from request
     */
    private function formatLocation(Request $request): ?string
    {
        $lat = $request->input('lat');
        $lng = $request->input('lng');

        if (! $lat || ! $lng) {
            return null;
        }

        $locationData = [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $request->input('address', ''),
            'timestamp' => now()->toISOString(),
        ];

        return json_encode($locationData);
    }
}
