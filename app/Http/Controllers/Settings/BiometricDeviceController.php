<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessBiometricDownloadSession;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricAttLog;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\BiometricDownloadSession;
use App\Models\User;
use App\Services\Biometric\BiometricProcessingService;
use App\Services\Biometric\DeviceCapabilityService;
use App\Services\Biometric\DeviceReconciliationService;
use App\Services\Biometric\TemplateRoamingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class BiometricDeviceController extends Controller
{
    protected BiometricProcessingService $biometricService;

    protected DeviceCapabilityService $capabilityService;

    public function __construct(
        BiometricProcessingService $biometricService,
        DeviceCapabilityService $capabilityService
    ) {
        $this->biometricService = $biometricService;
        $this->capabilityService = $capabilityService;
    }

    public function index()
    {
        $devices = BiometricDevice::orderBy('name')
            ->get()
            ->map(function ($device) {
                $device->is_online = $device->isOnline();
                $device->online_status = $device->getOnlineStatusAttribute();

                return $device;
            });

        $employees = User::select('employee_id as id', 'employee_id', 'name')
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        if (request()->expectsJson()) {
            return response()->json([
                'devices' => $devices,
                'employees' => $employees,
            ]);
        }

        return Inertia::render('Settings/BiometricDevices', [
            'title' => 'Biometric Devices',
            'devices' => $devices,
            'employees' => $employees,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'serial_number' => 'required|string|max:191|unique:biometric_devices,serial_number',
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'location' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'protocol' => 'nullable|in:push_sdk,adms',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
            'config' => 'nullable|array',
            // adms_token is intentionally NOT accepted here. It is a shared secret
            // and must be server-generated via the regenerate-adms-token endpoint,
            // never typed in by an admin and echoed back through a form.
        ]);

        $device = BiometricDevice::create($data);

        // Auto-link new device to the single Biometric AT
        $device->attendanceTypes()->syncWithoutDetaching([$this->getBiometricAt()->id]);

        return response()->json([
            'message' => 'Device registered successfully.',
            'device' => $device->fresh(),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $device = BiometricDevice::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'serial_number' => 'sometimes|required|string|max:191|unique:biometric_devices,serial_number,'.$id,
            'ip_address' => 'nullable|ip',
            'port' => 'nullable|integer|min:1|max:65535',
            'location' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'protocol' => 'nullable|in:push_sdk,adms',
            'is_active' => 'boolean',
            'notes' => 'nullable|string|max:1000',
            'config' => 'nullable|array',
            // adms_token is intentionally NOT accepted here — see store().
        ]);

        $device->update($data);

        return response()->json([
            'message' => 'Device updated successfully.',
            'device' => $device->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $device = BiometricDevice::findOrFail($id);
        $device->delete();

        return response()->json(['message' => 'Device deleted.']);
    }

    public function regenerateToken($id)
    {
        $device = BiometricDevice::findOrFail($id);
        $token = $device->regenerateToken();

        return response()->json([
            'message' => 'Token regenerated.',
            'auth_token' => $token,
        ]);
    }

    /**
     * Provision (or rotate) the per-device ADMS shared secret used by the
     * EnsureAdmsDeviceAuthorized middleware on the /iclock/* push endpoints.
     *
     * This is the ONLY write path for biometric_devices.adms_token — it is not a
     * mass-assignable form field on purpose. The plaintext token is returned
     * here ONCE so the admin can paste it into the device's server settings;
     * it is never echoed back by index()/show() afterwards.
     *
     * Note this rotates a live credential: until the device itself is updated
     * with the new token it will fail auth (rejected in strict mode, logged in
     * observe mode).
     */
    public function regenerateAdmsToken($id)
    {
        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        $token = $device->regenerateAdmsToken();

        // Credential rotation must be auditable. Never log the token value.
        Log::info('ADMS device token regenerated', [
            'device_id' => $device->id,
            'serial' => $device->serial_number,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'ADMS token regenerated. Copy it now — it will not be shown again.',
            'adms_token' => $token,
        ]);
    }

    /**
     * Returns the single system-wide Biometric attendance type, creating it if absent.
     */
    protected function getBiometricAt(): AttendanceType
    {
        return AttendanceType::firstOrCreate(
            ['slug' => 'biometric'],
            ['name' => 'Biometric Device', 'is_active' => true, 'config' => []]
        );
    }

    /**
     * Sync all existing devices that are not yet linked to the Biometric AT.
     * Called once from the index to backfill historical devices.
     */
    public function syncAllToPool()
    {
        $at = $this->getBiometricAt();
        $deviceIds = BiometricDevice::pluck('id');
        $at->biometricDevices()->syncWithoutDetaching($deviceIds);

        return response()->json(['message' => 'All devices synced to Biometric AT.', 'at_id' => $at->id]);
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
     * Execute ping command.
     *
     * BLOCKING. This shells out and waits, so it must only ever be reached from
     * an endpoint the admin explicitly triggered (pingDevice / bulkPing) — never
     * from a polled one. See getHealthMetrics() for why.
     *
     * The IP is re-validated here rather than trusted from the column. It is
     * currently written under a `nullable|ip` rule, but a controller that is one
     * bad migration or one seeder away from taking arbitrary text must not be
     * the thing that decides whether a string reaches a shell.
     *
     * Marked protected, not private, so a test can substitute it and assert that
     * the polled health endpoint never calls it.
     */
    protected function executePing($ip)
    {
        try {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return false;
            }

            // Windows: ping -n 1 -w 1000 IP
            // Linux/Mac: ping -c 1 -W 1 IP
            $os = PHP_OS_FAMILY;
            $safeIp = escapeshellarg($ip);
            $command = $os === 'Windows'
                ? 'ping -n 1 -w 1000 '.$safeIp
                : 'ping -c 1 -W 1 '.$safeIp;

            $output = [];
            $exitCode = 0;
            exec($command, $output, $exitCode);

            return $exitCode === 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get device health metrics.
     *
     * Deliberately does NOT ping. The admin panel auto-refreshes this endpoint
     * every 30 seconds, and the previous implementation ran a blocking
     * exec("ping ... -w 1000") once per device inside the map. With N devices,
     * most of them offline (the ping only returns early when the host answers),
     * a single request held a PHP-FPM worker for up to N seconds — every 30
     * seconds, multiplied by every open admin tab. On a small pool that is
     * worker exhaustion, and the tab that caused it is the one an admin opens
     * precisely when something is already wrong.
     *
     * Liveness now comes from last_heartbeat_at, which is not merely the cheap
     * option, it is the correct one. These are ADMS push devices: they open the
     * connection, we never dial them. last_heartbeat_at is stamped by every
     * /iclock/* poll, so it measures the thing we actually care about — "is this
     * terminal still talking to us". ICMP does not: a device can answer ping
     * from a healthy NIC while its ADMS client is wedged, misconfigured onto the
     * wrong server URL, or holding a stale token, and the old code would have
     * scored that unit as reachable.
     *
     * On-demand ICMP is still available, unchanged, on the explicit
     * biometric-devices.ping and biometric-devices.bulk.ping actions — an admin
     * clicking a button can afford to wait; a 30-second poll cannot.
     */
    public function getHealthMetrics()
    {
        $devices = BiometricDevice::all()->map(function ($device) {
            $isOnline = $device->isOnline();
            $lastHeartbeat = $device->last_heartbeat_at;

            // Calculate health score (0-100)
            $healthScore = 100;

            // Deduct points for offline status
            if (! $isOnline) {
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
            if (! $device->is_active) {
                $healthScore -= 10;
            }

            // Ensure score is between 0 and 100
            $healthScore = max(0, min(100, $healthScore));

            // Calculate uptime (days since creation or last reset).
            // Carbon 3 returns a signed float here, so the operands have to be
            // this way round or an older device reports negative uptime.
            $uptimeDays = $device->created_at ? (int) $device->created_at->diffInDays(now()) : 0;

            return [
                'id' => $device->id,
                'name' => $device->name,
                'serial_number' => $device->serial_number,
                'ip_address' => $device->ip_address,
                'is_online' => $isOnline,
                'is_active' => $device->is_active,
                'last_heartbeat' => $lastHeartbeat ? $lastHeartbeat->toISOString() : null,
                // Kept, and kept null on purpose: no ICMP round trip was taken,
                // so there is no honest number to put here. The key stays so the
                // existing table column keeps rendering (it falls back to 'N/A'),
                // and latency_measured says outright that null means "not
                // measured" rather than "measured, unreachable".
                'latency' => null,
                'latency_measured' => false,
                'liveness_source' => 'last_heartbeat_at',
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
            'meta' => [
                'ping_performed' => false,
                'liveness_source' => 'last_heartbeat_at',
                'online_threshold_minutes' => 5,
                'note' => 'Reachability is derived from the last ADMS heartbeat, not ICMP. Use the per-device ping action for an on-demand network check.',
            ],
        ]);
    }

    /**
     * How many trailing log lines getAdmsLogs() will look at before it gives up
     * and reports the result as truncated. Bounds both memory and the amount of
     * disk this endpoint can be made to read.
     */
    private const ADMS_LOG_SCAN_LINES = 5000;

    /**
     * Get ADMS request logs.
     *
     * Reads the application log from whatever channel is actually configured
     * (see resolveLogFiles) rather than a hardcoded laravel.log, scans a bounded
     * tail of it backwards, and paginates the ADMS-related lines it finds.
     *
     * Three things this fixes, all of which were silent failures:
     *
     *  - the path was hardcoded to storage/logs/laravel.log, so the moment the
     *    log channel is `daily` (which writes laravel-Y-m-d.log) this endpoint
     *    read a file that did not exist and returned an empty list. An empty log
     *    viewer is indistinguishable from "nothing has gone wrong", and the one
     *    thing it is for is diagnosing devices EnsureAdmsDeviceAuthorized has
     *    rejected. Both layouts are handled now.
     *
     *  - pagination was fabricated: current_page was the literal 1 and total was
     *    the size of a fixed tail slice, while the UI renders real page controls
     *    against those numbers. The keys are unchanged, but the values are now
     *    the actual page and the actual number of matched lines in the scanned
     *    window (with scan_truncated saying when that count is a floor, not a
     *    total, so the UI is never told a lower bound is exact).
     *
     *  - it was O(n^2): SplFileObject::seek() on a forward-only stream rewinds
     *    and re-reads from line 0, and it was called once per line. tailLines()
     *    walks backwards in 64 KB blocks and touches each byte once.
     */
    public function getAdmsLogs(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:500',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        // `limit` is the older parameter name and is still honoured so an
        // existing caller does not silently start getting a different page size.
        $perPage = (int) ($request->input('per_page') ?? $request->input('limit') ?? 100);
        $page = (int) $request->input('page', 1);

        try {
            $files = $this->resolveLogFiles();

            if ($files === []) {
                // No log file at all is a normal state (fresh deploy, log
                // cleared, non-file channel such as stderr/syslog). Say so
                // rather than erroring, and still return the full key set.
                return response()->json([
                    'logs' => [],
                    'total' => 0,
                    'current_page' => 1,
                    'per_page' => $perPage,
                    'last_page' => 1,
                    'from' => null,
                    'to' => null,
                    'scanned_lines' => 0,
                    'scan_truncated' => false,
                    'log_files' => [],
                    'message' => 'No readable application log file was found for the configured log channel.',
                ]);
            }

            [$matched, $scannedLines, $truncated] = $this->scanAdmsLogLines($files);

            // Real pagination over the matched set, which is already newest-first.
            $total = count($matched);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = ($page - 1) * $perPage;
            $slice = array_slice($matched, $offset, $perPage);

            return response()->json([
                'logs' => $slice,
                'total' => $total,
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'from' => $slice === [] ? null : $offset + 1,
                'to' => $slice === [] ? null : $offset + count($slice),
                'scanned_lines' => $scannedLines,
                // True when the scan window filled up before the log ran out, in
                // which case `total` is the number of matches within the newest
                // ADMS_LOG_SCAN_LINES lines, not within all history.
                'scan_truncated' => $truncated,
                'log_files' => array_map('basename', $files),
            ]);
        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'error' => 'Failed to read the application log.',
                'message' => $e->getMessage(),
                'logs' => [],
                'total' => 0,
                'current_page' => 1,
                'per_page' => $perPage,
            ], 500);
        }
    }

    /**
     * Resolve the on-disk log files for the configured logging channel, newest
     * modification first.
     *
     * Handles both file layouts because both are live possibilities in this app:
     * `single` writes storage/logs/laravel.log, `daily` writes
     * storage/logs/laravel-Y-m-d.log next to it. Rather than branch on the
     * driver and read only one, both are collected — a box that has been
     * switched between the two (this one has: rotated laravel-Y-m-d.log files
     * sit beside a live laravel.log) still shows its full recent history, and
     * flipping LOG_CHANNEL does not blank the viewer.
     *
     * Channels with no file at all (stderr, syslog, papertrail, null) simply
     * contribute nothing, which is the honest answer for them.
     */
    protected function resolveLogFiles(): array
    {
        $bases = [];

        foreach ($this->resolveLogChannelNames() as $channel) {
            $config = config('logging.channels.'.$channel);

            if (! is_array($config)) {
                continue;
            }

            if (! in_array($config['driver'] ?? null, ['single', 'daily'], true)) {
                continue;
            }

            if (! empty($config['path'])) {
                $bases[] = (string) $config['path'];
            }
        }

        if ($bases === []) {
            // Nothing file-based is configured. Fall back to the framework
            // default location so a misconfigured channel does not hide logs
            // that are demonstrably on disk.
            $bases[] = storage_path('logs/laravel.log');
        }

        $files = [];

        foreach (array_unique($bases) as $base) {
            $directory = dirname($base);
            $name = pathinfo($base, PATHINFO_FILENAME);
            $extension = pathinfo($base, PATHINFO_EXTENSION);
            $suffix = $extension !== '' ? '.'.$extension : '';

            // single layout
            if (is_file($base) && is_readable($base)) {
                $files[$base] = true;
            }

            // daily layout: <name>-YYYY-MM-DD<suffix>
            $matches = glob($directory.DIRECTORY_SEPARATOR.$name.'-*'.$suffix) ?: [];

            foreach ($matches as $match) {
                if (is_file($match) && is_readable($match)) {
                    $files[$match] = true;
                }
            }
        }

        $files = array_keys($files);

        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files;
    }

    /**
     * The channel names that actually write files for the default channel,
     * unwrapping a one-level `stack`. Nested stacks are not unwrapped — Laravel
     * permits them but this app does not use one, and a cycle in a config file
     * should not be able to hang an admin endpoint.
     */
    protected function resolveLogChannelNames(): array
    {
        $default = (string) config('logging.default', 'stack');
        $config = config('logging.channels.'.$default);

        if (is_array($config) && ($config['driver'] ?? null) === 'stack') {
            $channels = array_map('trim', (array) ($config['channels'] ?? []));

            return array_values(array_filter($channels, fn ($channel) => $channel !== ''));
        }

        return [$default];
    }

    /**
     * Walk the given files newest-first, pulling ADMS-related lines out of a
     * bounded tail of each.
     *
     * Returns [matched entries newest-first, lines scanned, whether the scan
     * window was exhausted before the logs were].
     */
    protected function scanAdmsLogLines(array $files): array
    {
        $budget = self::ADMS_LOG_SCAN_LINES;
        $matched = [];
        $scanned = 0;
        $truncated = false;
        $id = 0;

        foreach ($files as $file) {
            if ($budget <= 0) {
                $truncated = true;
                break;
            }

            $reachedStartOfFile = false;
            $lines = $this->tailLines($file, $budget, $reachedStartOfFile);
            $scanned += count($lines);

            if (! $reachedStartOfFile) {
                // We stopped before the top of this file, so there are older
                // lines here we never looked at.
                $truncated = true;
            }

            $budget -= count($lines);

            foreach ($lines as $line) {
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                if (stripos($line, 'ADMS') === false && stripos($line, 'biometric') === false) {
                    continue;
                }

                // Extract timestamp from log line format: [2026-05-17 11:31:10]
                $timestamp = null;
                if (preg_match('/\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})/', $line, $matches)) {
                    $timestamp = str_replace('T', ' ', $matches[1]);
                }

                $matched[] = [
                    'id' => $id++,
                    'message' => $line,
                    'level' => $this->parseLogLevel($line),
                    'created_at' => $timestamp,
                    'file' => basename($file),
                ];
            }
        }

        return [$matched, $scanned, $truncated];
    }

    /**
     * Read up to $maxLines trailing lines of a file, newest first, without
     * reading the whole file.
     *
     * Seeks backwards in blocks from EOF and stops as soon as it has enough
     * lines, so cost is proportional to the tail requested and not to the size
     * of a multi-megabyte laravel.log. The byte ceiling guards the degenerate
     * case of a log with very few newlines (a single enormous serialised
     * payload), which would otherwise pull the whole file into memory.
     */
    protected function tailLines(string $path, int $maxLines, ?bool &$reachedStartOfFile = null): array
    {
        $reachedStartOfFile = false;

        if ($maxLines < 1) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            $blockSize = 64 * 1024;
            $maxBytes = 16 * 1024 * 1024;
            $bytesRead = 0;

            fseek($handle, 0, SEEK_END);
            $position = ftell($handle);

            if ($position === false || $position === 0) {
                $reachedStartOfFile = $position === 0;

                return [];
            }

            $lines = [];
            // Bytes of a line whose beginning lies further left than we have read.
            $remainder = '';
            // Set when the line budget ran out with material still unread. Without
            // it, a file small enough to sit inside one block but longer than
            // $maxLines would leave $position at 0 and be reported as fully
            // scanned, which is the same silent lie as the fake pagination: the
            // caller would publish a floor as an exact total.
            $stoppedOnBudget = false;

            while ($position > 0 && count($lines) < $maxLines && $bytesRead < $maxBytes) {
                $read = (int) min($blockSize, $position);
                $position -= $read;
                $bytesRead += $read;

                fseek($handle, $position, SEEK_SET);
                $chunk = fread($handle, $read);

                if ($chunk === false) {
                    break;
                }

                $parts = explode("\n", $chunk.$remainder);
                // The first element may continue leftwards into the next block.
                $remainder = array_shift($parts);

                for ($i = count($parts) - 1; $i >= 0; $i--) {
                    if (count($lines) >= $maxLines) {
                        $stoppedOnBudget = true;

                        break;
                    }

                    if (trim($parts[$i]) === '') {
                        continue;
                    }

                    $lines[] = rtrim($parts[$i], "\r");
                }
            }

            if ($position === 0 && trim($remainder) !== '') {
                if (count($lines) < $maxLines) {
                    $lines[] = rtrim($remainder, "\r");
                } else {
                    $stoppedOnBudget = true;
                }
            }

            $reachedStartOfFile = $position === 0 && ! $stoppedOnBudget;

            return $lines;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Map a Monolog line ("local.ERROR: ...") onto the level string the UI
     * colours by.
     */
    protected function parseLogLevel(string $line): string
    {
        if (preg_match('/\.(EMERGENCY|ALERT|CRITICAL|ERROR|WARNING|NOTICE|INFO|DEBUG)\b/', $line, $matches)) {
            $level = strtolower($matches[1]);

            return match ($level) {
                'emergency', 'alert', 'critical', 'error' => 'error',
                'warning' => 'warning',
                default => 'info',
            };
        }

        return 'info';
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
        $perPage = (int) $request->input('per_page', 25);
        $page = (int) $request->input('page', 1);
        $search = $request->input('search');
        $status = $request->input('status');
        $deviceId = $request->input('device_id');

        $query = BiometricAttLog::with([
            'user:employee_id,name',
            'device:id,name,serial_number',
        ])->orderByDesc('punch_time');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('user_pin', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->withTrashed()
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('employee_id', 'like', "%{$search}%"));
            });
        }

        if ($status && $status !== 'all') {
            $query->where('punch_status', $status);
        }

        if ($deviceId && $deviceId !== 'all') {
            $query->where('biometric_device_id', $deviceId);
        }

        $logs = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'logs' => $logs,
            'stats' => [
                'total' => BiometricAttLog::count(),
                'processed' => BiometricAttLog::where('punch_status', 'processed')->count(),
                'unknown_user' => BiometricAttLog::where('punch_status', 'unknown_user')->count(),
                'failed' => BiometricAttLog::whereIn('punch_status', ['failed', 'wrong_device', 'duplicate'])->count(),
            ],
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
                if (! $device || ! $device->ip_address) {
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
                'message' => 'Pinged '.count($deviceIds).' device(s). '.$reachableCount.' reachable.',
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
                // Detach attendance type associations for all devices
                DB::table('attendance_type_biometric_device')
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

    public function downloadLogs(Request $request, $id)
    {
        $device = BiometricDevice::findOrFail($id);

        if (! $device->is_active) {
            return response()->json(['message' => 'Device is inactive.'], 403);
        }

        if (! $device->isAdms()) {
            return response()->json(['message' => 'Log downloads are only supported for ADMS devices.'], 400);
        }

        try {
            $session = $this->biometricService->initiateLogDownload(
                $device,
                'manual',
                auth()->id()
            );

            // Also dispatch the monitoring job
            ProcessBiometricDownloadSession::dispatch($session);

            return response()->json([
                'message' => 'Log download initiated. The device will be commanded to send its logs upon its next connection.',
                'session' => $session,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to initiate log download: '.$e->getMessage(),
            ], 500);
        }
    }

    public function bulkDownloadLogs(Request $request)
    {
        $request->validate([
            'device_ids' => 'required|array',
            'device_ids.*' => 'exists:biometric_devices,id',
        ]);

        $deviceIds = $request->input('device_ids');

        try {
            $result = $this->biometricService->bulkInitiateLogDownload(
                $deviceIds,
                'bulk',
                auth()->id()
            );

            foreach ($result['sessions'] as $session) {
                ProcessBiometricDownloadSession::dispatch($session);
            }

            $count = count($result['sessions']);
            $skippedCount = count($result['skipped']);

            return response()->json([
                'message' => "Log download initiated for {$count} device(s).".($skippedCount > 0 ? " Skipped {$skippedCount} device(s)." : ''),
                'sessions' => $result['sessions'],
                'skipped' => $result['skipped'],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to bulk initiate log download: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getDownloadHistory(Request $request)
    {
        $perPage = $request->input('per_page', 20);
        $page = (int) $request->input('page', 1);
        $deviceId = $request->input('device_id');

        if ($perPage === 'all' || (int) $perPage === -1) {
            $sessions = $this->biometricService->getDownloadSessions(
                $deviceId ? (int) $deviceId : null,
                'all'
            );
        } else {
            $sessions = $this->biometricService->getDownloadSessions(
                $deviceId ? (int) $deviceId : null,
                (int) $perPage,
                $page
            );
        }

        return response()->json([
            'sessions' => $sessions,
        ]);
    }

    public function getSessionLogs(Request $request, $id)
    {
        try {
            $session = BiometricDownloadSession::with('command')->findOrFail($id);

            $query = BiometricAttLog::with('user:employee_id,name')
                ->where('biometric_device_id', $session->biometric_device_id)
                ->where('punch_status', 'downloaded');

            $start = $session->started_at ?? $session->created_at;
            $end = $session->completed_at ?? now();

            // Add a buffer of 10 seconds before and after to account for any tiny race conditions
            $query->whereBetween('created_at', [
                $start->copy()->subSeconds(10),
                $end->copy()->addSeconds(10),
            ]);

            $logs = $query->orderBy('punch_time', 'asc')->get();

            $grouped = [];
            foreach ($logs as $log) {
                $pin = $log->user_pin;
                $name = $log->user ? $log->user->name : 'Unknown';
                $punchTime = $log->punch_time;

                if (! $punchTime) {
                    continue;
                }

                $date = $punchTime->format('Y-m-d');
                $time = $punchTime->format('H:i:s');

                $key = $pin.'_'.$date;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = [
                        'pin' => $pin,
                        'name' => $name,
                        'date' => $date,
                        'times' => [],
                    ];
                }
                $grouped[$key]['times'][] = $time;
            }

            $rows = [];
            foreach ($grouped as $key => $group) {
                $times = $group['times'];
                sort($times);

                $inTimeRaw = count($times) > 0 ? $times[0] : '—';
                $outTimeRaw = count($times) > 1 ? $times[count($times) - 1] : '—';

                $rows[] = [
                    'pin' => $group['pin'],
                    'name' => $group['name'],
                    'date' => $group['date'],
                    'inTime' => $this->formatTo12Hour($inTimeRaw),
                    'outTime' => $this->formatTo12Hour($outTimeRaw),
                ];
            }

            // Sort by date desc, pin asc
            usort($rows, function ($a, $b) {
                if ($a['date'] !== $b['date']) {
                    return strcmp($b['date'], $a['date']);
                }

                return strcmp($a['pin'], $b['pin']);
            });

            return response()->json([
                'logs' => $rows,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to fetch session logs: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Import the punches captured by a completed download session into
     * attendance. The download itself only parks rows in biometric_att_logs with
     * punch_status='downloaded'; this is the explicit second step that turns
     * them into attendance records.
     */
    public function importSessionLogs(Request $request, $id)
    {
        $session = BiometricDownloadSession::find($id);

        if (! $session) {
            return response()->json(['message' => 'Download session not found'], 404);
        }

        try {
            $result = $this->biometricService->importDownloadedLogs($session);

            $imported = $result['imported'] ?? 0;
            $duplicates = $result['duplicates'] ?? 0;
            $failed = $result['failed'] ?? 0;
            $skippedUnknown = $result['skipped_unknown'] ?? 0;

            return response()->json([
                'message' => "Imported {$imported} punch(es). {$duplicates} duplicate(s), {$skippedUnknown} skipped (unknown user), {$failed} failed.",
                'imported' => $imported,
                'duplicates' => $duplicates,
                'failed' => $failed,
                'skipped_unknown' => $skippedUnknown,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Failed to import session logs: '.$e->getMessage(),
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────
    //  Device capabilities and device-internal settings (matrix §4b / §5)
    // ──────────────────────────────────────────────────────────────

    /**
     * The catalogue of writable device-internal settings, for the UI to build a
     * form from. Static data — no device involved.
     *
     * Returned grouped as well as flat so a form can render sections without
     * re-deriving the grouping, and with the dangerous keys called out
     * separately so a client cannot accidentally render them as ordinary rows.
     */
    public function settingsCatalogue()
    {
        $catalogue = DeviceCapabilityService::SETTINGS_CATALOGUE;

        $groups = [];
        $dangerous = [];

        foreach ($catalogue as $key => $meta) {
            $groups[$meta['group'] ?? 'Other'][$key] = $meta;

            if ($this->isDangerousKey($key)) {
                $dangerous[] = $key;
            }
        }

        return response()->json([
            'catalogue' => $catalogue,
            'groups' => $groups,
            'dangerous_keys' => $dangerous,
            'confirmation_field' => 'confirm_dangerous',
            'probe_keys' => DeviceCapabilityService::CAPABILITY_KEYS,
        ]);
    }

    /**
     * The stored capability snapshot for one device (counts, maxima, firmware,
     * platform, MAC, and which keys the unit rejected with -1004).
     *
     * Read-only: this never talks to the device. It reports what the last probe
     * learned, which may be nothing at all — `has_data` / `is_stale` in the
     * snapshot are how the UI renders that.
     */
    public function capabilities($id)
    {
        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        return response()->json([
            'capabilities' => $this->capabilityService->snapshot($device),
        ]);
    }

    /**
     * Queue a capability probe: `INFO` plus one `GET OPTION FROM <keys>`.
     *
     * ADMS is device-initiated. There is no way to pull from the device on
     * demand — the commands sit in the queue until the unit next polls
     * /iclock/getrequest, and the answers arrive later as a push. The response
     * says so explicitly so the UI does not promise fresh data on this click.
     */
    public function probe(Request $request, $id)
    {
        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if (! $device->isAdms()) {
            return response()->json(['message' => 'Capability probing is only supported for ADMS protocol devices.'], 400);
        }

        if (! $device->is_active) {
            return response()->json(['message' => 'Device is inactive.'], 403);
        }

        $info = $this->biometricService->createCommand($device, ['command_type' => 'INFO']);

        $getOption = $this->biometricService->createCommand($device, [
            'command_type' => 'GET_OPTION',
            'payload' => ['keys' => DeviceCapabilityService::CAPABILITY_KEYS],
        ]);

        Log::info('Biometric capability probe queued', [
            'device_id' => $device->id,
            'serial' => $device->serial_number,
            'command_ids' => [$info->id, $getOption->id],
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => 'Probe queued. ADMS is device-initiated, so the device will pick these commands up on its next poll and the results arrive asynchronously as a push — this page will not update immediately.',
            'asynchronous' => true,
            'command_ids' => [$info->id, $getOption->id],
            'commands' => [
                ['id' => $info->id, 'type' => $info->command_type, 'adms_string' => $info->toAdmsString()],
                ['id' => $getOption->id, 'type' => $getOption->command_type, 'adms_string' => $getOption->toAdmsString()],
            ],
            'probed_keys' => DeviceCapabilityService::CAPABILITY_KEYS,
        ]);
    }

    /**
     * Queue device-internal setting writes — one `SET_OPTION` command per key.
     *
     * One key per command is deliberate (see BiometricDeviceCommand::toAdmsString):
     * a batched write returns a single code, so a -1004 would not say which key
     * the model rejected, and -1004 is exactly the capability signal we want.
     *
     * Two hard rules, both enforced here and again in the ADMS queue endpoint:
     *  - only keys present in SETTINGS_CATALOGUE are accepted; arbitrary keys
     *    from user input never reach the wire,
     *  - a key the catalogue marks dangerous requires `confirm_dangerous=true`
     *    on this request. There is deliberately no multi-device settings
     *    endpoint: dangerous keys must never be fired at a fleet.
     */
    public function updateDeviceSettings(Request $request, $id)
    {
        $request->validate([
            'settings' => 'required|array|min:1',
            'confirm_dangerous' => 'sometimes|boolean',
        ]);

        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if (! $device->isAdms()) {
            return response()->json(['message' => 'Device settings are only supported for ADMS protocol devices.'], 400);
        }

        $catalogue = DeviceCapabilityService::SETTINGS_CATALOGUE;
        $settings = $request->input('settings');
        $confirmed = $request->boolean('confirm_dangerous');

        $unknown = [];
        $invalid = [];
        $needsConfirmation = [];

        foreach ($settings as $key => $value) {
            $key = trim((string) $key);

            if (! array_key_exists($key, $catalogue)) {
                $unknown[] = $key;

                continue;
            }

            if (! is_scalar($value) || preg_match('/[\r\n\t]/', (string) $value)) {
                $invalid[] = $key;

                continue;
            }

            if ($this->isDangerousKey($key) && ! $confirmed) {
                $needsConfirmation[] = $key;
            }
        }

        if ($unknown !== []) {
            return response()->json([
                'message' => 'Unknown device setting(s): '.implode(', ', $unknown).'. Only keys in the settings catalogue can be written.',
                'unknown_keys' => $unknown,
            ], 422);
        }

        if ($invalid !== []) {
            return response()->json([
                'message' => 'Invalid value for device setting(s): '.implode(', ', $invalid).'.',
                'invalid_keys' => $invalid,
            ], 422);
        }

        if ($needsConfirmation !== []) {
            return response()->json([
                'message' => 'These settings can make the device unreachable — recovery requires physical access to the unit: '
                    .implode(', ', $needsConfirmation)
                    .'. Re-send with confirm_dangerous=true to proceed.',
                'requires_confirmation' => true,
                'dangerous_keys' => $needsConfirmation,
                'help' => collect($needsConfirmation)
                    ->mapWithKeys(fn ($key) => [$key => $catalogue[$key]['help'] ?? null])
                    ->all(),
            ], 422);
        }

        $queued = [];

        foreach ($settings as $key => $value) {
            $key = trim((string) $key);

            $command = $this->biometricService->createCommand($device, [
                'command_type' => 'SET_OPTION',
                'payload' => ['key' => $key, 'value' => (string) $value],
            ]);

            $queued[] = [
                'id' => $command->id,
                'key' => $key,
                'value' => (string) $value,
                'dangerous' => $this->isDangerousKey($key),
                'adms_string' => $command->toAdmsString(),
            ];
        }

        Log::info('Biometric device settings queued', [
            'device_id' => $device->id,
            'serial' => $device->serial_number,
            'keys' => array_column($queued, 'key'),
            'dangerous_confirmed' => $confirmed,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => count($queued).' setting(s) queued. ADMS is device-initiated: the device applies them on its next poll and reports back asynchronously, so a key it does not support will show up as "not supported on this model" in command history rather than failing now.',
            'asynchronous' => true,
            'commands' => $queued,
            'command_ids' => array_column($queued, 'id'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Template roaming (matrix §2 — DATA UPDATE FINGERTMP)
    // ──────────────────────────────────────────────────────────────

    /**
     * List the biometric templates this server holds.
     *
     * Until now capture was one-way: templatev10 / facetmpv10 pushes were
     * stored and nothing could ever read them back, so the 13 employees whose
     * fingerprints only exist on one MB460 were invisible in the UI. This is
     * the read side of that — no device is contacted, it reports what is on our
     * side.
     *
     * Template payloads themselves are never returned; the service returns
     * metadata only. A base64 fingerprint template is the biometric secret, and
     * an admin list screen has no use for it.
     */
    public function templates(Request $request)
    {
        $request->validate([
            'user_id' => 'nullable|integer',
            'device_id' => 'nullable|integer',
        ]);

        $userId = $request->filled('user_id') ? (string) $request->input('user_id') : null;
        $deviceId = $request->filled('device_id') ? (int) $request->input('device_id') : null;

        // Resolved from the container at call time rather than injected into the
        // constructor on purpose: template roaming is one feature of this
        // controller, and a missing binding must not take down ping, health or
        // the device CRUD with it.
        $templates = app(TemplateRoamingService::class)->listTemplates($userId, $deviceId);

        return response()->json([
            'templates' => $templates,
            'total' => $templates->count(),
            'filters' => ['user_id' => $userId, 'device_id' => $deviceId],
        ]);
    }

    /**
     * Queue write-back of stored templates onto a device.
     *
     * This is the only action in this controller that puts BIOMETRIC data onto
     * hardware, so it is gated harder than the rest:
     *
     *  - `confirm_restore` must be explicitly true. There is no "are you sure"
     *    dialog on the server side of an API, so the confirmation has to be a
     *    field on the request, the same shape used for the strand-the-device
     *    setting keys above.
     *  - ADMS only. DATA UPDATE FINGERTMP is an ADMS/PUSH command; a push_sdk
     *    row has no queue to put it on.
     *  - `user_ids` narrows the restore. Omitted means every user with a stored
     *    template — the fleet-replication case — which is exactly why the
     *    confirmation is not optional.
     *  - who triggered it is logged, always. Copying a fingerprint template onto
     *    a second reader is an act someone has to be answerable for.
     *
     * ADMS is device-initiated, so nothing is written now: the commands sit in
     * the queue until the unit next polls /iclock/getrequest.
     */
    public function restoreTemplates(Request $request, $id)
    {
        $request->validate([
            'confirm_restore' => 'sometimes|boolean',
            'user_ids' => 'sometimes|array',
            'user_ids.*' => 'integer',
        ]);

        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if (! $device->isAdms()) {
            return response()->json([
                'message' => 'Template restore is only supported for ADMS protocol devices.',
            ], 400);
        }

        if (! $device->is_active) {
            return response()->json(['message' => 'Device is inactive.'], 403);
        }

        if (! $request->boolean('confirm_restore')) {
            return response()->json([
                'message' => 'Restoring templates writes fingerprint/face data onto this device. Re-send with confirm_restore=true to proceed.',
                'requires_confirmation' => true,
                'confirmation_field' => 'confirm_restore',
            ], 422);
        }

        $userIds = array_values(array_unique(array_map('intval', $request->input('user_ids', []))));

        $result = app(TemplateRoamingService::class)->restoreTemplatesToDevice($device, $userIds);

        Log::info('Biometric template restore queued', [
            'device_id' => $device->id,
            'serial' => $device->serial_number,
            'requested_user_ids' => $userIds,
            'scope' => $userIds === [] ? 'all_users_with_templates' : 'selected_users',
            'queued' => $result['queued'] ?? null,
            'skipped' => $result['skipped'] ?? null,
            'users' => $result['users'] ?? null,
            'user_id' => auth()->id(),
        ]);

        $queued = (int) ($result['queued'] ?? 0);

        return response()->json([
            'message' => $queued.' template write(s) queued for '.$device->name
                .'. ADMS is device-initiated: the device applies them on its next poll, so this page will not update immediately.',
            'asynchronous' => true,
            'queued' => $queued,
            'skipped' => (int) ($result['skipped'] ?? 0),
            'users' => (int) ($result['users'] ?? 0),
            'reasons' => $result['reasons'] ?? [],
        ]);
    }

    /**
     * Queue a `DATA DELETE FINGERTMP` removing fingerprint template(s) for one
     * PIN FROM THE DEVICE.
     *
     * ── What this does and does not touch ──────────────────────────────────
     * This deletes on the HARDWARE only. Nothing is removed from
     * `biometric_templates` — our stored copy is the backup that makes
     * restoreTemplates() possible, and the usual reason to delete on a device is
     * to move somebody onto a different unit. Deleting on-device while we still
     * hold the template is recoverable; the reverse is not, so the two must
     * never be described interchangeably in a response an admin reads.
     *
     * ── Scope: one finger, or all of them ─────────────────────────────────
     * `fid` omitted means EVERY finger enrolled for that PIN — the protocol
     * expresses "all" by leaving the field off the wire entirely (see
     * BiometricDeviceCommand::toAdmsString: `FID=` is a syntax error and
     * `FID=0` would quietly delete only the first finger). That is a materially
     * larger action than deleting a single slot, so the two are distinguished
     * everywhere: in the confirmation refusal, in the success message, and in a
     * machine-readable `scope` / `all_fingers` pair on every response.
     *
     * ── Guards, in the same order as restoreTemplates() ────────────────────
     *  - unknown device -> 404,
     *  - non-ADMS -> 400: DATA DELETE FINGERTMP is an ADMS/PUSH command and a
     *    push_sdk row has no queue to put it on,
     *  - inactive -> 403,
     *  - `confirm_delete` must be explicitly true -> 422. An API has no "are you
     *    sure" dialog, so the confirmation is a field on the request.
     *
     * `fid` is range-checked HERE (0-9) rather than left to the service's
     * exception, so an out-of-range value — including the -1 face/palm storage
     * sentinel, which would otherwise reach the wire as `FID=-1` — comes back as
     * an ordinary validation error a form can render against the field.
     *
     * ADMS is device-initiated, so nothing is destroyed now: the command sits in
     * the queue until the unit next polls /iclock/getrequest.
     */
    public function deleteTemplate(Request $request, $id)
    {
        $request->validate([
            'pin' => 'required|string|max:191',
            // Optional. Absent/null == every finger for this PIN.
            // min:0 rejects TemplateRoamingService::NO_FINGER_INDEX (-1): face and
            // palm rows carry that sentinel and cannot be addressed by a
            // FINGERTMP delete at all.
            'fid' => 'sometimes|nullable|integer|min:0|max:'.TemplateRoamingService::MAX_FINGER_INDEX,
            'confirm_delete' => 'sometimes|boolean',
        ]);

        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        if (! $device->isAdms()) {
            return response()->json([
                'message' => 'Template delete is only supported for ADMS protocol devices.',
            ], 400);
        }

        if (! $device->is_active) {
            return response()->json(['message' => 'Device is inactive.'], 403);
        }

        $pin = trim((string) $request->input('pin'));

        if ($pin === '') {
            return response()->json(['message' => 'A device PIN is required.'], 422);
        }

        $fid = $request->input('fid');
        $fid = ($fid === null || $fid === '') ? null : (int) $fid;
        $allFingers = $fid === null;
        $scope = $allFingers ? 'all_fingers' : 'single_finger';

        if (! $request->boolean('confirm_delete')) {
            return response()->json([
                'message' => $allFingers
                    ? 'This deletes EVERY fingerprint enrolled for PIN '.$pin.' from '.$device->name
                        .' — all fingers, not one. The copy stored in this system is kept and can be restored afterwards; the device loses its own. Re-send with confirm_delete=true to proceed.'
                    : 'This deletes fingerprint #'.$fid.' for PIN '.$pin.' from '.$device->name
                        .'. Only that one finger is removed from the device; the copy stored in this system is kept and can be restored afterwards. Re-send with confirm_delete=true to proceed.',
                'requires_confirmation' => true,
                'confirmation_field' => 'confirm_delete',
                'scope' => $scope,
                'all_fingers' => $allFingers,
                'pin' => $pin,
                'fid' => $fid,
            ], 422);
        }

        try {
            $command = app(TemplateRoamingService::class)->deleteTemplateFromDevice($device, $pin, $fid);
        } catch (\InvalidArgumentException $e) {
            // The service re-checks the same preconditions. Anything it refuses
            // that got past the guards above is still a caller error, not a 500.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Destroying biometric data on hardware has to be attributable. Device,
        // PIN and the FID SCOPE — "all fingers" and "finger 3" are different
        // acts and the log must not read the same for both.
        Log::info('Biometric template delete queued', [
            'device_id' => $device->id,
            'serial' => $device->serial_number,
            'command_id' => $command->id,
            'pin' => $pin,
            'fid' => $fid,
            'scope' => $scope,
            'all_fingers' => $allFingers,
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => ($allFingers
                    ? 'Deletion of ALL fingerprints for PIN '.$pin.' queued for '.$device->name.'.'
                    : 'Deletion of fingerprint #'.$fid.' for PIN '.$pin.' queued for '.$device->name.'.')
                .' ADMS is device-initiated: the device applies it on its next poll, so nothing has been removed yet.',
            'asynchronous' => true,
            'command_id' => $command->id,
            'scope' => $scope,
            'all_fingers' => $allFingers,
            'pin' => $pin,
            'fid' => $fid,
            'device_id' => $device->id,
            'device_name' => $device->name,
            // Said explicitly, because it is the one thing that makes this
            // action reversible and the one thing easiest to assume wrongly.
            'stored_copy_retained' => true,
            'note' => 'This removes the template from the device only. The copy stored in this system is untouched, so the enrolment can be restored to a terminal later.',
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Unknown-user remediation
    // ──────────────────────────────────────────────────────────────

    /**
     * Link a device PIN that resolved to nobody onto a real employee, and put
     * its stranded punches back in front of the importer.
     *
     * When a punch arrives for a PIN no user carries, resolveOrCreateUser()
     * mints a soft-deleted placeholder and the row is parked as
     * punch_status='unknown_user'. Nothing ever revisits that state, so those
     * punches are lost until someone fixes the PIN by hand in the database.
     *
     * Three things have to happen together, hence the transaction:
     *  1. the real user takes the PIN as their employee_id, so FUTURE punches
     *     resolve without any of this,
     *  2. the placeholder releases the PIN — leaving it would let
     *     resolveOrCreateUser() keep matching the soft-deleted row and the fix
     *     would appear to do nothing,
     *  3. the stranded rows are re-pointed and set back to 'downloaded', which
     *     is the status importDownloadedLogs() selects on.
     *
     * The guards are the point of this endpoint. Setting employee_id is
     * re-keying an identity: every biometric punch, past and future, is
     * attributed by it. So we refuse if the target already carries a DIFFERENT
     * employee_id, refuse if a live user already holds this PIN, refuse if the
     * PIN is held by a soft-deleted REAL user (an ex-employee's history, not a
     * placeholder), and refuse a PIN that has nothing unresolved — an
     * already-fixed PIN must not be silently re-pointed at someone else.
     *
     * ── Response contract ──────────────────────────────────────────────────
     * EVERY response from this endpoint — success and refusal alike — carries
     * the same three keys:
     *
     *   message          human-readable, safe to surface verbatim
     *   pin              the PIN as evaluated (trimmed)
     *   unresolved_count how many unknown_user rows this PIN had when the
     *                    request was evaluated
     *
     * unresolved_count is on the refusals too, and deliberately so: it is the
     * number that explains the decision. A caller that gets a 422 needs to know
     * whether it refused because there was nothing to do (0) or because
     * something was in the way despite there being work (>0), and a UI listing
     * stranded PINs wants that count either way. Making it conditional would
     * force every consumer to null-check a field that is always knowable.
     * Refusals add one or two diagnostic keys on top (current_employee_id /
     * requested_pin, or conflicting_user_id); the success path adds the counts
     * of what it changed.
     *
     * The "nothing to remediate" precondition is checked FIRST, before any of
     * the identity guards. This endpoint exists to drain the unknown_user
     * backlog; if a PIN has no stranded rows there is no work to authorise, so
     * reasoning about who may hold the PIN is moot. Checking it first also
     * makes a double-submit say "already done" rather than reporting the
     * employee linked by the first request as a conflict.
     */
    public function linkAttLogUser(Request $request)
    {
        $data = $request->validate([
            'pin' => 'required|string|max:191',
            'user_id' => 'required|string|exists:users,employee_id',
        ]);

        $pin = trim($data['pin']);

        if ($pin === '') {
            return response()->json([
                'message' => 'PIN is required.',
                'pin' => $pin,
                'unresolved_count' => 0,
            ], 422);
        }

        // Evaluated up front, before any guard, because it is reported on every
        // branch — see the response contract above.
        $unresolvedCount = BiometricAttLog::where('user_pin', $pin)
            ->where('punch_status', 'unknown_user')
            ->count();

        if ($unresolvedCount === 0) {
            return response()->json([
                'message' => "PIN {$pin} has no unresolved punches — there is nothing to remediate. This endpoint only links PINs that are still in the unknown_user state.",
                'pin' => $pin,
                'unresolved_count' => 0,
            ], 422);
        }

        // Not withTrashed(): the target of a link must be a live employee.
        $user = User::find($data['user_id']);

        if (! $user) {
            return response()->json([
                'message' => 'Target user not found, or is deleted. Templates and punches can only be linked to an active employee.',
                'pin' => $pin,
                'unresolved_count' => $unresolvedCount,
            ], 422);
        }

        $currentEmployeeId = $user->employee_id === null ? null : (string) $user->employee_id;

        if ($currentEmployeeId !== null && $currentEmployeeId !== '' && $currentEmployeeId !== $pin) {
            return response()->json([
                'message' => "{$user->name} already has employee ID {$currentEmployeeId}. Linking PIN {$pin} would re-key this employee and silently re-attribute their attendance history. Fix the employee record first if the ID is genuinely wrong.",
                'pin' => $pin,
                'unresolved_count' => $unresolvedCount,
                'current_employee_id' => $currentEmployeeId,
                'requested_pin' => $pin,
            ], 422);
        }

        // Anyone else already carrying this PIN. A live holder is a genuine
        // collision; a soft-deleted holder is only safe to release if it is one
        // of our own auto-created placeholders.
        $holders = User::withTrashed()
            ->where('employee_id', $pin)
            ->where('id', '!=', $user->id)
            ->get();

        $liveHolder = $holders->first(fn (User $holder) => $holder->deleted_at === null);

        if ($liveHolder) {
            return response()->json([
                'message' => "PIN {$pin} is already assigned to {$liveHolder->name} (user #{$liveHolder->id}). Resolve that conflict before linking.",
                'pin' => $pin,
                'unresolved_count' => $unresolvedCount,
                'conflicting_user_id' => $liveHolder->id,
            ], 422);
        }

        $realDeletedHolder = $holders->first(fn (User $holder) => ! $this->isAutoCreatedPlaceholder($holder));

        if ($realDeletedHolder) {
            return response()->json([
                'message' => "PIN {$pin} belongs to deleted employee {$realDeletedHolder->name} (user #{$realDeletedHolder->id}), not to an auto-created placeholder. Reassigning it would move that employee's attendance history.",
                'pin' => $pin,
                'unresolved_count' => $unresolvedCount,
                'conflicting_user_id' => $realDeletedHolder->id,
            ], 422);
        }

        $reason = 'Linked to user #'.$user->id.' by user #'.(auth()->id() ?? 0).' on '.now()->toDateTimeString();

        $result = DB::transaction(function () use ($user, $pin, $holders, $reason) {
            $released = 0;

            foreach ($holders as $holder) {
                // Only placeholders reach here — the guards above rejected
                // everything else. Release the PIN so resolveOrCreateUser()
                // stops matching the soft-deleted row.
                $holder->employee_id = null;
                $holder->saveQuietly();
                $released++;
            }

            $user->employee_id = $pin;
            $user->save();

            $relinked = BiometricAttLog::where('user_pin', $pin)
                ->where('punch_status', 'unknown_user')
                ->update([
                    'user_id' => $user->id,
                    // 'downloaded' is what BiometricProcessingService::importDownloadedLogs()
                    // selects on, so this is what "retry me" means here.
                    'punch_status' => 'downloaded',
                    'punch_status_reason' => $reason,
                    'updated_at' => now(),
                ]);

            return ['released' => $released, 'relinked' => $relinked];
        });

        Log::info('Biometric unknown-user PIN linked', [
            'pin' => $pin,
            'linked_user_id' => $user->id,
            'logs_relinked' => $result['relinked'],
            'placeholders_released' => $result['released'],
            'user_id' => auth()->id(),
        ]);

        return response()->json([
            'message' => "PIN {$pin} linked to {$user->name}. {$result['relinked']} punch(es) queued for re-import; {$result['released']} placeholder record(s) released.",
            'pin' => $pin,
            // The count as it stood when the request was evaluated — same key,
            // same meaning as on every refusal. logs_relinked is what actually
            // moved; they differ only if a concurrent push added a row.
            'unresolved_count' => $unresolvedCount,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'logs_relinked' => $result['relinked'],
            'placeholders_released' => $result['released'],
            'punch_status' => 'downloaded',
            'note' => 'The punches are back in the downloaded state; biometric:import-downloaded replays them into attendance on its next run.',
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    //  Reconciliation: device punches vs attendance
    // ──────────────────────────────────────────────────────────────

    /**
     * "Is this employee really absent, or did we lose his punches?"
     *
     * Answering that once required a full raw pull off the terminal and a dozen
     * ad-hoc SQL queries. It gets asked again every month about somebody else,
     * so it is an endpoint.
     *
     * Read-only and device-scoped. Nothing is queued, nothing is written, and
     * the device is never contacted — this compares what `biometric_att_logs`
     * holds for one device against what `attendances` holds for the same people
     * over the same dates. `DeviceReconciliationService` carries the reasoning
     * about clock offsets, day bucketing and what does and does not count as a
     * finding; this method is the HTTP shape around it.
     *
     * The date range is validated but the SEMANTIC refusals — inverted range, a
     * window longer than the service will read — come back from the service as
     * InvalidArgumentException and are answered 422 with the service's own
     * message. Duplicating those bounds in a validation rule here would let the
     * two drift apart, and the service is the one that has to honour them.
     *
     * Resolved from the container at call time rather than injected into the
     * constructor, for the same reason as templates(): one reporting feature
     * failing to resolve must not take ping, health and the device CRUD down
     * with it.
     */
    public function reconciliation(Request $request, $id)
    {
        $request->validate([
            'from' => 'nullable|date',
            'until' => 'nullable|date',
        ]);

        $device = BiometricDevice::find($id);

        if (! $device) {
            return response()->json(['message' => 'Device not found'], 404);
        }

        try {
            $report = app(DeviceReconciliationService::class)->reconcile(
                $device,
                $request->input('from'),
                $request->input('until'),
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($report);
    }

    /**
     * Is this one of the soft-deleted stand-ins resolveOrCreateUser() mints for
     * an unrecognised PIN, rather than a real person who was deleted?
     *
     * Matched on the shape that helper actually writes (name / email /
     * user_name), and only ever consulted for users that are already
     * soft-deleted, so a live account can never be classed as disposable.
     */
    private function isAutoCreatedPlaceholder(User $user): bool
    {
        if ($user->deleted_at === null) {
            return false;
        }

        $pin = (string) $user->employee_id;

        return $user->email === 'device_user_'.$pin.'@placeholder.local'
            || $user->user_name === 'device_user_'.$pin
            || $user->name === 'Device User '.$pin;
    }

    /**
     * Does the catalogue flag this key as able to strand the device?
     */
    private function isDangerousKey(string $key): bool
    {
        $meta = DeviceCapabilityService::SETTINGS_CATALOGUE[$key] ?? [];

        // Accept either spelling so this cannot silently degrade to "nothing is
        // dangerous" if the catalogue's flag is renamed.
        return (bool) ($meta['dangerous'] ?? $meta['danger'] ?? false);
    }

    private function formatTo12Hour(?string $timeStr): string
    {
        if (! $timeStr || $timeStr === '—') {
            return '—';
        }
        try {
            return Carbon::createFromFormat('H:i:s', $timeStr)->format('h:i:s A');
        } catch (\Exception $e) {
            try {
                return Carbon::parse($timeStr)->format('h:i:s A');
            } catch (\Exception $e2) {
                return $timeStr;
            }
        }
    }
}
