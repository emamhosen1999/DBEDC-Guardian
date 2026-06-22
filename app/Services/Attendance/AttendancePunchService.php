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

    private const MAX_OVERNIGHT_HOURS = 18;

    private const MAX_CLOCK_DRIFT_HOURS = 2;

    /**
     * Process punch in/out for a user
     */
    public function processPunch($user, Request $request): array
    {
        try {
            $punchTime = $this->resolvePunchTime($request);
            $punchDate = $punchTime->copy()->startOfDay();

            // Honour explicit check_type sent by biometric devices (ZKTeco: in/out/break_*).
            // Absent check_type falls back to the original toggle behaviour (for manual punches).
            $checkType = $request->input('check_type');

            $existingAttendance = $this->getExistingAttendance($user->id, $punchDate);

            $isOutPunch = in_array($checkType, ['out', 'break_out', 'ot_out']);
            $isInPunch = in_array($checkType, ['in',  'break_in',  'ot_in']);

            if ($isOutPunch) {
                $openRow = $this->findOpenAttendanceToClose($user->id, $punchTime);
                if ($openRow) {
                    return $this->punchOut($openRow, $request, $user, $punchTime);
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

                $openRow = $this->findOpenAttendanceToClose($user->id, $punchTime) ?? $existingAttendance;

                return $this->punchOut($openRow, $request, $user, $punchTime);
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
     * Resolve the authoritative punch moment.
     *
     * Trusted device/biometric sources carry the REAL punch time in `punch_time`
     * (a device may push or be back-downloaded hours later) — honour it so worked
     * minutes / late / OT / overnight detection compute on the true moment, not the
     * server's processing time. Manual/web punches always use server time, so a user
     * cannot back-date their own punch.
     */
    private function resolvePunchTime(Request $request): Carbon
    {
        $raw = $request->input('punch_time');
        $source = $request->input('source');

        if ($raw && in_array($source, ['biometric', 'device'], true)) {
            try {
                $parsed = Carbon::parse($raw);
                $now = Carbon::now();
                // Reject future-dated / over-drifted device clocks: a real punch is never in the
                // future, and a clock running fast beyond tolerance is drift, not a real moment.
                if ($parsed->greaterThan($now->copy()->addHours(self::MAX_CLOCK_DRIFT_HOURS))) {
                    Log::warning('Rejected future/over-drifted device punch_time; using server time', [
                        'punch_time' => $raw,
                        'now' => $now->toDateTimeString(),
                    ]);

                    return $now;
                }

                return $parsed;
            } catch (\Throwable $e) {
                // Unparseable device timestamp — fall back to server time rather than fail capture.
            }
        }

        return Carbon::now();
    }

    /**
     * Resolve the open attendance row that an OUT punch should close.
     *
     * Returns today's open row (existing behavior) OR, if none, the previous
     * day's open row when (a) the resolved shift for the open row's punch-in
     * crosses midnight AND (b) the out-punch is within MAX_OVERNIGHT_HOURS of
     * that punch-in. This only changes WHICH open row an out-punch closes —
     * it never blocks capture.
     */
    private function findOpenAttendanceToClose(int $userId, \Carbon\CarbonInterface $punchMoment, bool $lock = false): ?Attendance
    {
        // 1) Today's open row — existing behavior.
        $todayQuery = Attendance::where('user_id', $userId)
            ->whereDate('date', $punchMoment->copy()->startOfDay())
            ->whereNull('punchout');
        if ($lock) {
            $todayQuery->lockForUpdate();
        }
        $today = $todayQuery->latest()->first();
        if ($today) {
            return $today;
        }

        // 2) Overnight: prior-day open row whose shift crosses midnight, within the bounded window.
        $priorQuery = Attendance::where('user_id', $userId)
            ->whereDate('date', $punchMoment->copy()->subDay()->startOfDay())
            ->whereNull('punchout');
        if ($lock) {
            $priorQuery->lockForUpdate();
        }
        $prior = $priorQuery->latest()->first();
        if (! $prior || ! $prior->punchin) {
            return null;
        }
        $in = Carbon::parse($prior->punchin);
        if ($in->diffInHours($punchMoment) > self::MAX_OVERNIGHT_HOURS) {
            return null;
        }
        $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)->resolve($userId, $in);

        return $shift->crossesMidnight ? $prior : null;
    }

    /**
     * Process punch out
     */
    private function punchOut(Attendance $attendance, Request $request, $user, Carbon $punchTime): array
    {
        if ($attendance->punchin && $punchTime->lessThanOrEqualTo(Carbon::parse($attendance->punchin))) {
            return [
                'status' => 'error',
                'message' => 'Punch-out cannot be before punch-in.',
                'code' => 422,
            ];
        }

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
            try {
                Log::error('Punch policy assessment failed: '.$e->getMessage(), ['user_id' => $user->id, 'attendance_id' => $attendance->id]);
            } catch (\Throwable) {
                // Swallow logging failures too: a log driver/disk failure must never
                // propagate into the surrounding DB::transaction and roll back the
                // just-created Attendance row. Capture is never blocked.
            }
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
        $punchTime = $this->resolvePunchTime($request);
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
            $openRow = $this->findOpenAttendanceToClose($user->id, $punchTime, lock: true);
            if ($openRow) {
                return $this->punchOut($openRow, $request, $user, $punchTime);
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

            $openRow = $this->findOpenAttendanceToClose($user->id, $punchTime, lock: true) ?? $existingAttendance;

            return $this->punchOut($openRow, $request, $user, $punchTime);
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
        $qrCode = $request->input('qr_code');

        if (! $lat && ! $lng && ! $qrCode) {
            return null;
        }

        $locationData = [
            'lat' => $lat,
            'lng' => $lng,
            'address' => $request->input('address', ''),
            'timestamp' => now()->toISOString(),
        ];

        if ($qrCode) {
            $locationData['qr_code'] = $qrCode;
        }

        return json_encode($locationData);
    }
}
