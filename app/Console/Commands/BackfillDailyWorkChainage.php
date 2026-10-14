<?php

namespace App\Console\Commands;

use App\Services\DailyWork\DailyWorkChainageBackfill;
use Illuminate\Console\Command;

class BackfillDailyWorkChainage extends Command
{
    protected $signature = 'daily-works:backfill-chainage {--all : Recompute every row, not only those still without start_m} {--dry-run : Count only, write nothing}';

    protected $description = 'Fill daily_works.start_m / end_m from the location text (idempotent, chunked); report unreadable locations';

    public function handle(DailyWorkChainageBackfill $backfill): int
    {
        $report = $backfill->run((bool) $this->option('all'), (bool) $this->option('dry-run'));

        $this->info(sprintf(
            '%s: scanned %d, filled %d (%d kilometre-only), unreadable %d rows in %d distinct texts.',
            $this->option('dry-run') ? 'Dry run' : 'Done', $report['scanned'], $report['filled'], $report['kilometre_only'], $report['unparseable_rows'], count($report['unparseable']),
        ));
        $this->line(sprintf('Jurisdiction attributed as of the work date: %d rows set, %d works without a jurisdiction.', $report['attributed'], $report['unattributed']));
        foreach (array_slice($report['unparseable'], 0, 40, true) as $text => $n) {
            $this->line(sprintf('  %5d  %s', $n, str_replace("\n", ' / ', $text)));
        }

        return self::SUCCESS;
    }
}
