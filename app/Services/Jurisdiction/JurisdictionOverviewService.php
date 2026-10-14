<?php

namespace App\Services\Jurisdiction;

use App\Enums\JurisdictionKind;
use App\Models\Jurisdiction;
use App\Models\JurisdictionHandover;
use App\Models\JurisdictionMember;
use App\Models\JurisdictionVersion;
use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use App\Services\Corridor\CorridorGeometryResolver;
use App\Services\Map\Layers\StructuresLayer;
use App\Services\Map\MapFilter;
use App\Services\Org\ReportingLineService;
use App\Support\Corridor\Chainage;
use Illuminate\Support\Collection;

/**
 * Everything the Jurisdictions tab, its map and the print view show, AS OF a date: the boundaries and names valid that
 * day, the team valid that day, and the period's works attributed to each jurisdiction. Real data only.
 */
class JurisdictionOverviewService
{
    public function __construct(
        private readonly ChainageResolver $resolver,
        private readonly JurisdictionTeamService $team,
        private readonly JurisdictionStatsService $stats,
        private readonly JurisdictionVersionService $versions,
        private readonly CorridorGeometryResolver $geometry,
        private readonly JurisdictionTeamResolver $teams,
        private readonly ReportingLineService $lines,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $viewer, string $asOf, JurisdictionPeriod $period, bool $withMap = true, bool $withContacts = true): array
    {
        $this->versions->syncAllMirrors(); // dated changes that have taken effect since the last write
        $this->lines->reconcile(); // reporting-line changes that bypassed model events

        $bands = $this->resolver->validOn($asOf)->sortBy(fn (array $v): string => sprintf('%d|%08d|%d', ['section' => 0, 'facility' => 1, 'area' => 2][$v['kind']], $v['start_m'] ?? 99999999, $v['id']))->values();
        $jurisdictions = Jurisdiction::withTrashed()->whereIn('id', $bands->pluck('jurisdiction_id'))->get()->keyBy('id');
        $stats = $this->stats->forPeriod($jurisdictions->filter(fn (Jurisdiction $j): bool => $j->kind === JurisdictionKind::Section)->values(), $period);
        $teams = $jurisdictions->mapWithKeys(fn (Jurisdiction $j): array => [$j->id => $this->team->teamAsOf($j, $asOf)]);
        $inchargeIds = $teams->flatMap(fn (Collection $t) => $t->where('role', JurisdictionMember::IN_CHARGE)->pluck('employee_id'))->unique()->values()->all();
        $workload = $this->stats->openWorkload($inchargeIds);
        $worksMap = [];
        foreach ($jurisdictions as $id => $j) {
            $worksMap[$id] = $stats['by_jurisdiction'][$id]['works_by_employee'] ?? [];
        }
        $resolved = $this->teams->resolve($jurisdictions->values(), $teams->all(), $worksMap, $asOf, $viewer, $withContacts);
        $upcoming = $this->upcoming($jurisdictions->keys()->all(), $asOf);

        $rows = [];
        $position = 0;
        foreach ($bands as $band) {
            $j = $jurisdictions[$band['jurisdiction_id']] ?? null;
            if ($j === null) {
                continue;
            }
            $team = $teams[$j->id];
            $inCharge = $team->firstWhere('role', JurisdictionMember::IN_CHARGE);
            $isSection = $band['kind'] === 'section';
            $s = $stats['by_jurisdiction'][$j->id] ?? ['works' => 0, 'completed' => 0, 'completion_rate' => null, 'by_status' => [], 'by_type' => [], 'rfis' => 0, 'objections' => 0, 'last_activity' => null, 'works_by_employee' => []];
            $hasChain = $band['start_m'] !== null;
            $rows[] = [
                'id' => $j->id, 'kind' => $band['kind'], 'subtype' => $band['subtype'], 'carriageway' => $band['carriageway'],
                'code' => $band['code'], 'name' => $band['name'], 'location' => $band['name'],
                'start_m' => $band['start_m'], 'end_m' => $band['end_m'],
                'start_chainage' => $hasChain ? Chainage::format($band['start_m']) : null, 'end_chainage' => $hasChain ? Chainage::format($band['end_m']) : null,
                'length_m' => $hasChain ? $band['end_m'] - $band['start_m'] + 1 : null,
                'has_area' => $band['kind'] === 'area', 'attributes_works' => $isSection,
                'description' => $j->description, 'colour_token' => $j->colour_token ?? ($isSection ? JurisdictionPalette::forPosition($position++) : $band['kind']),
                'colour_chosen' => $j->colour_token !== null,
                'valid_from' => $band['valid_from'], 'valid_to' => $band['valid_to'], 'retired' => $j->trashed(),
                'in_charge' => $inCharge === null ? null : $this->employee($inCharge->employee_id, $inCharge->employee) + [
                    'since' => $inCharge->effective_from?->toDateString(), 'open_workload' => $workload[$inCharge->employee_id] ?? 0,
                ],
                'team' => $resolved[$j->id]['tree'], 'team_kpis' => $resolved[$j->id]['kpis'], 'team_warnings' => $resolved[$j->id]['warnings'], 'team_note' => $resolved[$j->id]['note'],
                'stats' => collect($s)->except('works_by_employee')->all(),
                'upcoming' => $upcoming[$j->id] ?? [],
            ];
        }

        $coverage = $this->resolver->coverage($asOf);
        $archived = $this->archived($asOf);

        return [
            'as_of' => $asOf, 'is_today' => $asOf === ChainageResolver::today(),
            'period' => $stats['period'], 'generated_at' => now('Asia/Dhaka')->toIso8601String(),
            'jurisdictions' => $rows,
            'coverage' => $coverage,
            'coverage_by_kind' => $this->resolver->coverageByKind($asOf),
            'archived' => $archived,
            'summary' => [
                'count' => count($rows), 'sections' => collect($rows)->where('kind', 'section')->count(), 'coverage_pct' => $coverage['coverage_pct'], 'gaps' => count($coverage['gaps']), 'overlaps' => count($coverage['overlaps']),
                'unassigned_incharge' => collect($rows)->whereNull('in_charge')->count(),
                'works' => collect($rows)->where('kind', 'section')->sum(fn (array $r): int => $r['stats']['works']), 'unplaced' => $stats['unplaced'],
            ],
            'map' => $withMap ? $this->map($viewer, $rows) : null,
        ];
    }

    /** @return array{employee_id: string, name: ?string, designation: ?string, photo: ?string} */
    public function employee(?string $id, ?User $user): array
    {
        return [
            'employee_id' => (string) $id, 'name' => $user?->name, 'designation' => $user?->designation?->title,
            'photo' => $user?->profile_image_url,
        ];
    }

    /**
     * Planned changes after the as-of date: a boundary version or in-charge row that starts later.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function upcoming(array $ids, string $asOf): array
    {
        $out = [];
        foreach (JurisdictionVersion::query()->whereIn('jurisdiction_id', $ids)->where('valid_from', '>', $asOf)->orderBy('valid_from')->get() as $v) {
            $out[$v->jurisdiction_id][] = ['type' => 'boundary', 'from' => $v->valid_from->toDateString(), 'label' => $v->name.' '.Chainage::format($v->start_m).' to '.Chainage::format($v->end_m)];
        }
        $rows = JurisdictionMember::query()->whereIn('jurisdiction_id', $ids)->where('role', JurisdictionMember::IN_CHARGE)->where('effective_from', '>', $asOf)
            ->with('employee:employee_id,name')->orderBy('effective_from')->get();
        foreach ($rows as $m) {
            $out[$m->jurisdiction_id][] = ['type' => 'in_charge', 'from' => $m->effective_from->toDateString(), 'label' => ($m->employee?->name ?? $m->employee_id).' becomes in-charge'];
        }

        return $out;
    }

    /**
     * Jurisdictions that are not valid on the date but have a past: retired (the latest definition ended) or deleted.
     *
     * @return array<int, array<string, mixed>>
     */
    private function archived(string $asOf): array
    {
        $validIds = $this->resolver->validOn($asOf)->pluck('jurisdiction_id')->all();
        $out = [];
        foreach (Jurisdiction::withTrashed()->whereNotIn('id', $validIds)->orderBy('id')->get() as $j) {
            $latest = JurisdictionVersion::query()->where('jurisdiction_id', $j->id)->orderByDesc('id')->first();
            if ($latest === null) {
                continue;
            }
            $future = $latest->valid_from !== null && $latest->valid_from->toDateString() > $asOf;
            $out[] = ['id' => $j->id, 'kind' => $latest->kind?->value ?? 'section', 'code' => $latest->code, 'name' => $latest->name, 'deleted' => $j->trashed(),
                'state' => $j->trashed() ? 'deleted' : ($future ? 'planned' : 'retired'), 'retired_on' => $latest->valid_to?->toDateString(), 'starts_on' => $future ? $latest->valid_from->toDateString() : null];
        }

        return $out;
    }

    /** @return array<string, mixed> the payload CorridorSvgMap draws: centreline, structures, one band layer per jurisdiction */
    public function map(User $viewer, array $rows): array
    {
        $geometry = $this->geometry->resolve();
        $alignment = $geometry->alignment;
        $structures = (new StructuresLayer)->run($viewer, MapFilter::today(), $geometry);

        $layers = [[
            'key' => 'corridor.structures', 'label' => 'Toll plazas & structures', 'group' => 'corridor', 'icon' => 'signpost-split', 'tone' => 'theme',
            'count' => count($structures->features), 'features' => $structures->features, 'geometry' => [StructuresLayer::POINT],
        ]];
        $groups = ['corridor' => 'Corridor'];
        foreach ($rows as $r) {
            if ($r['kind'] === 'facility') {
                $mid = $alignment->pointAt((int) round(($r['start_m'] + $r['end_m']) / 2));
                if ($mid !== null) {
                    $group = 'jr-facility';
                    $groups[$group] = 'Facilities';
                    $layers[] = ['key' => 'jurisdiction:'.$r['id'], 'label' => $r['name'], 'group' => $group, 'icon' => 'building', 'tone' => 'info', 'count' => 1, 'geometry' => [StructuresLayer::POINT], 'features' => [[
                        'id' => 'jurisdiction:'.$r['id'], 'shape' => 'point', 'title' => $r['code'].' '.$r['name'], 'tone' => 'info', 'lat' => $mid['lat'], 'lng' => $mid['lng'],
                        'chainage_m' => $r['start_m'], 'jurisdiction_id' => $r['id'],
                        'fields' => array_values(array_filter([
                            ['label' => 'Type', 'value' => ucfirst(str_replace('_', ' ', (string) $r['subtype']))],
                            ['label' => 'Chainage', 'value' => $r['start_chainage'].($r['end_m'] > $r['start_m'] ? ' to '.$r['end_chainage'] : '')],
                            $r['in_charge'] ? ['label' => 'In charge', 'value' => (string) $r['in_charge']['name']] : null,
                        ])),
                    ]]];
                }

                continue;
            }
            if ($r['kind'] === 'area') {
                $ring = JurisdictionVersion::query()->where('jurisdiction_id', $r['id'])->orderByDesc('id')->value('geometry');
                $ring = is_string($ring) ? json_decode($ring, true) : $ring;
                $path = array_map(fn (array $p): array => [$p[1], $p[0]], array_slice($ring['coordinates'][0] ?? [], 0, -1));
                if (count($path) >= 3) {
                    $group = 'jr-area';
                    $groups[$group] = 'Areas';
                    $layers[] = ['key' => 'jurisdiction:'.$r['id'], 'label' => $r['name'], 'group' => $group, 'icon' => 'bounding-box', 'tone' => 'info', 'count' => 1, 'geometry' => ['polygon'], 'features' => [[
                        'id' => 'jurisdiction:'.$r['id'], 'shape' => 'polygon', 'title' => $r['code'].' '.$r['name'], 'tone' => 'info', 'path' => $path, 'jurisdiction_id' => $r['id'],
                        'lat' => array_sum(array_column($path, 0)) / count($path), 'lng' => array_sum(array_column($path, 1)) / count($path),
                        'fields' => array_values(array_filter([['label' => 'Kind', 'value' => 'Area'], $r['in_charge'] ? ['label' => 'In charge', 'value' => (string) $r['in_charge']['name']] : null])),
                    ]]];
                }

                continue;
            }
            $path = $alignment->slice($r['start_m'], $r['end_m']);
            if (count($path) < 2) {
                continue;
            }
            $mid = $alignment->pointAt((int) round(($r['start_m'] + $r['end_m']) / 2));
            $group = 'jr-'.$r['colour_token'];
            $groups[$group] = $r['code'].' '.$r['name'];
            $features = [[
                'id' => 'jurisdiction:'.$r['id'], 'shape' => 'band', 'title' => $r['code'].' '.$r['name'], 'tone' => 'info', 'path' => $path,
                'from_m' => $r['start_m'], 'to_m' => $r['end_m'], 'chainage_m' => $r['start_m'], 'lat' => $mid['lat'] ?? $path[0][0], 'lng' => $mid['lng'] ?? $path[0][1],
                'label' => $r['code'], 'jurisdiction_id' => $r['id'],
                'fields' => array_values(array_filter([
                    ['label' => 'Chainage', 'value' => $r['start_chainage'].' to '.$r['end_chainage']],
                    ['label' => 'Length', 'value' => number_format($r['length_m'] / 1000, 1).' km'],
                    $r['in_charge'] ? ['label' => 'In charge', 'value' => (string) $r['in_charge']['name']] : null,
                ])),
            ]];
            if ($r['in_charge'] !== null && $mid !== null) {
                $features[] = [
                    'id' => 'jurisdiction:'.$r['id'].':in-charge', 'shape' => 'point', 'title' => (string) $r['in_charge']['name'], 'tone' => 'neutral',
                    'lat' => $mid['lat'], 'lng' => $mid['lng'], 'chainage_m' => (int) round(($r['start_m'] + $r['end_m']) / 2), 'jurisdiction_id' => $r['id'],
                    'fields' => [['label' => 'In charge of', 'value' => $r['code']]],
                    'person' => ['name' => $r['in_charge']['name'], 'photo' => $r['in_charge']['photo']],
                ];
            }
            $layers[] = ['key' => 'jurisdiction:'.$r['id'], 'label' => $r['name'].' in-charge and band', 'group' => $group, 'icon' => 'diagram-3', 'tone' => 'info', 'count' => count($features),
                'features' => $features, 'geometry' => [StructuresLayer::CHAINAGE_BAND], 'people' => true];
        }

        return [
            'alignment' => [
                'points' => array_map(fn (array $p): array => [round($p['lat'], 6), round($p['lng'], 6), $p['chainage_m']], $alignment->simplified(400)),
                'length_m' => $alignment->lengthM(), 'source' => $geometry->source,
            ],
            'groups' => $groups, 'layers' => $layers,
        ];
    }

    /** @return Collection<int, JurisdictionHandover> */
    public function handovers(Jurisdiction $j): Collection
    {
        return JurisdictionHandover::query()->where('jurisdiction_id', $j->id)->with(['outgoing:employee_id,name', 'incoming:employee_id,name', 'transfers'])->orderByDesc('effective_date')->orderByDesc('id')->get();
    }
}
