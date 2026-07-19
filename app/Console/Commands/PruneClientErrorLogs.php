<?php

namespace App\Console\Commands;

use App\Models\ClientErrorLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retention for client crash telemetry.
 *
 * Even with fingerprint grouping the table grows: every new bug in every new
 * release mints a new group. Two windows, keyed on LAST SEEN (a group last seen
 * yesterday is live no matter how old its first sighting):
 *
 *   - resolved groups older than 30 days -> triaged and quiet, nothing left to learn
 *   - ANY group older than 90 days       -> beyond the window we would act on
 *
 * Deletes in chunks so a large backlog cannot lock the table or blow memory.
 */
class PruneClientErrorLogs extends Command
{
    protected $signature = 'client-errors:prune
                            {--resolved-days=30 : Delete resolved groups last seen this many days ago}
                            {--max-days=90 : Delete ANY group last seen this many days ago}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune old client (mobile) error telemetry groups';

    public function handle(): int
    {
        $resolvedDays = max((int) $this->option('resolved-days'), 1);
        $maxDays = max((int) $this->option('max-days'), 1);
        $dryRun = (bool) $this->option('dry-run');

        $resolvedCutoff = Carbon::now()->subDays($resolvedDays);
        $maxCutoff = Carbon::now()->subDays($maxDays);

        $resolvedQuery = ClientErrorLog::query()
            ->whereNotNull('resolved_at')
            ->where('last_seen_at', '<', $resolvedCutoff);

        $staleQuery = ClientErrorLog::query()
            ->where('last_seen_at', '<', $maxCutoff);

        if ($dryRun) {
            $this->info(sprintf(
                'Dry run: %d resolved group(s) older than %d days, %d group(s) older than %d days.',
                $resolvedQuery->count(), $resolvedDays,
                $staleQuery->count(), $maxDays,
            ));

            return self::SUCCESS;
        }

        $resolvedDeleted = $this->deleteInChunks($resolvedQuery);
        $staleDeleted = $this->deleteInChunks($staleQuery);

        $this->info(sprintf(
            'Pruned %d resolved group(s) older than %d days and %d group(s) older than %d days.',
            $resolvedDeleted, $resolvedDays, $staleDeleted, $maxDays,
        ));

        return self::SUCCESS;
    }

    protected function deleteInChunks(\Illuminate\Database\Eloquent\Builder $query, int $chunk = 500): int
    {
        $deleted = 0;

        do {
            $batch = (clone $query)->limit($chunk)->pluck('id');

            if ($batch->isEmpty()) {
                break;
            }

            $deleted += ClientErrorLog::query()->whereIn('id', $batch)->delete();
        } while ($batch->count() === $chunk);

        return $deleted;
    }
}
