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
        $devices = BiometricDevice::withCount('users')
            ->orderBy('name')
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

        // Update users_count
        $device->update(['users_count' => DB::table('biometric_device_users')->where('biometric_device_id', $device->id)->count()]);

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

        // Update users_count
        $device->update(['users_count' => DB::table('biometric_device_users')->where('biometric_device_id', $device->id)->count()]);

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
        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $relevantLines = array_slice(array_reverse($lines), 0, $limit);

        foreach ($relevantLines as $line) {
            if (str_contains($line, 'ADMS') || str_contains($line, 'iclock') || str_contains($line, 'biometric')) {
                $logs[] = [
                    'timestamp' => $this->extractTimestamp($line),
                    'message' => $line,
                    'type' => $this->determineLogType($line),
                ];
            }
        }

        return response()->json(['logs' => $logs]);
    }

    /**
     * Extract timestamp from log line
     */
    private function extractTimestamp($line)
    {
        if (preg_match('/\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/', $line, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Determine log type based on content
     */
    private function determineLogType($line)
    {
        if (str_contains($line, 'handshake')) return 'handshake';
        if (str_contains($line, 'push')) return 'push';
        if (str_contains($line, 'command')) return 'command';
        if (str_contains($line, 'attendance')) return 'attendance';
        if (str_contains($line, 'ERROR')) return 'error';
        if (str_contains($line, 'WARNING')) return 'warning';
        return 'info';
    }
}
