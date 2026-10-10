<?php

namespace App\Services\Map;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Corridor\CorridorGeometryResolver;
use App\Services\Dashboard\RouteAccess;
use App\Services\Map\Layers\AttendancePunchesLayer;
use App\Services\Map\Layers\BiometricDevicesLayer;
use App\Services\Map\Layers\CctvLayer;
use App\Services\Map\Layers\GeofencesLayer;
use App\Services\Map\Layers\JurisdictionsLayer;
use App\Services\Map\Layers\PatrolRouteLayer;
use App\Services\Map\Layers\RegisterLayers;
use App\Services\Map\Layers\StructuresLayer;
use App\Services\Map\Layers\TollPlazasLayer;
use App\Services\Map\Layers\WorkLocationCountLayer;
use App\Services\Map\Layers\WorkLocationsLayer;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Every location-bearing thing in the system as a permission-gated map layer. Authorization is checked on
 * EVERY request (a revoked permission drops the layer at once); only the computed features are cached,
 * per viewer, per scope fingerprint and per filter. Empty layers are not sent, so the map never offers a
 * toggle that shows nothing. A layer that throws is reported and omitted; it never takes the card down.
 *
 * Rows the system holds a position for but that this map deliberately does not draw are listed in
 * EXCLUDED with the reason; tests/Feature/Map/MapLayerCoverageTest fails when a table with a coordinate,
 * chainage, geofence or location column is neither a layer's table nor listed here.
 */
class MapLayerRegistry
{
    public const GROUPS = [
        'corridor' => 'Corridor',
        'workforce' => 'Workforce',
        'works' => 'Daily works & quality',
        'om' => 'Operations & maintenance',
        'tolls' => 'Tolls',
        'safety' => 'Safety',
    ];

    /** @var array<string, string> table => why it is not a layer */
    public const EXCLUDED = [
        'user_sessions' => 'Privacy: login and session locations are not shown on any map.',
        'user_sessions_tracking' => 'Privacy: login and session locations are not shown on any map.',
        'user_devices' => 'Privacy: device and IP identifiers are not shown on any map.',
        'departments' => 'Office description text (departments.location); departments have no position of their own.',
        'experiences' => 'Postal / free-text place of a past employer; not a corridor position.',
        'job_applicant_experience' => 'Postal / free-text place of a past employer; not a corridor position.',
        'job_interviews' => 'Free-text interview venue or link; not a corridor position.',
        'jobs_recruitment' => 'Postal address of a vacancy; not a corridor position.',
        'training_sessions' => 'Free-text training venue; not a corridor position.',
        'compliance_policies' => 'applicable_locations is a policy scope list of site names, not positions.',
        'regulatory_requirements' => 'applicable_locations is a policy scope list of site names, not positions.',
        'coverage_requirements' => 'Headcount rules per work location; the location is drawn by the work locations layer.',
        'work_location_attendance_type' => 'Pivot between work locations and attendance types; positions come from work_locations.',
        'inventory_adjustments' => 'Warehouse bin (location_id), not a geographic position.',
        'inventory_stocks' => 'Warehouse bin (location_id), not a geographic position.',
        'inventory_transfers' => 'Warehouse bin (location_id), not a geographic position.',
        'stock_movements' => 'Warehouse bin (location_id), not a geographic position.',
        'logistics_shipments' => 'Shipment origin and destination bins, not corridor positions.',
        'om_traffic_logs' => 'Per-section aggregates keyed by section code; the table has no chainage or coordinates to place.',
        'urls_and_ip_addresses' => 'Privacy: IP addresses and URL routes are never mapped.',
    ];

    /** @var Collection<int, MapLayer>|null */
    private ?Collection $layers = null;

    public function __construct(
        private readonly Container $container,
        private readonly DepartmentScope $scope,
        private readonly RouteAccess $routes,
        private readonly CorridorGeometryResolver $geometry,
    ) {}

    /** @return Collection<int, MapLayer> */
    public function all(): Collection
    {
        return $this->layers ??= collect([
            $this->container->make(StructuresLayer::class),
            $this->container->make(JurisdictionsLayer::class),
            $this->container->make(PatrolRouteLayer::class),
            $this->container->make(WorkLocationsLayer::class),
            $this->container->make(AttendancePunchesLayer::class),
            $this->container->make(GeofencesLayer::class),
            new WorkLocationCountLayer('roster', $this->scope),
            new WorkLocationCountLayer('assigned', $this->scope),
            $this->container->make(BiometricDevicesLayer::class),
            ...RegisterLayers::make($this->scope),
            $this->container->make(CctvLayer::class),
            $this->container->make(TollPlazasLayer::class),
        ])->values();
    }

    /** @return Collection<int, MapLayer> */
    public function visibleFor(User $viewer): Collection
    {
        return $this->all()->filter(fn (MapLayer $layer): bool => $layer->authorizes($viewer))->values();
    }

    /** @return array<int, string> every table some layer reads */
    public function coveredTables(): array
    {
        return $this->all()->flatMap(fn (MapLayer $l): array => $l->tables())->unique()->values()->all();
    }

    /**
     * The map payload for one viewer and filter: the centreline, and every permitted layer that has features.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(User $viewer, MapFilter $filter, ?string $fingerprint = null): array
    {
        $key = sprintf('map:v1:%s:%s:%s:%s', $viewer->getKey(), $fingerprint ?? 'x', $this->geometry->version(), $filter->key());

        return $this->cache()->remember($key, max(60, (int) config('dashboard.refresh_seconds', 120)), fn (): array => $this->build($viewer, $filter));
    }

    /** @return array<string, mixed> */
    public function build(User $viewer, MapFilter $filter): array
    {
        $geometry = $this->geometry->resolve();
        $permitted = $this->visibleFor($viewer);
        $selected = $filter->layers === null ? $permitted : $permitted->filter(fn (MapLayer $l): bool => in_array($l->key(), $filter->layers, true));

        $layers = [];
        $empty = 0;
        $failed = 0;
        $unplaced = 0;
        foreach ($selected as $layer) {
            try {
                $result = $layer->run($viewer, $filter, $geometry);
            } catch (Throwable $e) {
                report($e);
                $failed++;

                continue;
            }
            $unplaced += $result->unplaced;
            if ($result->features === []) {
                $empty++;

                continue;
            }
            $route = $layer->route();
            $layers[] = $layer->meta() + [
                'count' => $result->total, 'unplaced' => $result->unplaced, 'truncated' => $result->truncated(),
                'href' => $route !== null && $this->routes->canOpen($viewer, $route) ? $this->routes->url($route, $layer->routeQuery($filter)) : null,
                'features' => $result->features,
            ];
        }

        $alignment = $geometry->alignment;

        return [
            'generated_at' => now()->toIso8601String(),
            'filter' => $filter->toArray(),
            'alignment' => [
                'points' => array_map(fn (array $p): array => [round($p['lat'], 6), round($p['lng'], 6), $p['chainage_m']], $alignment->simplified(400)),
                'length_m' => $alignment->lengthM(),
                'source' => $geometry->source,
            ],
            'groups' => self::GROUPS,
            'layers' => $layers,
            'summary' => [
                'registered' => $this->all()->count(),
                'permitted' => $permitted->count(),
                'with_data' => count($layers),
                'empty' => $empty,
                'failed' => $failed,
                'unplaced' => $unplaced,
                'excluded' => count(self::EXCLUDED),
            ],
        ];
    }

    /** The application's default cache store, falling back to `file` when caching is switched off (as the widget registry does). */
    private function cache(): Repository
    {
        $configured = config('dashboard.cache_store');
        if (is_string($configured) && $configured !== '') {
            return Cache::store($configured);
        }
        $default = (string) config('cache.default');

        return config("cache.stores.{$default}.driver") === 'null' ? Cache::store('file') : Cache::store();
    }
}
