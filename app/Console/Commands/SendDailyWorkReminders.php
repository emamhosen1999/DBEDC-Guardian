<?php

namespace App\Console\Commands;

use App\Models\RfiObjection;
use App\Models\User;
use App\Notifications\OverdueWorkReminderNotification;
use App\Notifications\PendingObjectionReminderNotification;
use App\Services\DailyWork\DailyWorkWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendDailyWorkReminders extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'daily-work:send-reminders
                            {--type=all : Type of reminders to send (all, overdue, objections)}
                            {--dry-run : Show what would be sent without actually sending}';

    /**
     * The console command description.
     */
    protected $description = 'Send automated reminders for overdue daily work and pending objections';

    private DailyWorkWorkflowService $workflowService;

    public function __construct(DailyWorkWorkflowService $workflowService)
    {
        parent::__construct();
        $this->workflowService = $workflowService;
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $type = $this->option('type');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No notifications will be sent');
        }

        $this->info('Starting Daily Work reminder process...');

        $results = [
            'overdue_work' => 0,
            'pending_objections' => 0,
            'notifications_sent' => 0,
        ];

        try {
            // Send overdue work reminders
            if ($type === 'all' || $type === 'overdue') {
                $results['overdue_work'] = $this->sendOverdueWorkReminders($isDryRun);
            }

            // Send pending objection reminders
            if ($type === 'all' || $type === 'objections') {
                $results['pending_objections'] = $this->sendPendingObjectionReminders($isDryRun);
            }

            $results['notifications_sent'] = $results['overdue_work'] + $results['pending_objections'];

            $this->info('Reminder process completed successfully!');
            $this->table(
                ['Type', 'Count'],
                [
                    ['Overdue Work Reminders', $results['overdue_work']],
                    ['Pending Objection Reminders', $results['pending_objections']],
                    ['Total Notifications', $results['notifications_sent']],
                ]
            );

            Log::info('Daily Work reminders sent', $results);

        } catch (\Exception $e) {
            $this->error('Error sending reminders: ' . $e->getMessage());
            Log::error('Daily Work reminder command failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Send reminders for overdue work.
     */
    private function sendOverdueWorkReminders(bool $isDryRun): int
    {
        $overdueWork = $this->workflowService->getOverdueWorkForReminders();

        if ($overdueWork->isEmpty()) {
            $this->info('No overdue work found.');
            return 0;
        }

        $this->info("Found {$overdueWork->count()} overdue work items");

        $notificationsSent = 0;

        foreach ($overdueWork as $dailyWork) {
            $usersToNotify = $this->getUsersForWorkNotification($dailyWork);

            foreach ($usersToNotify as $user) {
                if ($isDryRun) {
                    $this->line("Would send overdue reminder to {$user->name} for work {$dailyWork->number}");
                } else {
                    $user->notify(new OverdueWorkReminderNotification($dailyWork));
                    $notificationsSent++;
                }
            }
        }

        return $notificationsSent;
    }

    /**
     * Send reminders for pending objections.
     */
    private function sendPendingObjectionReminders(bool $isDryRun): int
    {
        $pendingObjections = $this->workflowService->getPendingObjectionsForReminders();

        if ($pendingObjections->isEmpty()) {
            $this->info('No pending objections requiring reminders found.');
            return 0;
        }

        $this->info("Found {$pendingObjections->count()} pending objections");

        $notificationsSent = 0;

        foreach ($pendingObjections as $objection) {
            // Get all daily works this objection is attached to
            $dailyWorks = $objection->dailyWorks;

            foreach ($dailyWorks as $dailyWork) {
                $usersToNotify = $this->getUsersForWorkNotification($dailyWork);

                foreach ($usersToNotify as $user) {
                    if ($isDryRun) {
                        $this->line("Would send pending objection reminder to {$user->name} for objection {$objection->title}");
                    } else {
                        $user->notify(new PendingObjectionReminderNotification($objection, $dailyWork));
                        $notificationsSent++;
                    }
                }
            }
        }

        return $notificationsSent;
    }

    /**
     * Get users to notify for a daily work item.
     */
    private function getUsersForWorkNotification($dailyWork): array
    {
        $users = [];

        // Always include incharge
        if ($dailyWork->inchargeUser) {
            $users[] = $dailyWork->inchargeUser;
        }

        // Include assigned user if different
        if ($dailyWork->assignedUser && $dailyWork->assigned !== $dailyWork->incharge) {
            $users[] = $dailyWork->assignedUser;
        }

        // Remove duplicates
        return collect($users)->unique('id')->toArray();
    }
}