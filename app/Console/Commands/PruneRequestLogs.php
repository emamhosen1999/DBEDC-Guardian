<?php

namespace App\Console\Commands;

use App\Models\RequestLog;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

class PruneRequestLogs extends Command
{
    protected $signature = 'request-logs:prune
        {--days= : Retain this many days; defaults to config(request-logs.retention_days)}
        {--archive : Export the stale rows to a gzip NDJSON file before deleting them}
        {--chunk=5000 : Rows per archive read / delete statement}
        {--dry-run : Report what would be archived and deleted without changing anything}';

    protected $description = 'Archive (optional) and delete request logs older than the retention period, in primary-key chunks';

    public function handle(): int
    {
        $days = max(1, (int) ($this->option('days') ?? config('request-logs.retention_days', 90)));
        $chunk = max(100, (int) $this->option('chunk'));
        $cutoff = Carbon::now()->subDays($days);

        $stale = fn () => RequestLog::query()->where('created_at', '<', $cutoff);

        $count = $stale()->count();
        $this->info("Found {$count} request log(s) older than {$days} days ({$cutoff->toDateTimeString()}).");

        if ($count === 0) {
            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run: nothing archived or deleted.');

            return self::SUCCESS;
        }

        // Everything to archive/delete is bounded by the max id present now, so rows arriving meanwhile are untouched.
        $maxId = (int) $stale()->max('id');

        if ($this->option('archive')) {
            $archived = $this->archive($stale, $maxId, $chunk, $cutoff);
            if ($archived === null) {
                return self::FAILURE; // never delete what could not be archived
            }
            $this->info("Archived {$archived} request log(s).");
        }

        $deleted = 0;
        do {
            // Select the next chunk of ids, then delete by primary key: short row locks, no table scan lock.
            $ids = $stale()->where('id', '<=', $maxId)->orderBy('id')->limit($chunk)->pluck('id');
            if ($ids->isEmpty()) {
                break;
            }
            $deleted += RequestLog::whereIn('id', $ids)->delete();
        } while ($ids->count() === $chunk);

        $this->info("Deleted {$deleted} request log(s).");

        return self::SUCCESS;
    }

    /**
     * @return int|null rows written, or null on failure
     */
    private function archive(callable $stale, int $maxId, int $chunk, Carbon $cutoff): ?int
    {
        $disk = Storage::disk('local');
        $dir = trim((string) config('request-logs.archive_path', 'request-log-archives'), '/');
        $disk->makeDirectory($dir);

        $relative = $dir.'/request-logs-until-'.$cutoff->format('Ymd-His').'-'.now()->format('Ymd-His').'.ndjson.gz';
        $handle = gzopen($disk->path($relative), 'wb9');
        if ($handle === false) {
            $this->error("Cannot open {$relative} for writing; nothing was deleted.");

            return null;
        }

        $written = 0;
        $lastId = 0;
        try {
            do {
                $rows = $stale()->where('id', '<=', $maxId)->where('id', '>', $lastId)->orderBy('id')->limit($chunk)->get();
                foreach ($rows as $row) {
                    $line = json_encode($row->getAttributes() + ['created_at' => (string) $row->created_at], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    gzwrite($handle, $line."\n");
                    $written++;
                }
                $lastId = $rows->isEmpty() ? $lastId : (int) $rows->last()->id;
            } while ($rows->count() === $chunk);
        } finally {
            gzclose($handle);
        }

        $this->info('Archive: '.$disk->path($relative));

        return $written;
    }
}
