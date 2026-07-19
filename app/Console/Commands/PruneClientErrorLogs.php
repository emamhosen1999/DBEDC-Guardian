<?php

namespace App\Console\Commands;

use App\Models\ClientErrorLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Retention for crash telemetry — BOTH streams (source=mobile and source=server).
 *
 * Even with fingerprint grouping the table grows: every new bug in every new
 * release mints a new group, and the server stream mints one per new throw site.
 * Two windows, keyed on LAST SEEN (a group last seen yesterday is live no matter
 * how old its first sighting):
 *
 *   - resolved groups older than 30 days -> triaged and quiet, nothing left to learn
 *   - ANY group older than 90 days       -> beyond the window we would act on
 *
 * The windows are deliberately SHARED across streams rather than split: the two
 * halves of one incident should expire together, otherwise a resolved mobile
 * crash disappears while its server cause lingers (or vice versa) and the board
 * shows half a story. `--source` exists for targeted cleanup, not policy drift.
 *
 * Deletes in chunks so a large backlog cannot lock the table or blow memory.
 */
class PruneClientErrorLogs extends Command
{
    protected $signature = 'client-errors:prune
                            {--resolved-days=30 : Delete resolved groups last seen this many days ago}
                            {--max-days=90 : Delete ANY group last seen this many days ago}
                            {--source=all : Limit to one stream: all|mobile|server}
                            {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Prune old error telemetry groups (mobile crashes + server exceptions)';

    public function handle(): int
    {
        $resolvedDays = max((int) $this->option('resolved-days'), 1);
        $maxDays = max((int) $this->option('max-days'), 1);
        $dryRun = (bool) $this->option('dry-run');
        $source = (string) $this->option('source');

        if ($source !== 'all' && ! in_array($source, ClientErrorLog::SOURCES, true)) {
            $this->error('Invalid --source. Expected one of: all, '.implode(', ', ClientErrorLog::SOURCES).'.');

            return self::FAILURE;
        }

        $resolvedCutoff = Carbon::now()->subDays($resolvedDays);
        $maxCutoff = Carbon::now()->subDays($maxDays);

        $resolvedQuery = ClientErrorLog::query()
            ->source($source)
            ->whereNotNull('resolved_at')
            ->where('last_seen_at', '<', $resolvedCutoff);

        $staleQuery = ClientErrorLog::query()
            ->source($source)
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
            'Pruned %d resolved group(s) older than %d days and %d group(s) older than %d days (source: %s).',
            $resolvedDeleted, $resolvedDays, $staleDeleted, $maxDays, $source,
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
