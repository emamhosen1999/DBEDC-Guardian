<?php

namespace App\Services\Biometric;

use App\Events\BiometricAttendanceReceived;
use App\Events\BiometricDeviceConnected;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricAttLog;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BiometricProcessingService
{
    protected AttendancePunchService $punchService;

    public function __construct(AttendancePunchService $punchService)
    {
        $this->punchService = $punchService;
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
            'name'        => 'Device User ' . $deviceUserId,
            'email'       => 'device_user_' . $deviceUserId . '@placeholder.local',
            'password'    => bcrypt(Str::random(32)),
            'employee_id' => $deviceUserId,
            'active'      => false,
        ]);
        $user->delete(); // soft-delete = inactive

        return ['user' => $user, 'is_new' => true];
    }

    /**
     * Validate that the user has a biometric attendance type and the device is
     * authorised for that attendance zone.
     *
     * @return array{valid: bool, reason: string|null, attendance_type: AttendanceType|null}
     */
    public function validateAttendanceEligibility(User $user, BiometricDevice $device): array
    {
        if (! $user->attendance_type_id) {
            return ['valid' => false, 'reason' => 'User has no attendance type assigned', 'attendance_type' => null];
        }

        $attendanceType = AttendanceType::with('biometricDevices')->find($user->attendance_type_id);

        if (! $attendanceType || ! str_starts_with($attendanceType->slug, 'biometric')) {
            return [
                'valid'  => false,
                'reason' => 'Attendance type is not biometric: ' . ($attendanceType?->slug ?? 'not found'),
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
    public function isDuplicatePunch(int $userId, $punchTime): bool
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
            'device_serial'  => $serialNumber,
            'device_user_id' => $deviceUserId,
            'source'         => 'biometric',
            'punch_time'     => $punchTime,
            'check_type'     => $checkType,
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
        $query = BiometricAttLog::with(['user:id,name,employee_id,profile_image', 'device:id,name,serial_number'])
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
        $stats = DB::table('biometric_att_logs')->selectRaw("
            COUNT(*) as total,
            SUM(punch_status = 'processed')   as processed,
            SUM(punch_status = 'unknown_user') as unknown_user,
            SUM(punch_status IN ('failed','wrong_device','duplicate')) as failed
        ")->first();

        return [
            'total'        => (int) ($stats->total        ?? 0),
            'processed'    => (int) ($stats->processed    ?? 0),
            'unknown_user' => (int) ($stats->unknown_user ?? 0),
            'failed'       => (int) ($stats->failed       ?? 0),
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
     * Build the ADMS handshake options response body.
     */
    public function buildHandshakeOptionsBody(string $serialNumber): string
    {
        return implode("\r\n", [
            "GET OPTION FROM: {$serialNumber}",
            "ATTLOGStamp=9999",
            "OPERLOGStamp=9999",
            "ATTPHOTOStamp=9999",
            "errorDelay=30",
            "delay=10",
            "transTimes=00:00;14:05",
            "transFlag=1111000000",
            "encrypt=None",
            "ServerVer=2.4.1",
            "PushProtVer=2.4.1",
            "",
        ]);
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
            '0'     => 'in',
            '1'     => 'out',
            '2'     => 'break_out',
            '3'     => 'break_in',
            '4'     => 'ot_in',
            '5'     => 'ot_out',
            'I', 'i' => 'in',
            'O', 'o' => 'out',
            default => 'in',
        };
    }

    /**
     * Process bulk attendance log lines from an ADMS push.
     *
     * @return array{processed: int, errors: int, duplicates: int, total_lines: int}
     */
    public function processAttendanceLogs(string $rawData, BiometricDevice $device, string $serialNumber): array
    {
        $lines = explode("\n", trim($rawData));
        $processedCount = 0;
        $errorCount = 0;
        $duplicateCount = 0;

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
                'line'   => $line,
                'parsed_data' => $data,
            ]);

            $hasUserId = !empty($data['PIN']);
            $hasCheckTime = !empty($data['DateTime']);

            // Skip lines that don't match ATTLOG format
            if (! $hasUserId || ! $hasCheckTime) {
                $errorCount++;
                Log::warning('ADMS push: line does not match ATTLOG format', [
                    'serial'        => $serialNumber,
                    'line'          => $line,
                    'parsed_data'   => $data,
                    'has_user_id'   => $hasUserId,
                    'has_check_time' => $hasCheckTime,
                ]);
                continue;
            }

            $deviceUserId = trim($data['PIN'] ?? '');
            $checkTime = trim($data['DateTime'] ?? '');
            $rawCheckType = trim($data['Status'] ?? '0');
            $checkType = $this->mapAdmsCheckType($rawCheckType);

            // Resolve user by matching PIN to employee_id
            $resolved = $this->resolveOrCreateUser($deviceUserId);
            $user = $resolved['user'];

            if ($resolved['is_new']) {
                $attLogStatus = 'unknown_user';
                $attLogReason = 'Auto-created as inactive placeholder';

                Log::info('ADMS push: auto-created inactive user', [
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'new_user_id'    => $user->id,
                ]);
            } else {
                $attLogStatus = 'failed';
                $attLogReason = 'Pending processing';
            }

            // Log the punch immediately
            $logId = DB::table('biometric_att_logs')->insertGetId([
                'biometric_device_id'   => $device->id,
                'serial_number'         => $serialNumber,
                'user_pin'              => $deviceUserId,
                'user_id'               => $user->id,
                'punch_time'            => $checkTime,
                'check_type'            => $checkType,
                'punch_status'          => $attLogStatus,
                'punch_status_reason'   => $attLogReason,
                'verify_code'           => $data['VerifyCode'] ?? null,
                'work_code'             => $data['WorkCode'] ?? null,
                'raw_data'              => $line,
                'context'               => json_encode($data),
                'occurred_at'           => $checkTime,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

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
                    'punch_status'        => $punchStatus,
                    'punch_status_reason' => $eligibility['reason'],
                    'updated_at'          => now(),
                ]);
                Log::warning('ADMS push: attendance validation failed', [
                    'device_serial' => $serialNumber,
                    'user_id'       => $user->id,
                    'reason'        => $eligibility['reason'],
                ]);
                continue;
            }

            // Idempotency check
            if ($this->isDuplicatePunch($user->id, $checkTime)) {
                $duplicateCount++;
                DB::table('biometric_att_logs')
                    ->where('id', $logId)
                    ->update(['punch_status' => 'duplicate', 'updated_at' => now()]);
                Log::info('ADMS push: duplicate punch skipped', [
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time'     => $checkTime,
                ]);
                continue;
            }

            // Build synthetic request for punch service
            $syntheticRequest = $this->buildSyntheticPunchRequest($serialNumber, $deviceUserId, $checkTime, $checkType);

            // Process through existing punch service
            try {
                $result = $this->processPunch($user, $syntheticRequest);

                if ($result['status'] === 'success') {
                    $processedCount++;
                    DB::table('biometric_att_logs')
                        ->where('id', $logId)
                        ->update(['punch_status' => 'processed', 'punch_status_reason' => null, 'updated_at' => now()]);
                } else {
                    $errorCount++;
                    DB::table('biometric_att_logs')
                        ->where('id', $logId)
                        ->update([
                            'punch_status'        => 'failed',
                            'punch_status_reason' => $result['message'] ?? null,
                            'updated_at'          => now(),
                        ]);
                }

                Log::info('ADMS punch processed', [
                    'user_id'        => $user->id,
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time'     => $checkTime,
                    'check_type'     => $checkType,
                    'result_status'  => $result['status'],
                ]);

                event(new BiometricAttendanceReceived($device, $user, [
                    'device_user_id' => $deviceUserId,
                    'check_time'     => $checkTime,
                    'check_type'     => $checkType,
                    'result'         => $result,
                ]));
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('ADMS punch error: ' . $e->getMessage(), [
                    'user_id'        => $user->id,
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                ]);
            }
        }

        Log::info('ADMS push completed', [
            'serial'             => $serialNumber,
            'processed'          => $processedCount,
            'duplicates_skipped' => $duplicateCount,
            'errors'             => $errorCount,
            'total_lines'        => count($lines),
        ]);

        return [
            'processed'   => $processedCount,
            'errors'      => $errorCount,
            'duplicates'  => $duplicateCount,
            'total_lines' => count($lines),
        ];
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
                        'type'      => 'OPLOG',
                        'operation' => $parts[1] ?? null,
                        'pin'       => $parts[2] ?? null,
                        'datetime'  => $parts[3] ?? null,
                        'result'    => $parts[4] ?? null,
                        'params'    => array_slice($parts, 5),
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
                'serial_number'       => $serialNumber,
                'raw_data'            => $line,
                'operation_type'      => $operationType,
                'user_pin'            => $userPin,
                'context'             => json_encode($data),
                'occurred_at'         => $occurredAt,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }

        Log::info('ADMS push: OPERLOG stored', [
            'serial'        => $serialNumber,
            'entries_count' => count($lines),
        ]);
    }

    /**
     * Convert OPLOG operation code to human-readable name.
     */
    public function getOperLogName(string $code): string
    {
        return match ((string) $code) {
            '0'   => 'Verify',
            '1'   => 'Finger',
            '2'   => 'Face',
            '3'   => 'Card',
            '4'   => 'Password',
            '5'   => 'General',
            '6'   => 'Enroll User',
            '7'   => 'Enroll FP',
            '8'   => 'Enroll Face',
            '9'   => 'Enroll Card',
            '10'  => 'Enroll Password',
            '12'  => 'Delete User',
            '13'  => 'Delete FP',
            '14'  => 'Delete Face',
            '15'  => 'Delete Card',
            '16'  => 'Delete Password',
            '30'  => 'Enroll FP',
            '70'  => 'Verify FP',
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
                    'device_serial'    => $serialNumber,
                    'device_user_id'   => $pin,
                    'system_user_id'   => $existingUser->id,
                    'system_user_name' => $existingUser->name,
                ]);
                return ['success' => true, 'reason' => 'already_exists'];
            }

            // Create placeholder account as INACTIVE
            $newUser = User::create([
                'name'        => $name,
                'email'       => 'device-auto-' . $pin . '@device-auto.local',
                'password'    => bcrypt(Str::random(32)),
                'active'      => false,
                'employee_id' => $pin,
            ]);

            Log::info('User enrollment: created inactive system user (pending admin approval)', [
                'device_serial'    => $serialNumber,
                'device_user_id'   => $pin,
                'system_user_id'   => $newUser->id,
                'system_user_name' => $newUser->name,
            ]);

            DB::commit();

            return ['success' => true, 'reason' => 'created'];
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to process user enrollment', [
                'device_serial'  => $serialNumber,
                'device_user_id' => $pin,
                'error'          => $e->getMessage(),
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
     * @return array{success: bool, reason: string|null}
     */
    public function processTemplateUpload(string $content, string $table, string $serialNumber, BiometricDevice $device): array
    {
        // Pattern to capture User ID and Template string
        $pattern = '/USERID=(?P<userid>\d+).*?TMP=(?P<template>[a-zA-Z0-9+\/=\s]+)/s';

        if (! preg_match($pattern, $content, $matches)) {
            Log::warning('Template upload: invalid format', ['table' => $table]);
            return ['success' => false, 'reason' => 'invalid_format'];
        }

        $userId = $matches['userid'];
        $template = trim($matches['template']);

        // Resolve system user by employee_id (PIN)
        $systemUser = User::where('employee_id', $userId)->first();
        if (! $systemUser) {
            Log::warning('Template upload: no system user for device PIN', [
                'device_serial'  => $serialNumber,
                'device_user_id' => $userId,
            ]);
            return ['success' => true, 'reason' => 'no_user'];
        }

        // Determine template type based on table
        $templateType = $table === 'templatev10' ? 'fingerprint' : 'face';

        try {
            // Save to biometric_templates table
            DB::table('biometric_templates')->updateOrInsert(
                [
                    'device_user_id'      => $userId,
                    'biometric_device_id' => $device->id,
                    'template_type'       => $templateType,
                ],
                [
                    'user_id'          => $systemUser->id,
                    'template_data'    => $template,
                    'template_size'    => strlen($template),
                    'template_version' => $table,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]
            );

            Log::info('Biometric template saved', [
                'device_serial' => $serialNumber,
                'user_id'       => $userId,
                'template_type' => $templateType,
                'template_size' => strlen($template),
            ]);

            return ['success' => true, 'reason' => 'saved'];
        } catch (\Exception $e) {
            Log::error('Failed to save biometric template', [
                'device_serial' => $serialNumber,
                'user_id'       => $userId,
                'error'         => $e->getMessage(),
            ]);
            return ['success' => false, 'reason' => 'exception'];
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
            'command_type'        => $validated['command_type'],
            'payload'             => $validated['payload'] ?? null,
            'status'              => 'pending',
            'scheduled_at'        => $validated['scheduled_at'] ?? null,
        ]);

        Log::info('ADMS command queued', [
            'device_id'     => $device->id,
            'device_serial' => $device->serial_number,
            'command_id'    => $command->id,
            'command_type'  => $command->command_type,
            'scheduled_at'  => $command->scheduled_at,
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
                'id'            => $cmd->id,
                'command_type'  => $cmd->command_type,
                'status'        => $cmd->status,
                'payload'       => $cmd->payload,
                'return_code'   => $cmd->return_code,
                'error_message' => $cmd->error_message,
                'sent_at'       => $cmd->sent_at,
                'executed_at'   => $cmd->executed_at,
                'created_at'    => $cmd->created_at,
                'adms_string'   => method_exists($cmd, 'toAdmsString') ? $cmd->toAdmsString() : null,
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

                Log::info('ADMS devicecmd: command acknowledged', [
                    'serial'       => $serialNumber,
                    'command_id'   => $command->id,
                    'command_type' => $command->command_type,
                    'return_code'  => $returnCode,
                ]);
            } else {
                Log::warning('ADMS devicecmd: command not found', [
                    'serial'     => $serialNumber,
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
    public function getOperationLogs(?int $deviceId): \Illuminate\Support\Collection
    {
        $query = DB::table('biometric_oper_logs')
            ->orderBy('occurred_at', 'desc')
            ->limit(100);

        if ($deviceId) {
            $query->where('biometric_device_id', $deviceId);
        }

        return $query->get()->map(function ($log) {
            return [
                'id'             => $log->id,
                'level'          => 'info',
                'serial_number'  => $log->serial_number,
                'operation_type' => $log->operation_type,
                'user_pin'       => $log->user_pin,
                'raw_data'       => $log->raw_data,
                'context'        => json_decode($log->context ?? '[]', true),
                'created_at'     => $log->occurred_at,
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

            Log::info('ADMS command acknowledged', [
                'serial'       => $ackSn,
                'command_id'   => $command->id,
                'command_type' => $command->command_type,
                'return_code'  => $returnCode,
            ]);
        } else {
            Log::warning('ADMS acknowledgment: command not found or device mismatch', [
                'serial'     => $ackSn,
                'command_id' => $ackData['ID'],
            ]);
        }

        return true;
    }
}
