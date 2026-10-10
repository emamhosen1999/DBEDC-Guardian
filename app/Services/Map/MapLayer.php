<?php

namespace App\Services\Map;

use App\Models\User;
use App\Services\Corridor\CorridorGeometry;
use App\Support\Corridor\Chainage;

/**
 * One permission-gated map layer. Every location-bearing thing in the system is a layer or is
 * excluded with a written reason (MapLayerRegistry::EXCLUDED); the coverage test enforces it.
 *
 * A layer declares what it is (key, label, group, geometry kind), who may see it (any-of
 * permissions), how its rows are scoped (`scope()` - department_scope for employee-owned rows,
 * permission for registers governed by their permission alone), its time behaviour and its popup
 * fields. collect() turns the viewer's rows into features; chainage rows are placed on the
 * alignment here, so every client receives coordinates.
 */
abstract class MapLayer
{
    public const POINT = 'lat_lng_point';

    public const CHAINAGE_POINT = 'chainage_point';

    public const CHAINAGE_BAND = 'chainage_band';

    public const GEOFENCE_CIRCLE = 'geofence_circle';

    public const POLYGON = 'polygon';

    public const ROUTE = 'route';

    public const SCOPE_PERMISSION = 'permission';

    public const SCOPE_DEPARTMENT = 'department_scope';

    /** No time filter / follows the requested window / only open items (window ignored). */
    public const TIME_NONE = 'none';

    public const TIME_PERIOD = 'period';

    public const TIME_OPEN = 'open';

    abstract public function key(): string;

    abstract public function label(): string;

    /** corridor | workforce | works | om | tolls | safety */
    abstract public function group(): string;

    /** @return array<int, string> geometry kinds the layer emits (a register may hold both coordinates and chainages) */
    abstract public function geometry(): array;

    /** @return array<int, string> any-of permission names */
    abstract public function permissions(): array;

    /** @return array<int, string> database tables this layer reads (the coverage test maps columns to layers through these) */
    abstract public function tables(): array;

    /** @return array<string, string> popup field key => label, in display order */
    abstract public function fields(): array;

    abstract protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void;

    public function scope(): string
    {
        return self::SCOPE_PERMISSION;
    }

    public function timeMode(): string
    {
        return self::TIME_NONE;
    }

    /** Route NAME of the page that lists these records (drill-down), or null. */
    public function route(): ?string
    {
        return null;
    }

    /** @return array<string, string> query parameters for the drill-down page */
    public function routeQuery(MapFilter $filter): array
    {
        return [];
    }

    /** Marker colour family: theme | good | warn | crit | info | neutral. */
    public function tone(): string
    {
        return 'theme';
    }

    /** Bootstrap-icons name for the legend / header toggle. */
    public function icon(): string
    {
        return 'geo-alt';
    }

    /** Where the rows come from, for the attribution line (source, and how fresh it is). */
    public function source(): string
    {
        return 'Guardian database ('.implode(', ', $this->tables()).')';
    }

    public function authorizes(User $viewer): bool
    {
        return $viewer->canAny($this->permissions());
    }

    public function run(User $viewer, MapFilter $filter, CorridorGeometry $geometry): LayerResult
    {
        $out = new LayerResult;
        $this->collect($viewer, $filter, $geometry, $out);

        return $out;
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        return [
            'key' => $this->key(), 'label' => $this->label(), 'group' => $this->group(), 'geometry' => $this->geometry(),
            'tone' => $this->tone(), 'icon' => $this->icon(), 'time_mode' => $this->timeMode(), 'scope' => $this->scope(),
            'fields' => $this->fields(), 'source' => $this->source(),
        ];
    }

    // ── feature builders ───────────────────────────────────────────

    /**
     * @param  array<string, string|int|float|null>  $values  popup values keyed like fields()
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function feature(string $shape, string|int $id, string $title, array $values, string $tone, array $extra = []): array
    {
        $fields = [];
        foreach ($this->fields() as $key => $label) {
            $value = $values[$key] ?? null;
            if ($value !== null && $value !== '') {
                $fields[] = ['label' => $label, 'value' => (string) $value];
            }
        }

        return ['id' => $this->key().':'.$id, 'shape' => $shape, 'title' => $title, 'tone' => $tone, 'fields' => $fields] + $extra;
    }

    /**
     * Place a chainage point or span on the alignment; counts the row as unplaced when it cannot be.
     *
     * @param  array{from: int, to: int}|null  $span
     * @param  array<string, string|int|float|null>  $values
     */
    protected function placeSpan(LayerResult $out, CorridorGeometry $geometry, ?array $span, string|int $id, string $title, array $values, string $tone, array $extra = []): void
    {
        if ($span === null) {
            $out->skip();

            return;
        }
        if ($span['from'] === $span['to']) {
            $at = $geometry->alignment->pointAt($span['from']);
            if ($at === null) {
                $out->skip();

                return;
            }
            $out->add($this->feature('point', $id, $title, $values + ['chainage' => Chainage::format($span['from'])], $tone, ['lat' => $at['lat'], 'lng' => $at['lng'], 'chainage_m' => $span['from']] + $extra));

            return;
        }
        $path = $geometry->alignment->slice($span['from'], $span['to']);
        if (count($path) < 2) {
            $out->skip();

            return;
        }
        $mid = $geometry->alignment->pointAt((int) round(($span['from'] + $span['to']) / 2));
        $out->add($this->feature('band', $id, $title, $values + ['chainage' => Chainage::format($span['from']).' to '.Chainage::format($span['to'])], $tone, [
            'path' => $path, 'from_m' => $span['from'], 'to_m' => $span['to'], 'lat' => $mid['lat'] ?? $path[0][0], 'lng' => $mid['lng'] ?? $path[0][1], 'chainage_m' => $span['from'],
        ] + $extra));
    }
}
