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

// Send attendance reminders daily
Schedule::command('attendance:reminders')
    ->dailyAt('22:17')
    ->timezone(config('app.timezone', 'UTC'))
    ->before(function () {
        Log::info('Starting attendance reminder job');
    })
    ->onSuccess(function () {
        Log::info('Attendance reminders sent successfully');
    })
    ->onFailure(function () {
        Log::error('Failed to send attendance reminders');
    })
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/attendance-reminders.log'));

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

// Scheduled download of attendance logs from all active ADMS devices - runs every 4 hours
Schedule::command('biometric:scheduled-log-download')
    ->everyFourHours()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/biometric-log-download.log'));

// Close forgotten open punches at their resolved shift end
Schedule::command('attendance:auto-punch-out')->hourly()->withoutOverlapping();
