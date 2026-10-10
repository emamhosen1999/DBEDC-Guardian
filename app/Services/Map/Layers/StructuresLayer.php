<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use App\Support\Corridor\Chainage;

/** Toll plazas, bridges and interchanges on the corridor (imported, or the client survey workbook until the export arrives). */
final class StructuresLayer extends MapLayer
{
    public function key(): string
    {
        return 'corridor.structures';
    }

    public function label(): string
    {
        return 'Toll plazas & structures';
    }

    public function group(): string
    {
        return 'corridor';
    }

    public function geometry(): array
    {
        return [self::POINT];
    }

    public function permissions(): array
    {
        return ['core.dashboard.view'];
    }

    public function tables(): array
    {
        return ['corridor_geometries', 'corridor_geometry_points', 'corridor_features'];
    }

    public function fields(): array
    {
        return ['chainage' => 'Chainage', 'kind' => 'Type', 'code' => 'Code', 'accuracy' => 'Position'];
    }

    public function icon(): string
    {
        return 'signpost-split';
    }

    public function source(): string
    {
        return 'Corridor structures (imported corridor_features, else the client survey workbook)';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        $search = $filter->search !== null ? mb_strtolower($filter->search) : null;
        foreach ($geometry->features as $f) {
            if ($search !== null && ! str_contains(mb_strtolower($f['name'].' '.$f['code']), $search)) {
                continue;
            }
            $out->add($this->feature('point', $f['code'], $f['name'], [
                'chainage' => Chainage::format($f['chainage_m']),
                'kind' => ucfirst(str_replace('_', ' ', $f['kind'])),
                'code' => $f['code'],
                'accuracy' => $f['estimated'] ? 'Estimated, plus/minus 200-300 m until a field GPS fix' : null,
            ], $f['estimated'] ? 'warn' : ($f['kind'] === 'toll_plaza' ? 'theme' : 'info'), [
                'lat' => $f['lat'], 'lng' => $f['lng'], 'chainage_m' => $f['chainage_m'], 'kind' => $f['kind'], 'estimated' => $f['estimated'],
            ]));
        }
    }
}
