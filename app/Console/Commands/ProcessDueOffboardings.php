<?php

namespace App\Console\Commands;

use App\Jobs\ProcessOffboardingLwd;
use App\Models\HRM\Offboarding;
use Illuminate\Console\Command;

/**
 * Safety net for ProcessOffboardingLwd: queues the LWD effects (access revocation,
 * biometric removal, roster cleanup) for every offboarding whose last working day
 * is over but which was never processed — a missed/failed delayed job, an
 * environment on the `sync` queue driver (which ignores delay), or a deploy gap.
 */
class ProcessDueOffboardings extends Command
{
    protected $signature = 'offboarding:process-due';

    protected $description = 'Queue LWD processing for offboardings whose last working day has passed';

    public function handle(): int
    {
        $count = 0;

        // LWD < today => the LWD day is over (app timezone).
        Offboarding::query()
            ->where('status', '!=', Offboarding::STATUS_CANCELLED)
            ->whereNull('lwd_processed_at')
            ->whereDate('last_working_date', '<', now()->toDateString())
            ->orderBy('id')
            ->each(function (Offboarding $offboarding) use (&$count) {
                ProcessOffboardingLwd::dispatch($offboarding);
                $count++;
            });

        $this->info("Queued LWD processing for {$count} offboarding(s).");

        return self::SUCCESS;
    }
}
