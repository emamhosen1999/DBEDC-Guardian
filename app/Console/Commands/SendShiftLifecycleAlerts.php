<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Attendance\MissingPunchInNotification;
use App\Notifications\Attendance\ShiftAbsenceNotification;
use App\Notifications\Attendance\ShiftStartReminderNotification;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\ShiftLifecycleAlertService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Proactive, time-based attendance alerts around the shift lifecycle. One pass
 * over today's roster evaluates three windows per rostered employee:
 *
 *   1. reminder  — [start - lead, start)                 → notify EMPLOYEE
 *   2. overdue   — [start + missingAfter, start + absenceAfter) & no punch-in
 *                                                          → notify EMPLOYEE
 *   3. absence   — [start + absenceAfter, shift end) & no punch-in
 *                                                          → notify MANAGER
 *
 * Each (employee, business-date, shift, phase) fires AT MOST ONCE via an atomic
 * cache marker, so re-running on the next tick never double-sends. Runs
 * frequently (see routes/console.php) so every window is caught; missed ticks
 * self-heal because a window is a range, not an instant.
 *
 * ── The marker store is not assumed to work ───────────────────────────────
 * Every one of the three alerts is gated on `Cache::add()` returning true. A
 * store that cannot persist returns false from add() forever — NullStore::put()
 * is hardcoded to return false — and false is indistinguishable from "somebody
 * already claimed this marker". So a single env change turns the dedupe gate
 * into a permanent OFF switch for the entire command.
 *
 * That is not hypothetical. Production ran CACHE_STORE=null for 3,672 scheduled
 * runs, delivered zero notifications in all of them, and logged
 * "reminders: 0, overdue: 0, absence: 0" every time — a line identical to a
 * genuinely quiet day, which is why nobody noticed. The driver was fixed and the
 * very next run sent four absence escalations that had been due for weeks.
 * `.env.example` still ships CACHE_STORE=null, so any fresh deployment starts
 * from exactly that state.
 *
 * Two changes make the failure impossible to repeat silently:
 *
 *  1. The store is PROBED, once per run, with a throwaway key. If the configured
 *     default cannot hold a marker, a real store is resolved from
 *     MARKER_STORE_FALLBACKS instead — dedupe keeps working regardless of what
 *     CACHE_STORE says, because "at most once" is a property this command should
 *     own rather than inherit from an unrelated setting. Falling back is said out
 *     loud on stderr and in the log, so the misconfiguration still gets fixed.
 *  2. If NOTHING can hold a marker, the command fails toward noise: it alerts
 *     anyway, warns loudly with the store name and the remedy, and returns a
 *     non-zero exit code so a scheduler or monitor can see it. A duplicate
 *     reminder is an annoyance; a missed absence escalation is the incident this
 *     command exists to prevent.
 *
 * The summary line is also no longer ambiguous: it reports how many rostered
 * employees were evaluated and how many alerts were suppressed as
 * already-sent, so "0, 0, 0" from a live roster reads differently from "0, 0, 0"
 * because nobody is rostered.
 */
class SendShiftLifecycleAlerts extends Command
{
    protected $signature = 'attendance:shift-alerts
                            {--phase=all : Which alert to run: reminder|overdue|absence|all}
                            {--lead=30 : Minutes BEFORE start for the shift-start reminder}
                            {--missing-after=15 : Minutes AFTER start to flag an overdue punch-in}
                            {--absence-after=60 : Minutes AFTER start to escalate as a likely absence}';

    protected $description = 'Send proactive shift-lifecycle attendance alerts (start reminder, overdue punch-in, absence).';

    /** Cache marker lifetime: comfortably covers a cross-midnight shift + margin. */
    private const MARKER_TTL_HOURS = 26;

    /**
     * Stores tried, in order, when the configured default cannot hold a marker.
     *
     * `database` first on purpose: this command has already queried the roster
     * by the time markers matter, so if the database were down there would be
     * nothing to alert about — a working DB is a precondition, not a wish. The
     * `cache` table ships in this app's migrations. `file` is second because it
     * needs a writable storage/framework/cache directory, which is the single
     * most common thing to be wrong on a fresh deployment.
     *
     * `array` is deliberately NOT here. It would pass the probe and hold markers
     * for the length of one process, which buys nothing — each (user, date,
     * shift, phase) is evaluated once per run anyway — while silencing the
     * warning that says de-duplication is broken. A fallback that hides the
     * problem without solving it is the failure this whole change is about.
     */
    private const MARKER_STORE_FALLBACKS = ['database', 'file'];

    /**
     * The store markers are actually claimed in, or null when none can hold one.
     */
    private ?CacheRepository $markers = null;

    /** Name of the store above, for the summary line. */
    private ?string $markerStoreName = null;

    /**
     * Alerts that were due and not sent because their marker already existed.
     * Reported in the summary so "0 sent" is legible: 0 of 40 suppressed reads
     * completely differently from 0 of 0.
     */
    private int $suppressed = 0;

    public function handle(ShiftLifecycleAlertService $service): int
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        $phase = (string) $this->option('phase');
        $lead = max(0, (int) $this->option('lead'));
        $missingAfter = max(0, (int) $this->option('missing-after'));
        $absenceAfter = max(0, (int) $this->option('absence-after'));

        $sent = ['reminder' => 0, 'overdue' => 0, 'absence' => 0];
        $this->suppressed = 0;
        $this->markers = $this->resolveMarkerStore();

        $evaluated = 0;

        foreach ($service->candidates($today) as $row) {
            $evaluated++;

            /** @var User $user */
            $user = $row['user'];
            /** @var ShiftSchedule $schedule */
            $schedule = $row['schedule'];
            $start = $schedule->start;
            $end = $schedule->end;
            $code = $row['shift_code'];
            $date = $row['date'];
            $shiftId = $row['shift_id'];

            // Shift already ended → nothing proactive left to do.
            if ($now->greaterThanOrEqualTo($end)) {
                continue;
            }

            // ── 1. Shift-start reminder: before the shift starts ──────────────
            if ($now->lessThan($start)) {
                if ($this->wants($phase, 'reminder')
                    && $now->greaterThanOrEqualTo($start->copy()->subMinutes($lead))
                    && $this->markOnce('reminder', $user->id, $date, $shiftId)) {
                    $this->deliver($user, new ShiftStartReminderNotification($code, $start->format('H:i'), $date), 'reminder');
                    $sent['reminder']++;
                }

                continue; // still before start → overdue/absence not applicable
            }

            // From here now is INSIDE the shift. A punch-in clears all follow-ups.
            if ($service->hasPunchedIn($user->id, $date)) {
                continue;
            }

            // ── 3. Absence escalation (checked first so it wins once open) ────
            if ($now->greaterThanOrEqualTo($start->copy()->addMinutes($absenceAfter))) {
                if ($this->wants($phase, 'absence')) {
                    $manager = $service->resolveManager($user);
                    if ($manager && $this->markOnce('absence', $user->id, $date, $shiftId)) {
                        $this->deliver($manager, new ShiftAbsenceNotification($user->name, $code, $start->format('H:i'), $date), 'absence');
                        $sent['absence']++;
                    }
                }

                continue;
            }

            // ── 2. Overdue punch-in: [start + missingAfter, start + absenceAfter) ─
            if ($this->wants($phase, 'overdue')
                && $now->greaterThanOrEqualTo($start->copy()->addMinutes($missingAfter))
                && $this->markOnce('overdue', $user->id, $date, $shiftId)) {
                $this->deliver($user, new MissingPunchInNotification($code, $start->format('H:i'), $date), 'overdue');
                $sent['overdue']++;
            }
        }

        // Every number here exists so that a zero can be read. "reminders: 0,
        // overdue: 0, absence: 0" was the line printed 3,672 times through a
        // total outage, and it is also the correct line on a quiet Sunday. The
        // roster size and the suppression count are what separate the two, and
        // the de-duplication state is what separates both from a broken store.
        $this->info(sprintf(
            'Shift alerts — %d rostered employee(s) evaluated; sent reminders: %d, overdue: %d, absence: %d; %d suppressed as already sent; markers: %s.',
            $evaluated,
            $sent['reminder'],
            $sent['overdue'],
            $sent['absence'],
            $this->suppressed,
            $this->markers === null ? 'DISABLED (no usable cache store)' : $this->markerStoreName,
        ));

        // A non-zero exit is the only signal a scheduler or an uptime monitor
        // can see. Alerts were still delivered — see markOnce() — so this says
        // "the guarantee is degraded", not "nothing ran".
        return $this->markers === null ? self::FAILURE : self::SUCCESS;
    }

    private function wants(string $phase, string $which): bool
    {
        return $phase === 'all' || $phase === $which;
    }

    /**
     * Atomically claim the once-per-(phase, user, date, shift) marker. Returns
     * true only on the FIRST claim; false if this alert already fired.
     *
     * With no usable store this returns TRUE — every time, for every alert. That
     * is the whole point: `false` from a dead store means "the store is dead",
     * not "already sent", and reading it as the latter is what deleted 3,672
     * runs' worth of notifications. A duplicate reminder is an annoyance an
     * employee can ignore; a missing absence escalation is the event this
     * command exists to raise.
     */
    private function markOnce(string $phase, string $userId, string $date, int $shiftId): bool
    {
        if ($this->markers === null) {
            return true;
        }

        $key = "shift-alert:{$phase}:{$userId}:{$date}:{$shiftId}";

        if ($this->markers->add($key, true, now()->addHours(self::MARKER_TTL_HOURS))) {
            return true;
        }

        $this->suppressed++;

        return false;
    }

    /**
     * Pick a store that can actually hold a marker, saying so out loud when the
     * configured one cannot.
     *
     * "At most once" is a guarantee this command makes about the notifications
     * it sends, so it owns it rather than inheriting it from CACHE_STORE — an
     * unrelated setting that a deploy, a container image or a copied
     * `.env.example` can change without anybody thinking about attendance
     * alerts. Falling back keeps the guarantee; warning keeps the
     * misconfiguration visible so it still gets fixed.
     */
    private function resolveMarkerStore(): ?CacheRepository
    {
        $default = (string) config('cache.default');

        if ($this->storeHoldsMarkers($default)) {
            $this->markerStoreName = $default;

            return Cache::store($default);
        }

        foreach (self::MARKER_STORE_FALLBACKS as $name) {
            if ($name === $default || ! $this->storeHoldsMarkers($name)) {
                continue;
            }

            $this->warn(sprintf(
                'Cache store "%s" cannot hold a shift-alert marker, so alerts are being de-duplicated in "%s" instead. '
                .'Set CACHE_STORE to a real store (database/file/redis) — while it is wrong, anything else relying on the cache is silently broken too.',
                $default,
                $name
            ));
            Log::warning('Shift lifecycle alerts fell back to a working marker store', [
                'configured_store' => $default,
                'marker_store' => $name,
            ]);

            $this->markerStoreName = $name;

            return Cache::store($name);
        }

        // Nothing can hold a marker. Fail toward noise, loudly, and let the exit
        // code carry it to whatever is watching the scheduler.
        $this->error(sprintf(
            'No cache store can hold a shift-alert marker (default "%s"; also tried %s). '
            .'Shift reminders, missing-punch alerts and absence escalations are being sent WITHOUT de-duplication, so every five-minute run will re-send them. '
            .'Set CACHE_STORE to database, file or redis.',
            $default,
            implode(', ', self::MARKER_STORE_FALLBACKS)
        ));
        Log::error('Shift lifecycle alerts cannot de-duplicate: no cache store persists', [
            'configured_store' => $default,
            'fallbacks_tried' => self::MARKER_STORE_FALLBACKS,
        ]);

        $this->markerStoreName = null;

        return null;
    }

    /**
     * Can this store hold a marker? Probed with a throwaway key, once per run.
     *
     * Probed rather than inferred from the driver name, because "null store",
     * "redis pointed at nothing", "cache table never migrated" and "cache
     * directory not writable by the web user" all fail identically — as a
     * `false` from add() that is indistinguishable from a marker somebody else
     * already claimed.
     *
     * The value is read back rather than trusting add()'s return. A store can
     * report a successful write and drop it (a full disk, an evicting memory
     * cache, a driver that acknowledges optimistically); if the marker is not
     * there a moment later it will not be there on the next run either, which is
     * exactly the condition this probe is for.
     */
    private function storeHoldsMarkers(string $name): bool
    {
        $probe = 'shift-alert:probe:'.uniqid('', true);

        try {
            $store = Cache::store($name);

            if (! $store->add($probe, true, now()->addMinute())) {
                return false;
            }

            $held = $store->get($probe) !== null;
            $store->forget($probe);

            return $held;
        } catch (\Throwable $exception) {
            // A store that throws on write (no cache table, unreachable redis)
            // is unusable, not fatal — there are two more to try.
            Log::warning("Shift lifecycle alerts: marker probe failed on cache store \"{$name}\"", [
                'error' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    private function deliver(User $notifiable, Notification $notification, string $phase): void
    {
        try {
            $notifiable->notify($notification);
        } catch (\Throwable $exception) {
            Log::warning("Shift lifecycle alert [{$phase}] failed for user {$notifiable->id}", [
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
