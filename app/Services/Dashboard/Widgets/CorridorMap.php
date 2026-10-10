<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Dashboard\DashboardWidget;
use App\Services\Map\MapFilter;
use App\Services\Map\MapLayerRegistry;

/**
 * The corridor "everything map": the centreline from the best real source plus every location-bearing
 * layer the viewer holds the permission for (App\Services\Map\MapLayerRegistry), for today.
 *
 * Permission: core.dashboard.view for the card; each layer inside carries its own permission gate and
 * scope strategy (DepartmentScope for employee-owned rows). The maximized card asks
 * GET /dashboard/map for other dates and ranges.
 */
final class CorridorMap extends DashboardWidget
{
    public function __construct(DepartmentScope $scope, private readonly MapLayerRegistry $layers)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'ops.corridor_map';
    }

    public function title(): string
    {
        return 'Corridor map';
    }

    public function permissions(): array
    {
        return ['core.dashboard.view'];
    }

    public function personas(): array
    {
        return ['line_manager', 'department_admin', 'hr_manager', 'administrator', 'project_manager', 'operator'];
    }

    public function section(): string
    {
        return self::SECTION_OPS;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 0;
    }

    public function span(): int
    {
        return 12;
    }

    public function ttl(): int
    {
        return 120;
    }

    public function type(): string
    {
        return 'corridor_map';
    }

    public function data(User $viewer): array
    {
        return ['scope' => $this->scopeMeta($viewer)] + $this->layers->build($viewer, MapFilter::today());
    }
}
