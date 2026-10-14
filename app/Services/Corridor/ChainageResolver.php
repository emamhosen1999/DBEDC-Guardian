<?php

namespace App\Services\Corridor;

use App\Models\Jurisdiction;
use App\Models\JurisdictionVersion;
use App\Support\Corridor\Chainage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that turns chainage text into metres and metres into a jurisdiction - AS OF a date.
 *
 * Parsing is App\Support\Corridor\Chainage (pure, unit tested). The bands come from jurisdiction_versions, so
 * "which jurisdiction covers K29+500" has a different answer in 2024 and in 2026 if a boundary moved, and a work is always
 * attributed with the boundaries valid ON ITS DATE. Dates are Y-m-d Asia/Dhaka strings; null means today. A version is valid
 * on a date when valid_from <= date <= valid_to (a NULL end is open).
 */
class ChainageResolver
{
    public const CACHE_KEY = 'jurisdiction_versions_v2';

    /** The corridor runs K0+000 to K48+999 (Dhaka Bypass Expressway). */
    public const CORRIDOR_START_M = 0;

    public const CORRIDOR_END_M = 48999;

    public static function today(): string
    {
        return CarbonImmutable::now('Asia/Dhaka')->toDateString();
    }

    /** "K5+900", "Ch 24+500", "K05+900-K06+400", "K27 N301 RAMP" as a span in metres, or null when it would be a guess. */
    public function span(int|float|string|null $text): ?array
    {
        return Chainage::locationSpan($text);
    }

    /** A single point in metres (a span's start); null when unreadable. */
    public function metres(int|float|string|null $text): ?int
    {
        return $this->span($text)['from'] ?? null;
    }

    /**
     * Every version ever recorded (small table, cached 5 minutes, forgotten on every write).
     *
     * @return array<int, array{id: int, jurisdiction_id: int, code: ?string, name: string, start_m: int, end_m: int, valid_from: ?string, valid_to: ?string}>
     */
    public function allVersions(): array
    {
        // A jurisdiction deleted as a mistake leaves no trace in resolution; a RETIRED one keeps its past versions.
        return Cache::remember(self::CACHE_KEY, 300, fn (): array => JurisdictionVersion::query()
            ->whereIn('jurisdiction_id', Jurisdiction::query()->select('id'))
            ->orderBy('start_m')->orderBy('id')->get()
            ->map(fn (JurisdictionVersion $v): array => [
                'id' => (int) $v->id, 'jurisdiction_id' => (int) $v->jurisdiction_id, 'code' => $v->code, 'name' => (string) $v->name,
                'kind' => $v->kind?->value ?? 'section', 'subtype' => $v->subtype, 'carriageway' => $v->carriageway?->value,
                'start_m' => $v->start_m === null ? null : (int) $v->start_m, 'end_m' => $v->end_m === null ? null : (int) $v->end_m,
                'valid_from' => $v->valid_from?->toDateString(), 'valid_to' => $v->valid_to?->toDateString(),
            ])->all());
    }

    /**
     * The bands valid on a date, in chainage order.
     *
     * @return Collection<int, array{id: int, jurisdiction_id: int, code: ?string, name: string, start_m: int, end_m: int, valid_from: ?string, valid_to: ?string}>
     */
    public function bands(?string $date = null, string $kind = 'section'): Collection
    {
        $date ??= self::today();

        return collect($this->allVersions())->filter(
            fn (array $v): bool => $v['kind'] === $kind && $v['start_m'] !== null && ($v['valid_from'] === null || $v['valid_from'] <= $date) && ($v['valid_to'] === null || $v['valid_to'] >= $date)
        )->sortBy('start_m')->values();
    }

    /**
     * Every jurisdiction of every kind valid on a date.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function validOn(?string $date = null): Collection
    {
        $date ??= self::today();

        return collect($this->allVersions())->filter(
            fn (array $v): bool => ($v['valid_from'] === null || $v['valid_from'] <= $date) && ($v['valid_to'] === null || $v['valid_to'] >= $date)
        )->values();
    }

    /**
     * Coverage by kind on a date. Sections get the full report (gaps and overlaps); facilities and areas are counted, since
     * a facility or an area is not expected to cover the road.
     *
     * @return array<string, array<string, mixed>>
     */
    public function coverageByKind(?string $date = null): array
    {
        $on = $this->validOn($date);
        $facilities = $on->where('kind', 'facility');

        return [
            'section' => $this->coverage($date),
            'facility' => ['count' => $facilities->count(), 'length_m' => (int) $facilities->sum(fn (array $v): int => ($v['end_m'] ?? 0) - ($v['start_m'] ?? 0) + 1)],
            'area' => ['count' => $on->where('kind', 'area')->count()],
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** The jurisdiction id covering a point on a date (null outside every band). */
    public function idAt(int $metres, ?string $date = null): ?int
    {
        foreach ($this->bands($date) as $band) {
            if ($metres >= $band['start_m'] && $metres <= $band['end_m']) {
                return $band['jurisdiction_id'];
            }
        }

        return null;
    }

    public function at(int $metres, ?string $date = null): ?Jurisdiction
    {
        $id = $this->idAt($metres, $date);

        return $id === null ? null : Jurisdiction::withTrashed()->find($id);
    }

    /**
     * The jurisdiction a work belonged to on $date: the one holding its start chainage, else the one holding its end.
     * The rule the register has always used, now on numbers and on the boundaries valid on the work's date.
     */
    public function idForSpan(?int $from, ?int $to = null, ?string $date = null): ?int
    {
        foreach ($this->bands($date) as $band) {
            foreach ([$from, $to] as $point) {
                if ($point !== null && $point >= $band['start_m'] && $point <= $band['end_m']) {
                    return $band['jurisdiction_id'];
                }
            }
        }

        return null;
    }

    public function forSpan(?int $from, ?int $to = null, ?string $date = null): ?Jurisdiction
    {
        $id = $this->idForSpan($from, $to, $date);

        return $id === null ? null : Jurisdiction::withTrashed()->find($id);
    }

    /** Resolve register location text ("K05+900-K06+400") to its jurisdiction on a date; null when unreadable or outside. */
    public function forLocation(?string $text, ?string $date = null): ?Jurisdiction
    {
        $span = $this->span($text);

        return $span === null ? null : $this->forSpan($span['from'], $span['to'], $date);
    }

    /**
     * Resolve the start and end tokens a caller has already cut out of its own grammar ("K05+900", "K30").
     * The validator and the import each keep their extraction regex; the numbers and the band test live here.
     */
    public function forTokens(?string $start, ?string $end = null, ?string $date = null): ?Jurisdiction
    {
        $from = Chainage::token($start);
        if ($from === null) {
            return null;
        }

        return $this->forSpan($from, Chainage::token($end), $date);
    }

    /**
     * Coverage of K0..K48+999 by the jurisdictions valid on a date: covered metres, the gaps and the overlaps.
     *
     * @return array{corridor: array{start_m: int, end_m: int, length_m: int}, covered_m: int, coverage_pct: float, gaps: array<int, array{start_m: int, end_m: int, length_m: int}>, overlaps: array<int, array{a: int, b: int, start_m: int, end_m: int, length_m: int}>}
     */
    public function coverage(?string $date = null, ?Collection $bands = null): array
    {
        $bands = ($bands ?? $this->bands($date))->sortBy('start_m')->values();
        $start = self::CORRIDOR_START_M;
        $end = self::CORRIDOR_END_M;
        $length = $end - $start + 1;

        $gaps = [];
        $overlaps = [];
        $cursor = $start; // first metre not yet covered
        $covered = 0;
        foreach ($bands as $i => $band) {
            $from = max($band['start_m'], $start);
            $to = min($band['end_m'], $end);
            if ($to < $from) {
                continue;
            }
            if ($from > $cursor) {
                $gaps[] = ['start_m' => $cursor, 'end_m' => $from - 1, 'length_m' => $from - $cursor];
            }
            if ($from < $cursor) { // starts inside what an earlier band already covers
                $overlapEnd = min($to, $cursor - 1);
                $with = $bands->first(fn (array $other, int $k): bool => $k < $i && $other['end_m'] >= $from);
                $overlaps[] = ['a' => $with['jurisdiction_id'] ?? 0, 'b' => $band['jurisdiction_id'], 'start_m' => $from, 'end_m' => $overlapEnd, 'length_m' => $overlapEnd - $from + 1];
            }
            $newFrom = max($from, $cursor);
            if ($to >= $newFrom) {
                $covered += $to - $newFrom + 1;
                $cursor = $to + 1;
            }
        }
        if ($cursor <= $end) {
            $gaps[] = ['start_m' => $cursor, 'end_m' => $end, 'length_m' => $end - $cursor + 1];
        }

        return [
            'corridor' => ['start_m' => $start, 'end_m' => $end, 'length_m' => $length],
            'covered_m' => $covered,
            'coverage_pct' => round($covered / $length * 100, 1),
            'gaps' => $gaps,
            'overlaps' => $overlaps,
        ];
    }
}
