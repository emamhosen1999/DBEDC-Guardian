<?php

use App\Models\NotificationLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled Tasks
|--------------------------------------------------------------------------
| Laravel 11 slim bootstrap: this file (registered via withRouting commands:)
| is the ONLY place the scheduler reads. The legacy app/Console/Kernel.php
| was never wired into bootstrap/app.php, so every schedule defined there was
| dead — the definitions now live here.
*/

// Test scheduler command runs every minute (for testing only)
if (config('app.env') === 'local') {
    Schedule::command('test:scheduler')
        ->everyMinute()
        ->onFailure(function () {
            Log::error('Test scheduler failed');
        });
}

// Attendance reminders are NOT a separate daily job any more (it ran at 22:17 for every user, rostered
// or not): attendance:shift-alerts below sends the start reminder and the overdue punch-in nudge to
// the employees who are rostered today, through the notification channels. `attendance:reminders`
// remains only as a manual alias of it.

// Failed queue jobs are kept for a week (long enough to inspect and retry), then pruned.
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily();

// Clean up old notification logs (keep 30 days)
Schedule::command('model:prune', [
    '--model' => [
        NotificationLog::class,
    ],
])->daily();

// Leave ledger scheduled tasks (Phase 3).
// Year-boundary: grant annual entitlement + carry forward last year's remaining.
Schedule::command('leave:grant-annual')
    ->yearly()->timezone(config('app.timezone', 'UTC'))->at('00:05')
    ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-grant-annual.log'));

Schedule::command('leave:carry-forward')
    ->yearly()->timezone(config('app.timezone', 'UTC'))->at('00:10')
    ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-carry-forward.log'));

// Monthly accrual for monthly-accrual types - 1st of each month.
Schedule::command('leave:accrue')
    ->monthlyOn(1, '00:15')->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-accrual.log'));

// Daily: expire elapsed carried days + reconcile ledger integrity.
Schedule::command('leave:expire-carried')
    ->daily()->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-expire-carried.log'));

// Daily: bank comp-off for yesterday's work on off-days/holidays/leave days.
Schedule::command('leave:grant-comp-off')
    ->dailyAt('01:00')->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-comp-off.log'));

Schedule::command('leave:reconcile-ledger')
    ->daily()->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Leave ledger reconcile detected drift');
    })
    ->appendOutputTo(storage_path('logs/leave-reconcile.log'));

// Process scheduled biometric device commands - runs every minute
Schedule::command('biometric:process-scheduled-commands')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/biometric-commands.log'));

// Scheduled download of attendance logs from all active ADMS devices - runs every
// 30 minutes so punches reach attendance in near-real-time. --hours=1 keeps the
// command's own guard active: a device that already synced within the hour is
// skipped, so the frequent tick does not hammer the hardware. --hours=0 would
// disable that guard and force every device to sync on every run.
Schedule::command('biometric:scheduled-log-download', ['--hours=1'])
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/biometric-log-download.log'));

// Second half of the capture-then-import flow: a download session only parks device
// logs in biometric_att_logs with punch_status = 'downloaded'. This drains them into
// real attendance records; without it nothing arrives automatically.
Schedule::command('biometric:import-downloaded')
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/biometric-import.log'));

// Device-silence alerting. ADMS is device-initiated: if a terminal stops
// pushing, nothing else in this system notices until attendance turns up empty.
// Runs every five minutes because that is the finest granularity production
// cron offers (schedule:run is on a */5 cron) — anything more frequent would
// simply not fire. The tick is cheap and does NOT mean five-minutely alerts:
// the command alerts on the transition into silence via a per-device cache
// marker, so a device that is down all night produces one alert, not 96.
Schedule::command('biometric:device-health-alert')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/biometric-device-health.log'));

// Close forgotten open punches at their resolved shift end
Schedule::command('attendance:auto-punch-out')->hourly()->withoutOverlapping();

// Proactive shift-lifecycle attendance alerts: shift-start reminder, overdue
// punch-in (→ employee), and absence escalation (→ manager). Runs every five
// minutes so each per-shift window is caught; every (user, date, shift, phase)
// fires at most once via cache markers, so frequent ticks never double-send.
Schedule::command('attendance:shift-alerts')
    ->everyFiveMinutes()
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/shift-alerts.log'));

// Daily absence streak computation: evaluates consecutive unauthorized absences
// and escalates per config('attendance.absence_escalation'). Runs after all
// shifts have ended so the full day's attendance is available.
Schedule::command('attendance:absence-streak')
    ->dailyAt('23:30')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/absence-streak.log'));

// Offboarding safety net: queue LWD effects (access revocation, biometric removal)
// for any offboarding whose last working day is over but was never processed.
// This is the real mechanism on the `sync` queue driver, which ignores delay().
Schedule::command('offboarding:process-due')
    ->dailyAt('00:15')
    ->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/offboarding-process-due.log'));

// Client Diagnostics retention: drop resolved crash groups after 30 days and
// anything untouched for 90 days. Off-peak so the chunked deletes never
// compete with the morning punch traffic.
Schedule::command('client-errors:prune')
    ->dailyAt('03:20')->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()
    ->onFailure(function () {
        Log::error('Client error telemetry prune failed');
    })
    ->appendOutputTo(storage_path('logs/client-errors-prune.log'));

// Aeon AI Copilot knowledge base refresh (daily at 04:00 AM off-peak)
Schedule::command('aeon:index')
    ->dailyAt('04:00')->timezone(config('app.timezone', 'UTC'))
    ->withoutOverlapping()
    ->runInBackground();
