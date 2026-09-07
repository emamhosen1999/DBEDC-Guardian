<?php

namespace App\Services\Biometric;

use App\Events\BiometricAttendanceReceived;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricAttLog;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\HRM\BiometricDownloadSession;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BiometricProcessingService
{
    protected AttendancePunchService $punchService;

    protected DeviceClockService $clockService;

    public function __construct(AttendancePunchService $punchService, ?DeviceClockService $clockService = null)
    {
        $this->punchService = $punchService;
        // Optional so any caller still constructing this by hand keeps working;
        // every caller in the app resolves it from the container, which injects
        // both.
        $this->clockService = $clockService ?? app(DeviceClockService::class);
    }

    /**
     * The device-clock estimator, for callers that need to read or expose it.
     */
    public function clock(): DeviceClockService
    {
        return $this->clockService;
    }

    /**
     * Read model for a device's clock: what we measured, on how much evidence,
     * and whether punches are currently being adjusted by it.
     *
     * Offered here as well as on DeviceClockService because the admin/device
     * controllers already hold this service, so surfacing the offset costs them
     * one call and no new dependency. Routing is deliberately not this layer's
     * business.
     *
     * @return array<string, mixed>
     */
    public function clockSnapshot(BiometricDevice $device): array
    {
        return $this->clockService->snapshot($device);
    }

    // ──────────────────────────────────────────────────────────────
    //  Device authentication & heartbeat
    // ──────────────────────────────────────────────────────────────

    /**
     * Authenticate a device by serial number and auth token.
     */
    public function authenticateDevice(string $serialNumber, string $authToken): ?BiometricDevice
    {
        return BiometricDevice::where('serial_number', $serialNumber)
            ->where('auth_token', $authToken)
            ->first();
    }

    /**
     * Find a device by serial number only (for ADMS protocol which doesn't use auth tokens).
     */
    public function findDeviceBySerial(string $serialNumber): ?BiometricDevice
    {
        return BiometricDevice::where('serial_number', $serialNumber)->first();
    }

    /**
     * Update a device's last heartbeat timestamp.
     */
    public function updateHeartbeat(BiometricDevice $device): void
    {
        $device->update(['last_heartbeat_at' => now()]);
    }

    /**
     * Extract serial number from an ADMS request.
     * ZKTeco always sends SN as a query parameter (?SN=xxx), never in the body.
     */
    public function getSerialNumber(Request $request): ?string
    {
        return $request->query('SN') ?: ($request->header('SN') ?: null);
    }

    // ──────────────────────────────────────────────────────────────
    //  Direct webhook punch handling (handle method logic)
    // ──────────────────────────────────────────────────────────────

    /**
     * Map a ZKTeco verify_code to a human-readable check type.
     */
    public function resolveCheckType($verifyCode, ?string $fallback = 'in'): string
    {
        $checkTypeMap = [
            0 => 'in',
            1 => 'out',
            2 => 'break_in',
            3 => 'break_out',
            4 => 'ot_in',
            5 => 'ot_out',
        ];

        return $checkTypeMap[$verifyCode] ?? $fallback;
    }

    /**
     * Create an initial ATTLOG record for a punch event.
     */
    public function createAttLog(array $data): BiometricAttLog
    {
        return BiometricAttLog::create($data);
    }

    /**
     * Create an ATTLOG record unless this device has already recorded that punch.
     *
     * Same as createAttLog(), but returns null instead of throwing when the punch
     * natural key (device + PIN + punch_time + check_type) is already staged, so
     * the direct webhook answers a device that retries a delivery with "already
     * recorded" instead of a 500.
     */
    public function createAttLogIfNotStaged(array $data): ?BiometricAttLog
    {
        try {
            return $this->createAttLog($data);
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }
    }

    /**
     * Insert one staged punch row, idempotently.
     *
     * `biometric_att_logs` carries a unique key over the punch natural key
     * (biometric_device_id, user_pin, punch_time, check_type). Re-staging is
     * therefore no longer possible at the database level; this turns that
     * guarantee into a return value the callers can branch on.
     *
     * Why catch the violation instead of `insertOrIgnore()`: on MySQL,
     * `insertOrIgnore()` compiles to `INSERT IGNORE`, which downgrades *every*
     * error on the statement to a warning — a truncated column or a bad foreign
     * key would silently vanish along with the duplicates. `insertGetId()` also
     * returns the new id, which the live push path needs to stamp the row's
     * outcome, and `INSERT IGNORE` would leave us unable to tell "already there"
     * from "quietly mangled".
     *
     * The narrow catch is Laravel's own driver-aware
     * UniqueConstraintViolationException, so only a unique/primary key rejection
     * is swallowed. Foreign-key and not-null failures share SQLSTATE 23000 and
     * must keep surfacing as errors; they arrive as plain QueryExceptions and are
     * not caught here.
     *
     * @param  array<string, mixed>  $row
     * @return int|null the new row id, or null when the punch was already staged
     */
    protected function insertAttLogRow(array $row): ?int
    {
        try {
            return DB::table('biometric_att_logs')->insertGetId($row);
        } catch (UniqueConstraintViolationException $e) {
            return null;
        }
    }

    /**
     * Resolve a system user by employee_id (PIN). If the user does not exist,
     * auto-create an inactive placeholder so admin can link/activate later.
     *
     * @return array{user: User, is_new: bool}
     */
    public function resolveOrCreateUser(string $deviceUserId): array
    {
        $user = User::withTrashed()->where('employee_id', $deviceUserId)->first();

        if ($user) {
            return ['user' => $user, 'is_new' => false];
        }

        $user = User::create([
            'name' => 'Device User '.$deviceUserId,
            'email' => 'device_user_'.$deviceUserId.'@placeholder.local',
            'user_name' => 'device_user_'.$deviceUserId,
            'password' => bcrypt(Str::random(32)),
            'employee_id' => $deviceUserId,
            'active' => false,
        ]);
        $user->delete(); // soft-delete = inactive

        return ['user' => $user, 'is_new' => true];
    }

    /**
     * The two eligibility rejections that are POLICY, not defects.
     *
     * Both mean the employee was deliberately configured for a non-biometric
     * method (WiFi/IP, GPS, QR) at the time they were read by a terminal. The
     * punch is real; recording attendance from it anyway would override the
     * attendance policy an admin actually set. `biometric:replay-orphaned-punches`
     * matches this exact text to separate them out and REPORT them rather than
     * replay them — so the strings are shared constants, not literals that can
     * drift apart from their consumer.
     */
    public const REASON_NO_ATTENDANCE_TYPE = 'User has no attendance type assigned';

    public const REASON_NOT_BIOMETRIC_PREFIX = 'Attendance type is not biometric: ';

    /**
     * Validate that the user has a biometric attendance type and the device is
     * authorised for that attendance zone.
     *
     * @return array{valid: bool, reason: string|null, attendance_type: AttendanceType|null}
     */
    public function validateAttendanceEligibility(User $user, BiometricDevice $device): array
    {
        if (! $user->attendance_type_id) {
            return ['valid' => false, 'reason' => self::REASON_NO_ATTENDANCE_TYPE, 'attendance_type' => null];
        }

        $attendanceType = AttendanceType::with('biometricDevices')->find($user->attendance_type_id);

        if (! $attendanceType || ! str_starts_with($attendanceType->slug, 'biometric')) {
            return [
                'valid' => false,
                'reason' => self::REASON_NOT_BIOMETRIC_PREFIX.($attendanceType?->slug ?? 'not found'),
                'attendance_type' => $attendanceType,
            ];
        }

        // Zone check — if the attendance type has linked devices, punch must come from one of them
        $linkedDevices = $attendanceType->biometricDevices;
        if ($linkedDevices->isNotEmpty() && ! $linkedDevices->contains('id', $device->id)) {
            return ['valid' => false, 'reason' => 'Device not in attendance zone', 'attendance_type' => $attendanceType];
        }

        return ['valid' => true, 'reason' => null, 'attendance_type' => $attendanceType];
    }

    /**
     * Check whether a punch already exists for a user at the given time (idempotency).
     */
    public function isDuplicatePunch(string $userId, $punchTime): bool
    {
        return DB::table('attendances')
            ->where('user_id', $userId)
            ->where(function ($q) use ($punchTime) {
                $q->where('punchin', $punchTime)->orWhere('punchout', $punchTime);
            })
            ->exists();
    }

    /**
     * Build a synthetic Request for the punch service.
     */
    public function buildSyntheticPunchRequest(string $serialNumber, string $deviceUserId, string $punchTime, string $checkType): Request
    {
        return Request::create('/biometric/punch', 'POST', [
            'device_serial' => $serialNumber,
            'device_user_id' => $deviceUserId,
            'source' => 'biometric',
            'punch_time' => $punchTime,
            'check_type' => $checkType,
        ]);
    }

    /**
     * Process a single punch through the AttendancePunchService.
     */
    public function processPunch(User $user, Request $syntheticRequest): array
    {
        return $this->punchService->processPunch($user, $syntheticRequest);
    }

    // ──────────────────────────────────────────────────────────────
    //  ATTLOG listing
    // ──────────────────────────────────────────────────────────────

    /**
     * Query paginated ATTLOG records with optional filters.
     */
    public function queryAttLogs(?string $search, ?string $status, ?string $deviceId, int $perPage, int $page)
    {
        $query = BiometricAttLog::with(['user:employee_id,name', 'device:id,name,serial_number'])
            ->orderByDesc('punch_time');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('user_pin', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%"));
            });
        }

        if ($status && $status !== 'all') {
            $query->where('punch_status', $status);
        }

        if ($deviceId && $deviceId !== 'all') {
            $query->where('biometric_device_id', $deviceId);
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Aggregate ATTLOG statistics.
     */
    public function getAttLogStats(): array
    {
        // CASE WHEN rather than `SUM(col = 'x')`: the boolean-arithmetic form is a
        // MySQL idiom and is not valid on SQLite/PostgreSQL, which the test suite
        // (and any non-MySQL deployment) runs on.
        $stats = DB::table('biometric_att_logs')->selectRaw("
            COUNT(*) as total,
            SUM(CASE WHEN punch_status = 'processed'    THEN 1 ELSE 0 END) as processed,
            SUM(CASE WHEN punch_status = 'unknown_user' THEN 1 ELSE 0 END) as unknown_user,
            SUM(CASE WHEN punch_status = 'downloaded'   THEN 1 ELSE 0 END) as downloaded,
            SUM(CASE WHEN punch_status IN ('failed','wrong_device','duplicate') THEN 1 ELSE 0 END) as failed
        ")->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'processed' => (int) ($stats->processed ?? 0),
            'unknown_user' => (int) ($stats->unknown_user ?? 0),
            // Captured by a download session but not yet replayed into attendance.
            'downloaded' => (int) ($stats->downloaded ?? 0),
            'failed' => (int) ($stats->failed ?? 0),
        ];
    }

    // ──────────────────────────────────────────────────────────────
    //  ADMS handshake
    // ──────────────────────────────────────────────────────────────

    /**
     * Fetch the next pending command for a device (ADMS handshake/getrequest).
     */
    public function fetchNextPendingCommand(BiometricDevice $device): ?BiometricDeviceCommand
    {
        return BiometricDeviceCommand::where('biometric_device_id', $device->id)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now());
            })
            ->oldest()
            ->first();
    }

    /**
     * Generate an ADMS session ID.
     */
    public function generateSessionId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Push protocol version asserted when the device announces nothing usable.
     *
     * Also the version of this server implementation (`ServerVer`), which is
     * ours to state unconditionally — unlike `PushProtVer`, which describes a
     * protocol two parties have to agree on.
     */
    public const DEFAULT_PUSH_PROTO_VER = '2.4.1';

    /**
     * Build the ADMS handshake options response body.
     *
     * Two deliberate omissions, both matrix §3:
     *
     *  - **`ATTPHOTOStamp` is not advertised.** A `*Stamp` key is the per-table
     *    sync cursor that invites the device to push that table; advertising one
     *    for `ATTPHOTO` asked an MB460 with ~136 MB of free flash to upload
     *    capture photos over a shared-hosting link so we could drop them on the
     *    floor (admsPush() has no ATTPHOTO handler and nobody has asked for punch
     *    photos). Removing the key removes the invitation without touching a
     *    single attendance key: `ATTLOGStamp` and `transFlag` are unchanged, so
     *    the live push flow is bit-for-bit identical to what works today.
     *
     *    `transFlag` is deliberately NOT touched even though its third digit is
     *    commonly documented as the attendance-photo bit. That bit ordering is
     *    single-source `[?]`, and digits 1–4 currently enable the attendance,
     *    operation-log and user-enrolment pushes we DO consume — guessing wrong
     *    there would silently switch off a working feature to remove an
     *    invitation the missing Stamp has already removed.
     *
     *    **It stays untouched for template capture too, and the reason is now
     *    stronger than "unsourced".** `transFlag` was the obvious suspect for why
     *    the device has never pushed a `table=templatev10` — the leading ones plainly
     *    cover what we receive, so a template bit "must" be one of the zeros. The
     *    best-documented ordering says otherwise, and falsifies the guess rather
     *    than guiding it. That ordering reads:
     *
     *      1 attendance record · 2 operation log · 3 attendance photo ·
     *      4 enrolling a new fingerprint · 5 enrolling a new user ·
     *      6 fingerprint image · 7 changing user information ·
     *      8 changing a fingerprint · 9 new enrolled face · 10 user picture ·
     *      11 work code · 12 comparison photo
     *
     *    Under it, digit 4 — *enrolling a new fingerprint* — is **already 1** in
     *    `1111000000`. The production MB460 has logged 13 fingerprint enrolments and
     *    pushed zero templates with that bit set, so the single most plausible
     *    candidate is already enabled and demonstrably insufficient. Every remaining
     *    zero would be a pure guess, and three further problems compound it:
     *
     *      - Sources disagree on the ordering itself. The named-token form devices
     *        report is quoted elsewhere as `TransData AttLog OpLog AttPhoto
     *        EnrollUser ChgUser EnrollFP ChgFP UserPic`, which puts EnrollFP at 7,
     *        not 4 — conflicting with the numeric list at exactly the positions we
     *        would be editing.
     *      - Sources disagree on the LENGTH. A distributor guide shows
     *        `TransFlag=111111111111`, twelve characters; ours is ten. We do not know
     *        whether this firmware pads, truncates, or rejects.
     *      - The numeric-ordering citations are not independent; they trace back to
     *        one protocol document.
     *
     *    Against that, the correlation our OWN production system shows is clean and
     *    points somewhere else entirely: we advertise `ATTLOGStamp` and
     *    `OPERLOGStamp` and we receive exactly ATTLOG and OPERLOG. `*Stamp` is the
     *    per-table invitation — the same reasoning that removed `ATTPHOTOStamp`
     *    above — and no template stamp key is established in any source, so there is
     *    no safe key to add here either. Capture is therefore solved by ASKING:
     *    `QUERY_FINGERTMP` emits `DATA QUERY FINGERTMP`, which a wrong guess merely
     *    gets rejected by one device, whereas a wrong digit here stops attendance
     *    collection for a live business. What a hardware probe would need to settle
     *    before this line may move is recorded in matrix §3.
     *
     *  - **`PushProtVer` is negotiated, not asserted** — see
     *    negotiatePushProtoVersion().
     *
     * @param  string|null  $announcedPushVer  the `pushver` the device sent on this
     *                                         init handshake, if any
     */
    public function buildHandshakeOptionsBody(string $serialNumber, ?string $announcedPushVer = null): string
    {
        $attlogStamp = 9999;

        $device = $this->findDeviceBySerial($serialNumber);
        if ($device) {
            $hasPendingCommand = BiometricDeviceCommand::where('biometric_device_id', $device->id)
                ->where('command_type', 'CHECK_ATTLOG')
                ->where('status', 'pending')
                ->exists();

            $hasActiveSession = BiometricDownloadSession::where('biometric_device_id', $device->id)
                ->whereIn('status', ['pending', 'in_progress'])
                ->exists();

            if ($hasPendingCommand || $hasActiveSession) {
                $attlogStamp = 0;
            }
        }

        return implode("\r\n", [
            "GET OPTION FROM: {$serialNumber}",
            "ATTLOGStamp={$attlogStamp}",
            'OPERLOGStamp=9999',
            'errorDelay=30',
            'delay=10',
            'transTimes=00:00;14:05',
            'transFlag=1111000000',
            'encrypt=None',
            'ServerVer='.self::DEFAULT_PUSH_PROTO_VER,
            'PushProtVer='.$this->negotiatePushProtoVersion($announcedPushVer, $serialNumber),
            '',
        ]);
    }

    /**
     * Decide which push protocol version to claim back at the device.
     *
     * The device announces its own `pushver` on every init handshake and we then
     * ignored it and asserted 2.4.1 — a version the terminal never agreed to.
     * Echoing what it announced is the conservative direction of the two: the
     * failure mode that actually breaks a live terminal is claiming a HIGHER
     * protocol version than it speaks, because the device then expects keys and
     * behaviours we do not implement. Agreeing with the device cannot do that.
     *
     * Guards, because this value is device-supplied and goes straight onto the
     * wire on the one request that keeps attendance collection alive:
     *
     *  - only a strict dotted-numeric version is echoed. Anything else — empty,
     *    absent, `Ver 2.0.33S-20220623` (that is the FIRMWARE push version the
     *    MB460 reports, not the protocol version), or an injection attempt with
     *    CRLF in it — falls back to the hardcoded default, i.e. exactly today's
     *    behaviour.
     *  - the fallback is the current constant, so a device that announces
     *    nothing sees a byte-identical handshake to the one working in prod.
     */
    public function negotiatePushProtoVersion(?string $announced, ?string $serialNumber = null): string
    {
        $candidate = trim((string) $announced);

        if ($candidate === '' || ! preg_match('/^\d{1,3}(\.\d{1,3}){0,3}$/', $candidate)) {
            return self::DEFAULT_PUSH_PROTO_VER;
        }

        if ($candidate !== self::DEFAULT_PUSH_PROTO_VER) {
            Log::info('ADMS handshake: echoing device-announced push protocol version', [
                'serial' => $serialNumber,
                'announced_pushver' => $candidate,
                'default' => self::DEFAULT_PUSH_PROTO_VER,
            ]);
        }

        return $candidate;
    }

    // ──────────────────────────────────────────────────────────────
    //  ADMS push: attendance log processing
    // ──────────────────────────────────────────────────────────────

    /**
     * Map a raw ADMS check-type code to a human-readable check type.
     */
    public function mapAdmsCheckType(string $rawCheckType): string
    {
        return match ((string) $rawCheckType) {
            '0' => 'in',
            '1' => 'out',
            '2' => 'break_out',
            '3' => 'break_in',
            '4' => 'ot_in',
            '5' => 'ot_out',
            'I', 'i' => 'in',
            'O', 'o' => 'out',
            default => 'in',
        };
    }

    /**
     * Process bulk attendance log lines from an ADMS push.
     *
     * ── Device clock correction (DeviceClockService) ─────────────────────────
     *
     * Two things happen here that did not before, and they are deliberately in
     * this order:
     *
     *  1. **Measure, from this push, before anything is written.** A live push is
     *     the device reporting a punch as it happens, so `punch_time` minus the
     *     moment this request is being handled IS its clock error. One sample per
     *     push (see recordClockSampleFromPush) and only on the live branch — a
     *     download session replays history, and the same subtraction there would
     *     read as a fake offset of days. Sampling first rather than last means
     *     the newest evidence is already in the median when this push's own
     *     punches are corrected, which is what makes a repaired device stop being
     *     corrected on the very next push instead of the one after.
     *  2. **Correct at the point of ingest.** The corrected moment is what goes to
     *     the punch service and therefore into `attendances`; the RAW device
     *     timestamp stays in `biometric_att_logs.punch_time` and the correction is
     *     recorded beside it. That split is what keeps re-correction impossible:
     *     every correction is computed from an immutable raw value, never from a
     *     previously corrected one.
     *
     * @return array{processed: int, errors: int, duplicates: int, total_lines: int}
     */
    public function processAttendanceLogs(string $rawData, BiometricDevice $device, string $serialNumber): array
    {
        $lines = explode("\n", trim($rawData));
        $processedCount = 0;
        $errorCount = 0;
        $duplicateCount = 0;

        $session = BiometricDownloadSession::where('biometric_device_id', $device->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();
        $isDownloading = ! is_null($session);

        if (! $isDownloading) {
            $this->recordClockSampleFromPush($device, $lines, $serialNumber);
        }

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // Parse tab-separated format
            $parts = explode("\t", trim($line));
            $data = [];
            if (count($parts) >= 2) {
                $data['PIN'] = $parts[0] ?? null;
                $data['DateTime'] = $parts[1] ?? null;
                $data['Status'] = $parts[2] ?? '0';
                $data['VerifyCode'] = $parts[3] ?? null;
                $data['WorkCode'] = $parts[4] ?? null;
            }

            Log::info('ADMS push: parsing ATTLOG line', [
                'serial' => $serialNumber,
                'line' => $line,
                'parsed_data' => $data,
            ]);

            $hasUserId = ! empty($data['PIN']);
            $hasCheckTime = ! empty($data['DateTime']);

            // Skip lines that don't match ATTLOG format
            if (! $hasUserId || ! $hasCheckTime) {
                $errorCount++;
                Log::warning('ADMS push: line does not match ATTLOG format', [
                    'serial' => $serialNumber,
                    'line' => $line,
                    'parsed_data' => $data,
                    'has_user_id' => $hasUserId,
                    'has_check_time' => $hasCheckTime,
                ]);

                continue;
            }

            $deviceUserId = trim($data['PIN'] ?? '');
            $checkTime = trim($data['DateTime'] ?? '');
            $rawCheckType = trim($data['Status'] ?? '0');
            $checkType = $this->mapAdmsCheckType($rawCheckType);

            if ($isDownloading) {
                $user = User::withTrashed()->where('employee_id', $deviceUserId)->first();

                $stagedId = $this->insertAttLogRow([
                    'biometric_device_id' => $device->id,
                    'serial_number' => $serialNumber,
                    'user_pin' => $deviceUserId,
                    'user_id' => $user ? $user->id : null,
                    'punch_time' => $checkTime,
                    'check_type' => $checkType,
                    'punch_status' => 'downloaded',
                    'punch_status_reason' => 'Downloaded via active sync session',
                    'verify_code' => $data['VerifyCode'] ?? null,
                    'work_code' => $data['WorkCode'] ?? null,
                    'raw_data' => $line,
                    'context' => json_encode($data),
                    'occurred_at' => $checkTime,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Already staged by an earlier download of the same window. This
                // is the 48k-duplicate case: every CHECK_ATTLOG made the device
                // re-send punches we had already captured, and the old plain
                // insert() staged each one again.
                if ($stagedId === null) {
                    $duplicateCount++;

                    Log::debug('ADMS push: punch already staged — re-download ignored', [
                        'serial' => $serialNumber,
                        'device_user_id' => $deviceUserId,
                        'check_time' => $checkTime,
                        'check_type' => $checkType,
                    ]);

                    continue;
                }

                $processedCount++;

                continue;
            }

            // Device clock correction. `$checkTime` stays the device's own raw
            // string from here on — it is what identifies the punch (and what
            // the unique natural key is built from). `$punchMoment` is the
            // corrected moment everything downstream acts on: the duplicate
            // check reads it because that is what `attendances` now holds, and
            // the punch service receives it so its future-punch guard sees the
            // corrected time rather than the device's skewed one.
            $correction = $this->clockService->correct($device, $checkTime);
            $punchMoment = $correction['punch_time'];

            // Resolve user by matching PIN to employee_id
            $resolved = $this->resolveOrCreateUser($deviceUserId);
            $user = $resolved['user'];

            if ($resolved['is_new']) {
                $attLogStatus = 'unknown_user';
                $attLogReason = 'Auto-created as inactive placeholder';

                Log::info('ADMS push: auto-created inactive user', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'new_user_id' => $user->id,
                ]);
            } else {
                $attLogStatus = 'failed';
                $attLogReason = 'Pending processing';
            }

            // Log the punch immediately.
            //
            // `punch_time` and `occurred_at` are the device's raw account and
            // are never rewritten; `corrected_punch_time` /
            // `clock_offset_applied_seconds` say what we did with it, and are
            // NULL when nothing was corrected. An auditor can therefore see both
            // what the terminal claimed and what landed in attendance.
            $logId = $this->insertAttLogRow([
                'biometric_device_id' => $device->id,
                'serial_number' => $serialNumber,
                'user_pin' => $deviceUserId,
                'user_id' => $user->id,
                'punch_time' => $checkTime,
                'corrected_punch_time' => $correction['applied'] ? $punchMoment : null,
                'clock_offset_applied_seconds' => $correction['applied'] ? $correction['applied_seconds'] : null,
                'check_type' => $checkType,
                'punch_status' => $attLogStatus,
                'punch_status_reason' => $attLogReason,
                'verify_code' => $data['VerifyCode'] ?? null,
                'work_code' => $data['WorkCode'] ?? null,
                'raw_data' => $line,
                'context' => json_encode($data),
                'occurred_at' => $checkTime,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // This exact punch is already on record for this device. Previously a
            // second row was written and then walked all the way to
            // isDuplicatePunch() only to be stamped 'duplicate'; now the row is
            // simply never created. The EXISTING row is deliberately left
            // untouched — re-running the pipeline over it would let a re-push
            // overwrite a 'processed' row's status with 'duplicate' and destroy
            // the provenance of a punch that really was recorded.
            if ($logId === null) {
                $duplicateCount++;

                Log::info('ADMS push: punch already recorded for this device — re-push ignored', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    'check_type' => $checkType,
                ]);

                continue;
            }

            // If user was just auto-created, skip further processing
            if ($attLogStatus === 'unknown_user') {
                $errorCount++;

                continue;
            }

            // Validate attendance eligibility
            $eligibility = $this->validateAttendanceEligibility($user, $device);

            if (! $eligibility['valid']) {
                $errorCount++;
                $punchStatus = ($eligibility['reason'] === 'Device not in attendance zone') ? 'wrong_device' : 'failed';
                DB::table('biometric_att_logs')->where('id', $logId)->update([
                    'punch_status' => $punchStatus,
                    'punch_status_reason' => $eligibility['reason'],
                    'updated_at' => now(),
                ]);
                Log::warning('ADMS push: attendance validation failed', [
                    'device_serial' => $serialNumber,
                    'user_id' => $user->id,
                    'reason' => $eligibility['reason'],
                ]);

                continue;
            }

            // Idempotency check — against the CORRECTED moment, because that is
            // what a previous run of this punch would have written to
            // `attendances`.
            if ($this->isDuplicatePunch($user->id, $punchMoment)) {
                $duplicateCount++;
                DB::table('biometric_att_logs')
                    ->where('id', $logId)
                    ->update(['punch_status' => 'duplicate', 'updated_at' => now()]);
                Log::info('ADMS push: duplicate punch skipped', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    'corrected_time' => $correction['applied'] ? $punchMoment : null,
                ]);

                continue;
            }

            // Build synthetic request for punch service
            $syntheticRequest = $this->buildSyntheticPunchRequest($serialNumber, $deviceUserId, $punchMoment, $checkType);

            // Process through existing punch service
            try {
                $result = $this->processPunch($user, $syntheticRequest);

                if ($result['status'] === 'success') {
                    $processedCount++;
                    DB::table('biometric_att_logs')
                        ->where('id', $logId)
                        ->update([
                            'punch_status' => 'processed',
                            // Normally null. Non-null only when the punch service
                            // had to recover the punch rather than pair it — see
                            // recoveryNote(). `check_type` is NOT touched: it keeps
                            // holding the direction byte the terminal sent.
                            'punch_status_reason' => $this->recoveryNote($result),
                            'updated_at' => now(),
                        ]);
                } else {
                    $errorCount++;
                    DB::table('biometric_att_logs')
                        ->where('id', $logId)
                        ->update([
                            'punch_status' => 'failed',
                            'punch_status_reason' => $result['message'] ?? null,
                            'updated_at' => now(),
                        ]);
                }

                Log::info('ADMS punch processed', [
                    'user_id' => $user->id,
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    // Present only when the punch was moved, so a log reader can
                    // tell an adjusted punch from an untouched one at a glance.
                    'corrected_time' => $correction['applied'] ? $punchMoment : null,
                    'clock_offset_applied_seconds' => $correction['applied'] ? $correction['applied_seconds'] : null,
                    'check_type' => $checkType,
                    'result_status' => $result['status'],
                ]);

                event(new BiometricAttendanceReceived($device, $user, [
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    'check_type' => $checkType,
                    'result' => $result,
                ]));
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('ADMS punch error: '.$e->getMessage(), [
                    'user_id' => $user->id,
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                ]);
            }
        }

        Log::info('ADMS push completed', [
            'serial' => $serialNumber,
            'processed' => $processedCount,
            'duplicates_skipped' => $duplicateCount,
            'errors' => $errorCount,
            'total_lines' => count($lines),
        ]);

        // Update active download session if exists
        $session = BiometricDownloadSession::where('biometric_device_id', $device->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($session) {
            $session->update([
                'status' => 'in_progress',
                'total_records' => $session->total_records + count($lines),
                'processed_count' => $session->processed_count + $processedCount,
                'duplicate_count' => $session->duplicate_count + $duplicateCount,
                'failed_count' => $session->failed_count + $errorCount,
                'started_at' => $session->started_at ?? now(),
            ]);

            $device->update(['last_log_download_at' => now()]);
        }

        return [
            'processed' => $processedCount,
            'errors' => $errorCount,
            'duplicates' => $duplicateCount,
            'total_lines' => count($lines),
        ];
    }

    /**
     * Take ONE device-clock sample from a live ATTLOG push.
     *
     * Called only from the live branch of processAttendanceLogs(): a push that
     * arrives while a download session is open is the device replaying history
     * on request, and the gap between those timestamps and now() is the age of
     * the history, not the error in its clock. Sampling those would poison the
     * estimate with hours or days of fake offset, which is exactly the input the
     * median exists to survive — so we do not feed it in the first place.
     *
     * Why one sample per push rather than one per line: a device that has been
     * offline pushes its backlog live, in a single body, oldest to newest. Every
     * line would be a sample and one burst could fill the whole rolling window
     * with punches that are genuinely old. Taking the NEWEST punch in the body
     * bounds a burst's influence to a single sample, and the newest line is also
     * the one most likely to be "just now" — the observation we actually want.
     *
     * The timestamp is only sampled when it has the canonical ATTLOG shape.
     * Anything else is left to the parser downstream, which is tolerant by
     * design; a measurement, unlike a punch, is better skipped than guessed.
     *
     * @param  array<int, string>  $lines
     */
    protected function recordClockSampleFromPush(BiometricDevice $device, array $lines, string $serialNumber): void
    {
        $newest = null;

        foreach ($lines as $line) {
            $parts = explode("\t", trim($line));

            if (count($parts) < 2 || trim($parts[0]) === '') {
                continue;
            }

            $stamp = trim($parts[1]);

            if (! preg_match('/^\d{4}-\d{1,2}-\d{1,2}[ T]\d{1,2}:\d{2}/', $stamp)) {
                continue;
            }

            try {
                $moment = Carbon::parse($stamp);
            } catch (\Throwable $e) {
                continue;
            }

            if ($newest === null || $moment->greaterThan($newest)) {
                $newest = $moment;
            }
        }

        if ($newest === null) {
            return;
        }

        $offset = $this->clockService->recordLiveSample($device, $newest);

        if ($offset === null) {
            return;
        }

        Log::debug('ADMS push: device clock sampled', [
            'serial' => $serialNumber,
            'device_id' => $device->id,
            'sample_offset_seconds' => $offset,
            'device_time' => $newest->toDateTimeString(),
            'estimate_offset_seconds' => $device->clock_offset_seconds,
            'estimate_samples' => $device->clock_offset_samples,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Downloaded log import (second half of the capture-then-import flow)
    // ──────────────────────────────────────────────────────────────

    /**
     * Number of rows pulled per keyset page while importing a session.
     */
    private const IMPORT_CHUNK_SIZE = 250;

    /**
     * How long the per-session import lock is held before it self-expires.
     *
     * Long enough for a large backfill to finish, short enough that a worker
     * killed mid-import unblocks the session within a single 15-minute
     * scheduler tick instead of wedging it until someone intervenes.
     */
    private const IMPORT_LOCK_SECONDS = 600;

    /**
     * Cache key for a session's import lock.
     */
    public static function importLockKey(BiometricDownloadSession $session): string
    {
        return "biometric-import-session-{$session->id}";
    }

    /**
     * Base query for the `downloaded` ATTLOG rows that belong to a session.
     *
     * Scoping intentionally mirrors BiometricDeviceController::getSessionLogs()
     * exactly (same device, punch_status = 'downloaded', created_at inside the
     * session window with a 10 s buffer on both ends) so the read-only session
     * report and this importer always agree on what belongs to a session.
     */
    protected function downloadedLogsQuery(BiometricDownloadSession $session)
    {
        $start = $session->started_at ?? $session->created_at;
        $end = $session->completed_at ?? now();

        return BiometricAttLog::query()
            ->where('biometric_device_id', $session->biometric_device_id)
            ->where('punch_status', 'downloaded')
            ->whereBetween('created_at', [
                $start->copy()->subSeconds(10),
                $end->copy()->addSeconds(10),
            ]);
    }

    /**
     * `downloaded` rows for a device that belong to no download session at all.
     *
     * A session window is the wrong frame for these rows, because they were never
     * captured by a session. Two ways a row ends up here:
     *
     *  - an `unknown_user` punch minted by the LIVE push path (the non-downloading
     *    branch of processAttendanceLogs) and later reset to `downloaded` when an
     *    admin links that PIN to a real employee. Its created_at is the moment the
     *    device pushed the punch, which falls inside no session window — so the
     *    session-scoped importer never selects it and the row sits on `downloaded`
     *    forever. That is a silent data-loss bug: the admin is told the punch was
     *    linked and it still never becomes attendance.
     *  - any row whose owning session has since been deleted.
     *
     * Membership is decided by asking whether ANY session for this device covers
     * the row's created_at, using only column comparisons and COALESCE so MySQL
     * and SQLite take the same path. The ±10 s buffer downloadedLogsQuery() adds
     * is deliberately not mirrored here: a row within 10 s of a session boundary
     * can therefore be claimed by both passes, which is harmless — both run the
     * identical importDownloadedLog() rules, whichever gets there first moves the
     * row off `downloaded`, and isDuplicatePunch() backstops the loser.
     */
    protected function sessionlessDownloadedLogsQuery(BiometricDevice $device)
    {
        return BiometricAttLog::query()
            ->where('biometric_device_id', $device->id)
            ->where('punch_status', 'downloaded')
            ->whereNotExists(function ($query) use ($device) {
                $query->selectRaw('1')
                    ->from('biometric_download_sessions')
                    ->where('biometric_download_sessions.biometric_device_id', $device->id)
                    ->whereRaw(
                        'coalesce(biometric_download_sessions.started_at, biometric_download_sessions.created_at) <= biometric_att_logs.created_at'
                    )
                    ->whereRaw(
                        'coalesce(biometric_download_sessions.completed_at, ?) >= biometric_att_logs.created_at',
                        [now()->toDateTimeString()]
                    );
            });
    }

    /**
     * Promote a download session's captured `downloaded` ATTLOG rows into real
     * attendance records.
     *
     * The ADMS push path deliberately only CAPTURES rows while a download session
     * is active (see the $isDownloading branch in processAttendanceLogs) — it skips
     * user resolution, eligibility, dedupe and the punch service entirely. This is
     * the missing second step: it replays those captured rows through the very same
     * rules the live push path applies.
     *
     * Idempotent: every row is moved off `downloaded` as it is handled, so a second
     * run simply selects nothing; isDuplicatePunch() backstops anything that was
     * captured twice. Rows are replayed in punch_time order because in/out pairing
     * is order-sensitive.
     *
     * Concurrency: the import can now be kicked off from three places at once —
     * the ProcessBiometricDownloadSession job when a session finalises, the
     * every-15-minutes scheduler, and the pre-export sync. withoutOverlapping()
     * only stops the scheduler racing itself, so a per-session lock is what stops
     * two importers reading the same `downloaded` row before either flips its
     * status. Losing the race is not an error: another importer already owns this
     * session, so we return the zeroed contract immediately rather than blocking a
     * queue worker or throwing into a report the user asked for.
     *
     * @return array{imported: int, duplicates: int, failed: int, skipped_unknown: int}
     */
    public function importDownloadedLogs(BiometricDownloadSession $session): array
    {
        $lock = Cache::lock(self::importLockKey($session), self::IMPORT_LOCK_SECONDS);

        if (! $lock->get()) {
            // Deliberately the same shape (and the same zeroes) as "nothing to do".
            // The return contract is consumed by the console command, the job and
            // the export, and existing tests assert the exact 4-key array; a fifth
            // key would be a breaking change for a condition every caller treats
            // identically — they all just move on. The distinction that matters is
            // operational, not control-flow, so it goes to the log instead.
            Log::info('Biometric downloaded-log import skipped: session already being imported', [
                'session_id' => $session->id,
                'biometric_device_id' => $session->biometric_device_id,
            ]);

            return [
                'imported' => 0,
                'duplicates' => 0,
                'failed' => 0,
                'skipped_unknown' => 0,
            ];
        }

        try {
            return $this->runDownloadedLogImport($session);
        } finally {
            $lock->release();
        }
    }

    /**
     * The import itself, run under the per-session lock taken by
     * importDownloadedLogs().
     *
     * @return array{imported: int, duplicates: int, failed: int, skipped_unknown: int}
     */
    protected function runDownloadedLogImport(BiometricDownloadSession $session): array
    {
        $device = $session->device;

        if (! $device) {
            Log::warning('Biometric downloaded-log import: session has no device', [
                'session_id' => $session->id,
                'biometric_device_id' => $session->biometric_device_id,
            ]);

            return [
                'imported' => 0,
                'duplicates' => 0,
                'failed' => 0,
                'skipped_unknown' => 0,
            ];
        }

        $totals = $this->replayDownloadedLogs(
            fn () => $this->downloadedLogsQuery($session),
            $device
        );

        // Second pass: rows this device holds that belong to no session window at
        // all — see sessionlessDownloadedLogsQuery(). They cannot be reached by
        // any session-scoped query, so without this they are never imported.
        $orphans = $this->importSessionlessDownloadedLogs($device);

        foreach ($totals as $key => $value) {
            $totals[$key] = $value + $orphans[$key];
        }

        Log::info('Biometric downloaded-log import completed', [
            'session_id' => $session->id,
            'device_id' => $device->id,
            'serial' => $device->serial_number,
            'imported' => $totals['imported'],
            'duplicates' => $totals['duplicates'],
            'failed' => $totals['failed'],
            'skipped_unknown' => $totals['skipped_unknown'],
            'sessionless_imported' => $orphans['imported'],
        ]);

        return $totals;
    }

    /**
     * Cache key for a device's session-less import sweep.
     */
    public static function sessionlessImportLockKey(BiometricDevice $device): string
    {
        return "biometric-import-sessionless-{$device->id}";
    }

    /**
     * Import the `downloaded` rows a device holds outside every session window.
     *
     * Public so an operator or a future remediation screen can sweep one device
     * without inventing a fake session for rows that never had one; also called
     * as the second pass of every session import, so no existing trigger — the
     * finalising job, the scheduler, the console command, the pre-export sync —
     * needs to know this category exists.
     *
     * Two guards:
     *
     *  - a device with a pending / in-progress session is skipped entirely. The
     *    device may still be pushing, and replaying a half-received batch would
     *    mis-pair in/out punches. This is the same reasoning the console command
     *    already applies when it refuses to import unfinished sessions.
     *  - a device-scoped lock, so two session imports for the same device cannot
     *    sweep the same orphan rows at once.
     *
     * @return array{imported: int, duplicates: int, failed: int, skipped_unknown: int}
     */
    public function importSessionlessDownloadedLogs(BiometricDevice $device): array
    {
        $empty = ['imported' => 0, 'duplicates' => 0, 'failed' => 0, 'skipped_unknown' => 0];

        $deviceIsBusy = BiometricDownloadSession::where('biometric_device_id', $device->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists();

        if ($deviceIsBusy) {
            return $empty;
        }

        $lock = Cache::lock(self::sessionlessImportLockKey($device), self::IMPORT_LOCK_SECONDS);

        if (! $lock->get()) {
            Log::info('Biometric session-less import skipped: device already being swept', [
                'device_id' => $device->id,
            ]);

            return $empty;
        }

        try {
            $totals = $this->replayDownloadedLogs(
                fn () => $this->sessionlessDownloadedLogsQuery($device),
                $device
            );
        } finally {
            $lock->release();
        }

        if (array_sum($totals) > 0) {
            Log::info('Biometric session-less downloaded rows imported', [
                'device_id' => $device->id,
                'serial' => $device->serial_number,
            ] + $totals);
        }

        return $totals;
    }

    /**
     * Replay every `downloaded` row a query selects, in punch_time order.
     *
     * Keyset cursor on (punch_time, id). A plain offset chunk() would skip rows
     * because the result set shrinks as each row is moved off `downloaded`;
     * a keyset cursor is both stable under that mutation and memory-bounded.
     * The query is rebuilt per page for the same reason.
     *
     * @param  callable():Builder  $queryFactory
     * @return array{imported: int, duplicates: int, failed: int, skipped_unknown: int}
     */
    protected function replayDownloadedLogs(callable $queryFactory, BiometricDevice $device): array
    {
        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        $skippedUnknown = 0;

        $cursorPunchTime = null;
        $cursorId = 0;

        while (true) {
            $query = $queryFactory();

            if ($cursorPunchTime !== null) {
                $query->where(function ($q) use ($cursorPunchTime, $cursorId) {
                    $q->where('punch_time', '>', $cursorPunchTime)
                        ->orWhere(function ($q2) use ($cursorPunchTime, $cursorId) {
                            $q2->where('punch_time', $cursorPunchTime)->where('id', '>', $cursorId);
                        });
                });
            }

            $logs = $query->orderBy('punch_time')
                ->orderBy('id')
                ->limit(self::IMPORT_CHUNK_SIZE)
                ->get();

            if ($logs->isEmpty()) {
                break;
            }

            foreach ($logs as $log) {
                $cursorPunchTime = $log->getRawOriginal('punch_time');
                $cursorId = $log->id;

                $outcome = $this->importDownloadedLog($log, $device);

                match ($outcome) {
                    'imported' => $imported++,
                    'duplicate' => $duplicates++,
                    'unknown_user' => $skippedUnknown++,
                    default => $failed++,
                };
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'skipped_unknown' => $skippedUnknown,
        ];
    }

    /**
     * Replay a single captured `downloaded` row through the live punch rules.
     *
     * ── Clock correction on the batch path ──────────────────────────────────
     *
     * A downloaded log carries exactly the same skew as a live push — it came
     * off the same clock — but it cannot be MEASURED the same way, because the
     * gap between its timestamp and the moment we import it is the age of the
     * history, not the device's error. So the batch path consumes the estimate
     * the live path measured, and contributes nothing back to it.
     *
     * The correction is computed here, at import, rather than being frozen onto
     * the row when it was staged: the estimate at import time is the freshest
     * one, and a row that sat in `downloaded` for a week through a device clock
     * repair is corrected by what is true now, not by what was true when it was
     * captured. It is computed from `punch_time`, which is still the device's
     * raw value, so re-running the import over a row can only ever recompute the
     * same correction — never stack a second one on top of the first.
     *
     * @return string one of imported|duplicate|unknown_user|failed
     */
    protected function importDownloadedLog(BiometricAttLog $log, BiometricDevice $device): string
    {
        $serialNumber = $log->serial_number ?: $device->serial_number;
        $deviceUserId = (string) $log->user_pin;
        $checkTime = $log->punch_time instanceof Carbon
            ? $log->punch_time->format('Y-m-d H:i:s')
            : (string) $log->getRawOriginal('punch_time');
        $checkType = $log->check_type ?: 'in';

        $correction = $this->clockService->correct($device, $checkTime);
        $punchMoment = $correction['punch_time'];
        $correctionColumns = [
            'corrected_punch_time' => $correction['applied'] ? $punchMoment : null,
            'clock_offset_applied_seconds' => $correction['applied'] ? $correction['applied_seconds'] : null,
        ];

        try {
            // 1. Resolve the user — same helper the live push path uses.
            $resolved = $this->resolveOrCreateUser($deviceUserId);
            $user = $resolved['user'];

            if ($resolved['is_new']) {
                $this->markAttLog($log->id, 'unknown_user', 'Auto-created as inactive placeholder', $user->id);

                Log::info('Biometric downloaded-log import: auto-created inactive user', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'new_user_id' => $user->id,
                ]);

                return 'unknown_user';
            }

            // 2. Zone / attendance-type eligibility.
            $eligibility = $this->validateAttendanceEligibility($user, $device);

            if (! $eligibility['valid']) {
                $punchStatus = ($eligibility['reason'] === 'Device not in attendance zone') ? 'wrong_device' : 'failed';
                $this->markAttLog($log->id, $punchStatus, $eligibility['reason'], $user->id);

                Log::warning('Biometric downloaded-log import: attendance validation failed', [
                    'device_serial' => $serialNumber,
                    'user_id' => $user->id,
                    'reason' => $eligibility['reason'],
                ]);

                return 'failed';
            }

            // 3. Idempotency backstop for a punch already recorded by any path.
            if ($this->isDuplicatePunch($user->id, $punchMoment)) {
                $this->markAttLog($log->id, 'duplicate', 'Punch already recorded', $user->id, $correctionColumns);

                Log::info('Biometric downloaded-log import: duplicate punch skipped', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    'corrected_time' => $correction['applied'] ? $punchMoment : null,
                ]);

                return 'duplicate';
            }

            // 4. Same synthetic request + punch service as the live path, on the
            //    corrected moment.
            $syntheticRequest = $this->buildSyntheticPunchRequest($serialNumber, $deviceUserId, $punchMoment, $checkType);
            $result = $this->processPunch($user, $syntheticRequest);

            if (($result['status'] ?? null) === 'success') {
                $this->markAttLog($log->id, 'processed', $this->recoveryNote($result), $user->id, $correctionColumns);

                event(new BiometricAttendanceReceived($device, $user, [
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    'check_type' => $checkType,
                    'result' => $result,
                ]));

                return 'imported';
            }

            $this->markAttLog($log->id, 'failed', $result['message'] ?? null, $user->id);

            return 'failed';
        } catch (\Throwable $e) {
            // One bad row must never abort the whole import.
            Log::error('Biometric downloaded-log import error: '.$e->getMessage(), [
                'att_log_id' => $log->id,
                'device_serial' => $serialNumber,
                'device_user_id' => $deviceUserId,
                'check_time' => $checkTime,
            ]);

            try {
                $this->markAttLog($log->id, 'failed', Str::limit($e->getMessage(), 200));
            } catch (\Throwable) {
                // A failed status write must not escalate either — the keyset cursor
                // has already advanced past this row, so the import still terminates.
            }

            return 'failed';
        }
    }

    /**
     * What to write into `punch_status_reason` for a SUCCESSFUL punch.
     *
     * Null for the overwhelming majority — a processed punch that paired normally
     * has nothing to explain, and stamping every row with prose would make the
     * ATTLOG screen unreadable and hide the rows that do.
     *
     * Non-null only when AttendancePunchService reports that it recovered the
     * punch instead of pairing it (today: an OUT-first day, see
     * AttendancePunchService::RECOVERY_OUT_FIRST). That row's `check_type` still
     * says `out`, because that is what the terminal sent and it is part of the
     * punch natural key; this column is where "…and here is why it nonetheless
     * became a check-in" lives. The authoritative record is the
     * `attendance_audit_logs` row the punch service writes on every ingest path;
     * this is the same fact made visible from the device side.
     *
     * @param  array<string, mixed>  $result  the punch service's return value
     */
    protected function recoveryNote(array $result): ?string
    {
        if (empty($result['recovery'])) {
            return null;
        }

        return $result['recovery_reason'] ?? null;
    }

    /**
     * Replay ONE already-staged ATTLOG row through the live punch rules.
     *
     * Exposed for `biometric:replay-orphaned-punches`, which reprocesses punches
     * that were captured and then REJECTED — `punch_status = 'failed'` — rather
     * than punches still sitting on `downloaded`. The rules that should apply to
     * them are identical in every respect (user resolution, zone/attendance-type
     * eligibility, clock correction recomputed from the immutable raw timestamp,
     * the duplicate backstop, the punch service itself, and the status write), so
     * this deliberately delegates instead of the command growing its own copy.
     * A second implementation of these rules is exactly how a replay path drifts
     * away from the ingest path it is supposed to be replaying.
     *
     * Safe to call twice on the same row: importDownloadedLog() recomputes the
     * clock correction from the raw `punch_time` rather than compounding it, and
     * isDuplicatePunch() rejects a punch that already reached `attendances`.
     *
     * @return string one of imported|duplicate|unknown_user|failed
     */
    public function replayAttLog(BiometricAttLog $log, BiometricDevice $device): string
    {
        return $this->importDownloadedLog($log, $device);
    }

    /**
     * Move an ATTLOG row off `downloaded` and onto its resolved status.
     *
     * `$extra` carries columns the caller resolved alongside the status — today
     * that is the clock correction (`corrected_punch_time`,
     * `clock_offset_applied_seconds`), written in the same statement as the
     * status so a row can never claim to be `processed` without saying which
     * moment was processed.
     *
     * @param  array<string, mixed>  $extra
     */
    protected function markAttLog(int $logId, string $status, ?string $reason = null, ?string $userId = null, array $extra = []): void
    {
        $update = $extra + [
            'punch_status' => $status,
            'punch_status_reason' => $reason,
            'updated_at' => now(),
        ];

        if ($userId !== null) {
            $update['user_id'] = $userId;
        }

        DB::table('biometric_att_logs')->where('id', $logId)->update($update);
    }

    // ──────────────────────────────────────────────────────────────
    //  ADMS push: OPERLOG processing
    // ──────────────────────────────────────────────────────────────

    /**
     * Store OPERLOG entries for audit trail.
     */
    public function storeOperLog(string $rawData, string $serialNumber, ?BiometricDevice $device): void
    {
        $lines = explode("\n", trim($rawData));

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            $data = [];
            $operationType = null;
            $userPin = null;
            $occurredAt = now();

            // Check if line starts with OPLOG (space-separated format)
            if (str_starts_with(trim($line), 'OPLOG')) {
                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 4) {
                    $data = [
                        'type' => 'OPLOG',
                        'operation' => $parts[1] ?? null,
                        'pin' => $parts[2] ?? null,
                        'datetime' => $parts[3] ?? null,
                        'result' => $parts[4] ?? null,
                        'params' => array_slice($parts, 5),
                    ];
                    $operationType = $this->getOperLogName($parts[1] ?? '0');
                    $userPin = $parts[2] ?? null;
                    $rawOccurredAt = $parts[3] ?? null;
                    $occurredAt = $rawOccurredAt ? Carbon::parse($rawOccurredAt) : now();
                }
            } else {
                // Parse key=value format (FP, USER, etc.)
                if (preg_match_all('/([^=\t\n]+)=([^\t\n]*)/', $line, $matches)) {
                    $data = array_combine($matches[1], $matches[2]);
                }
                $operationType = $data['Operation'] ?? $data['operation'] ?? $data['type'] ?? null;
                $userPin = $data['PIN'] ?? $data['pin'] ?? null;
                $rawOccurredAt2 = $data['DateTime'] ?? $data['dateTime'] ?? null;
                $occurredAt = $rawOccurredAt2 ? Carbon::parse($rawOccurredAt2) : now();
            }

            DB::table('biometric_oper_logs')->insert([
                'biometric_device_id' => $device ? $device->id : null,
                'serial_number' => $serialNumber,
                'raw_data' => $line,
                'operation_type' => $operationType,
                'user_pin' => $userPin,
                'context' => json_encode($data),
                'occurred_at' => $occurredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Log::info('ADMS push: OPERLOG stored', [
            'serial' => $serialNumber,
            'entries_count' => count($lines),
        ]);
    }

    /**
     * Operation type stamped on a persisted `table=errorlog` entry.
     *
     * A distinct, non-numeric value so device faults are trivially separable
     * from OPLOG codes in the same table and in the admin log view.
     */
    public const ERRORLOG_OPERATION_TYPE = 'Device Error';

    /**
     * Hard cap on errorlog lines persisted from a single push.
     *
     * A terminal in a fault loop can push the same error thousands of times.
     * Diagnostics do not need all of them, and an unbounded write path driven by
     * a misbehaving device is how a shared-hosting database fills up.
     */
    public const ERRORLOG_MAX_LINES = 200;

    /**
     * Persist a `table=errorlog` push (matrix §1).
     *
     * Why this is stored and `rtlog` is not: errorlog is the device reporting its
     * own faults — the one device→server table that says something we cannot
     * learn any other way, and exactly what you want in hand when a terminal
     * stops collecting. It is also low volume by nature. `rtlog` is the opposite:
     * a realtime mirror of punches we already receive on ATTLOG, so persisting it
     * would duplicate attendance data at the highest volume of any table on the
     * protocol, for no information gain.
     *
     * Reuses `biometric_oper_logs` rather than adding a table: same shape (device,
     * serial, raw line, parsed context, occurred_at), same retention question, and
     * the admin log endpoint already reads it, so faults surface with no UI work.
     *
     * This is storage only. Nothing here can reach the attendance parser — the
     * controller's table allowlist sends only ATTLOG and the legacy untabled push
     * there — and no row written here ever becomes attendance.
     */
    public function storeErrorLog(string $rawData, string $serialNumber, ?BiometricDevice $device): int
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", trim($rawData))),
            fn (string $line) => $line !== ''
        ));

        $overflow = max(0, count($lines) - self::ERRORLOG_MAX_LINES);
        $lines = array_slice($lines, 0, self::ERRORLOG_MAX_LINES);
        $stored = 0;

        foreach ($lines as $line) {
            $data = [];

            // Same key=value shape OPERLOG uses for its non-OPLOG entries; a
            // device that sends a bare, unparseable string still gets its raw
            // line stored, which is the part that matters for diagnostics.
            if (preg_match_all('/([^=\t\n]+)=([^\t\n]*)/', $line, $matches)) {
                $data = array_combine($matches[1], $matches[2]);
            }

            $rawOccurredAt = $data['DateTime'] ?? $data['dateTime'] ?? $data['time'] ?? null;

            try {
                $occurredAt = $rawOccurredAt ? Carbon::parse($rawOccurredAt) : now();
            } catch (\Exception $e) {
                $occurredAt = now();
            }

            DB::table('biometric_oper_logs')->insert([
                'biometric_device_id' => $device?->id,
                'serial_number' => $serialNumber,
                'raw_data' => $line,
                'operation_type' => self::ERRORLOG_OPERATION_TYPE,
                'user_pin' => $data['PIN'] ?? $data['pin'] ?? null,
                'context' => json_encode($data),
                'occurred_at' => $occurredAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $stored++;
        }

        Log::warning('ADMS push: device reported an error log', [
            'serial' => $serialNumber,
            'device_id' => $device?->id,
            'entries_stored' => $stored,
            'entries_dropped' => $overflow,
        ]);

        return $stored;
    }

    /**
     * Convert OPLOG operation code to human-readable name.
     */
    public function getOperLogName(string $code): string
    {
        return match ((string) $code) {
            '0' => 'Verify',
            '1' => 'Finger',
            '2' => 'Face',
            '3' => 'Card',
            '4' => 'Password',
            '5' => 'General',
            '6' => 'Enroll User',
            '7' => 'Enroll FP',
            '8' => 'Enroll Face',
            '9' => 'Enroll Card',
            '10' => 'Enroll Password',
            '12' => 'Delete User',
            '13' => 'Delete FP',
            '14' => 'Delete Face',
            '15' => 'Delete Card',
            '16' => 'Delete Password',
            '30' => 'Enroll FP',
            '70' => 'Verify FP',
            '151' => 'Super Admin',
            default => 'Unknown',
        };
    }

    // ──────────────────────────────────────────────────────────────
    //  ADMS push: user enrollment
    // ──────────────────────────────────────────────────────────────

    /**
     * Handle user enrollment from device (Device → System sync).
     *
     * @return array{success: bool, reason: string|null}
     */
    public function processUserEnrollment(string $content, string $serialNumber, BiometricDevice $device): array
    {
        // Parse user enrollment data
        // Format: PIN=42\tName=John Doe\tCard=123456\tPrivilege=0
        $pattern = '/PIN=(?P<pin>\d+).*?Name=(?P<name>[^\t\n]+).*?Card=(?P<card>[^\t\n]*)/s';

        if (! preg_match($pattern, $content, $matches)) {
            Log::warning('User enrollment: invalid format');

            return ['success' => false, 'reason' => 'invalid_format'];
        }

        $pin = $matches['pin'];
        $name = trim($matches['name']);

        try {
            DB::beginTransaction();

            // Try to find existing system user by employee_id (PIN)
            $existingUser = User::where('employee_id', $pin)->first();

            if ($existingUser) {
                DB::rollBack();
                Log::info('User enrollment: user already exists with employee_id', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $pin,
                    'system_user_id' => $existingUser->id,
                    'system_user_name' => $existingUser->name,
                ]);

                return ['success' => true, 'reason' => 'already_exists'];
            }

            // Create placeholder account as INACTIVE
            $newUser = User::create([
                'name' => $name,
                'email' => 'device-auto-'.$pin.'@device-auto.local',
                'user_name' => 'device-auto-'.$pin,
                'password' => bcrypt(Str::random(32)),
                'active' => false,
                'employee_id' => $pin,
            ]);

            Log::info('User enrollment: created inactive system user (pending admin approval)', [
                'device_serial' => $serialNumber,
                'device_user_id' => $pin,
                'system_user_id' => $newUser->id,
                'system_user_name' => $newUser->name,
            ]);

            DB::commit();

            return ['success' => true, 'reason' => 'created'];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process user enrollment', [
                'device_serial' => $serialNumber,
                'device_user_id' => $pin,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'reason' => 'exception'];
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  ADMS push: template upload
    // ──────────────────────────────────────────────────────────────

    /**
     * Handle biometric template uploads from device (for biometric roaming).
     *
     * ── The multi-finger defect this method used to have ────────────────────
     *
     * It captured exactly two fields — `USERID` and `TMP` — with
     * `/USERID=(?P<userid>\d+).*?TMP=(?P<template>[a-zA-Z0-9+\/=\s]+)/s`, and
     * wrote them with an `updateOrInsert` keyed on (device_user_id,
     * biometric_device_id, template_type). `FID`, the finger index the device
     * sends in every fingerprint push, was never even looked for. So a person's
     * SECOND enrolled finger overwrote their first: one row per person per
     * device, whichever finger arrived last, restored into slot 0.
     *
     * That is not a cosmetic loss. The live MB460 (`AF6P231260266`) holds 26
     * fingerprints across 13 employees — two each — so the roaming restore built
     * to protect them could return roughly half, without saying so, at exactly
     * the moment somebody was depending on it.
     *
     * Two things were wrong and both are fixed here:
     *
     *  1. `FID` is parsed and persisted, and the write is keyed per finger slot.
     *  2. A push carrying MORE THAN ONE template is now stored as more than one
     *     row. The old regex could not do this even in principle: `.*?TMP=`
     *     matched the first template marker and the `[a-zA-Z0-9+/=\s]+` class
     *     then swallowed every following line — separators, `USERID=`, digits
     *     and all, since every one of those characters is in the class — so a
     *     two-finger push produced ONE row whose template was the concatenation
     *     of the whole remaining body. Multi-finger enrolment is precisely when
     *     a device sends several records at once, so fixing FID without fixing
     *     this would have fixed nothing.
     *
     * ── Parsing ─────────────────────────────────────────────────────────────
     *
     * Field order is not assumed. The old pattern required `USERID` to precede
     * `TMP`; real firmware is inconsistent about ordering, so each descriptor
     * field is now matched independently. What IS assumed is that `TMP` comes
     * last, which is the one ordering every source agrees on and the only one
     * that can be relied upon — a base64 blob may contain the characters of any
     * other key, so nothing after it can be parsed safely.
     *
     * ── This is also where a QUERY_FINGERTMP reply lands ────────────────────
     *
     * `DATA QUERY FINGERTMP` (BiometricDeviceCommand::toAdmsString()) returns its
     * results as a `table=templatev10` PUSH, not in the acknowledgement — exactly
     * as `DATA QUERY USERINFO` returns a `table=USERINFO` push. So a Return=0 on
     * that command proves only that the query was accepted; this method is what
     * decides whether anything is actually kept, and rows in
     * `biometric_templates` are the only evidence that capture works.
     *
     * The reply shape is the multi-record one: a full-device dump is many
     * templates in one body, and a single person is typically two fingers. That
     * is the shape the rewrite above exists to handle, and it is pinned by
     * tests/Feature/Biometric/TemplateAcquisitionTest.php against both spellings
     * of the PIN field, tab-separated descriptors, and CRLF line endings.
     *
     * One known boundary, recorded rather than guessed at: records are separated
     * by LINE, and a firmware that returned several complete templates on a
     * single line with no newline between them would be read as one record. Every
     * observed ADMS table (ATTLOG, OPERLOG, USERINFO) is line-per-record, so that
     * shape is not expected — but a first real reply from an MB460 is worth
     * eyeballing before it is trusted, because the failure would be a stored row
     * that looks fine and restores garbage.
     *
     * @return array{success: bool, reason: string|null, stored: int, skipped: int}
     */
    public function processTemplateUpload(string $content, string $table, string $serialNumber, BiometricDevice $device): array
    {
        $records = $this->parseTemplateRecords($content);

        if ($records === []) {
            Log::warning('Template upload: invalid format', ['table' => $table]);

            return ['success' => false, 'reason' => 'invalid_format', 'stored' => 0, 'skipped' => 0];
        }

        // Determine template type based on table
        $templateType = $table === 'templatev10' ? 'fingerprint' : 'face';

        $stored = 0;
        $missingUser = 0;
        $failed = 0;

        foreach ($records as $record) {
            // Resolve system user by employee_id (PIN)
            $systemUser = User::where('employee_id', $record['userid'])->first();

            if (! $systemUser) {
                $missingUser++;

                Log::warning('Template upload: no system user for device PIN', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $record['userid'],
                ]);

                continue;
            }

            $fingerIndex = $this->resolveFingerIndex($templateType, $record['fid']);

            try {
                $this->storeTemplateRow(
                    [
                        'device_user_id' => $record['userid'],
                        'biometric_device_id' => $device->id,
                        'template_type' => $templateType,
                        'finger_index' => $fingerIndex,
                    ],
                    [
                        'user_id' => $systemUser->id,
                        'template_data' => $record['template'],
                        'template_size' => strlen($record['template']),
                        'template_version' => $table,
                    ]
                );

                $stored++;

                Log::info('Biometric template saved', [
                    'device_serial' => $serialNumber,
                    'user_id' => $record['userid'],
                    'template_type' => $templateType,
                    // The slot this landed in, and whether the device told us or
                    // we fell back. Without this a silent collapse back to one
                    // finger per person would be invisible in the logs again.
                    'finger_index' => $fingerIndex,
                    'finger_index_reported' => $record['fid'] !== null,
                    'template_size' => strlen($record['template']),
                ]);
            } catch (\Exception $e) {
                $failed++;

                Log::error('Failed to save biometric template', [
                    'device_serial' => $serialNumber,
                    'user_id' => $record['userid'],
                    'finger_index' => $fingerIndex,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($failed > 0) {
            // Answered as a failure even when some records landed, so the device
            // re-pushes the batch. Re-pushing is safe: every write is keyed on
            // the finger slot, so a replay updates rows rather than duplicating
            // them. Reporting success here would lose a template silently, which
            // is the whole class of bug this method is being fixed for.
            return ['success' => false, 'reason' => 'exception', 'stored' => $stored, 'skipped' => $missingUser + $failed];
        }

        if ($stored === 0) {
            // Nothing stored and nothing broken: every record belonged to a PIN
            // we have no user for. Answered OK — the device is behaving correctly
            // and must not be made to retry a push we will never accept.
            return ['success' => true, 'reason' => 'no_user', 'stored' => 0, 'skipped' => $missingUser];
        }

        return ['success' => true, 'reason' => 'saved', 'stored' => $stored, 'skipped' => $missingUser];
    }

    /**
     * Split a template push into one record per template, and pull the fields we
     * store out of each.
     *
     * Record boundaries: a line that opens a new `USERID=`/`PIN=` field starts a
     * new record; anything else is appended to the record in progress. That
     * append is what preserves the old parser's tolerance of whitespace inside a
     * template — the previous regex allowed `\s` inside `TMP`, so a firmware that
     * wraps base64 across lines kept working, and it still does.
     *
     * Within a record, everything from the `TMP=` field to the end of the record
     * is the template, and everything before it is the descriptor. Splitting
     * there is what makes the descriptor safe to parse with simple per-field
     * patterns in any order: a base64 payload can contain the literal text of any
     * other key (`FID=`, `PIN=`), so no field may be matched across it.
     *
     * @return list<array{userid: string, fid: int|null, template: string}>
     */
    protected function parseTemplateRecords(string $content): array
    {
        $records = [];

        foreach ($this->splitTemplateRecords($content) as $raw) {
            // Everything after the first TMP= that opens a field. `(?:^|\s)`
            // rather than `\b` so a `TMP=` occurring inside a value can never be
            // mistaken for the field marker.
            $parts = preg_split('/(?:^|\s)TMP=/i', $raw, 2);

            if ($parts === false || count($parts) < 2) {
                continue;
            }

            [$descriptor, $template] = $parts;

            // Whitespace is stripped, not merely trimmed: the roaming command is
            // tab-separated, so a tab or newline surviving inside a template
            // would split one command field into two on the device.
            $template = preg_replace('/\s+/', '', $template);

            if ($template === '') {
                continue;
            }

            // PIN is accepted as a synonym because firmware disagrees about which
            // spelling a template push uses; USERID wins when both are present.
            $userId = $this->matchTemplateField($descriptor, ['USERID', 'PIN']);

            if ($userId === null) {
                continue;
            }

            $records[] = [
                'userid' => $userId,
                'fid' => $this->matchTemplateFingerIndex($descriptor),
                'template' => $template,
            ];
        }

        return $records;
    }

    /**
     * Break a push body into per-template chunks.
     *
     * @return list<string>
     */
    protected function splitTemplateRecords(string $content): array
    {
        $records = [];
        $current = '';

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $opensRecord = (bool) preg_match('/(?:^|\s)(?:USERID|PIN)=/i', $line);

            if ($opensRecord && trim($current) !== '') {
                $records[] = $current;
                $current = '';
            }

            $current .= ($current === '' ? '' : "\n").$line;
        }

        if (trim($current) !== '') {
            $records[] = $current;
        }

        return $records;
    }

    /**
     * First of the given keys present in a record descriptor, as a digit string.
     *
     * @param  list<string>  $keys
     */
    protected function matchTemplateField(string $descriptor, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (preg_match('/(?:^|\s)'.$key.'=(-?\d+)/i', $descriptor, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * The `FID` a device reported, or null when it reported none.
     *
     * A negative index is treated as "not reported": negatives are not part of
     * the 0-9 the protocol defines, and -1 is the stored sentinel meaning "this
     * modality has no finger", which a device value must never be able to forge.
     *
     * An index ABOVE 9 is kept verbatim rather than clamped. Clamping it to 0
     * would let an unexpected value quietly overwrite a real thumb; keeping it
     * stores the template safely in a slot of its own and surfaces the problem
     * later as a device rejecting one restore command, which is the failure we
     * can see.
     */
    protected function matchTemplateFingerIndex(string $descriptor): ?int
    {
        $fid = $this->matchTemplateField($descriptor, ['FID']);

        if ($fid === null || (int) $fid < 0) {
            return null;
        }

        return (int) $fid;
    }

    /**
     * The finger slot a captured template is stored in.
     *
     * Face and palm get NO_FINGER_INDEX. They have no finger, and the value must
     * not be 0, because 0 is a real finger: a face row sharing slot 0 with
     * somebody's thumb is the exact collision the unique key exists to prevent.
     *
     * A fingerprint whose push carried no FID keeps the historical behaviour —
     * slot 0, FALLBACK_FINGER_INDEX, the same slot such a template already
     * restores into. A device that omits FID must still get its template stored.
     */
    protected function resolveFingerIndex(string $templateType, ?int $reportedFid): int
    {
        if ($templateType !== 'fingerprint') {
            return TemplateRoamingService::NO_FINGER_INDEX;
        }

        return $reportedFid ?? TemplateRoamingService::FALLBACK_FINGER_INDEX;
    }

    /**
     * Write one template into its finger slot.
     *
     * `updateOrInsert()` is a SELECT followed by an UPDATE or an INSERT, which is
     * not atomic — and `biometric_templates` now carries a real unique index over
     * the slot key, so two pushes of the same finger arriving together can make
     * the INSERT lose the race and throw. The retry turns that into the UPDATE it
     * was always meant to be. The catch is Laravel's driver-aware
     * UniqueConstraintViolationException, so only a uniqueness rejection is
     * absorbed; a foreign-key or not-null failure shares SQLSTATE 23000 and must
     * keep surfacing.
     *
     * @param  array<string, mixed>  $slot
     * @param  array<string, mixed>  $values
     */
    protected function storeTemplateRow(array $slot, array $values): void
    {
        try {
            DB::table('biometric_templates')->updateOrInsert(
                $slot,
                $values + ['created_at' => now(), 'updated_at' => now()]
            );
        } catch (UniqueConstraintViolationException $e) {
            DB::table('biometric_templates')
                ->where($slot)
                ->update($values + ['updated_at' => now()]);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Command management
    // ──────────────────────────────────────────────────────────────

    /**
     * Create a new command for a biometric device.
     */
    public function createCommand(BiometricDevice $device, array $validated): BiometricDeviceCommand
    {
        $command = BiometricDeviceCommand::create([
            'biometric_device_id' => $device->id,
            'command_type' => $validated['command_type'],
            'payload' => $validated['payload'] ?? null,
            'status' => 'pending',
            'scheduled_at' => $validated['scheduled_at'] ?? null,
        ]);

        Log::info('ADMS command queued', [
            'device_id' => $device->id,
            'device_serial' => $device->serial_number,
            'command_id' => $command->id,
            'command_type' => $command->command_type,
            'scheduled_at' => $command->scheduled_at,
        ]);

        return $command;
    }

    /**
     * Get command history for a device.
     */
    public function getCommandHistory(int $deviceId): array
    {
        $commands = BiometricDeviceCommand::where('biometric_device_id', $deviceId)
            ->orderBy('created_at', 'desc')
            ->get();

        $commandsArray = [];
        foreach ($commands as $cmd) {
            $commandsArray[] = [
                'id' => $cmd->id,
                'command_type' => $cmd->command_type,
                'status' => $cmd->status,
                'payload' => $cmd->payload,
                'return_code' => $cmd->return_code,
                'error_message' => $cmd->error_message,
                'sent_at' => $cmd->sent_at,
                'executed_at' => $cmd->executed_at,
                'scheduled_at' => $cmd->scheduled_at,
                'created_at' => $cmd->created_at,
                'adms_string' => method_exists($cmd, 'toAdmsString') ? $cmd->toAdmsString() : null,
            ];
        }

        return $commandsArray;
    }

    /**
     * Parse and acknowledge a command from raw ADMS data.
     */
    public function acknowledgeCommand(string $rawData, BiometricDevice $device, string $serialNumber): void
    {
        $normalizedData = str_replace(["\n", "\t"], '&', $rawData);
        parse_str($normalizedData, $ackData);

        if (isset($ackData['ID'])) {
            $command = BiometricDeviceCommand::find($ackData['ID']);
            if ($command) {
                $returnCode = $ackData['Return'] ?? '1';
                $command->markAsExecuted($returnCode);
                $this->completeDownloadSessionForCommand($command, $returnCode);

                Log::info('ADMS devicecmd: command acknowledged', [
                    'serial' => $serialNumber,
                    'command_id' => $command->id,
                    'command_type' => $command->command_type,
                    'return_code' => $returnCode,
                ]);
            } else {
                Log::warning('ADMS devicecmd: command not found', [
                    'serial' => $serialNumber,
                    'command_id' => $ackData['ID'],
                ]);
            }
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Operation logs query
    // ──────────────────────────────────────────────────────────────

    /**
     * Get operation logs, optionally filtered by device.
     */
    public function getOperationLogs(?int $deviceId): Collection
    {
        $query = DB::table('biometric_oper_logs')
            ->orderBy('occurred_at', 'desc')
            ->limit(100);

        if ($deviceId) {
            $query->where('biometric_device_id', $deviceId);
        }

        return $query->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'level' => 'info',
                'serial_number' => $log->serial_number,
                'operation_type' => $log->operation_type,
                'user_pin' => $log->user_pin,
                'raw_data' => $log->raw_data,
                'context' => json_decode($log->context ?? '[]', true),
                'created_at' => $log->occurred_at,
            ];
        });
    }

    // ──────────────────────────────────────────────────────────────
    //  ADMS push: command acknowledgment (inline in admsPush)
    // ──────────────────────────────────────────────────────────────

    /**
     * Check whether raw data contains a command acknowledgment.
     */
    public function isCommandAcknowledgment(string $rawData): bool
    {
        return str_contains($rawData, 'ID=') && str_contains($rawData, 'Return=');
    }

    /**
     * Process an inline command acknowledgment from an ADMS push.
     */
    public function processInlineAcknowledgment(string $rawData, Request $request): bool
    {
        $normalizedData = str_replace(["\n", "\t"], '&', $rawData);
        parse_str($normalizedData, $ackData);

        if (! isset($ackData['ID'])) {
            return false;
        }

        $ackSn = $this->getSerialNumber($request);
        $ackDevice = $ackSn ? BiometricDevice::where('serial_number', $ackSn)->first() : null;

        if (! $ackDevice) {
            Log::warning('ADMS acknowledgment: unknown device', ['sn' => $ackSn, 'command_id' => $ackData['ID']]);

            return false;
        }

        $command = BiometricDeviceCommand::where('id', $ackData['ID'])
            ->where('biometric_device_id', $ackDevice->id)
            ->first();

        if ($command) {
            $returnCode = $ackData['Return'] ?? '1';
            $command->markAsExecuted($returnCode);
            $this->completeDownloadSessionForCommand($command, $returnCode);

            Log::info('ADMS command acknowledged', [
                'serial' => $ackSn,
                'command_id' => $command->id,
                'command_type' => $command->command_type,
                'return_code' => $returnCode,
            ]);
        } else {
            Log::warning('ADMS acknowledgment: command not found or device mismatch', [
                'serial' => $ackSn,
                'command_id' => $ackData['ID'],
            ]);
        }

        return true;
    }

    /**
     * Initiate an attendance log download session for a device.
     */
    public function initiateLogDownload(BiometricDevice $device, string $triggerType, ?string $userId = null, ?array $payload = null): BiometricDownloadSession
    {
        if (! $device->is_active) {
            throw new \InvalidArgumentException('Device is inactive.');
        }

        if (! $device->isAdms()) {
            throw new \InvalidArgumentException('Log downloads are only supported for ADMS devices.');
        }

        // Check if there is already an active session to prevent duplicates
        $existing = BiometricDownloadSession::where('biometric_device_id', $device->id)
            ->whereIn('status', ['pending', 'in_progress'])
            ->first();

        if ($existing) {
            // If the existing session is older than 5 minutes, mark it as failed (timeout) and proceed to create a new session
            if ($existing->created_at->lt(now()->subMinutes(5))) {
                $existing->markFailed('Session timed out after 5 minutes of inactivity.');
            } else {
                return $existing;
            }
        }

        return DB::transaction(function () use ($device, $triggerType, $userId, $payload) {
            // 1. Create a pending session
            $session = BiometricDownloadSession::create([
                'biometric_device_id' => $device->id,
                'trigger_type' => $triggerType,
                'status' => 'pending',
                'created_by' => $userId,
            ]);

            // 2. Create the command to tell the device to check attlog
            $command = $this->createCommand($device, [
                'command_type' => 'CHECK_ATTLOG',
                'payload' => $payload,
            ]);

            // 3. Link the command to the session
            $session->update(['command_id' => $command->id]);

            return $session;
        });
    }

    /**
     * Bulk initiate download sessions for multiple devices.
     */
    public function bulkInitiateLogDownload(array $deviceIds, string $triggerType, ?string $userId = null): array
    {
        $devices = BiometricDevice::whereIn('id', $deviceIds)->active()->get();
        $sessions = [];
        $skipped = [];

        foreach ($deviceIds as $id) {
            $device = $devices->firstWhere('id', $id);
            if ($device && $device->isAdms()) {
                try {
                    $sessions[] = $this->initiateLogDownload($device, $triggerType, $userId);
                } catch (\Exception $e) {
                    $skipped[] = ['device_id' => $id, 'reason' => $e->getMessage()];
                }
            } else {
                $skipped[] = [
                    'device_id' => $id,
                    'reason' => $device ? 'Not an ADMS protocol device.' : 'Device not found or inactive.',
                ];
            }
        }

        return [
            'sessions' => $sessions,
            'skipped' => $skipped,
        ];
    }

    /**
     * Query paginated download sessions.
     */
    public function getDownloadSessions(?int $deviceId = null, $perPage = 20, int $page = 1)
    {
        $query = BiometricDownloadSession::with(['device:id,name,serial_number', 'creator:employee_id,name', 'command'])
            ->where('status', '!=', 'failed')
            ->orderBy('created_at', 'desc');

        if ($deviceId) {
            $query->where('biometric_device_id', $deviceId);
        }

        if ($perPage === 'all' || (int) $perPage === -1) {
            return $query->get();
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Complete the download session associated with a command.
     */
    public function completeDownloadSessionForCommand(BiometricDeviceCommand $command, string $returnCode): void
    {
        $session = BiometricDownloadSession::where('command_id', $command->id)->first();
        if (! $session) {
            return;
        }

        if ($returnCode == '0') {
            if ($session->failed_count > 0 && $session->processed_count > 0) {
                $session->markPartial();
            } elseif ($session->failed_count > 0 && $session->processed_count == 0 && $session->total_records > 0) {
                $session->markFailed('Completed with errors. No records were processed successfully.');
            } else {
                $session->markCompleted();
            }
        } else {
            // Decode rather than echoing the raw code: -1004 means the model does not
            // support the command, which is a capability fact, not a device fault.
            // Surfacing "Device returned error code: -1004" sends an admin hunting a
            // failure that will never resolve on that hardware.
            $decoded = BiometricDeviceCommand::decodeReturnCode($returnCode);

            $session->markFailed(
                $decoded['unsupported']
                    ? "Not supported on this model (device returned {$returnCode})."
                    : "Device returned error code: {$returnCode} — {$decoded['label']}."
            );
        }
    }
}
