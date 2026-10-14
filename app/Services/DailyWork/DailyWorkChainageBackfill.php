<?php

namespace App\Services\DailyWork;

use App\Services\Jurisdiction\JurisdictionAttribution;
use App\Support\Corridor\Chainage;
use Illuminate\Support\Facades\DB;

/**
 * Fills daily_works.start_m / end_m from `location`, then daily_works.jurisdiction_id as of each work's date. Idempotent and chunked: by default only rows whose start_m is
 * still NULL are looked at, so a re-run does nothing but retry the unreadable ones. Plain query-builder updates, so no
 * model events fire (no realtime broadcast, no lock_version bump, updated_at untouched).
 *
 * A location that is not unambiguously one point or one span ("K17-396", three chainages in one cell, free text) is
 * NOT guessed: it stays NULL and is returned in the report.
 */
class DailyWorkChainageBackfill
{
    private const CHUNK = 1000;

    /**
     * @return array{scanned: int, filled: int, kilometre_only: int, unparseable_rows: int, unparseable: array<string, int>, attributed: int, unattributed: int}
     */
    public function run(bool $all = false, bool $dryRun = false): array
    {
        $report = ['scanned' => 0, 'filled' => 0, 'kilometre_only' => 0, 'unparseable_rows' => 0, 'unparseable' => [], 'attributed' => 0, 'unattributed' => 0];

        $query = DB::table('daily_works')->select('id', 'location')->orderBy('id');
        if (! $all) {
            $query->whereNull('start_m');
        }

        $query->chunkById(self::CHUNK, function ($rows) use (&$report, $dryRun): void {
            $byValue = []; // "start|end" => [ids]: one UPDATE per distinct span in the chunk
            foreach ($rows as $row) {
                $report['scanned']++;
                $span = Chainage::locationSpan($row->location);
                if ($span === null) {
                    $report['unparseable_rows']++;
                    $key = (string) $row->location;
                    $report['unparseable'][$key] = ($report['unparseable'][$key] ?? 0) + 1;

                    continue;
                }
                if (Chainage::span($row->location) === null) {
                    $report['kilometre_only']++;
                }
                $byValue[$span['from'].'|'.$span['to']][] = $row->id;
            }
            // One commit per chunk, not per statement: ~1,000 single-row updates commit in one fsync.
            DB::transaction(function () use ($byValue, &$report, $dryRun): void {
                foreach ($byValue as $key => $ids) {
                    [$from, $to] = array_map('intval', explode('|', $key));
                    $report['filled'] += count($ids);
                    if (! $dryRun) {
                        DB::table('daily_works')->whereIn('id', $ids)->update(['start_m' => $from, 'end_m' => $to]);
                    }
                }
            });
        });

        // Then the jurisdiction each work belonged to ON ITS DATE (idempotent; only jurisdiction_id is written).
        if (! $dryRun) {
            $attribution = app(JurisdictionAttribution::class)->run(null, null, true);
            $report['attributed'] = $attribution['changed'];
            $report['unattributed'] = $attribution['unresolved'];
        }

        arsort($report['unparseable']);

        return $report;
    }
}
