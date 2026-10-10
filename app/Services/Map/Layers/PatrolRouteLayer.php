<?php

namespace App\Services\Map\Layers;

use App\Models\HRM\AttendanceType;
use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;

/** The patrol route(s) configured in Attendance settings ("K0-48 - Route"), with their corridor tolerance. */
final class PatrolRouteLayer extends MapLayer
{
    public function key(): string
    {
        return 'corridor.patrol_route';
    }

    public function label(): string
    {
        return 'Patrol route';
    }

    public function group(): string
    {
        return 'corridor';
    }

    public function geometry(): array
    {
        return [self::ROUTE];
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
        return ['type' => 'Attendance type', 'tolerance' => 'Corridor tolerance', 'waypoints' => 'Waypoints'];
    }

    public function route(): ?string
    {
        return 'attendance.unified';
    }

    public function icon(): string
    {
        return 'signpost';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        foreach (AttendanceType::query()->where('is_active', true)->where('slug', 'like', 'route_waypoint%')->orderBy('id')->get() as $type) {
            foreach ((array) ($type->config['routes'] ?? []) as $i => $route) {
                if (($route['is_active'] ?? true) === false) {
                    continue;
                }
                $path = collect($route['waypoints'] ?? [])
                    ->filter(fn ($w): bool => is_numeric($w['lat'] ?? null) && is_numeric($w['lng'] ?? null))
                    ->map(fn ($w): array => [(float) $w['lat'], (float) $w['lng']])->values()->all();
                if (count($path) < 2) {
                    $out->skip();

                    continue;
                }
                $out->add($this->feature('route', $type->id.'-'.$i, (string) ($route['name'] ?? $type->name), [
                    'type' => $type->name,
                    'tolerance' => isset($route['tolerance']) ? $route['tolerance'].' m' : null,
                    'waypoints' => count($path),
                ], 'theme', ['path' => $path, 'lat' => $path[0][0], 'lng' => $path[0][1], 'tolerance_m' => (int) ($route['tolerance'] ?? 0)]));
            }
        }
    }
}
