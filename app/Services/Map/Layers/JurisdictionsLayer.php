<?php

namespace App\Services\Map\Layers;

use App\Models\Jurisdiction;
use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use App\Support\Corridor\Chainage;

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
        return ['jurisdictions'];
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
        $rows = Jurisdiction::query()->orderBy('start_chainage')->get();
        $names = User::query()->whereIn('employee_id', $rows->pluck('incharge')->filter()->all())->pluck('name', 'employee_id');
        foreach ($rows as $j) {
            $from = Chainage::parse($j->start_chainage);
            $to = Chainage::parse($j->end_chainage);
            if ($filter->search !== null && ! str_contains(mb_strtolower((string) $j->location), mb_strtolower($filter->search))) {
                continue;
            }
            $this->placeSpan($out, $geometry, $from === null || $to === null ? null : ['from' => $from, 'to' => $to], $j->id, (string) $j->location, [
                'incharge' => $names[(string) $j->incharge] ?? null,
                'length' => $from !== null && $to !== null ? number_format(($to - $from + 1) / 1000, 1).' km' : null,
            ], 'info');
        }
    }
}
