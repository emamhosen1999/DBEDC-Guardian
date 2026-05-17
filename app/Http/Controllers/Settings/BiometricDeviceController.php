<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\HRM\BiometricDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BiometricDeviceController extends Controller
{
    public function index()
    {
        $devices = BiometricDevice::orderBy('name')
            ->get()
            ->map(function ($device) {
                $device->is_online = $device->isOnline();
                $device->online_status = $device->getOnlineStatusAttribute();
                return $device;
            });

        $employees = User::select('id', 'name', 'employee_id')
            ->where('active', true)
            ->orderBy('name')
            ->get();

        if (request()->expectsJson()) {
            return response()->json([
                'devices'   => $devices,
                'employees' => $employees,
            ]);
        }

        return Inertia::render('Settings/BiometricDevices', [
            'title'     => 'Biometric Devices',
            'devices'   => $devices,
            'employees' => $employees,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name'          => 'required|string|max:255',
            'serial_number' => 'required|string|max:191|unique:biometric_devices,serial_number',
            'ip_address'    => 'nullable|ip',
            'location'      => 'nullable|string|max:255',
            'model'         => 'nullable|string|max:255',
            'protocol'      => 'nullable|in:push_sdk,adms',
            'is_active'     => 'boolean',
            'config'        => 'nullable|array',
        ]);

        $device = BiometricDevice::create($data);

        return response()->json([
            'message' => 'Device registered successfully.',
            'device'  => $device->fresh(),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $device = BiometricDevice::findOrFail($id);

        $data = $request->validate([
            'name'          => 'sometimes|required|string|max:255',
            'serial_number' => 'sometimes|required|string|max:191|unique:biometric_devices,serial_number,' . $id,
            'ip_address'    => 'nullable|ip',
            'location'      => 'nullable|string|max:255',
            'model'         => 'nullable|string|max:255',
            'protocol'      => 'nullable|in:push_sdk,adms',
            'is_active'     => 'boolean',
            'config'        => 'nullable|array',
        ]);

        $device->update($data);

        return response()->json([
            'message' => 'Device updated successfully.',
            'device'  => $device->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $device = BiometricDevice::findOrFail($id);
        $device->delete();

        return response()->json(['message' => 'Device deleted.']);
    }

    public function addUser(Request $request, $id)
    {
        $device = BiometricDevice::findOrFail($id);

        $data = $request->validate([
            'user_id'        => 'required|exists:users,id',
            'device_user_id' => 'required|string|max:50',
        ]);

        $exists = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('user_id', $data['user_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'User already mapped to this device.'], 422);
        }

        DB::table('biometric_device_users')->insert([
            'biometric_device_id' => $device->id,
            'user_id'             => $data['user_id'],
            'device_user_id'      => $data['device_user_id'],
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        $user = User::select('id', 'name', 'employee_id')->find($data['user_id']);

        return response()->json([
            'message'        => 'User mapped to device.',
            'user'           => $user,
            'device_user_id' => $data['device_user_id'],
        ], 201);
    }

    public function removeUser(Request $request, $id, $userId)
    {
        $device = BiometricDevice::findOrFail($id);

        DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('user_id', $userId)
            ->delete();

        return response()->json(['message' => 'User removed from device.']);
    }

    public function regenerateToken($id)
    {
        $device = BiometricDevice::findOrFail($id);
        $token  = $device->regenerateToken();

        return response()->json([
            'message'    => 'Token regenerated.',
            'auth_token' => $token,
        ]);
    }

    /**
     * Add a device user entry without linking to a system user (unlinked)
     */
    public function addDeviceEntry(Request $request, $id)
    {
        $device = BiometricDevice::findOrFail($id);

        $data = $request->validate([
            'device_user_id' => 'required|string|max:50',
        ]);

        $exists = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('device_user_id', $data['device_user_id'])
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Device user ID already exists.'], 422);
        }

        DB::table('biometric_device_users')->insert([
            'biometric_device_id' => $device->id,
            'user_id'             => null,
            'device_user_id'      => $data['device_user_id'],
            'is_active'           => true,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Update users_count
        $device->update(['users_count' => DB::table('biometric_device_users')->where('biometric_device_id', $device->id)->count()]);

        return response()->json([
            'message' => 'Device entry added.',
        ], 201);
    }

    /**
     * Link an unlinked device entry to a system user
     */
    public function linkDeviceUser(Request $request, $id)
    {
        $device = BiometricDevice::findOrFail($id);

        $data = $request->validate([
            'device_user_id' => 'required|string|max:50',
            'user_id'        => 'required|exists:users,id',
        ]);

        // Check if device user exists
        $entry = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('device_user_id', $data['device_user_id'])
            ->first();

        if (!$entry) {
            return response()->json(['message' => 'Device user entry not found.'], 404);
        }

        if ($entry->user_id) {
            return response()->json(['message' => 'Already linked to a user.'], 422);
        }

        // Check if user already mapped to this device
        $userExists = DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('user_id', $data['user_id'])
            ->exists();

        if ($userExists) {
            return response()->json(['message' => 'User already enrolled on this device.'], 422);
        }

        DB::table('biometric_device_users')
            ->where('id', $entry->id)
            ->update(['user_id' => $data['user_id'], 'updated_at' => now()]);

        $user = User::select('id', 'name', 'employee_id')->find($data['user_id']);

        return response()->json([
            'message' => 'User linked to device entry.',
            'user'    => $user,
        ]);
    }

    /**
     * Unlink a user from a device entry (set user_id to null)
     */
    public function unlinkDeviceUser(Request $request, $id, $userId)
    {
        $device = BiometricDevice::findOrFail($id);

        DB::table('biometric_device_users')
            ->where('biometric_device_id', $device->id)
            ->where('user_id', $userId)
            ->update(['user_id' => null, 'updated_at' => now()]);

        return response()->json(['message' => 'User unlinked from device.']);
    }

    /**
     * Get all device entries (linked + unlinked)
     */
    public function deviceUsers($id)
    {
        $device = BiometricDevice::findOrFail($id);

        $entries = DB::table('biometric_device_users as bdu')
            ->leftJoin('users as u', 'u.id', '=', 'bdu.user_id')
            ->where('bdu.biometric_device_id', $device->id)
            ->select(
                'bdu.id',
                'bdu.user_id',
                'bdu.device_user_id',
                'bdu.is_active',
                'u.name',
                'u.employee_id'
            )
            ->get();

        return response()->json(['entries' => $entries]);
    }

    /**
     * Get all active biometric devices for dropdown
     */
    public function getActiveDevices()
    {
        $devices = BiometricDevice::active()
            ->select('id', 'name', 'serial_number', 'location', 'model')
            ->orderBy('name')
            ->get();

        return response()->json(['devices' => $devices]);
    }

    /**
     * Ping a biometric device
     */
    public function pingDevice(Request $request, $id)
    {
        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if (! $device->ip_address) {
            return response()->json(['message' => 'Device has no IP address configured'], 400);
        }

        $ipAddress = $device->ip_address;
        $startTime = microtime(true);

        // Try to ping the device
        $pingResult = $this->executePing($ipAddress);

        $latency = round((microtime(true) - $startTime) * 1000, 2);

        return response()->json([
            'success' => $pingResult,
            'latency' => $latency,
            'ip_address' => $ipAddress,
            'message' => $pingResult ? 'Device is reachable' : 'Device is unreachable',
        ]);
    }

    /**
     * Execute ping command
     */
    private function executePing($ip)
    {
        try {
            // Windows: ping -n 1 -w 1000 IP
            // Linux/Mac: ping -c 1 -W 1 IP
            $os = PHP_OS_FAMILY;
            $command = $os === 'Windows'
                ? "ping -n 1 -w 1000 {$ip}"
                : "ping -c 1 -W 1 {$ip}";

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            return $exitCode === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get device health metrics
     */
    public function getHealthMetrics()
    {
        $devices = BiometricDevice::all()->map(function ($device) {
            $isOnline = $device->isOnline();
            $lastHeartbeat = $device->last_heartbeat_at;
            
            // Calculate health score (0-100)
            $healthScore = 100;
            
            // Deduct points for offline status
            if (!$isOnline) {
                $healthScore -= 50;
            }
            
            // Deduct points for old heartbeat (more than 5 minutes)
            if ($lastHeartbeat && $lastHeartbeat->lt(now()->subMinutes(5))) {
                $healthScore -= 30;
            }
            
            // Deduct points for very old heartbeat (more than 1 hour)
            if ($lastHeartbeat && $lastHeartbeat->lt(now()->subHour())) {
                $healthScore -= 20;
            }
            
            // Deduct points for inactive device
            if (!$device->is_active) {
                $healthScore -= 10;
            }
            
            // Ensure score is between 0 and 100
            $healthScore = max(0, min(100, $healthScore));
            
            // Calculate uptime (days since creation or last reset)
            $uptimeDays = $device->created_at ? now()->diffInDays($device->created_at) : 0;
            
            // Get latency from last ping (stored in config or calculate fresh)
            $latency = null;
            if ($device->ip_address) {
                $startTime = microtime(true);
                $reachable = $this->executePing($device->ip_address);
                $latency = $reachable ? round((microtime(true) - $startTime) * 1000, 2) : null;
            }
            
            return [
                'id' => $device->id,
                'name' => $device->name,
                'serial_number' => $device->serial_number,
                'ip_address' => $device->ip_address,
                'is_online' => $isOnline,
                'is_active' => $device->is_active,
                'last_heartbeat' => $lastHeartbeat ? $lastHeartbeat->toISOString() : null,
                'latency' => $latency,
                'uptime_days' => $uptimeDays,
                'health_score' => $healthScore,
                'status' => $healthScore >= 80 ? 'healthy' : ($healthScore >= 50 ? 'warning' : 'critical'),
            ];
        });

        $totalDevices = $devices->count();
        $onlineDevices = $devices->where('is_online', true)->count();
        $offlineDevices = $totalDevices - $onlineDevices;
        $healthyDevices = $devices->where('status', 'healthy')->count();
        $warningDevices = $devices->where('status', 'warning')->count();
        $criticalDevices = $devices->where('status', 'critical')->count();
        
        $overallHealthScore = $totalDevices > 0 ? round($devices->avg('health_score'), 1) : 100;

        return response()->json([
            'devices' => $devices,
            'summary' => [
                'total' => $totalDevices,
                'online' => $onlineDevices,
                'offline' => $offlineDevices,
                'healthy' => $healthyDevices,
                'warning' => $warningDevices,
                'critical' => $criticalDevices,
                'overall_health_score' => $overallHealthScore,
            ],
        ]);
    }

    /**
     * Get ADMS request logs
     */
    public function getAdmsLogs(Request $request)
    {
        $limit = $request->get('limit', 100);
        $logFile = storage_path('logs/laravel.log');

        if (! file_exists($logFile)) {
            return response()->json(['logs' => []]);
        }

        $logs = [];
        $file = new \SplFileObject($logFile);
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();
        $linesToRead = min($limit, $totalLines);

        for ($i = 0; $i < $linesToRead; $i++) {
            $file->seek($totalLines - $linesToRead + $i);
            $line = trim($file->current());
            if (empty($line)) continue;

            // Parse log line and extract ADMS-related entries
            if (str_contains($line, 'ADMS') || str_contains($line, 'biometric')) {
                // Extract timestamp from log line format: [2026-05-17 11:31:10]
                $timestamp = null;
                if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
                    $timestamp = $matches[1];
                }

                $logs[] = [
                    'id' => $totalLines - $linesToRead + $i,
                    'message' => $line,
                    'level' => str_contains($line, 'ERROR') ? 'error' : (str_contains($line, 'WARNING') ? 'warning' : 'info'),
                    'created_at' => $timestamp,
                ];
            }
        }

        return response()->json([
            'logs' => array_reverse($logs),
            'total' => count($logs),
            'current_page' => 1,
            'per_page' => $limit,
        ]);
    }

    /**
     * Get OPERLOG entries from database
     */
    public function getOperLogs(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'device_id' => 'nullable|exists:biometric_devices,id',
        ]);

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        $deviceId = $request->input('device_id');

        $query = DB::table('biometric_oper_logs')
            ->orderBy('occurred_at', 'desc');

        if ($deviceId) {
            $query->where('biometric_device_id', $deviceId);
        }

        $logs = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'logs' => $logs->items(),
            'current_page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }

    /**
     * Get ATTLOG entries (attendance logs from biometric devices)
     */
    public function getAttLogs(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'device_id' => 'nullable|exists:biometric_devices,id',
            'user_id' => 'nullable|exists:users,id',
        ]);

        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        $deviceId = $request->input('device_id');
        $userId = $request->input('user_id');

        $query = DB::table('biometric_att_logs')
            ->orderBy('punch_time', 'desc');

        if ($deviceId) {
            $query->where('biometric_device_id', $deviceId);
        }

        if ($userId) {
            $query->where('user_id', $userId);
        }

        $logs = $query->paginate($perPage, ['*'], 'page', $page);

        $deviceIds = collect($logs->items())->pluck('biometric_device_id')->unique()->toArray();
        $devices = BiometricDevice::whereIn('id', $deviceIds)->pluck('name', 'id')->toArray();

        $userIds = collect($logs->items())->pluck('user_id')->unique()->toArray();
        $users = User::whereIn('id', $userIds)->pluck('name', 'id')->toArray();

        $logsWithDetails = collect($logs->items())->map(function ($log) use ($devices, $users) {
            $punchTime = $log->punch_time ? new \DateTime($log->punch_time) : null;
            return [
                'id' => $log->id,
                'device_id' => $log->biometric_device_id,
                'device_name' => $devices[$log->biometric_device_id] ?? 'Unknown',
                'serial_number' => $log->serial_number,
                'user_pin' => $log->user_pin,
                'user_id' => $log->user_id,
                'user_name' => $log->user_id ? ($users[$log->user_id] ?? 'Unknown') : 'Unlinked',
                'punch_time' => $log->punch_time,
                'date' => $punchTime ? $punchTime->format('Y-m-d') : null,
                'time' => $punchTime ? $punchTime->format('H:i:s') : null,
                'check_type' => $log->check_type,
                'created_at' => $log->created_at,
            ];
        })->toArray();

        return response()->json([
            'logs' => $logsWithDetails,
            'current_page' => $logs->currentPage(),
            'per_page' => $logs->perPage(),
            'total' => $logs->total(),
        ]);
    }

    /**
     * Bulk ping multiple devices
     */
    public function bulkPing(Request $request)
    {
        $request->validate([
            'device_ids' => 'required|array',
            'device_ids.*' => 'exists:biometric_devices,id',
        ]);

        try {
            $deviceIds = $request->input('device_ids');
            $results = [];

            foreach ($deviceIds as $deviceId) {
                $device = BiometricDevice::find($deviceId);
                if (!$device || !$device->ip_address) {
                    $results[] = ['id' => $deviceId, 'success' => false, 'message' => 'No IP address'];
                    continue;
                }

                $startTime = microtime(true);
                $pingResult = $this->executePing($device->ip_address);
                $latency = round((microtime(true) - $startTime) * 1000, 2);

                $results[] = [
                    'id' => $deviceId,
                    'success' => $pingResult,
                    'latency' => $latency,
                    'message' => $pingResult ? 'Reachable' : 'Unreachable',
                ];
            }

            $reachableCount = collect($results)->where('success', true)->count();

            return response()->json([
                'message' => "Pinged {$deviceIds} device(s). {$reachableCount} reachable.",
                'results' => $results,
                'reachable_count' => $reachableCount,
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'error' => 'Failed to ping devices.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Bulk delete multiple devices
     */
    public function bulkDelete(Request $request)
    {
        $request->validate([
            'device_ids' => 'required|array',
            'device_ids.*' => 'exists:biometric_devices,id',
        ]);

        try {
            $deviceIds = $request->input('device_ids');
            $count = DB::transaction(function () use ($deviceIds) {
                // Clean up user mappings for all devices
                DB::table('biometric_device_users')
                    ->whereIn('biometric_device_id', $deviceIds)
                    ->delete();

                // Clean up device commands for all devices
                DB::table('biometric_device_commands')
                    ->whereIn('biometric_device_id', $deviceIds)
                    ->delete();

                // Delete devices
                return BiometricDevice::whereIn('id', $deviceIds)->delete();
            });

            return response()->json([
                'message' => "{$count} device(s) deleted successfully.",
                'count' => $count,
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'error' => 'Failed to delete devices.',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
