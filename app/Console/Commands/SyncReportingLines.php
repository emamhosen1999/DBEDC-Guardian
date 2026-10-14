<?php

namespace App\Console\Commands;

use App\Services\Org\ReportingLineService;
use Illuminate\Console\Command;

class SyncReportingLines extends Command
{
    protected $signature = 'org:sync-reporting-lines';

    protected $description = 'Record reporting-line changes that bypassed model events (query-builder updates, imports) in employee_reporting_lines';

    public function handle(ReportingLineService $lines): int
    {
        $this->info('Reporting lines recorded: '.$lines->reconcile());

        return self::SUCCESS;
    }
}
