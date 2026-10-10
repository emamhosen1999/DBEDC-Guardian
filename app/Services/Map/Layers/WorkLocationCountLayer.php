<?php

namespace App\Services\Map\Layers;

use App\Models\HRM\RosterDay;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Access\DepartmentScope;
use App\Services\Corridor\CorridorGeometry;
use App\Services\Map\LayerResult;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayer;
use Illuminate\Support\Facades\DB;

/**
 * Employee headcount per work location, drawn at the location's coordinates: either who is ROSTERED there
 * in the window (roster_days.work_location_id) or who is ASSIGNED there (users.work_location_id).
 * Both are employee-owned, so both go through DepartmentScope.
 */
final class WorkLocationCountLayer extends MapLayer
{
    public function __construct(private readonly string $mode, private readonly DepartmentScope $scope)
    {
        if (! in_array($mode, ['roster', 'assigned'], true)) {
            throw new \InvalidArgumentException('mode must be roster or assigned');
        }
    }

    public function key(): string
    {
        return $this->mode === 'roster' ? 'workforce.roster' : 'workforce.assigned';
    }

    public function label(): string
    {
        return $this->mode === 'roster' ? 'Rostered posts' : 'Assigned posts';
    }

    public function group(): string
    {
        return 'workforce';
    }

    public function geometry(): array
    {
        return [self::GEOFENCE_CIRCLE, self::POINT];
    }

    public function permissions(): array
    {
        return $this->mode === 'roster' ? ['attendance.roster.manage', 'attendance.view'] : ['employees.view'];
    }

    public function tables(): array
    {
        return $this->mode === 'roster' ? ['roster_days', 'work_locations'] : ['users', 'work_locations'];
    }

    public function fields(): array
    {
        return ['people' => $this->mode === 'roster' ? 'Rostered' : 'Assigned', 'window' => 'Window', 'address' => 'Address'];
    }

    public function scope(): string
    {
        return self::SCOPE_DEPARTMENT;
    }

    public function timeMode(): string
    {
        return $this->mode === 'roster' ? self::TIME_PERIOD : self::TIME_NONE;
    }

    public function route(): ?string
    {
        return $this->mode === 'roster' ? 'attendance.unified' : 'showWorkLocations';
    }

    public function icon(): string
    {
        return $this->mode === 'roster' ? 'calendar2-week' : 'people';
    }

    protected function collect(User $viewer, MapFilter $filter, CorridorGeometry $geometry, LayerResult $out): void
    {
        if ($this->mode === 'roster') {
            $counts = $this->scope->applyToEmployeeOwned(RosterDay::query(), $viewer, 'roster_days.user_id')
                ->whereNotNull('work_location_id')
                ->where('date', '>=', $filter->from->toDateString())->where('date', '<', $filter->to->addDay()->toDateString())
                ->select('work_location_id', DB::raw('COUNT(DISTINCT user_id) as n'))->groupBy('work_location_id')->pluck('n', 'work_location_id');
        } else {
            $counts = $this->scope->applyToUsers(User::query(), $viewer)->where('users.is_active', true)->whereNotNull('users.work_location_id')
                ->select('users.work_location_id', DB::raw('COUNT(*) as n'))->groupBy('users.work_location_id')->pluck('n', 'work_location_id');
        }

        $window = $this->mode === 'roster'
            ? ($filter->isSingleDay() ? $filter->from->toDateString() : $filter->from->toDateString().' to '.$filter->to->toDateString())
            : null;

        foreach (WorkLocation::query()->whereIn('id', $counts->keys()->all())->get() as $w) {
            if (! is_numeric($w->latitude) || ! is_numeric($w->longitude)) {
                $out->skip();

                continue;
            }
            $out->add($this->feature($w->geofence_radius ? 'circle' : 'point', $w->id, (string) $w->name, [
                'people' => (int) $counts[$w->id], 'window' => $window, 'address' => $w->address,
            ], 'info', ['lat' => (float) $w->latitude, 'lng' => (float) $w->longitude, 'radius_m' => $w->geofence_radius ? (int) $w->geofence_radius : null, 'count' => (int) $counts[$w->id]]));
        }
    }
}
