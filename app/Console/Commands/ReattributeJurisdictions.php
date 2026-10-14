<?php

namespace App\Console\Commands;

use App\Services\Access\AccessAudit;
use App\Services\Jurisdiction\JurisdictionAttribution;
use Illuminate\Console\Command;

class ReattributeJurisdictions extends Command
{
    protected $signature = 'jurisdictions:reattribute {--from= : First work date (Y-m-d)} {--to= : Last work date (Y-m-d)} {--apply : Write the changes (default: dry run)}';

    protected $description = 'Re-resolve daily_works.jurisdiction_id as of each work date against the jurisdiction versions (dry run unless --apply)';

    public function handle(JurisdictionAttribution $attribution, AccessAudit $audit): int
    {
        $from = $this->option('from') ?: null;
        $to = $this->option('to') ?: null;
        $report = $attribution->run($from, $to, (bool) $this->option('apply'));

        $this->info(sprintf('%s: scanned %d, %s %d, without a jurisdiction %d (range %s to %s).',
            $report['apply'] ? 'Applied' : 'Dry run', $report['scanned'], $report['apply'] ? 'changed' : 'would change', $report['changed'], $report['unresolved'], $from ?? 'beginning', $to ?? 'today'));
        foreach ($report['moves'] as $move => $n) {
            $this->line(sprintf('  %6d  %s', $n, $move));
        }
        if ($report['apply'] && $report['changed'] > 0) {
            $audit->record('jurisdiction.works_reattributed', 'daily_works', null, null, ['from' => $from, 'to' => $to, 'changed' => $report['changed'], 'moves' => $report['moves']]);
        }

        return self::SUCCESS;
    }
}
