<?php

namespace App\Console\Commands\Leave;

use App\Models\HRM\LeaveLedger;
use Illuminate\Console\Command;

/**
 * Re-derives the running balance per (user, leave_type, period_year) from the signed
 * amounts and asserts each row's stored balance_after matches. Fails (exit 1) on drift.
 */
class ReconcileLeaveLedger extends Command
{
    protected $signature = 'leave:reconcile-ledger {--fix : Rewrite drifted balance_after values}';

    protected $description = 'Verify (and optionally repair) the leave ledger running balances';

    public function handle(): int
    {
        $mismatches = 0;

        LeaveLedger::query()
            ->select('user_id', 'leave_type', 'period_year')
            ->distinct()
            ->get()
            ->each(function ($g) use (&$mismatches) {
                $running = 0.0;
                LeaveLedger::where('user_id', $g->user_id)
                    ->where('leave_type', $g->leave_type)
                    ->where('period_year', $g->period_year)
                    ->orderBy('id')
                    ->get()
                    ->each(function ($row) use (&$running, &$mismatches) {
                        $running = round($running + (float) $row->amount, 2);
                        if (abs($running - (float) $row->balance_after) > 0.001) {
                            $mismatches++;
                            $this->warn("Drift on ledger #{$row->id}: stored {$row->balance_after}, expected {$running}");
                            if ($this->option('fix')) {
                                \Illuminate\Support\Facades\DB::table('leave_ledger')
                                    ->where('id', $row->id)->update(['balance_after' => $running]);
                            }
                        }
                    });
            });

        if ($mismatches > 0) {
            $this->error("Ledger reconcile found {$mismatches} mismatch(es).");

            return Command::FAILURE;
        }

        $this->info('Leave ledger reconciles cleanly.');

        return Command::SUCCESS;
    }
}
