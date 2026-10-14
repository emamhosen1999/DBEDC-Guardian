<?php

namespace App\Console\Commands;

use App\Services\Jurisdiction\JurisdictionVersionService;
use Illuminate\Console\Command;

class SyncJurisdictionsCurrent extends Command
{
    protected $signature = 'jurisdictions:sync-current';

    protected $description = 'Refresh the current-value mirror on jurisdictions (boundaries, in-charge) after a dated change has taken effect';

    public function handle(JurisdictionVersionService $versions): int
    {
        $this->info('Mirrors refreshed: '.$versions->syncAllMirrors());

        return self::SUCCESS;
    }
}
