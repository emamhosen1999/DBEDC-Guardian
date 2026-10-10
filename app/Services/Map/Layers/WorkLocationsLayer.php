<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;

/** Work locations with coordinates, as geofence circles (a location without a radius is a point). */
final class WorkLocationsLayer extends MapLayer
{
    public function key(): string
    {
        return 'corridor.work_locations';
    }

    public function label(): string
    {
        return 'Work locations';
    }

    public function group(): string
    {
        return 'corridor';
    }

    public function geometry(): array
    {
        return [self::GEOFENCE_CIRCLE, self::POINT];
    }

    public function permissions(): array
    {
        return ['employees.view'];
    }

    public function tables(): array
    {
        return ['work_locations'];
    }

    public function fields(): array
    {
        return ['code' => 'Code', 'address' => 'Address', 'radius' => 'Geofence'];
    }

    public function route(): ?string
    {
        return 'showWorkLocations';
    }

    public function icon(): string
    {
        return 'building-check';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        $query = WorkLocation::query()->where('is_active', true);
        if ($filter->search !== null) {
            $like = '%'.$filter->search.'%';
            $query->where(fn ($w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like));
        }
        foreach ($query->get() as $w) {
            if (! is_numeric($w->latitude) || ! is_numeric($w->longitude)) {
                $out->skip();

                continue;
            }
            $out->add($this->feature($w->geofence_radius ? 'circle' : 'point', $w->id, (string) $w->name, [
                'code' => $w->code, 'address' => $w->address, 'radius' => $w->geofence_radius ? $w->geofence_radius.' m' : null,
            ], 'theme', ['lat' => (float) $w->latitude, 'lng' => (float) $w->longitude, 'radius_m' => $w->geofence_radius ? (int) $w->geofence_radius : null]));
        }
    }
}
