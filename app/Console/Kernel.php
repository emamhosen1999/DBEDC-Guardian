<?php

namespace App\Console;

use App\Console\Commands\AttendanceAutoPunchOut;
use App\Console\Commands\ProcessScheduledBiometricCommands;
use App\Console\Commands\ScheduledBiometricLogDownload;
use App\Console\Commands\SendAttendanceReminders;
use App\Models\NotificationLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        AttendanceAutoPunchOut::class,
        SendAttendanceReminders::class,
        ProcessScheduledBiometricCommands::class,
        ScheduledBiometricLogDownload::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule)
    {
        // Test scheduler command runs every minute (for testing only)
        if (config('app.env') === 'local') {
            $schedule->command('test:scheduler')
                ->everyMinute()
                ->onFailure(function () {
                    Log::error('Test scheduler failed');
                });
        }

        // Send attendance reminders daily at 8:00 AM
        $schedule->command('attendance:reminders')
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
                // Optionally send an alert to admins here
            })
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/attendance-reminders.log'));

        // Clean up old notification logs (keep 30 days)
        $schedule->command('model:prune', [
            '--model' => [
                NotificationLog::class,
            ],
        ])->daily();

        // Leave ledger scheduled tasks (Phase 3).
        // Year-boundary: grant annual entitlement + carry forward last year's remaining.
        $schedule->command('leave:grant-annual')
            ->yearly()->timezone(config('app.timezone', 'UTC'))->at('00:05')
            ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-grant-annual.log'));

        $schedule->command('leave:carry-forward')
            ->yearly()->timezone(config('app.timezone', 'UTC'))->at('00:10')
            ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-carry-forward.log'));

        // Monthly accrual for monthly-accrual types - 1st of each month.
        $schedule->command('leave:accrue')
            ->monthlyOn(1, '00:15')->timezone(config('app.timezone', 'UTC'))
            ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-accrual.log'));

        // Daily: expire elapsed carried days + reconcile ledger integrity.
        $schedule->command('leave:expire-carried')
            ->daily()->timezone(config('app.timezone', 'UTC'))
            ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-expire-carried.log'));

        // Daily: bank comp-off for yesterday's work on off-days/holidays/leave days.
        $schedule->command('leave:grant-comp-off')
            ->dailyAt('01:00')->timezone(config('app.timezone', 'UTC'))
            ->withoutOverlapping()->appendOutputTo(storage_path('logs/leave-comp-off.log'));

        $schedule->command('leave:reconcile-ledger')
            ->daily()->timezone(config('app.timezone', 'UTC'))
            ->withoutOverlapping()
            ->onFailure(function () {
                Log::error('Leave ledger reconcile detected drift');
            })
            ->appendOutputTo(storage_path('logs/leave-reconcile.log'));

        // Process scheduled biometric device commands - runs every minute
        $schedule->command('biometric:process-scheduled-commands')
            ->everyMinute()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/biometric-commands.log'));

        // Scheduled download of attendance logs from all active ADMS devices - runs every 4 hours
        $schedule->command('biometric:scheduled-log-download')
            ->everyFourHours()
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path('logs/biometric-log-download.log'));

        // Close forgotten open punches at their resolved shift end
        $schedule->command('attendance:auto-punch-out')->hourly()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
