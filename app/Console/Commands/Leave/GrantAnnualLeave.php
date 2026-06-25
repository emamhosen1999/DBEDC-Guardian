<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\LeaveAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class GrantAnnualLeave extends Command
{
    protected $signature = 'leave:grant-annual {--year= : defaults to current} {--user=} {--dry-run}';

    protected $description = 'Grant annual entitlement (opening) for annual_upfront leave types (ledger)';

    public function handle(LeaveAccrualService $accrual): int
    {
        $year = $this->option('year') ? (int) $this->option('year') : Carbon::now()->year;
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $posted = $accrual->grantAnnual($year, $userId, (bool) $this->option('dry-run'));
        $this->info("Annual grant {$year}: {$posted} posting(s)".($this->option('dry-run') ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }
}
