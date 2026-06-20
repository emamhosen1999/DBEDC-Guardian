<?php

namespace App\Services\Attendance;

use App\Models\HRM\Attendance;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling attendance punch operations
 */
class AttendancePunchService
{
    private const DEDUPE_WINDOW_SECONDS = 30;

    /**
     * Process punch in/out for a user
     */
    public function processPunch($user, Request $request): array
    {
        try {
            // Always use server time for all attendance punches
            $punchTime = Carbon::now();
            $punchDate = $punchTime->copy()->startOfDay();

            // Honour explicit check_type sent by biometric devices (ZKTeco: in/out/break_*).
            // Absent check_type falls back to the original toggle behaviour (for manual punches).
            $checkType = $request->input('check_type');

            $existingAttendance = $this->getExistingAttendance($user->id, $punchDate);

            $isOutPunch = in_array($checkType, ['out', 'break_out', 'ot_out']);
            $isInPunch = in_array($checkType, ['in',  'break_in',  'ot_in']);

            if ($isOutPunch) {
                if ($existingAttendance && ! $existingAttendance->punchout) {
                    return $this->punchOut($existingAttendance, $request, $user, $punchTime);
                }

                return [
                    'status' => 'error',
                    'message' => 'No open attendance record to punch out from.',
                    'code' => 422,
                ];
            }

            if ($isInPunch && $existingAttendance && ! $existingAttendance->punchout) {
                return [
                    'status' => 'error',
                    'message' => 'Already punched in for this period.',
                    'code' => 422,
                ];
            }

            // No explicit check_type (manual toggle) or explicit 'in': decide by existing record.
            if (! $isInPunch && $existingAttendance && ! $existingAttendance->punchout) {
                $lastEvent = $existingAttendance->punchout ?? $existingAttendance->punchin;
                if ($lastEvent && Carbon::parse($lastEvent)->diffInSeconds($punchTime) < self::DEDUPE_WINDOW_SECONDS) {
                    return [
                        'status' => 'error',
                        'message' => 'Duplicate punch ignored. Please wait a moment and try again.',
                        'code' => 429,
                    ];
                }

                return $this->punchOut($existingAttendance, $request, $user, $punchTime);
            }

            // Run punch-in logic inside a transaction to avoid races
            return DB::transaction(function () use ($user, $request) {
                return $this->processPunchInTransaction($user, $request);
            }, 5);

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

        try {
            $assessment = app(\App\Services\Attendance\PunchPolicyGuard::class)->assess($user->id, $punchTime);
            $attendance->forceFill([
                'policy_status' => $assessment['policy_status'],
                'needs_approval' => $assessment['needs_approval'],
                'policy_exception_reason' => $assessment['reason'],
            ])->save();
            $warning = $assessment['warning'] ?? null;
        } catch (\Throwable $e) {
            Log::error('Punch policy assessment failed: '.$e->getMessage(), ['user_id' => $user->id, 'attendance_id' => $attendance->id]);
            $warning = null; // capture is never blocked: degrade to accepted defaults
        }

        $result = [
            'status' => 'success',
            'message' => 'Successfully punched in!',
            'action' => 'punch_in',
            'attendance_id' => $attendance->id,
        ];

        if ($warning !== null) {
            $result['warning'] = $warning;
        }

        return $result;
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
            $attendanceType = $user->attendanceType;
            if (! $attendanceType) {
                return;
            }

            $baseSlug = preg_replace('/_\d+$/', '', $attendanceType->slug);
            if (! in_array($baseSlug, ['geo_polygon', 'route_waypoint'])) {
                return;
            }

            if (! preg_match('/^data:image\/(\w+);base64,/', $photoData, $matches)) {
                return;
            }

            $extension = $matches[1];
            $photoDataPart = substr($photoData, strpos($photoData, ',') + 1);
            $bytes = base64_decode($photoDataPart);

            if ($bytes === false) {
                Log::warning('Failed to decode base64 photo data');

                return;
            }

            $filename = 'attendance_'.$attendance->id.'_'.$collection.'_'.time().'.'.$extension;
            $tempDir = storage_path('app/temp');
            if (! file_exists($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $tempPath = $tempDir.DIRECTORY_SEPARATOR.$filename;
            file_put_contents($tempPath, $bytes);

            if (method_exists($attendance, 'addMedia')) {
                $attendance->addMedia($tempPath)
                    ->usingFileName($filename)
                    ->toMediaCollection($collection);
            }

            @unlink($tempPath);
        } catch (\Exception $e) {
            Log::error('Photo upload failed: '.$e->getMessage(), [
                'attendance_id' => $attendance->id,
                'collection' => $collection,
            ]);
        }
    }

    private function processPunchInTransaction($user, Request $request): array
    {
        // Always use server time for all attendance punches
        $punchTime = Carbon::now();
        $punchDate = $punchTime->copy()->startOfDay();

        // Honour explicit check_type sent by biometric devices (ZKTeco: in/out/break_*).
        // Absent check_type falls back to the original toggle behaviour (for manual punches).
        $checkType = $request->input('check_type');

        // Fetch existing attendance row with FOR UPDATE to prevent concurrent modifications
        $existingAttendance = Attendance::where('user_id', $user->id)
            ->whereDate('date', $punchDate)
            ->lockForUpdate()
            ->latest()
            ->first();

        $isOutPunch = in_array($checkType, ['out', 'break_out', 'ot_out']);
        $isInPunch = in_array($checkType, ['in',  'break_in',  'ot_in']);

        if ($isOutPunch) {
            if ($existingAttendance && ! $existingAttendance->punchout) {
                return $this->punchOut($existingAttendance, $request, $user, $punchTime);
            }

            return [
                'status' => 'error',
                'message' => 'No open attendance record to punch out from.',
                'code' => 422,
            ];
        }

        if ($isInPunch && $existingAttendance && ! $existingAttendance->punchout) {
            return [
                'status' => 'error',
                'message' => 'Already punched in for this period.',
                'code' => 422,
            ];
        }

        // No explicit check_type (manual toggle) or explicit 'in': decide by existing record.
        if (! $isInPunch && $existingAttendance && ! $existingAttendance->punchout) {
            if (! $checkType) {
                $lastEvent = $existingAttendance->punchout ?? $existingAttendance->punchin;
                if ($lastEvent && Carbon::parse($lastEvent)->diffInSeconds($punchTime) < self::DEDUPE_WINDOW_SECONDS) {
                    return [
                        'status' => 'error',
                        'message' => 'Duplicate punch ignored. Please wait a moment and try again.',
                        'code' => 429,
                    ];
                }
            }

            return $this->punchOut($existingAttendance, $request, $user, $punchTime);
        }

        return $this->punchIn($user, $punchDate, $request, $punchTime);
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
