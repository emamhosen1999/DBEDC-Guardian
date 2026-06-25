<?php

namespace App\Console\Commands\Leave;

use App\Services\Leave\LeaveAccrualService;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RunLeaveAccrual extends Command
{
    protected $signature = 'leave:accrue {--month= : YYYY-MM, defaults to current} {--user=} {--dry-run}';

    protected $description = 'Post monthly accrual for monthly-accrual leave types (ledger)';

    public function handle(LeaveAccrualService $accrual): int
    {
        $month = $this->option('month') ? Carbon::createFromFormat('Y-m', $this->option('month')) : Carbon::now();
        $userId = $this->option('user') ? (int) $this->option('user') : null;

        $posted = $accrual->accrueMonthly($month->year, $month->month, $userId, (bool) $this->option('dry-run'));
        $this->info("Accrual {$month->format('Y-m')}: {$posted} posting(s)".($this->option('dry-run') ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }
}
