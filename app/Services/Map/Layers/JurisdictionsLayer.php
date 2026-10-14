<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Jurisdiction\JurisdictionTeamService;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;

/** Project phases (jurisdictions) as chainage bands with their in-charge. Governed by jurisdiction.view. */
final class JurisdictionsLayer extends MapLayer
{
    public function key(): string
    {
        return 'corridor.jurisdictions';
    }

    public function label(): string
    {
        return 'Jurisdictions';
    }

    public function group(): string
    {
        return 'corridor';
    }

    public function geometry(): array
    {
        return [self::CHAINAGE_BAND];
    }

    public function permissions(): array
    {
        return ['jurisdiction.view'];
    }

    public function tables(): array
    {
        return ['jurisdictions', 'jurisdiction_versions', 'jurisdiction_members'];
    }

    public function fields(): array
    {
        return ['chainage' => 'Chainage', 'incharge' => 'In charge', 'length' => 'Length'];
    }

    public function route(): ?string
    {
        return 'jurisdiction';
    }

    public function tone(): string
    {
        return 'info';
    }

    public function icon(): string
    {
        return 'diagram-3';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        // Today's boundaries (jurisdiction_versions) and today's in-charge (jurisdiction_members), as plain numbers.
        $resolver = app(ChainageResolver::class);
        $team = app(JurisdictionTeamService::class);
        $bands = $resolver->bands()->values();
        $incharge = [];
        foreach ($bands as $band) {
            $incharge[$band['jurisdiction_id']] = $team->inChargeAsOf($band['jurisdiction_id']);
        }
        $names = User::query()->whereIn('employee_id', array_filter($incharge))->pluck('name', 'employee_id');
        foreach ($bands as $band) {
            if ($filter->search !== null && ! str_contains(mb_strtolower($band['name'].' '.($band['code'] ?? '')), mb_strtolower($filter->search))) {
                continue;
            }
            $this->placeSpan($out, $geometry, ['from' => $band['start_m'], 'to' => $band['end_m']], $band['jurisdiction_id'], trim(($band['code'] ? $band['code'].' ' : '').$band['name']), [
                'incharge' => $names[(string) ($incharge[$band['jurisdiction_id']] ?? '')] ?? null,
                'length' => number_format(($band['end_m'] - $band['start_m'] + 1) / 1000, 1).' km',
            ], 'info');
        }
    }
}
