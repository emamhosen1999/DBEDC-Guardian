<?php

namespace App\Services\Jurisdiction;

use App\Models\HRM\AttendanceType;
use App\Support\Corridor\GeoPolygon;

/** The attendance geofence polygons that can be taken as an `area` jurisdiction (real data: attendance_types config). */
class GeofenceSource
{
    /** @return array<int, array{id: string, name: string, vertices: int, geometry: array}> */
    public function all(): array
    {
        $out = [];
        foreach (AttendanceType::query()->where('is_active', true)->where('slug', 'like', 'geo_polygon%')->orderBy('id')->get() as $type) {
            $polygons = (array) ($type->config['polygons'] ?? []);
            if ($polygons === [] && ! empty($type->config['polygon'])) {
                $polygons = [['name' => $type->name, 'points' => $type->config['polygon']]];
            }
            foreach ($polygons as $i => $polygon) {
                if (($polygon['is_active'] ?? true) === false) {
                    continue;
                }
                $points = collect($polygon['points'] ?? [])->filter(fn ($p): bool => is_numeric($p['lat'] ?? null) && is_numeric($p['lng'] ?? null))
                    ->map(fn ($p): array => [(float) $p['lat'], (float) $p['lng']])->values()->all();
                if (count($points) < 3) {
                    continue;
                }
                try {
                    $geometry = GeoPolygon::normalize(GeoPolygon::fromLatLng($points));
                } catch (\InvalidArgumentException) {
                    continue; // a geofence outside Bangladesh or malformed is not offered
                }
                $out[] = ['id' => $type->id.'-'.$i, 'name' => (string) ($polygon['name'] ?? $type->name), 'vertices' => count($geometry['coordinates'][0]) - 1, 'geometry' => $geometry];
            }
        }

        return $out;
    }

    public function find(string $id): ?array
    {
        foreach ($this->all() as $g) {
            if ($g['id'] === $id) {
                return $g['geometry'];
            }
        }

        return null;
    }
}
