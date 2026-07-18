<?php

namespace App\Services\Attendance;

use App\Events\Domain\AttendancePunched;
use App\Models\HRM\Attendance;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

    private const SYNC_CAPTURE_FUTURE_SKEW_MINUTES = 2;

    private const SYNC_CAPTURE_MAX_AGE_HOURS = 72;

    /**
     * Tolerance window (minutes) used when deciding whether a post-midnight
     * punch-in belongs to the PRIOR business day's overnight shift. Mirrors
     * the spirit of AttendanceStatusService::OUTSIDE_WINDOW_MINUTES.
     */
    private const REBIND_TOLERANCE_MINUTES = 120;

    /**
     * Process punch in/out for a user
     */
    public function processPunch($user, Request $request): array
    {
        try {
            // Anti-falsification: a field method that structurally requires a capture
            // photo (geo-polygon / route-waypoint) must not accept a photo-less punch.
            // Covers BOTH the web and mobile punch paths (both delegate here).
            if ($photoError = $this->guardRequiredPhoto($user, $request)) {
                return $photoError;
            }

            $punchTime = $this->resolvePunchTime($request);
            $punchDate = $this->resolveBusinessDate($user->id, $punchTime);

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
     * Resolve the correct BUSINESS date (attendances.date) for a punch-in moment.
     *
     * A shift that crosses midnight is rostered on day D but its window runs into
     * D+1. Without this, a punch-in captured after midnight (e.g. a late-arriving
     * night-shift officer at 00:15) is floored to the CALENDAR date of the punch
     * (D+1) instead of the ROSTERED date (D) — leaving the officer wrongly marked
     * ABSENT on D and creating a phantom unscheduled row on D+1.
     *
     * Rule: if YESTERDAY's resolved schedule (relative to the punch) is a working
     * day, crosses midnight, and the punch falls inside that shift's window
     * (start - REBIND_TOLERANCE_MINUTES through end) — and there is no already
     * COMPLETED attendance row for yesterday covering it — bind the punch to
     * yesterday's date instead of today's. An OPEN prior-day row is intentionally
     * still eligible for rebind so the "already punched in" collision check
     * (which reads the resolved date) correctly finds it, rather than creating a
     * second row on the wrong day.
     *
     * Ties are broken in favour of TODAY's own schedule when the punch is also
     * plausibly an early arrival for today's shift and today's start is closer.
     *
     * Day-shift users are entirely unaffected: yesterday never crosses midnight,
     * so the very first check returns today's calendar date unchanged.
     */
    private function resolveBusinessDate(int $userId, Carbon $punchTime): Carbon
    {
        $today = $punchTime->copy()->startOfDay();
        $prevDay = $today->copy()->subDay();

        $resolver = app(ScheduleResolver::class);
        $prevSchedule = $resolver->resolve($userId, $prevDay);

        if (! $prevSchedule->isWorkingDay || ! $prevSchedule->crossesMidnight) {
            return $today;
        }

        $prevWindowStart = $prevSchedule->start->copy()->subMinutes(self::REBIND_TOLERANCE_MINUTES);
        $prevWindowEnd = $prevSchedule->end->copy();

        if ($punchTime->lessThan($prevWindowStart) || $punchTime->greaterThan($prevWindowEnd)) {
            return $today;
        }

        // A COMPLETED row already covers yesterday's shift — this punch is a
        // distinct, unscheduled event and must not be folded into that closed day.
        $prevRow = $this->getExistingAttendance($userId, $prevDay);
        if ($prevRow && $prevRow->punchout !== null) {
            return $today;
        }

        // Tie-break: today may ALSO have a legitimate claim (e.g. an early
        // arrival for today's own shift). Prefer whichever start is closer.
        $todaySchedule = $resolver->resolve($userId, $today);
        if ($todaySchedule->isWorkingDay) {
            $todayWindowStart = $todaySchedule->start->copy()->subMinutes(self::REBIND_TOLERANCE_MINUTES);
            $todayWindowEnd = $todaySchedule->end->copy();

            $nearToday = $punchTime->greaterThanOrEqualTo($todayWindowStart)
                && $punchTime->lessThanOrEqualTo($todayWindowEnd);

            if ($nearToday) {
                $distPrev = abs($punchTime->diffInSeconds($prevSchedule->start));
                $distToday = abs($punchTime->diffInSeconds($todaySchedule->start));

                if ($distToday < $distPrev) {
                    return $today;
                }
            }
        }

        return $prevDay;
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
        // Bounded offline-capture channel (sync push only). The `sync_capture`
        // attribute is set server-side by DataSyncService and can never come from
        // client input, so a human punch request (which is additionally scrubbed by
        // GuardsServerAuthoritativePunchTime) can never reach this branch. The
        // capture time here has already been bounded/rejected upstream; the same
        // window is re-checked defensively before it is trusted.
        if ($request->attributes->get('sync_capture') === true) {
            $captured = $this->boundedSyncCaptureTime($request->input('captured_at'));

            if ($captured !== null) {
                return $captured;
            }

            return Carbon::now();
        }

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
     * Bound a client-asserted offline capture time for the sync channel.
     * Returns null when unparseable, in the future beyond a small skew, or older
     * than the offline window — callers then fall back to server time.
     */
    private function boundedSyncCaptureTime($raw): ?Carbon
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($raw);
        } catch (\Throwable $e) {
            return null;
        }

        $now = Carbon::now();

        if ($parsed->greaterThan($now->copy()->addMinutes(self::SYNC_CAPTURE_FUTURE_SKEW_MINUTES))) {
            return null;
        }

        if ($parsed->lessThan($now->copy()->subHours(self::SYNC_CAPTURE_MAX_AGE_HOURS))) {
            return null;
        }

        return $parsed;
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
    private function findOpenAttendanceToClose(int $userId, CarbonInterface $punchMoment, bool $lock = false): ?Attendance
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
        // Resolve against the row's BUSINESS date (attendances.date), not the raw
        // punch-in timestamp: after cross-midnight rebinding the two can differ
        // (a late arrival keeps its real capture time but is dated to the shift's
        // rostered day), and the business date is what the roster is keyed on.
        $shift = app(ScheduleResolver::class)->resolve($userId, $prior->date);

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

        $this->stampOfflineFlag($attendance, $request);

        // Handle photo upload for polygon/route types
        $this->handlePhotoUpload($attendance, $request, 'punchout_photo', $user);

        // Domain bus (additive, after-commit). When this runs inside the punch
        // transaction the event is held until commit and dropped on rollback.
        AttendancePunched::dispatch(
            $user->id ?? null,
            $attendance->id,
            AttendancePunched::ACTION_OUT,
            $this->businessDateKey($attendance, $punchTime),
        );

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

        $this->stampOfflineFlag($attendance, $request);

        // Handle photo upload for polygon/route types
        $this->handlePhotoUpload($attendance, $request, 'punchin_photo', $user);

        try {
            $assessment = app(PunchPolicyGuard::class)->assess($user->id, $punchTime);
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

        // Domain bus (additive, after-commit). punchIn always runs inside
        // processPunchInTransaction, so a rolled-back capture emits nothing.
        AttendancePunched::dispatch(
            $user->id ?? null,
            $attendance->id,
            AttendancePunched::ACTION_IN,
            $this->businessDateKey($attendance, $punchTime),
        );

        return $result;
    }

    /**
     * Realtime bucket key for an attendance row: the BUSINESS date the row is
     * filed under (which for an overnight shift is the prior day), falling back
     * to the punch moment only when the row somehow carries no date.
     */
    private function businessDateKey(Attendance $attendance, Carbon $punchTime): string
    {
        $date = $attendance->date;

        if ($date instanceof CarbonInterface) {
            return $date->format('Y-m-d');
        }

        if (is_string($date) && $date !== '') {
            try {
                return Carbon::parse($date)->format('Y-m-d');
            } catch (\Throwable) {
                // fall through to the punch moment
            }
        }

        return $punchTime->format('Y-m-d');
    }

    /**
     * Reject a photo-less punch when the user's resolved attendance methods ALL
     * require a verification photo (geo-polygon / route-waypoint).
     *
     * Conservative by design: if the user holds ANY non-photo method (wifi/IP,
     * QR, biometric), the punch is allowed through with no photo — they may have
     * used that method. Photo is therefore never blanket-required, and the whole
     * check is behind a config kill-switch for staged rollout.
     */
    private function guardRequiredPhoto($user, Request $request): ?array
    {
        if (! config('attendance.require_photo_for_field_methods', true)) {
            return null;
        }

        $types = method_exists($user, 'resolvedAttendanceTypes')
            ? $user->resolvedAttendanceTypes()
            : collect();

        if ($types->isEmpty()) {
            return null;
        }

        $allRequirePhoto = $types->every(fn ($type) => $type && $this->typeRequiresPhoto($type));
        if (! $allRequirePhoto) {
            return null;
        }

        $photo = $request->input('photo');
        if (is_string($photo) && trim($photo) !== '') {
            return null;
        }

        try {
            Log::warning('Attendance punch rejected: verification photo required but absent', [
                'user_id' => $user->id,
                'ip' => $request->ip(),
            ]);
        } catch (\Throwable) {
            // A logging/disk failure must never turn a rejected punch into a 500.
        }

        return [
            'status' => 'error',
            'message' => 'A verification photo is required for this attendance method. Please capture a photo and try again.',
            'code' => 422,
        ];
    }

    /**
     * Whether an attendance type structurally requires a capture photo.
     * Mirrors the geo_polygon / route_waypoint taxonomy used by handlePhotoUpload()
     * and the team-locations `requires_photo` flag.
     */
    private function typeRequiresPhoto($type): bool
    {
        $baseSlug = preg_replace('/_\d+$/', '', (string) ($type->slug ?? ''));

        return in_array($baseSlug, ['geo_polygon', 'route_waypoint'], true);
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
        $punchDate = $this->resolveBusinessDate($user->id, $punchTime);

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

        // ── Night-shift overnight close for manual (no check_type) punches ──
        // When there is no open row TODAY and no explicit check_type, check
        // whether a prior-day row should be closed (overnight shift).  The
        // findOpenAttendanceToClose method already safely gates this: it only
        // returns a prior-day row when the shift crosses_midnight AND the
        // punch is within MAX_OVERNIGHT_HOURS of the punch-in, so day-shift
        // workers are never wrongly paired.
        if (! $checkType) {
            $overnightRow = $this->findOpenAttendanceToClose($user->id, $punchTime, lock: true);
            if ($overnightRow) {
                return $this->punchOut($overnightRow, $request, $user, $punchTime);
            }
        }

        return $this->punchIn($user, $punchDate, $request, $punchTime);
    }

    /**
     * Flag an attendance row that was captured offline and replayed through the
     * bounded sync channel. Keeps the audit trail honest: the punch is attributed
     * to its real capture moment but marked as device-asserted. forceFill is used
     * because `was_offline` is intentionally not mass-assignable.
     */
    private function stampOfflineFlag(Attendance $attendance, Request $request): void
    {
        if ($request->attributes->get('sync_capture') === true) {
            $attendance->forceFill(['was_offline' => true])->save();
        }
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
