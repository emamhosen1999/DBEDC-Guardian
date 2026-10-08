<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\CommandCenterService;
use App\Services\Dashboard\DashboardWidget;

/**
 * The project command center (hero, KPIs, throughput, chainage, workforce trend, live
 * feed). The registry wraps CommandCenterService so GET /dashboard/command, the Inertia
 * dashboard and GET /api/v1/dashboard all read it through one path. The service applies
 * its own scope rules (project registers for global / project-permission holders,
 * DepartmentScope for the workforce picture) and its own 5-minute cache keyed per
 * viewer, so this widget adds no second cache layer (ttl 0).
 */
final class CommandCenter extends DashboardWidget
{
    public function __construct(DepartmentScope $scope, private readonly CommandCenterService $service)
    {
        parent::__construct($scope);
    }

    public function key(): string
    {
        return 'main.command';
    }

    public function title(): string
    {
        return 'Command center';
    }

    public function permissions(): array
    {
        return ['core.dashboard.view'];
    }

    public function personas(): array
    {
        return ['line_manager', 'department_admin', 'hr_manager', 'project_manager', 'administrator'];
    }

    public function section(): string
    {
        return self::SECTION_PROJECT;
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

    public function type(): string
    {
        return 'command';
    }

    public function ttl(): int
    {
        return 0;
    }

    public function data(User $viewer): array
    {
        return $this->service->payload($viewer);
    }
}
