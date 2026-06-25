<?php

namespace App\Console\Commands\Leave;

use App\Models\HRM\Leave;
use App\Models\HRM\LeaveLedger;
use App\Services\Leave\LeaveAccrualService;
use App\Services\Leave\LeaveLedgerService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Seeds the leave ledger for a live cut-over year so balances are immediately correct:
 *   1. annual_upfront entitlement (pro-rated on join) + monthly accrual Jan..current,
 *   2. consumption for each already-approved leave in the year.
 * Idempotent (all sub-operations skip duplicates).
 */
class SeedLeaveLedger extends Command
{
    protected $signature = 'leave:seed-ledger {year} {--user= : Only this user id} {--dry-run}';

    protected $description = 'Seed the leave ledger with entitlement, accrual back-fill, and consumption for a year';

    public function handle(LeaveAccrualService $accrual, LeaveLedgerService $ledger): int
    {
        $year = (int) $this->argument('year');
        $userId = $this->option('user') ? (int) $this->option('user') : null;
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        // 1. Annual entitlement + monthly accrual up to the current month (or full year if past).
        $grants = $accrual->grantAnnual($year, $userId, $dryRun);
        $lastMonth = ($year < Carbon::now()->year) ? 12 : Carbon::now()->month;
        $accruals = 0;
        for ($m = 1; $m <= $lastMonth; $m++) {
            $accruals += $accrual->accrueMonthly($year, $m, $userId, $dryRun);
        }

        // 2. Consumption for each approved leave in the year (idempotent: skip if already consumed).
        $consumptions = 0;
        $leaves = Leave::query()
            ->whereYear('from_date', $year)
            ->where('status', 'approved')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->get();

        foreach ($leaves as $leave) {
            $net = (float) LeaveLedger::where('source_type', 'leave')->where('source_id', $leave->id)
                ->whereIn('txn_type', ['consumption', 'consumption_reversal'])->sum('amount');
            if ($net < 0) {
                continue; // already consuming
            }
            if (! $dryRun) {
                $ledger->consume($leave);
            }
            $consumptions++;
        }

        $this->info("Seeded year {$year}: grants={$grants}, accruals={$accruals}, consumptions={$consumptions}".($dryRun ? ' (dry-run)' : ''));

        return Command::SUCCESS;
    }
}
