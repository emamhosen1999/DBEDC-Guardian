<?php

namespace App\Http\Controllers\Api;

use App\Events\BiometricAttendanceReceived;
use App\Events\BiometricDeviceConnected;
use App\Http\Controllers\Controller;
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

        // 3. Resolve user from pivot
        $mapping = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('device_user_id', $deviceUserId)
            ->first();

        if (! $mapping) {
            // insertOrIgnore prevents duplicates when same unknown user punches multiple times
            DB::table('biometric_device_users')->insertOrIgnore([
                'biometric_device_id' => $device->id,
                'user_id'             => null,
                'device_user_id'      => $deviceUserId,
                'is_active'           => true,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);

            Log::info('Biometric webhook: created unlinked entry for unknown device user', [
                'device_serial'  => $serialNumber,
                'device_user_id' => $deviceUserId,
            ]);

            return response()->json([
                'message' => 'Punch not processed — device user not linked. Unlinked entry created for manual linking.',
                'requires_linking' => true,
                'device_user_id' => $deviceUserId,
            ], 202);
        }

        // If mapping exists but user_id is null (unlinked), return similar response
        if (!$mapping->user_id) {
            return response()->json([
                'message' => 'Punch not processed — device user not linked to a system user.',
                'requires_linking' => true,
                'device_user_id' => $deviceUserId,
            ], 202);
        }

        $user = User::find($mapping->user_id);
        if (! $user) {
            return response()->json(['message' => 'System user not found.'], 404);
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

        // OPERLOG = device operation log (door events, admin actions) — acknowledge and ignore
        if ($table === 'OPERLOG') {
            Log::info('ADMS push: OPERLOG received (acknowledged, not stored)', [
                'sn' => $this->getSerialNumber($request),
                'size' => strlen($rawData),
            ]);
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

                // Check if device user already exists
                $existingMapping = DB::table('biometric_device_users')
                    ->where('biometric_device_id', $device->id)
                    ->where('device_user_id', $pin)
                    ->first();

                if ($existingMapping) {
                    // User already mapped, skip
                    DB::rollBack();
                    Log::info('User enrollment: device user already mapped', [
                        'device_serial' => $serialNumber,
                        'device_user_id' => $pin,
                    ]);
                    return new \Symfony\Component\HttpFoundation\Response(
                        "OK",
                        200,
                        ['Content-Type' => 'text/plain']
                    );
                }

                // Try to find existing system user by name
                $existingUser = User::where('name', $name)->first();

                if ($existingUser) {
                    // Auto-link to existing system user
                    DB::table('biometric_device_users')->insertOrIgnore([
                        'biometric_device_id' => $device->id,
                        'user_id' => $existingUser->id,
                        'device_user_id' => $pin,
                        'is_active' => true,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    Log::info('User enrollment: auto-linked to existing system user', [
                        'device_serial' => $serialNumber,
                        'device_user_id' => $pin,
                        'system_user_id' => $existingUser->id,
                        'system_user_name' => $existingUser->name,
                    ]);
                } else {
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

                    // Create mapping (also inactive until admin reviews)
                    DB::table('biometric_device_users')->insertOrIgnore([
                        'biometric_device_id' => $device->id,
                        'user_id' => $newUser->id,
                        'device_user_id' => $pin,
                        'is_active' => false,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    Log::info('User enrollment: created inactive system user (pending admin approval)', [
                        'device_serial' => $serialNumber,
                        'device_user_id' => $pin,
                        'system_user_id' => $newUser->id,
                        'system_user_name' => $newUser->name,
                    ]);
                }

                DB::commit();

                // Update users_count
                $device->update(['users_count' => DB::table('biometric_device_users')->where('biometric_device_id', $device->id)->count()]);

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
                        'template_data' => $template,
                        'template_size' => strlen($template),
                        'template_version' => $table,
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

        // Parse the attendance data using robust regex
        // Format: Multiple lines, each line: USERID=xxx\tCHECKTIME=xxx\tCHECKTYPE=I/O\tVERIFYCODE=x\tSENSORID=x
        $lines = explode("\n", trim($rawData));
        $processedCount = 0;
        $errorCount = 0;
        $duplicateCount = 0;

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            // Use regex for robust parsing (handles missing keys, extra whitespace)
            $data = [];
            if (preg_match_all('/([^=\t\n]+)=([^\t\n]*)/', $line, $matches)) {
                $data = array_combine($matches[1], $matches[2]);
            }

            $hasUserId = isset($data['USERID']) || isset($data['EnrollNumber']);
            $hasCheckTime = isset($data['CHECKTIME']) || isset($data['CheckTime']);
            
            // Skip lines that don't match ATTLOG format (could be OPERLOG or other data)
            if (! $hasUserId || ! $hasCheckTime) {
                // Silently skip non-ATTLOG lines to reduce log noise
                $errorCount++;
                continue;
            }

            $deviceUserId = trim($data['USERID'] ?? $data['EnrollNumber'] ?? '');
            $checkTime = trim($data['CHECKTIME'] ?? $data['CheckTime'] ?? '');
            // ZKTeco ADMS sends numeric check type: 0=IN, 1=OUT, 2=Break-Out, 3=Break-In, 4=OT-In, 5=OT-Out
            $rawCheckType = trim($data['CHECKTYPE'] ?? $data['CheckType'] ?? '0');
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

            // Resolve user from pivot table
            $mapping = DB::table('biometric_device_users')
                ->where('biometric_device_id', $device->id)
                ->where('device_user_id', $deviceUserId)
                ->first();

            if (! $mapping) {
                // insertOrIgnore prevents duplicate rows when same unknown user punches several
                // times before an admin links them (race condition on repeated ATTLOG batches)
                DB::table('biometric_device_users')->insertOrIgnore([
                    'biometric_device_id' => $device->id,
                    'user_id'             => null,
                    'device_user_id'      => $deviceUserId,
                    'is_active'           => true,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);

                Log::info('ADMS push: created unlinked entry', [
                    'device_serial'  => $serialNumber,
                    'device_user_id' => $deviceUserId,
                ]);
                continue;
            }

            // If unlinked, skip processing
            if (! $mapping->user_id) {
                continue;
            }

            // Idempotency check: avoid duplicate punches
            // Check if attendance record already exists for this exact punch
            $existingAttendance = DB::table('attendances')
                ->where('user_id', $mapping->user_id)
                ->where('punch_time', $checkTime)
                ->where('source', 'biometric')
                ->first();

            if ($existingAttendance) {
                $duplicateCount++;
                Log::info('ADMS push: duplicate punch skipped', [
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                ]);
                continue;
            }

            $user = User::find($mapping->user_id);
            if (! $user) {
                $errorCount++;
                Log::warning('ADMS push: system user not found', ['user_id' => $mapping->user_id]);
                continue;
            }

            // Build synthetic request for punch service
            $syntheticRequest = Request::create('/biometric/punch', 'POST', [
                'device_serial'  => $serialNumber,
                'device_user_id' => $deviceUserId,
                'source'         => 'biometric',
                'punch_time'     => $checkTime,
                'check_type'     => $checkType, // Pass CHECKTYPE to help determine IN/OUT
            ]);

            // Process through existing punch service
            try {
                $punchService = new AttendancePunchService();
                $result = $punchService->processPunch($user, $syntheticRequest);

                if ($result['status'] === 'success') {
                    $processedCount++;
                } else {
                    $errorCount++;
                }

                Log::info('ADMS punch processed', [
                    'user_id'       => $user->id,
                    'device_serial' => $serialNumber,
                    'device_user_id' => $deviceUserId,
                    'check_time'    => $checkTime,
                    'check_type'    => $checkType,
                    'result_status' => $result['status'],
                ]);

                // Dispatch attendance received event
                event(new BiometricAttendanceReceived($device, $user, [
                    'device_user_id' => $deviceUserId,
                    'check_time' => $checkTime,
                    'check_type' => $checkType,
                    'result' => $result,
                ]));
            } catch (\Exception $e) {
                $errorCount++;
                Log::error('ADMS punch error: ' . $e->getMessage(), [
                    'user_id'       => $user->id,
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
            'command_type' => 'required|in:REBOOT,SET_TIME,ADD_USER,UPDATE_USER,DELETE_USER,CLEAR_LOG,CLEAR_DATA',
            'payload' => 'nullable|array',
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
        ]);

        Log::info('ADMS command queued', [
            'device_id' => $device->id,
            'device_serial' => $device->serial_number,
            'command_id' => $command->id,
            'command_type' => $command->command_type,
        ]);

        return response()->json([
            'message' => 'Command queued successfully',
            'command' => [
                'id' => $command->id,
                'type' => $command->command_type,
                'status' => $command->status,
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
     * Get sync status for a device (for progress bar)
     */
    public function getSyncStatus(Request $request, $deviceId)
    {
        $device = BiometricDevice::find($deviceId);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $total = BiometricDeviceCommand::where('biometric_device_id', $deviceId)->count();
        $pending = BiometricDeviceCommand::where('biometric_device_id', $deviceId)->where('status', 'pending')->count();
        $sent = BiometricDeviceCommand::where('biometric_device_id', $deviceId)->where('status', 'sent')->count();
        $executed = BiometricDeviceCommand::where('biometric_device_id', $deviceId)->where('status', 'executed')->count();
        $failed = BiometricDeviceCommand::where('biometric_device_id', $deviceId)->where('status', 'failed')->count();

        return response()->json([
            'total' => $total,
            'pending' => $pending,
            'sent' => $sent,
            'executed' => $executed,
            'failed' => $failed,
            'progress' => $total > 0 ? round(($executed / $total) * 100, 1) : 0,
        ]);
    }

    /**
     * Sync all system users to a device
     */
    public function syncUsersToDevice(Request $request, $deviceId)
    {
        $device = BiometricDevice::find($deviceId);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if ($device->protocol !== 'adms') {
            return response()->json(['message' => 'User sync only supported for ADMS protocol devices'], 400);
        }

        // Execute sync synchronously (no queue)
        $job = new \App\Jobs\SyncUsersToDeviceJob($device);
        $job->handle();

        Log::info('Bulk user sync completed synchronously', [
            'device_id' => $device->id,
            'device_serial' => $device->serial_number,
        ]);

        return response()->json([
            'message' => 'User sync completed',
            'status' => 'completed',
            'device' => $device->name,
        ]);
    }

    /**
     * Request user data from device
     */
    public function requestUsersFromDevice(Request $request, $deviceId)
    {
        $device = BiometricDevice::find($deviceId);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if ($device->protocol !== 'adms') {
            return response()->json(['message' => 'User request only supported for ADMS protocol devices'], 400);
        }

        // Queue GET_USERINFO command
        $command = BiometricDeviceCommand::create([
            'biometric_device_id' => $device->id,
            'command_type' => 'GET_USERINFO',
            'payload' => [],
            'status' => 'pending',
            'retry_count' => 0,
        ]);

        Log::info('GET_USERINFO command queued', [
            'device_id' => $device->id,
            'device_serial' => $device->serial_number,
            'command_id' => $command->id,
        ]);

        return response()->json([
            'message' => 'GET_USERINFO command queued',
            'status' => 'queued',
            'command_id' => $command->id,
            'device' => $device->name,
        ]);
    }

    /**
     * Get users from device (from database - users enrolled from device)
     */
    public function getDeviceUsers(Request $request, $deviceId)
    {
        $device = BiometricDevice::find($deviceId);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $users = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->leftJoin('users', 'biometric_device_users.user_id', '=', 'users.id')
            ->select([
                'biometric_device_users.id',
                'biometric_device_users.device_user_id',
                'biometric_device_users.user_id',
                'biometric_device_users.is_active',
                'users.name as user_name',
                'users.email as user_email',
                'biometric_device_users.created_at',
            ])
            ->get();

        return response()->json([
            'users' => $users,
            'total' => count($users),
        ]);
    }

    /**
     * Get ADMS logs for monitoring
     */
    public function getLogs(Request $request, $deviceId = null)
    {
        $query = DB::table('logs')
            ->where('message', 'like', '%ADMS%')
            ->orderBy('created_at', 'desc')
            ->limit(100);

        if ($deviceId) {
            $device = BiometricDevice::find($deviceId);
            if ($device) {
                $query->where('message', 'like', '%' . $device->serial_number . '%');
            }
        }

        $logs = $query->get()->map(function ($log) {
            return [
                'id' => $log->id,
                'level' => $log->level ?? 'info',
                'message' => $log->message,
                'context' => json_decode($log->context ?? '[]', true),
                'created_at' => $log->created_at,
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
