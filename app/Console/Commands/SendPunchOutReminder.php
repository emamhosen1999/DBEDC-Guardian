<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\Attendance\MissedPunchNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendPunchOutReminder extends Command
{
    protected $signature = 'send:punchout-reminder';

    protected $description = 'Send Punch Out Reminder Notification to Users at 6 PM';

    public function __construct()
    {
        parent::__construct();
    }

    public function handle()
    {
        $date = now()->toDateString();

        $users = User::with(['attendances' => function ($query) {
            $query->whereNotNull('punchin'); // Filter attendances to only those with punchin data
        }])->whereHas('attendances', function ($query) {
            $query->whereNotNull('punchin'); // Ensure users have attendances with punchin
        })->get();

        foreach ($users as $user) {
            // Fire the structured notification (queued, non-breaking).
            // Deliverability across channels (push/db/mail) is handled by the
            // engine notify itself, which no-ops gracefully if the user has
            // no registered notification_tokens.
            try {
                $user->notify(new MissedPunchNotification('out', $date));
            } catch (\Throwable $exception) {
                Log::warning("MissedPunchNotification(out) failed for user {$user->id}", [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $this->info('Punch out reminder notifications sent successfully.');
    }
}
