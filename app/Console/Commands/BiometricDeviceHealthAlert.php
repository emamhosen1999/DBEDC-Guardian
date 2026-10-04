<?php

namespace App\Console\Commands;

use App\Models\HRM\BiometricDevice;
use App\Models\User;
use App\Notifications\Biometric\BiometricDeviceSilentNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alerts when an ACTIVE biometric terminal stops pushing.
 *
 * Nothing watched this before. ADMS is device-initiated — the server never
 * dials the unit — so if the MB460 dies at 02:00 the only symptom is that
 * attendance is quietly missing the next morning, discovered by the people
 * whose punches were lost.
 *
 * ── Why the threshold is not 5 minutes ────────────────────────────────────
 * BiometricDevice::isOnline() uses 5 minutes and that is right for what it is:
 * a live badge an admin is looking at, where "grey for a moment" costs nothing.
 * It is far too tight to page on. ADMS units poll every 30–120 s, so a single
 * Wi-Fi reassociation, a DHCP renew, a firmware reboot or a brief server blip
 * crosses 5 minutes routinely, and an alert that cries wolf twice a week is an
 * alert nobody reads at 02:00.
 *
 * The default is 45 minutes, which is defensible from the system's own
 * cadences rather than from taste:
 *   - it is ~22 missed polls at the slowest documented 120 s interval, so it
 *     cannot be a transient;
 *   - it is longer than one full biometric:scheduled-log-download cycle (30 min,
 *     see routes/console.php), so a device is only called silent after it has
 *     also failed to answer a scheduled download;
 *   - it still leaves hours of lead time before the first shift's punch window,
 *     which is the deadline that actually matters.
 * Tune with --minutes if a site's polling interval says otherwise.
 *
 * ── Not spamming ──────────────────────────────────────────────────────────
 * Production runs schedule:run every 5 minutes, so a naive implementation would
 * re-send every 5 minutes for the entire outage. Alerts fire on the TRANSITION
 * into silence: an atomic Cache::add() marker is claimed per device, and it is
 * cleared the moment the device is heard from again, so recovery re-arms the
 * alert. The marker expires after --repeat-after minutes (default 24 h) so a
 * device that is still dead a day later says so once more instead of vanishing
 * from view.
 *
 * ── When the marker store cannot hold a marker ────────────────────────────
 * This deployment ships CACHE_STORE=null (.env, .env.example), which resolves
 * to NullStore: put() always returns false, so Cache::add() always returns
 * false, so a naive reading of "add failed => somebody already claimed it"
 * would suppress EVERY alert forever. A device-down alert that silently never
 * fires is worse than a noisy one — it looks exactly like a healthy fleet.
 * So the store is probed once per run, and if it cannot persist we fail in the
 * safe direction: alert anyway, and say loudly on stderr and in the log that
 * de-duplication is off and why.
 */
class BiometricDeviceHealthAlert extends Command
{
    protected $signature = 'biometric:device-health-alert
                            {--minutes=45 : Minutes without a heartbeat before a device is considered silent}
                            {--repeat-after=1440 : Minutes before an already-alerted device is alerted again while still silent}
                            {--dry-run : Report what would be alerted without notifying or claiming the marker}';

    protected $description = 'Alert administrators when an active biometric device stops sending heartbeats';

    public function handle(): int
    {
        $threshold = max(1, (int) $this->option('minutes'));
        $repeatAfter = max(1, (int) $this->option('repeat-after'));
        $dryRun = (bool) $this->option('dry-run');

        $cutoff = now()->subMinutes($threshold);

        $devices = BiometricDevice::active()->orderBy('name')->get();

        if ($devices->isEmpty()) {
            $this->info('No active biometric devices.');

            return self::SUCCESS;
        }

        $silent = [];
        $recovered = 0;

        foreach ($devices as $device) {
            $lastHeartbeat = $device->last_heartbeat_at;

            if ($lastHeartbeat && $lastHeartbeat->gt($cutoff)) {
                // Heard from inside the window. Drop any marker so the NEXT
                // outage alerts immediately instead of being suppressed by the
                // previous one.
                //
                // has() then forget() rather than trusting forget()'s return:
                // it is not consistent across stores (DatabaseStore returns true
                // unconditionally, ArrayStore/RedisStore return whether the key
                // was there), and this counter drives an operator-facing line.
                if (! $dryRun && Cache::has($this->markerKey($device))) {
                    Cache::forget($this->markerKey($device));
                    $recovered++;
                }

                continue;
            }

            // Never seen. A device registered five minutes ago has not failed,
            // it has not been plugged in yet, so hold off until it has had the
            // full threshold to make its first contact.
            if (! $lastHeartbeat && $device->created_at && $device->created_at->gt($cutoff)) {
                continue;
            }

            $silent[] = $device;
        }

        if ($recovered > 0) {
            $this->info("{$recovered} device(s) recovered — alert markers cleared.");
        }

        if ($silent === []) {
            $this->info("All active devices have checked in within the last {$threshold} minute(s).");

            return self::SUCCESS;
        }

        $recipients = $this->recipients();

        if ($recipients->isEmpty()) {
            // Say it out loud. Silently having nobody to tell is exactly the
            // failure mode this command exists to remove.
            $this->warn('No user holds the attendance.settings permission — device-silence alerts have no recipient.');
            Log::warning('Biometric device health alert has no recipients', [
                'silent_devices' => collect($silent)->pluck('serial_number')->all(),
            ]);
        }

        $alerted = 0;
        $suppressed = 0;

        // Probed even on a dry run: "would this alert de-duplicate?" is exactly
        // the kind of thing a dry run is for. The probe uses a throwaway key,
        // so it never claims a device's marker.
        $markersUsable = $this->markersUsable();

        if (! $markersUsable) {
            $this->warn('Cache store "'.config('cache.default').'" cannot persist the transition marker — every run will re-alert. Set CACHE_STORE to a real store (database/file/redis) to get one alert per outage.');
            Log::warning('Biometric device health alert cannot de-duplicate: cache store does not persist', [
                'cache_store' => config('cache.default'),
                'silent_devices' => count($silent),
            ]);
        }

        foreach ($silent as $device) {
            $minutes = $device->last_heartbeat_at
                ? (int) $device->last_heartbeat_at->diffInMinutes(now())
                : 0;
            $lastSeen = $device->last_heartbeat_at?->format('Y-m-d H:i');

            $this->line(sprintf(
                '  SILENT  %s (%s) — %s',
                $device->name,
                $device->serial_number,
                $lastSeen === null ? 'never checked in' : "last heartbeat {$lastSeen} ({$minutes} min ago)"
            ));

            if ($dryRun) {
                $alerted++;

                continue;
            }

            // Atomic claim: true only for the first run that sees this outage.
            // Skipped entirely when the store cannot hold a marker — there a
            // false return means "no marker store", not "already alerted".
            if ($markersUsable && ! Cache::add($this->markerKey($device), true, now()->addMinutes($repeatAfter))) {
                $suppressed++;

                continue;
            }

            $notification = new BiometricDeviceSilentNotification(
                (string) $device->name,
                (string) $device->serial_number,
                $minutes,
                $lastSeen,
            );

            foreach ($recipients as $recipient) {
                try {
                    $recipient->notify($notification);
                } catch (\Throwable $exception) {
                    Log::error('Biometric device silence alert failed for user '.$recipient->id, [
                        'device_id' => $device->id,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            Log::warning('Biometric device silent', [
                'device_id' => $device->id,
                'name' => $device->name,
                'serial' => $device->serial_number,
                'last_heartbeat_at' => $device->last_heartbeat_at?->toIso8601String(),
                'silent_minutes' => $minutes,
                'threshold_minutes' => $threshold,
                'recipients' => $recipients->count(),
            ]);

            $alerted++;
        }

        $this->info(sprintf(
            '%s%d device(s) silent for %d+ minute(s); %d alert(s) sent to %d recipient(s), %d already-alerted device(s) suppressed.',
            $dryRun ? '[dry run] ' : '',
            count($silent),
            $threshold,
            $alerted,
            $recipients->count(),
            $suppressed
        ));

        return self::SUCCESS;
    }

    /**
     * Who hears about it: whoever can actually do something.
     *
     * attendance.settings is the permission that gates the biometric device
     * screen itself (routes/web.php), so it is by construction the set of
     * people who can reboot, re-provision or re-point the terminal. Deriving
     * the audience from the permission rather than a hardcoded role list means
     * it stays correct when roles are reorganised.
     */
    private function recipients()
    {
        return User::permission('attendance.settings')
            ->whereNull('deleted_at')
            ->get();
    }

    private function markerKey(BiometricDevice $device): string
    {
        return 'biometric-device-silent:'.$device->id;
    }

    /**
     * Can the configured cache store actually hold a transition marker?
     *
     * Probed rather than inferred from the driver name: a null store, a
     * misconfigured redis, or a cache directory the web user cannot write to
     * all fail the same way, and all of them would otherwise turn Cache::add()
     * into a permanent "already alerted".
     */
    private function markersUsable(): bool
    {
        $probe = 'biometric-device-silent:probe:'.uniqid('', true);

        try {
            if (! Cache::add($probe, true, now()->addMinute())) {
                return false;
            }
        } catch (\Throwable $exception) {
            Log::warning('Biometric device health alert marker probe failed: '.$exception->getMessage());

            return false;
        }

        Cache::forget($probe);

        return true;
    }
}
