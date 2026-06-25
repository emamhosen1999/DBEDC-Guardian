<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\CarryForwardService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunCarryForward extends Command
{
    protected $signature = 'leave:carry-forward {--from= : source year, defaults to previous} {--to= : target year, defaults to current} {--user=} {--dry-run}';

    protected $description = 'Carry forward last year\'s remaining balance (capped) into the new year (ledger)';

    public function handle(CarryForwardService $carry): int
    {
        $to = $this->option('to') ? (int) $this->option('to') : Carbon::now()->year;
        $from = $this->option('from') ? (int) $this->option('from') : $to - 1;
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $posted = $carry->rollOver($from, $to, $userId, (bool) $this->option('dry-run'));
        $this->info("Carry-forward {$from}->{$to}: {$posted} posting(s)".($this->option('dry-run') ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }
}
