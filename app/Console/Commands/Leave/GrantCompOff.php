<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\CompOffService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Banks compensatory-off grants for employees who worked on an off-day,
 * holiday, or their own approved leave day. Idempotent per (user, date) —
 * re-running over the same window never double-grants.
 */
class GrantCompOff extends Command
{
    protected $signature = 'leave:grant-comp-off
        {--from= : Start date (Y-m-d), default = yesterday}
        {--to= : End date (Y-m-d), default = same as --from}
        {--user= : Only this user id}
        {--dry-run : Count without posting}';

    protected $description = 'Bank comp-off ledger grants for work done on off-days, holidays, or approved leave days';

    public function handle(CompOffService $service): int
    {
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : Carbon::yesterday();
        $to = $this->option('to') ? Carbon::parse($this->option('to')) : $from->copy();
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $posted = $service->scan($from, $to, $userId, $dryRun);

        $this->info("Comp-off scan {$from->toDateString()}..{$to->toDateString()}: {$posted} grant(s)".($dryRun ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }
}
