<?php

namespace App\Services\Corridor;

use App\Models\HRM\AttendanceType;
use App\Support\Corridor\Alignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where the drawn centreline comes from, in the owner's priority order. Real data only - the
 * dhakabypass illustrative seed is never a source (corridor:import refuses it).
 *
 *  (a) the active imported version (dhakabypass `corridor_geometry` export, via corridor:import);
 *  (b) Guardian's own patrol route (attendance type "K0-48 - Route"), chainage-anchored to the
 *      client survey waypoints so K12+500 still means something;
 *  (c) the client-supplied survey waypoints themselves (DBEDC_Corridor_Waypoints.xlsx, 2026-09-02).
 */
class CorridorGeometryResolver
{
    public const CLIENT_FILE = 'database/data/corridor/client-waypoints.json';

    /** A patrol waypoint within this distance of a survey waypoint inherits its surveyed chainage. */
    private const ANCHOR_RADIUS_M = 150.0;

    private ?CorridorGeometry $memo = null;

    public function resolve(): CorridorGeometry
    {
        return $this->memo ??= $this->imported() ?? $this->patrolRoute() ?? $this->clientWaypoints();
    }

    public function forget(): void
    {
        $this->memo = null;
    }

    /** Cache fingerprint: changes when the source of the line changes. */
    public function version(): string
    {
        $s = $this->resolve()->source;

        return $s['key'].':'.($s['version'] ?? '0');
    }

    private function imported(): ?CorridorGeometry
    {
        if (! Schema::hasTable('corridor_geometries')) {
            return null;
        }
        $row = DB::table('corridor_geometries')->where('is_active', true)->orderByDesc('version')->first();
        if ($row === null) {
            return null;
        }
        $points = DB::table('corridor_geometry_points')->where('geometry_id', $row->id)->orderBy('seq')
            ->get(['lat', 'lng', 'chainage_m'])->map(fn ($p): array => (array) $p)->all();
        $alignment = new Alignment($points);
        if (! $alignment->isUsable()) {
            return null;
        }
        $features = DB::table('corridor_features')->where('geometry_id', $row->id)->orderBy('chainage_m')->get()
            ->map(fn ($f): array => $this->feature((array) $f))->all();

        return new CorridorGeometry($alignment, $features, [
            'key' => $row->source,
            'label' => 'Imported corridor centreline ('.$row->source.')',
            'captured_on' => $row->captured_on,
            'attribution' => $row->attribution,
            'chainage_basis' => $row->chainage_basis,
            'version' => (int) $row->version,
        ]);
    }

    private function patrolRoute(): ?CorridorGeometry
    {
        if (! Schema::hasTable('attendance_types')) {
            return null;
        }
        $client = $this->client();
        $best = null;
        foreach (AttendanceType::query()->where('is_active', true)->where('slug', 'like', 'route_waypoint%')->orderBy('id')->get() as $type) {
            foreach ((array) ($type->config['routes'] ?? []) as $route) {
                if (($route['is_active'] ?? true) === false) {
                    continue;
                }
                $waypoints = collect($route['waypoints'] ?? [])
                    ->filter(fn ($w): bool => is_numeric($w['lat'] ?? null) && is_numeric($w['lng'] ?? null))
                    ->map(fn ($w): array => ['lat' => (float) $w['lat'], 'lng' => (float) $w['lng']])->values()->all();
                if (count($waypoints) >= 2 && ($best === null || count($waypoints) > count($best['waypoints']))) {
                    $best = ['type' => $type, 'route' => $route, 'waypoints' => $waypoints];
                }
            }
        }
        if ($best === null) {
            return null;
        }

        $points = $this->anchored($best['waypoints'], $client['waypoints']);

        return new CorridorGeometry(new Alignment($points), $client['features'], [
            'key' => 'guardian_patrol_route',
            'label' => 'Guardian patrol route "'.$best['type']->name.'"',
            'captured_on' => optional($best['type']->updated_at)->toDateString(),
            'attribution' => 'DBEDC Guardian attendance type '.$best['type']->name.' (route waypoints); chainage anchored to '.$client['source']['label'],
            'chainage_basis' => 'client_waypoint_anchors',
            'version' => (int) optional($best['type']->updated_at)->timestamp,
            'tolerance_m' => (int) ($best['route']['tolerance'] ?? 0),
        ]);
    }

    private function clientWaypoints(): CorridorGeometry
    {
        $client = $this->client();

        return new CorridorGeometry(new Alignment($client['waypoints']), $client['features'], $client['source']);
    }

    /**
     * Patrol waypoints with chainage: a waypoint near a surveyed one takes its chainage, the rest are placed by
     * distance along the route between the neighbouring anchors (cumulative distance from K0 when none match).
     *
     * @param  array<int, array{lat: float, lng: float}>  $waypoints
     * @param  array<int, array<string, mixed>>  $survey
     * @return array<int, array{lat: float, lng: float, chainage_m: int}>
     */
    private function anchored(array $waypoints, array $survey): array
    {
        $cumulative = [0.0];
        for ($i = 1, $n = count($waypoints); $i < $n; $i++) {
            $cumulative[$i] = $cumulative[$i - 1] + Alignment::haversine($waypoints[$i - 1]['lat'], $waypoints[$i - 1]['lng'], $waypoints[$i]['lat'], $waypoints[$i]['lng']);
        }

        $anchors = [];
        foreach ($waypoints as $i => $w) {
            $nearest = null;
            foreach ($survey as $s) {
                $d = Alignment::haversine($w['lat'], $w['lng'], $s['lat'], $s['lng']);
                if ($d <= self::ANCHOR_RADIUS_M && ($nearest === null || $d < $nearest['d'])) {
                    $nearest = ['d' => $d, 'chainage_m' => (int) $s['chainage_m']];
                }
            }
            if ($nearest !== null && ($anchors === [] || $nearest['chainage_m'] > end($anchors)['chainage_m'])) {
                $anchors[$i] = ['chainage_m' => $nearest['chainage_m'], 'dist' => $cumulative[$i]];
            }
        }

        $out = [];
        $anchorIdx = array_keys($anchors);
        foreach ($waypoints as $i => $w) {
            if (isset($anchors[$i])) {
                $m = $anchors[$i]['chainage_m'];
            } elseif (count($anchorIdx) >= 2) {
                $before = null;
                $after = null;
                foreach ($anchorIdx as $a) {
                    if ($a < $i) {
                        $before = $a;
                    } elseif ($a > $i && $after === null) {
                        $after = $a;
                    }
                }
                $before ??= $anchorIdx[0];
                $after ??= end($anchorIdx);
                $span = $anchors[$after]['dist'] - $anchors[$before]['dist'];
                $t = $span > 0 ? ($cumulative[$i] - $anchors[$before]['dist']) / $span : 0.0;
                $m = (int) round($anchors[$before]['chainage_m'] + ($anchors[$after]['chainage_m'] - $anchors[$before]['chainage_m']) * $t);
            } else {
                $m = (int) round($cumulative[$i]);
            }
            $out[] = ['lat' => $w['lat'], 'lng' => $w['lng'], 'chainage_m' => max(0, $m)];
        }

        return $out;
    }

    /** @return array{waypoints: array<int, array<string, mixed>>, features: array<int, array<string, mixed>>, source: array<string, mixed>} */
    private function client(): array
    {
        $path = base_path(self::CLIENT_FILE);
        $d = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        $d = is_array($d) ? $d : ['waypoints' => [], 'features' => [], 'source' => '', 'date' => null, 'attribution' => ''];

        return [
            'waypoints' => array_map(fn (array $w): array => ['lat' => (float) $w['lat'], 'lng' => (float) $w['lng'], 'chainage_m' => (int) $w['chainage_m']], $d['waypoints']),
            'features' => array_map(fn (array $f): array => $this->feature($f), $d['features']),
            'source' => [
                'key' => 'client_waypoints',
                'label' => 'Client survey waypoints (DBEDC_Corridor_Waypoints.xlsx)',
                'captured_on' => $d['date'],
                'attribution' => $d['attribution'],
                'chainage_basis' => 'model_derived_from_routed_polyline',
                'version' => 1,
            ],
        ];
    }

    /** @param array<string, mixed> $f */
    private function feature(array $f): array
    {
        return [
            'code' => (string) $f['code'],
            'kind' => (string) $f['kind'],
            'name' => (string) $f['name'],
            'lat' => (float) $f['lat'],
            'lng' => (float) $f['lng'],
            'chainage_m' => (int) $f['chainage_m'],
            'estimated' => (bool) ($f['estimated'] ?? false),
            'note' => $f['note'] ?? null,
        ];
    }
}
