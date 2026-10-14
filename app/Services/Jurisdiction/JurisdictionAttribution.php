<?php

namespace App\Services\Jurisdiction;

use App\Services\Corridor\ChainageResolver;
use Illuminate\Support\Facades\DB;

/**
 * daily_works.jurisdiction_id, resolved AS OF each work's own date against the jurisdiction versions. Only that column is
 * written: the work's recorded incharge and assigned are the historical truth and are never touched here. Plain query
 * builder updates (no model events, no lock_version bump). Idempotent: a second run over the same range changes nothing.
 */
class JurisdictionAttribution
{
    private const CHUNK = 2000;

    public function __construct(private readonly ChainageResolver $resolver) {}

    /**
     * @return array{scanned: int, changed: int, unresolved: int, moves: array<string, int>, apply: bool}
     */
    public function run(?string $from = null, ?string $to = null, bool $apply = false): array
    {
        $report = ['scanned' => 0, 'changed' => 0, 'unresolved' => 0, 'moves' => [], 'apply' => $apply];

        $query = DB::table('daily_works')->select('id', 'date', 'start_m', 'end_m', 'jurisdiction_id')->whereNull('deleted_at')->orderBy('id');
        if ($from !== null) {
            $query->where('date', '>=', $from);
        }
        if ($to !== null) {
            $query->where('date', '<=', $to);
        }

        $query->chunkById(self::CHUNK, function ($rows) use (&$report, $apply): void {
            $byTarget = [];
            foreach ($rows as $row) {
                $report['scanned']++;
                $target = $row->start_m === null ? null : $this->resolver->idForSpan((int) $row->start_m, $row->end_m === null ? null : (int) $row->end_m, substr((string) $row->date, 0, 10));
                if ($target === null) {
                    $report['unresolved']++;
                }
                if ($target === ($row->jurisdiction_id === null ? null : (int) $row->jurisdiction_id)) {
                    continue;
                }
                $report['changed']++;
                $key = ($row->jurisdiction_id ?? 'none').' -> '.($target ?? 'none');
                $report['moves'][$key] = ($report['moves'][$key] ?? 0) + 1;
                $byTarget[$target ?? 0][] = $row->id;
            }
            if ($apply && $byTarget !== []) {
                DB::transaction(function () use ($byTarget): void {
                    foreach ($byTarget as $target => $ids) {
                        foreach (array_chunk($ids, 500) as $part) {
                            DB::table('daily_works')->whereIn('id', $part)->update(['jurisdiction_id' => $target === 0 ? null : $target]);
                        }
                    }
                });
            }
        });

        ksort($report['moves']);

        return $report;
    }
}
