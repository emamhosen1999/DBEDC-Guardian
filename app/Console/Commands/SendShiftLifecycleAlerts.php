<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Attendance\MissingPunchInNotification;
use App\Notifications\Attendance\ShiftAbsenceNotification;
use App\Notifications\Attendance\ShiftStartReminderNotification;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\ShiftLifecycleAlertService;
use Illuminate\Console\Command;
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

    public function handle(ShiftLifecycleAlertService $service): int
    {
        $now = now();
        $today = $now->copy()->startOfDay();

        $phase = (string) $this->option('phase');
        $lead = max(0, (int) $this->option('lead'));
        $missingAfter = max(0, (int) $this->option('missing-after'));
        $absenceAfter = max(0, (int) $this->option('absence-after'));

        $sent = ['reminder' => 0, 'overdue' => 0, 'absence' => 0];

        foreach ($service->candidates($today) as $row) {
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

        $this->info(sprintf(
            'Shift alerts sent — reminders: %d, overdue: %d, absence: %d.',
            $sent['reminder'],
            $sent['overdue'],
            $sent['absence'],
        ));

        return self::SUCCESS;
    }

    private function wants(string $phase, string $which): bool
    {
        return $phase === 'all' || $phase === $which;
    }

    /**
     * Atomically claim the once-per-(phase, user, date, shift) marker. Returns
     * true only on the FIRST claim; false if this alert already fired.
     */
    private function markOnce(string $phase, int $userId, string $date, int $shiftId): bool
    {
        $key = "shift-alert:{$phase}:{$userId}:{$date}:{$shiftId}";

        return Cache::add($key, true, now()->addHours(self::MARKER_TTL_HOURS));
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
