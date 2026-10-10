<?php

namespace App\Services\Map\Layers;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use App\Support\Corridor\Chainage;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * A register table placed on the corridor, declared as data: which columns carry the position
 * (lat/lng, a chainage, a chainage span or free text read only when unambiguous), how rows are
 * scoped, and how the time window applies. Rows that cannot be placed are counted, never guessed.
 *
 * Config keys: key, label, group, geometry[], permissions[], tables[], fields[key=>label], icon, tone,
 * route, query (Closure: Builder), period (column), period_cast, open (Closure), scope (Closure),
 * search[columns], coords [lat, lng], span (Closure: object -> ?array), title, values, row_tone (Closures).
 */
final class TableLayer extends MapLayer
{
    /** @param array<string, mixed> $c */
    public function __construct(private readonly array $c, private readonly DepartmentScope $scope) {}

    public function key(): string
    {
        return $this->c['key'];
    }

    public function label(): string
    {
        return $this->c['label'];
    }

    public function group(): string
    {
        return $this->c['group'];
    }

    public function geometry(): array
    {
        return $this->c['geometry'];
    }

    public function permissions(): array
    {
        return $this->c['permissions'];
    }

    public function tables(): array
    {
        return $this->c['tables'];
    }

    public function fields(): array
    {
        return ['chainage' => 'Chainage'] + $this->c['fields'];
    }

    public function scope(): string
    {
        return isset($this->c['scope']) ? self::SCOPE_DEPARTMENT : self::SCOPE_PERMISSION;
    }

    public function timeMode(): string
    {
        return isset($this->c['open']) ? self::TIME_OPEN : (isset($this->c['period']) ? self::TIME_PERIOD : self::TIME_NONE);
    }

    public function route(): ?string
    {
        return $this->c['route'] ?? null;
    }

    public function tone(): string
    {
        return $this->c['tone'] ?? 'theme';
    }

    public function icon(): string
    {
        return $this->c['icon'] ?? 'geo-alt';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        /** @var Builder $q */
        $q = isset($this->c['query']) ? ($this->c['query'])() : DB::table($this->c['tables'][0]);

        if (isset($this->c['open'])) {
            ($this->c['open'])($q);
        } elseif (isset($this->c['period'])) {
            $column = $this->c['period'];
            $q->where($column, '>=', $filter->from->toDateString())->where($column, '<', $filter->to->addDay()->toDateString());
        }
        if (isset($this->c['scope'])) {
            ($this->c['scope'])($q, $viewer, $this->scope);
        }
        if ($filter->search !== null && ($this->c['search'] ?? []) !== []) {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $filter->search).'%';
            $q->where(function (Builder $w) use ($like): void {
                foreach ($this->c['search'] as $column) {
                    $w->orWhere($column, 'like', $like);
                }
            });
        }

        foreach ($q->limit(20000)->get() as $row) {
            $id = $this->c['id'] ?? null;
            $id = $id instanceof Closure ? $id($row) : ($row->id ?? spl_object_id($row));
            $title = (string) ($this->c['title'])($row);
            $values = isset($this->c['values']) ? ($this->c['values'])($row) : [];
            $tone = isset($this->c['row_tone']) ? ($this->c['row_tone'])($row) : $this->tone();
            $span = isset($this->c['span']) ? ($this->c['span'])($row) : null;

            [$latCol, $lngCol] = $this->c['coords'] ?? [null, null];
            $lat = $latCol !== null ? ($row->{$latCol} ?? null) : null;
            $lng = $lngCol !== null ? ($row->{$lngCol} ?? null) : null;
            if (is_numeric($lat) && is_numeric($lng) && abs((float) $lat) > 0.0001) {
                $extra = $span !== null && $span['from'] === $span['to'] ? ['chainage_m' => $span['from']] : [];
                $out->add($this->feature('point', $id, $title, $values + ($extra !== [] ? ['chainage' => Chainage::format($span['from'])] : []), $tone, ['lat' => (float) $lat, 'lng' => (float) $lng] + $extra));

                continue;
            }
            $this->placeSpan($out, $geometry, $span, $id, $title, $values, $tone);
        }
    }
}
