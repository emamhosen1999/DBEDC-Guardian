<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\CarryForwardService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ExpireCarriedLeave extends Command
{
    protected $signature = 'leave:expire-carried {--user=} {--dry-run}';

    protected $description = 'Expire unused carried-forward days once their expiry window has passed (ledger)';

    public function handle(CarryForwardService $carry): int
    {
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $posted = $carry->expireCarried(Carbon::now(), $userId, (bool) $this->option('dry-run'));
        $this->info("Carry expiry: {$posted} posting(s)".($this->option('dry-run') ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }
}
