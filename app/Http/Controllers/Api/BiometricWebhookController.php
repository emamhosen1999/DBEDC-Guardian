<?php

namespace App\Http\Controllers\Api;

use App\Events\BiometricAttendanceReceived;
use App\Events\BiometricDeviceConnected;
use App\Http\Controllers\Controller;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDeviceCommand;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles push events from ZKTeco biometric devices.
 * The device sends a POST with auth_token, device_serial, device_user_id, and punch_time.
 */
class BiometricWebhookController extends Controller
{
    public function handle(Request $request)
    {
        $authToken    = $request->header('X-Device-Token') ?? $request->input('auth_token');
        $serialNumber = $request->input('device_serial');
        $deviceUserId = $request->input('device_user_id');
        $punchTime    = $request->input('punch_time'); // ISO8601 or timestamp from device

        if (! $authToken || ! $serialNumber || ! $deviceUserId) {
            return response()->json(['message' => 'Missing required parameters.'], 422);
        }

        // 1. Authenticate device
        $device = BiometricDevice::where('serial_number', $serialNumber)
            ->where('auth_token', $authToken)
            ->first();

        if (! $device) {
            Log::warning('Biometric webhook: unknown device or bad token', [
                'serial' => $serialNumber,
            ]);
            return response()->json(['message' => 'Unauthorized device.'], 401);
        }

        if (! $device->is_active) {
            return response()->json(['message' => 'Device is inactive.'], 403);
        }

        // 2. Update heartbeat
        $device->update(['last_heartbeat_at' => now()]);

        // 3. Resolve user by matching PIN to employee_id
        $user = User::where('employee_id', $deviceUserId)->first();
        if (! $user) {
            return response()->json(['message' => 'User not found with employee_id: ' . $deviceUserId], 404);
        }

        // 4. Build a synthetic request for the punch service
        $syntheticRequest = Request::create('/biometric/punch', 'POST', [
            'device_serial'  => $serialNumber,
            'device_user_id' => $deviceUserId,
            'source'         => 'biometric',
            'punch_time'     => $punchTime,
        ]);

        // 5. Process through the existing punch service
        try {
            $punchService = new AttendancePunchService();
            $result = $punchService->processPunch($user, $syntheticRequest);

            Log::info('Biometric punch processed', [
                'user_id'       => $user->id,
                'device_serial' => $serialNumber,
                'result_status' => $result['status'],
            ]);

            return response()->json($result, $result['status'] === 'error' ? ($result['code'] ?? 422) : 200);
        } catch (\Exception $e) {
            Log::error('Biometric webhook punch error: ' . $e->getMessage(), [
                'user_id'       => $user->id,
                'device_serial' => $serialNumber,
            ]);
            return response()->json(['message' => 'Failed to process punch.'], 500);
        }
    }

    /**
     * Heartbeat / health-check from device (optional GET endpoint).
     */
    public function heartbeat(Request $request)
    {
        $authToken    = $request->header('X-Device-Token') ?? $request->input('auth_token');
        $serialNumber = $request->input('device_serial');

        if (! $authToken || ! $serialNumber) {
            return response()->json(['message' => 'Missing parameters.'], 422);
        }

        $device = BiometricDevice::where('serial_number', $serialNumber)
            ->where('auth_token', $authToken)
            ->first();

        if (! $device) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $device->update(['last_heartbeat_at' => now()]);

        return response()->json(['message' => 'OK', 'server_time' => now()->toISOString()]);
    }

    /**
     * ZKTeco ADMS Push Protocol - Handshake (GET /iclock/cdata)
     * Device sends GET request to register itself and establish connection.
     * Must respond with plain text "OK" and SessionID header.
     * Also returns pending commands if any.
     */
    public function admsHandshake(Request $request)
    {
        Log::info('ADMS handshake received', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'query' => $request->query->all(),
        ]);

        $serialNumber = $request->query('SN');
        $options = $request->query('options', '');

        if (! $serialNumber) {
            Log::warning('ADMS handshake: missing serial number');
            return response('ERROR', 400)
                ->header('Content-Type', 'text/plain');
        }

        // Find device by serial number (ADMS doesn't use auth_token in handshake)
        $device = BiometricDevice::where('serial_number', $serialNumber)->first();

        if (! $device) {
            Log::warning('ADMS handshake: unknown device', ['serial' => $serialNumber]);
            return response('ERROR', 404)
                ->header('Content-Type', 'text/plain');
        }

        if (! $device->is_active) {
            Log::warning('ADMS handshake: inactive device', ['serial' => $serialNumber]);
            return response('ERROR', 403)
                ->header('Content-Type', 'text/plain');
        }

        // Update heartbeat
        $device->update(['last_heartbeat_at' => now()]);

        // Dispatch device connected event
        event(new BiometricDeviceConnected($device));

        // Generate session ID for this connection
        $sessionId = bin2hex(random_bytes(16));

        // Fetch one pending command at a time (sequential processing is safer for ADMS)
        $command = BiometricDeviceCommand::where('biometric_device_id', $device->id)
            ->where('status', 'pending')
            ->oldest()
            ->first();

        if ($command) {
            // Mark command as sent
            $command->markAsSent();

            Log::info('ADMS handshake: sending command', [
                'serial' => $serialNumber,
                'command_id' => $command->id,
                'command_type' => $command->command_type,
            ]);

            return new \Symfony\Component\HttpFoundation\Response(
                $command->toAdmsString(),
                200,
                [
                    'SessionID' => $sessionId,
                    'Content-Type' => 'text/plain',
                ]
            );
        }

        Log::info('ADMS handshake successful', [
            'serial' => $serialNumber,
            'session_id' => $sessionId,
            'options' => $options,
            'pending_commands' => 0,
        ]);

        // ZKTeco ADMS expects a multiline options block so the device knows from which
        // timestamp to start syncing. Returning just "OK" causes the device to either
        // resend all historical records or skip syncing entirely depending on firmware.
        // ATTLOGStamp=9999 tells the device to push everything it hasn't sent yet.
        $responseBody = implode("\r\n", [
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

        return new \Symfony\Component\HttpFoundation\Response(
            $responseBody,
            200,
            [
                'SessionID' => $sessionId,
                'Content-Type' => 'text/plain',
            ]
        );
    }

    /**
     * ZKTeco ADMS Push Protocol - Push Attendance Logs (POST /iclock/cdata)
     * Device sends POST request with attendance data in plain text format.
     * Data format: USERID=123\tCHECKTIME=2026-05-12 18:30:05\tCHECKTYPE=I\tVERIFYCODE=1\tSENSORID=1
     * Also handles command acknowledgments: ID=1&Return=0
     * Also handles template uploads: table=templatev10 or table=user
     * Must respond with plain text "OK" to acknowledge.
     */
    public function admsPush(Request $request)
    {
        Log::info('ADMS push received', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'query' => $request->query->all(),
            'content_length' => strlen($request->getContent()),
        ]);

        $rawData = $request->getContent();
        $table = $request->query('table');

        // Handle Biometric Template Uploads (Roaming)
        // templatev10 = fingerprint, facetmpv10 = face templates
        if ($table === 'templatev10' || $table === 'facetmpv10') {
            return $this->handleTemplateUpload($rawData, $table, $request);
        }

        // Handle User Enrollment from Device (Device → System sync)
        // ZKTeco sends table=USERINFO for user data pushes
        if ($table === 'USERINFO') {
            return $this->handleUserEnrollment($rawData, $table, $request);
        }

        // OPERLOG = device operation log (door events, admin actions) — store for audit trail
        if ($table === 'OPERLOG') {
            Log::info('ADMS push: OPERLOG received', [
                'sn' => $this->getSerialNumber($request),
                'size' => strlen($rawData),
            ]);

            // Store OPERLOG entries for audit trail
            $this->storeOperLog($rawData, $request);

            return new \Symfony\Component\HttpFoundation\Response("OK", 200, ['Content-Type' => 'text/plain']);
        }

        // Check if the body contains a Command Acknowledgment
        // Format: ID=1&Return=0 or ID=1\tReturn=0
        if (str_contains($rawData, 'ID=') && str_contains($rawData, 'Return=')) {
            // Parse acknowledgment (replace newlines with & for parse_str)
            $normalizedData = str_replace(["\n", "\t"], '&', $rawData);
            parse_str($normalizedData, $ackData);

            if (isset($ackData['ID'])) {
                $command = BiometricDeviceCommand::find($ackData['ID']);
                if ($command) {
                    $returnCode = $ackData['Return'] ?? '1';
                    $command->markAsExecuted($returnCode);

                    Log::info('ADMS command acknowledged', [
                        'serial' => $request->header('SN'),
                        'command_id' => $command->id,
                        'command_type' => $command->command_type,
                        'return_code' => $returnCode,
                    ]);
                } else {
                    Log::warning('ADMS acknowledgment: command not found', [
                        'serial' => $request->header('SN'),
                        'command_id' => $ackData['ID'],
                    ]);
                }

                return new \Symfony\Component\HttpFoundation\Response(
                    "OK",
                    200,
                    ['Content-Type' => 'text/plain']
                );
            }
        }

        // Default: Handle Attendance Logs
        return $this->processAttendanceLogs($rawData, $request);
    }

    /**
     * Store OPERLOG entries for audit trail
     */
    protected function storeOperLog($rawData, $request)
    {
        $serialNumber = $this->getSerialNumber($request);
        $device = BiometricDevice::where('serial_number', $serialNumber)->first();

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
                    // Format: OPLOG <operation> <pin> <datetime> <other_fields>...
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
                    $occurredAt = $rawOccurredAt ? \Carbon\Carbon::parse($rawOccurredAt) : now();
                }
            } else {
                // Parse key=value format (FP, USER, etc.)
                if (preg_match_all('/([^=\t\n]+)=([^\t\n]*)/', $line, $matches)) {
                    $data = array_combine($matches[1], $matches[2]);
                }
                $operationType = $data['Operation'] ?? $data['operation'] ?? $data['type'] ?? null;
                $userPin = $data['PIN'] ?? $data['pin'] ?? null;
                $rawOccurredAt2 = $data['DateTime'] ?? $data['dateTime'] ?? null;
                $occurredAt = $rawOccurredAt2 ? \Carbon\Carbon::parse($rawOccurredAt2) : now();
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
     * Convert OPLOG operation code to human-readable name
     */
    protected function getOperLogName($code): string
    {
        return match((string)$code) {
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

    /**
     * Handle user enrollment from device (Device → System sync)
     */
    protected function handleUserEnrollment($content, $table, Request $request)
    {
        // SN is always a query param in ADMS POST requests, never in the body
        $serialNumber = $this->getSerialNumber($request);
        if (! $serialNumber) {
            Log::warning('User enrollment: missing serial number');
            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        $device = BiometricDevice::where('serial_number', $serialNumber)->first();
        if (! $device) {
            Log::warning('User enrollment: unknown device', ['serial' => $serialNumber]);
            return response('ERROR', 401)->header('Content-Type', 'text/plain');
        }

        // Parse user enrollment data
        // Format: PIN=42\tName=John Doe\tCard=123456\tPrivilege=0
        $pattern = '/PIN=(?P<pin>\d+).*?Name=(?P<name>[^\t\n]+).*?Card=(?P<card>[^\t\n]*)/s';
        
        if (preg_match($pattern, $content, $matches)) {
            $pin = $matches['pin'];
            $name = trim($matches['name']);
            $card = isset($matches['card']) ? trim($matches['card']) : '';

            try {
                DB::beginTransaction();

                // Try to find existing system user by employee_id (PIN)
                $existingUser = User::where('employee_id', $pin)->first();

                if ($existingUser) {
                    // User already exists with this employee_id
                    DB::rollBack();
                    Log::info('User enrollment: user already exists with employee_id', [
                        'device_serial' => $serialNumber,
                        'device_user_id' => $pin,
                        'system_user_id' => $existingUser->id,
                        'system_user_name' => $existingUser->name,
                    ]);
                    return new \Symfony\Component\HttpFoundation\Response(
                        "OK",
                        200,
                        ['Content-Type' => 'text/plain']
                    );
                }

                // Create placeholder account as INACTIVE — admin must activate before the user
                // can log in or have attendance processed. Anyone who can register on the
                // physical device would otherwise gain an active system account.
                $newUser = User::create([
                    'name' => $name,
                    'email' => strtolower(str_replace(' ', '.', $name)) . '@device-auto.local',
                    'password' => bcrypt(\Illuminate\Support\Str::random(32)),
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

                return new \Symfony\Component\HttpFoundation\Response(
                    "OK",
                    200,
                    ['Content-Type' => 'text/plain']
                );
            } catch (\Exception $e) {
                DB::rollBack();
                Log::error('Failed to process user enrollment', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $pin,
                    'error' => $e->getMessage(),
                ]);
                return response('ERROR', 500)->header('Content-Type', 'text/plain');
            }
        }

        Log::warning('User enrollment: invalid format', ['table' => $table]);
        return response('ERROR', 400)->header('Content-Type', 'text/plain');
    }

    /**
     * Handle biometric template uploads from device (for biometric roaming)
     */
    protected function handleTemplateUpload($content, $table, Request $request)
    {
        // SN is always a query param in ADMS POST requests, never in the body
        $serialNumber = $this->getSerialNumber($request);
        if (! $serialNumber) {
            Log::warning('Template upload: missing serial number');
            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        $device = BiometricDevice::where('serial_number', $serialNumber)->first();
        if (! $device) {
            Log::warning('Template upload: unknown device', ['serial' => $serialNumber]);
            return response('ERROR', 401)->header('Content-Type', 'text/plain');
        }

        // Pattern to capture User ID and Template string
        $pattern = '/USERID=(?P<userid>\d+).*?TMP=(?P<template>[a-zA-Z0-9+\/=\s]+)/s';
        
        if (preg_match($pattern, $content, $matches)) {
            $userId = $matches['userid'];
            $template = trim($matches['template']);
            
            // Resolve system user by employee_id (PIN)
            $systemUser = User::where('employee_id', $userId)->first();
            if (! $systemUser) {
                Log::warning('Template upload: no system user for device PIN', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $userId,
                ]);
                return new \Symfony\Component\HttpFoundation\Response("OK", 200, ['Content-Type' => 'text/plain']);
            }

            // Determine template type based on table
            $templateType = $table === 'templatev10' ? 'fingerprint' : 'face';

            try {
                // Save to biometric_templates table
                DB::table('biometric_templates')->updateOrInsert(
                    [
                        'device_user_id' => $userId,
                        'biometric_device_id' => $device->id,
                        'template_type' => $templateType,
                    ],
                    [
                        'user_id' => $systemUser->id,
                        'template_data' => $template,
                        'template_size' => strlen($template),
                        'template_version' => $table,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );

                Log::info('Biometric template saved', [
                    'device_serial' => $serialNumber,
                    'user_id' => $userId,
                    'template_type' => $templateType,
                    'template_size' => strlen($template),
                ]);

                return new \Symfony\Component\HttpFoundation\Response(
                    "OK",
                    200,
                    ['Content-Type' => 'text/plain']
                );
            } catch (\Exception $e) {
                Log::error('Failed to save biometric template', [
                    'device_serial' => $serialNumber,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);
                return response('ERROR', 500)->header('Content-Type', 'text/plain');
            }
        }

        Log::warning('Template upload: invalid format', ['table' => $table]);
        return response('ERROR', 400)->header('Content-Type', 'text/plain');
    }

    /**
     * Extract serial number from an ADMS request.
     * ZKTeco always sends SN as a query parameter (?SN=xxx), never in the body.
     */
    protected function getSerialNumber(Request $request): ?string
    {
        return $request->query('SN') ?: ($request->header('SN') ?: null);
    }

    /**
     * Process attendance logs (refactored from admsPush)
     */
    protected function processAttendanceLogs($rawData, $request)
    {
        if (empty($rawData)) {
            Log::warning('ADMS push: empty body');
            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        // ZKTeco sends SN as query param (?SN=xxx); header is a fallback for edge cases
        $serialNumber = $this->getSerialNumber($request);

        if (! $serialNumber) {
            Log::warning('ADMS push: missing serial number');
            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        // Find device by serial number
        $device = BiometricDevice::where('serial_number', $serialNumber)->first();

        if (! $device) {
            Log::warning('ADMS push: unknown device', ['serial' => $serialNumber]);
            return response('ERROR', 401)->header('Content-Type', 'text/plain');
        }

        if (! $device->is_active) {
            Log::warning('ADMS push: inactive device', ['serial' => $serialNumber]);
            return response('ERROR', 403)->header('Content-Type', 'text/plain');
        }

        // Update heartbeat
        $device->update(['last_heartbeat_at' => now()]);

        // Log the raw data for debugging
        Log::info('ADMS push: raw ATTLOG data', [
            'serial' => $serialNumber,
            'raw_data' => $rawData,
            'data_length' => strlen($rawData),
        ]);

        // Parse the attendance data
        // Format: Multiple lines, each line tab-separated: PIN\tDateTime\tStatus\tVerifyCode\tWorkCode\tReserved...
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
                'line' => $line,
                'parsed_data' => $data,
            ]);

            $hasUserId = !empty($data['PIN']);
            $hasCheckTime = !empty($data['DateTime']);

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
            // ZKTeco ADMS sends numeric check type: 0=IN, 1=OUT, 2=Break-Out, 3=Break-In, 4=OT-In, 5=OT-Out
            $rawCheckType = trim($data['Status'] ?? '0');
            $checkType = match ((string) $rawCheckType) {
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

            // Resolve user by matching PIN to employee_id
            $user = User::where('employee_id', $deviceUserId)->first();

            if (! $user) {
                $errorCount++;
                Log::warning('ADMS push: user not found', [
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                ]);
                DB::table('biometric_att_logs')->insert([
                    'biometric_device_id'   => $device->id,
                    'serial_number'         => $serialNumber,
                    'user_pin'              => $deviceUserId,
                    'user_id'               => null,
                    'punch_time'            => $checkTime,
                    'check_type'            => $checkType,
                    'punch_status'          => 'unknown_user',
                    'punch_status_reason'   => 'No user matched employee_id: ' . $deviceUserId,
                    'verify_code'           => $data['VerifyCode'] ?? null,
                    'work_code'             => $data['WorkCode'] ?? null,
                    'raw_data'              => $line,
                    'context'               => json_encode($data),
                    'occurred_at'           => $checkTime,
                    'created_at'            => now(),
                    'updated_at'            => now(),
                ]);
                continue;
            }

            // Validate attendance type
            if (! $user->attendance_type_id) {
                $errorCount++;
                Log::warning('ADMS push: user has no attendance type', [
                    'device_serial' => $serialNumber,
                    'user_id'       => $user->id,
                ]);
                continue;
            }

            $attendanceType = AttendanceType::with('biometricDevices')->find($user->attendance_type_id);

            if (! $attendanceType || ! str_starts_with($attendanceType->slug, 'biometric')) {
                $errorCount++;
                Log::warning('ADMS push: user attendance type is not biometric', [
                    'device_serial'        => $serialNumber,
                    'user_id'              => $user->id,
                    'attendance_type_slug' => $attendanceType?->slug ?? null,
                ]);
                continue;
            }

            // Zone/group device check: if the AT has linked devices, punch must come from one of them
            $linkedDevices = $attendanceType->biometricDevices;
            $wrongDevice   = $linkedDevices->isNotEmpty() && ! $linkedDevices->contains('id', $device->id);

            // Store raw ATTLOG entry with punch_status
            DB::table('biometric_att_logs')->insert([
                'biometric_device_id'  => $device->id,
                'serial_number'        => $serialNumber,
                'user_pin'             => $deviceUserId,
                'user_id'              => $user->id,
                'punch_time'           => $checkTime,
                'check_type'           => $checkType,
                'punch_status'         => $wrongDevice ? 'wrong_device' : 'processed',
                'punch_status_reason'  => $wrongDevice ? 'Device not in attendance zone' : null,
                'verify_code'          => $data['VerifyCode'] ?? null,
                'work_code'            => $data['WorkCode'] ?? null,
                'raw_data'             => $line,
                'context'              => json_encode($data),
                'occurred_at'          => $checkTime,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);

            if ($wrongDevice) {
                $errorCount++;
                Log::warning('ADMS push: punch from device not in attendance zone', [
                    'device_serial'    => $serialNumber,
                    'device_id'        => $device->id,
                    'user_id'          => $user->id,
                    'attendance_type'  => $attendanceType->slug,
                ]);
                continue;
            }

            // Idempotency check: skip duplicate punches
            $existingAttendance = DB::table('attendances')
                ->where('user_id', $user->id)
                ->where(function ($query) use ($checkTime) {
                    $query->where('punchin', $checkTime)
                          ->orWhere('punchout', $checkTime);
                })
                ->exists();

            if ($existingAttendance) {
                $duplicateCount++;
                DB::table('biometric_att_logs')
                    ->where('user_id', $user->id)
                    ->where('punch_time', $checkTime)
                    ->where('serial_number', $serialNumber)
                    ->update(['punch_status' => 'duplicate', 'updated_at' => now()]);
                Log::info('ADMS push: duplicate punch skipped', [
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time'     => $checkTime,
                ]);
                continue;
            }

            // Build synthetic request for punch service
            $syntheticRequest = Request::create('/biometric/punch', 'POST', [
                'device_serial'  => $serialNumber,
                'device_user_id' => $deviceUserId,
                'source'         => 'biometric',
                'punch_time'     => $checkTime,
                'check_type'     => $checkType,
            ]);

            // Process through existing punch service
            try {
                $punchService = new AttendancePunchService();
                $result       = $punchService->processPunch($user, $syntheticRequest);

                if ($result['status'] === 'success') {
                    $processedCount++;
                } else {
                    $errorCount++;
                    DB::table('biometric_att_logs')
                        ->where('user_id', $user->id)
                        ->where('punch_time', $checkTime)
                        ->where('serial_number', $serialNumber)
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
            'serial' => $serialNumber,
            'processed' => $processedCount,
            'duplicates_skipped' => $duplicateCount,
            'errors' => $errorCount,
            'total_lines' => count($lines),
        ]);

        // Return plain text "OK" using Symfony response to ensure no extra whitespace
        return new \Symfony\Component\HttpFoundation\Response(
            "OK",
            200,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * Queue a command for a biometric device
     */
    public function queueCommand(Request $request)
    {
        $validated = $request->validate([
            'device_id' => 'required|exists:biometric_devices,id',
            'command_type' => 'required|in:REBOOT,SET_TIME,ADD_USER,UPDATE_USER,DELETE_USER,CLEAR_LOG,CLEAR_DATA,GET_USERINFO',
            'payload' => 'nullable|array',
            'scheduled_at' => 'nullable|date',
        ]);

        $device = BiometricDevice::find($validated['device_id']);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if ($device->protocol !== 'adms') {
            return response()->json(['message' => 'Commands only supported for ADMS protocol devices'], 400);
        }

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

        return response()->json([
            'message' => $command->isScheduled() ? 'Command scheduled successfully' : 'Command queued successfully',
            'command' => [
                'id' => $command->id,
                'type' => $command->command_type,
                'status' => $command->status,
                'scheduled_at' => $command->scheduled_at,
                'adms_string' => $command->toAdmsString(),
            ],
        ]);
    }

    /**
     * Get command history for a device
     */
    public function getCommands(Request $request, $deviceId)
    {
        $device = BiometricDevice::find($deviceId);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

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
                'created_at' => $cmd->created_at,
                'adms_string' => method_exists($cmd, 'toAdmsString') ? $cmd->toAdmsString() : null,
            ];
        }

        return response()->json(['commands' => $commandsArray]);
    }

    /**
     * Get ADMS operation logs for monitoring (from biometric_oper_logs table)
     */
    public function getLogs(Request $request, $deviceId = null)
    {
        $query = DB::table('biometric_oper_logs')
            ->orderBy('occurred_at', 'desc')
            ->limit(100);

        if ($deviceId) {
            $query->where('biometric_device_id', $deviceId);
        }

        $logs = $query->get()->map(function ($log) {
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

        return response()->json(['logs' => $logs]);
    }

    /**
     * ADMS Get Request - Device requests pending commands
     * Similar to handshake but specifically for command polling
     */
    public function admsGetRequest(Request $request)
    {
        $serialNumber = $request->query('SN');

        if (! $serialNumber) {
            Log::warning('ADMS getrequest: missing serial number');
            return response('ERROR', 400)
                ->header('Content-Type', 'text/plain');
        }

        $device = BiometricDevice::where('serial_number', $serialNumber)->first();

        if (! $device) {
            Log::warning('ADMS getrequest: unknown device', ['serial' => $serialNumber]);
            return response('ERROR', 404)
                ->header('Content-Type', 'text/plain');
        }

        if (! $device->is_active) {
            Log::warning('ADMS getrequest: inactive device', ['serial' => $serialNumber]);
            return response('ERROR', 403)
                ->header('Content-Type', 'text/plain');
        }

        // Update heartbeat
        $device->update(['last_heartbeat_at' => now()]);

        // Fetch pending commands
        $command = BiometricDeviceCommand::where('biometric_device_id', $device->id)
            ->where('status', 'pending')
            ->oldest()
            ->first();

        if ($command) {
            $command->markAsSent();

            Log::info('ADMS getrequest: sending command', [
                'serial' => $serialNumber,
                'command_id' => $command->id,
                'command_type' => $command->command_type,
            ]);

            return new \Symfony\Component\HttpFoundation\Response(
                $command->toAdmsString(),
                200,
                ['Content-Type' => 'text/plain']
            );
        }

        Log::info('ADMS getrequest: no pending commands', ['serial' => $serialNumber]);

        return new \Symfony\Component\HttpFoundation\Response(
            "OK",
            200,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * ADMS Device Command - Process command acknowledgment from device
     */
    public function admsDeviceCmd(Request $request)
    {
        $serialNumber = $this->getSerialNumber($request);
        $rawData = $request->getContent();

        if (! $serialNumber) {
            Log::warning('ADMS devicecmd: missing serial number');
            return response('ERROR', 400)
                ->header('Content-Type', 'text/plain');
        }

        $device = BiometricDevice::where('serial_number', $serialNumber)->first();

        if (! $device) {
            Log::warning('ADMS devicecmd: unknown device', ['serial' => $serialNumber]);
            return response('ERROR', 404)
                ->header('Content-Type', 'text/plain');
        }

        // Parse acknowledgment (format: ID=1&Return=0)
        $normalizedData = str_replace(["\n", "\t"], '&', $rawData);
        parse_str($normalizedData, $ackData);

        if (isset($ackData['ID'])) {
            $command = BiometricDeviceCommand::find($ackData['ID']);
            if ($command) {
                $returnCode = $ackData['Return'] ?? '1';
                $command->markAsExecuted($returnCode);

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

        // Update heartbeat
        $device->update(['last_heartbeat_at' => now()]);

        return new \Symfony\Component\HttpFoundation\Response(
            "OK",
            200,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * ADMS Test - Simple connectivity test endpoint
     */
    public function admsTest(Request $request)
    {
        $serialNumber = $request->query('SN');

        Log::info('ADMS test endpoint hit', ['serial' => $serialNumber]);

        return new \Symfony\Component\HttpFoundation\Response(
            "OK",
            200,
            ['Content-Type' => 'text/plain']
        );
    }
}
