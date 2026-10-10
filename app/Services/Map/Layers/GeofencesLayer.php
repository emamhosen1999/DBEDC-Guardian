<?php

namespace App\Services\Map\Layers;

use App\Models\HRM\AttendanceType;
use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;

/** Attendance geofence polygons (attendance types of the geo_polygon family), drawn as outlines. */
final class GeofencesLayer extends MapLayer
{
    public function key(): string
    {
        return 'workforce.geofences';
    }

    public function label(): string
    {
        return 'Attendance geofences';
    }

    public function group(): string
    {
        return 'workforce';
    }

    public function geometry(): array
    {
        return [self::POLYGON];
    }

    public function permissions(): array
    {
        return ['attendance.view', 'attendance.settings'];
    }

    public function tables(): array
    {
        return ['attendance_types'];
    }

    public function fields(): array
    {
        return ['type' => 'Attendance type', 'vertices' => 'Vertices'];
    }

    public function route(): ?string
    {
        return 'attendance.unified';
    }

    public function icon(): string
    {
        return 'bounding-box';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        foreach (AttendanceType::query()->where('is_active', true)->where('slug', 'like', 'geo_polygon%')->orderBy('id')->get() as $type) {
            $polygons = (array) ($type->config['polygons'] ?? []);
            if ($polygons === [] && ! empty($type->config['polygon'])) {
                $polygons = [['name' => $type->name, 'points' => $type->config['polygon']]];
            }
            foreach ($polygons as $i => $polygon) {
                if (($polygon['is_active'] ?? true) === false) {
                    continue;
                }
                $path = collect($polygon['points'] ?? [])
                    ->filter(fn ($p): bool => is_numeric($p['lat'] ?? null) && is_numeric($p['lng'] ?? null))
                    ->map(fn ($p): array => [(float) $p['lat'], (float) $p['lng']])->values()->all();
                if (count($path) < 3) {
                    $out->skip();

                    continue;
                }
                $out->add($this->feature('polygon', $type->id.'-'.$i, (string) ($polygon['name'] ?? $type->name), [
                    'type' => $type->name, 'vertices' => count($path),
                ], 'info', ['path' => $path, 'lat' => array_sum(array_column($path, 0)) / count($path), 'lng' => array_sum(array_column($path, 1)) / count($path)]));
            }
        }
    }
}
