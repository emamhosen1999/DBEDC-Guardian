<?php

namespace App\Http\Controllers\Api;

use App\Events\BiometricDeviceConnected;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessBiometricDownloadSession;
use App\Models\HRM\BiometricDevice;
use App\Services\Biometric\BiometricProcessingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Handles push events from ZKTeco biometric devices.
 * The device sends a POST with auth_token, device_serial, device_user_id, and punch_time.
 */
class BiometricWebhookController extends Controller
{
    protected BiometricProcessingService $biometricService;

    public function __construct(BiometricProcessingService $biometricService)
    {
        $this->biometricService = $biometricService;
    }

    public function handle(Request $request)
    {
        $authToken = $request->header('X-Device-Token') ?? $request->input('auth_token');
        $serialNumber = $request->input('device_serial');
        $deviceUserId = $request->input('device_user_id');
        $punchTime = $request->input('punch_time');

        if (! $authToken || ! $serialNumber || ! $deviceUserId) {
            return response()->json(['message' => 'Missing required parameters.'], 422);
        }

        // 1. Authenticate device
        $device = $this->biometricService->authenticateDevice($serialNumber, $authToken);

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
        $this->biometricService->updateHeartbeat($device);

        $resolvedPunchTime = $punchTime ? Carbon::parse($punchTime) : now();

        // Parse check_type
        $verifyCode = $request->input('verify_code');
        $checkType = $this->biometricService->resolveCheckType($verifyCode, $request->input('check_type', 'in'));

        // 3. Create an ATTLOG record immediately
        $attLog = $this->biometricService->createAttLog([
            'biometric_device_id' => $device->id,
            'serial_number' => $serialNumber,
            'user_pin' => $deviceUserId,
            'user_id' => null,
            'punch_time' => $resolvedPunchTime,
            'occurred_at' => now(),
            'check_type' => $checkType,
            'verify_code' => $verifyCode,
            'work_code' => $request->input('work_code'),
            'raw_data' => $request->getContent() ?: null,
            'punch_status' => 'failed',
            'punch_status_reason' => 'Pending processing',
        ]);

        // 4. Resolve user
        $resolved = $this->biometricService->resolveOrCreateUser($deviceUserId);
        $user = $resolved['user'];

        if ($resolved['is_new']) {
            $attLog->update([
                'user_id' => $user->id,
                'punch_status' => 'unknown_user',
                'punch_status_reason' => 'Auto-created as inactive placeholder. Assign attendance type to activate.',
            ]);

            Log::warning('Biometric webhook: unknown user auto-created', [
                'employee_id' => $deviceUserId,
                'new_user_id' => $user->id,
                'device_serial' => $serialNumber,
            ]);

            return response()->json([
                'message' => 'User auto-created as inactive. Assign attendance type to enable punching.',
                'user_id' => $user->id,
                'log_id' => $attLog->id,
            ], 202);
        }

        // Link the log to the resolved user
        $attLog->update(['user_id' => $user->id]);

        // 5. Validate attendance type eligibility
        $eligibility = $this->biometricService->validateAttendanceEligibility($user, $device);

        if (! $eligibility['valid']) {
            $punchStatus = ($eligibility['reason'] === 'Device not in attendance zone') ? 'wrong_device' : 'failed';
            $attLog->update([
                'punch_status' => $punchStatus,
                'punch_status_reason' => $eligibility['reason'],
            ]);
            Log::warning('Biometric webhook: attendance validation failed', [
                'user_id' => $user->id, 'device_serial' => $serialNumber, 'reason' => $eligibility['reason'],
            ]);

            $statusCode = ($punchStatus === 'wrong_device') ? 403 : 422;

            return response()->json(['message' => $eligibility['reason']], $statusCode);
        }

        // 6. Idempotency check
        if ($this->biometricService->isDuplicatePunch($user->id, $resolvedPunchTime)) {
            $attLog->update(['punch_status' => 'duplicate']);

            return response()->json(['message' => 'Duplicate punch — already recorded.'], 200);
        }

        // 7. Build synthetic request
        $syntheticRequest = $this->biometricService->buildSyntheticPunchRequest(
            $serialNumber,
            $deviceUserId,
            $resolvedPunchTime->toISOString(),
            $checkType
        );

        // 8. Process punch
        try {
            $result = $this->biometricService->processPunch($user, $syntheticRequest);

            $attLog->update([
                'punch_status' => $result['status'] === 'success' ? 'processed' : 'failed',
                'punch_status_reason' => $result['status'] === 'success' ? null : ($result['message'] ?? null),
            ]);

            Log::info('Biometric punch processed', [
                'user_id' => $user->id,
                'device_serial' => $serialNumber,
                'result_status' => $result['status'],
                'log_id' => $attLog->id,
            ]);

            return response()->json($result, $result['status'] === 'error' ? ($result['code'] ?? 422) : 200);
        } catch (\Exception $e) {
            $attLog->update([
                'punch_status' => 'failed',
                'punch_status_reason' => $e->getMessage(),
            ]);

            Log::error('Biometric webhook punch error: '.$e->getMessage(), [
                'user_id' => $user->id,
                'device_serial' => $serialNumber,
                'log_id' => $attLog->id,
            ]);

            return response()->json(['message' => 'Failed to process punch.'], 500);
        }
    }

    /**
     * Paginated ATTLOG list for admin UI.
     */
    public function attLogs(Request $request)
    {
        $perPage = (int) $request->input('perPage', 25);
        $page = (int) $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('status');
        $deviceId = $request->input('device_id');

        $logs = $this->biometricService->queryAttLogs($search, $status, $deviceId, $perPage, $page);
        $stats = $this->biometricService->getAttLogStats();

        return response()->json([
            'logs' => $logs,
            'stats' => $stats,
        ]);
    }

    /**
     * Heartbeat / health-check from device (optional GET endpoint).
     */
    public function heartbeat(Request $request)
    {
        $authToken = $request->header('X-Device-Token') ?? $request->input('auth_token');
        $serialNumber = $request->input('device_serial');

        if (! $authToken || ! $serialNumber) {
            return response()->json(['message' => 'Missing parameters.'], 422);
        }

        $device = $this->biometricService->authenticateDevice($serialNumber, $authToken);

        if (! $device) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $this->biometricService->updateHeartbeat($device);

        return response()->json(['message' => 'OK', 'server_time' => now()->toISOString()]);
    }

    /**
     * ZKTeco ADMS Push Protocol - Handshake (GET /iclock/cdata)
     */
    public function admsHandshake(Request $request)
    {
        Log::info('ADMS handshake received', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query' => $request->query->all(),
        ]);

        $serialNumber = $request->query('SN');

        if (! $serialNumber) {
            Log::warning('ADMS handshake: missing serial number');

            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        $device = $this->biometricService->findDeviceBySerial($serialNumber);

        if (! $device) {
            Log::warning('ADMS handshake: unknown device', ['serial' => $serialNumber]);

            return response('ERROR', 404)->header('Content-Type', 'text/plain');
        }

        if (! $device->is_active) {
            Log::warning('ADMS handshake: inactive device', ['serial' => $serialNumber]);

            return response('ERROR', 403)->header('Content-Type', 'text/plain');
        }

        // Update heartbeat
        $this->biometricService->updateHeartbeat($device);

        // Dispatch device connected event
        event(new BiometricDeviceConnected($device));

        // Generate session ID
        $sessionId = $this->biometricService->generateSessionId();

        // Fetch pending command
        $command = $this->biometricService->fetchNextPendingCommand($device);

        if ($command) {
            $command->markAsSent();

            Log::info('ADMS handshake: sending command', [
                'serial' => $serialNumber,
                'command_id' => $command->id,
                'command_type' => $command->command_type,
            ]);

            return new Response(
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
        ]);

        $responseBody = $this->biometricService->buildHandshakeOptionsBody($serialNumber);

        return new Response(
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
     */
    public function admsPush(Request $request)
    {
        Log::info('ADMS push received', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'query' => $request->query->all(),
            'content_length' => strlen($request->getContent()),
        ]);

        $rawData = $request->getContent();
        $table = $request->query('table');
        $serialNumber = $this->biometricService->getSerialNumber($request);

        if (! $serialNumber) {
            Log::warning('ADMS push: missing serial number');

            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        $device = $this->biometricService->findDeviceBySerial($serialNumber);

        if (! $device || ! $device->is_active) {
            Log::warning('ADMS push: unknown or inactive device', ['serial' => $serialNumber]);

            return response('ERROR', 401)->header('Content-Type', 'text/plain');
        }

        // Handle Biometric Template Uploads (Roaming)
        if ($table === 'templatev10' || $table === 'facetmpv10') {
            $result = $this->biometricService->processTemplateUpload($rawData, $table, $serialNumber, $device);

            return $result['success']
                ? new Response('OK', 200, ['Content-Type' => 'text/plain'])
                : response('ERROR', $result['reason'] === 'invalid_format' ? 400 : 500)->header('Content-Type', 'text/plain');
        }

        // Handle User Enrollment from Device
        if ($table === 'USERINFO') {
            // Defense in depth: EnsureAdmsDeviceAuthorized already gates USERINFO,
            // but re-check here so device-initiated enrollment can never create or
            // rewrite users without a verified per-device token even if the route
            // middleware is ever detached. USERINFO is far higher risk than a punch.
            if (config('attendance.adms_enrollment_requires_token', true)
                && ! $request->attributes->get('adms_token_verified', false)) {
                Log::warning('ADMS push: USERINFO enrollment blocked (device token not verified)', [
                    'serial' => $serialNumber,
                    'device_id' => $device->id,
                ]);

                return response('ERROR', 401)->header('Content-Type', 'text/plain');
            }

            $result = $this->biometricService->processUserEnrollment($rawData, $serialNumber, $device);

            return $result['success']
                ? new Response('OK', 200, ['Content-Type' => 'text/plain'])
                : response('ERROR', $result['reason'] === 'invalid_format' ? 400 : 500)->header('Content-Type', 'text/plain');
        }

        // OPERLOG
        if ($table === 'OPERLOG') {
            $this->biometricService->storeOperLog($rawData, $serialNumber, $device);

            return new Response('OK', 200, ['Content-Type' => 'text/plain']);
        }

        // Command Acknowledgment
        if ($this->biometricService->isCommandAcknowledgment($rawData)) {
            $this->biometricService->processInlineAcknowledgment($rawData, $request);

            return new Response('OK', 200, ['Content-Type' => 'text/plain']);
        }

        // Default: Handle Attendance Logs push
        $result = $this->biometricService->processAttendanceLogs($rawData, $device, $serialNumber);

        return new Response(
            'OK',
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
            'command_type' => 'required|in:REBOOT,SET_TIME,ADD_USER,UPDATE_USER,DELETE_USER,CLEAR_LOG,CLEAR_DATA,GET_USERINFO,CHECK_ATTLOG',
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

        if ($validated['command_type'] === 'CHECK_ATTLOG') {
            $session = $this->biometricService->initiateLogDownload(
                $device,
                'manual',
                auth()->id() ?? $request->user()?->id,
                $validated['payload'] ?? null
            );

            if ($session) {
                // Also dispatch the monitoring job to prevent stuck sessions
                ProcessBiometricDownloadSession::dispatch($session);
                $command = $session->command;
            } else {
                return response()->json(['message' => 'Failed to initiate log download session.'], 500);
            }
        } else {
            $command = $this->biometricService->createCommand($device, $validated);
        }

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

        $commandsArray = $this->biometricService->getCommandHistory((int) $deviceId);

        return response()->json(['commands' => $commandsArray]);
    }

    /**
     * Get ADMS operation logs for monitoring.
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

        return response()->json(['logs' => $logs]);
    }

    /**
     * ADMS Get Request - Device requests pending commands
     */
    public function admsGetRequest(Request $request)
    {
        $serialNumber = $request->query('SN');

        if (! $serialNumber) {
            Log::warning('ADMS getrequest: missing serial number');

            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        $device = $this->biometricService->findDeviceBySerial($serialNumber);

        if (! $device) {
            Log::warning('ADMS getrequest: unknown device', ['serial' => $serialNumber]);

            return response('ERROR', 404)->header('Content-Type', 'text/plain');
        }

        if (! $device->is_active) {
            Log::warning('ADMS getrequest: inactive device', ['serial' => $serialNumber]);

            return response('ERROR', 403)->header('Content-Type', 'text/plain');
        }

        $this->biometricService->updateHeartbeat($device);

        $command = $this->biometricService->fetchNextPendingCommand($device);

        if ($command) {
            $command->markAsSent();

            Log::info('ADMS getrequest: sending command', [
                'serial' => $serialNumber,
                'command_id' => $command->id,
                'command_type' => $command->command_type,
            ]);

            return new Response(
                $command->toAdmsString(),
                200,
                ['Content-Type' => 'text/plain']
            );
        }

        Log::info('ADMS getrequest: no pending commands', ['serial' => $serialNumber]);

        return new Response(
            'OK',
            200,
            ['Content-Type' => 'text/plain']
        );
    }

    /**
     * ADMS Device Command - Process command acknowledgment from device
     */
    public function admsDeviceCmd(Request $request)
    {
        $serialNumber = $this->biometricService->getSerialNumber($request);
        $rawData = $request->getContent();

        if (! $serialNumber) {
            Log::warning('ADMS devicecmd: missing serial number');

            return response('ERROR', 400)->header('Content-Type', 'text/plain');
        }

        $device = $this->biometricService->findDeviceBySerial($serialNumber);

        if (! $device) {
            Log::warning('ADMS devicecmd: unknown device', ['serial' => $serialNumber]);

            return response('ERROR', 404)->header('Content-Type', 'text/plain');
        }

        $this->biometricService->acknowledgeCommand($rawData, $device, $serialNumber);

        $this->biometricService->updateHeartbeat($device);

        return new Response(
            'OK',
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

        return new Response(
            'OK',
            200,
            ['Content-Type' => 'text/plain']
        );
    }
}
