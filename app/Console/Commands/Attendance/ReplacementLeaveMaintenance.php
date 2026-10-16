<?php

namespace App\Console\Commands\Attendance;

use App\Services\Attendance\CompOffService;
use Illuminate\Console\Command;

/** Daily: expire replacement credit past its 60 days (written to the ledger, never dropped) and send the 14 / 3 day reminders. */
class ReplacementLeaveMaintenance extends Command
{
    protected $signature = 'replacement:maintain';

    protected $description = 'Expire replacement leave credit and send the expiry reminders';

    public function handle(CompOffService $service): int
    {
        $reminded = $service->remind();
        $expired = $service->expire();
        $this->info("Replacement leave: {$reminded} reminder(s), {$expired} credit(s) expired.");

        return self::SUCCESS;
    }
}
