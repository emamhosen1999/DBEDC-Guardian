<?php

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The permission-driven widget registry behind every persona's home page — the same
 * payload feeds the web Dashboard and the mobile home (GET /dashboard/widgets and
 * GET /api/v1/dashboard?section=employee|main), so the two can never disagree about who sees what.
 *
 * Authorization is evaluated on EVERY request (a revoked permission hides the widget
 * at once); only the widget's numbers are cached, per widget, per viewer, per scope
 * fingerprint — so a change to the departments someone administers switches to a
 * fresh cache entry immediately instead of serving the old scope's figures.
 *
 * A widget that throws never takes the page down: it is reported and returned with
 * `error` set so the client can show a per-card retry state.
 */
class WidgetRegistry
{
    /**
     * Every widget, in display order within its section.
     *
     * @var array<int, class-string<DashboardWidget>>
     */
    public const WIDGETS = [
        // Employee dashboard - about ME, self scope.
        Widgets\MyAttendanceToday::class,
        Widgets\MyShift::class,
        Widgets\MyAttendanceMonth::class,
        Widgets\MyPunctuality::class,
        Widgets\MyLeaveBalances::class,
        Widgets\MyPendingRequests::class,
        Widgets\UpcomingHolidays::class,
        Widgets\MyAssets::class,
        // Main dashboard - the organisation within the viewer's DepartmentScope.
        Widgets\PendingApprovals::class,
        Widgets\TeamToday::class,
        Widgets\TeamTrend::class,
        Widgets\DepartmentOverview::class,
        Widgets\LeaveThisMonth::class,
        Widgets\HrSnapshot::class,
        Widgets\CommandCenter::class,
        Widgets\QualityNcrSummary::class,
        Widgets\RecentActivity::class,
        Widgets\DailyWorksSummary::class,
        Widgets\OmOperations::class,
        Widgets\SystemHealth::class,
    ];

    /** @var array<string, string> section key => label, in display order */
    public const SECTIONS = [
        DashboardWidget::SECTION_ME => 'My day',
        DashboardWidget::SECTION_TEAM => 'My team',
        DashboardWidget::SECTION_HR => 'People & compliance',
        DashboardWidget::SECTION_PROJECT => 'Project delivery',
        DashboardWidget::SECTION_OPS => 'Operations',
        DashboardWidget::SECTION_FINANCE => 'Finance',
        DashboardWidget::SECTION_ADMIN => 'System',
    ];

    /**
     * Holding ANY of these means the person supervises people, projects, operations,
     * money or the system — the full Dashboard is their home. Without them (and with
     * self-service access) the punch-centric employee home is. Baseline permissions
     * every employee has (daily-works.view, core.*) are deliberately not listed.
     */
    public const SUPERVISORY_PERMISSIONS = [
        'department.admin', 'leaves.approve', 'leaves.view', 'attendance.view', 'attendance.roster.manage',
        'employees.view', 'hr.onboarding.view', 'hr.offboarding.view', 'hr.assets.view',
        'projects.analytics', 'quality.view', 'quality.ncr.view', 'om.dashboard.view',
        'petty-cash.approve', 'petty-cash.view-all', 'system.monitoring.view',
    ];

    /** Permissions that put someone on the self-service (employee) home. */
    private const SELF_SERVICE_PERMISSIONS = ['attendance.own.view', 'attendance.own.punch', 'leave.own.view'];

    /** @var Collection<int, DashboardWidget>|null */
    private ?Collection $instances = null;

    public function __construct(
        private readonly Container $container,
        private readonly DepartmentScope $scope,
        private readonly RouteAccess $routes,
    ) {}

    /**
     * The widgets this viewer is authorized for on one dashboard, in display order.
     *
     * @param  string|null  $dashboard  DashboardWidget::DASHBOARD_EMPLOYEE | DASHBOARD_MAIN, null = both
     * @return Collection<int, DashboardWidget>
     */
    public function visibleFor(User $viewer, ?string $dashboard = null): Collection
    {
        return $this->all()
            ->filter(fn (DashboardWidget $widget): bool => $dashboard === null || $widget->dashboard() === $dashboard)
            ->filter(fn (DashboardWidget $widget): bool => $widget->authorizes($viewer))
            ->sortBy(fn (DashboardWidget $widget): int => $widget->priority())
            ->values();
    }

    /**
     * Every registered widget, instantiated once.
     *
     * @return Collection<int, DashboardWidget>
     */
    public function all(): Collection
    {
        return $this->instances ??= collect(self::WIDGETS)
            ->map(fn (string $class): DashboardWidget => $this->container->make($class))
            ->values();
    }

    /**
     * Self-service people (punch, leave) who supervise nothing belong on the employee
     * home; everyone else on the full dashboard. Replaces the old "has exactly the
     * Employee role" redirect: a person's PERMISSIONS decide, not the role's name.
     */
    public function prefersSelfServiceHome(User $viewer): bool
    {
        return $viewer->canAny(self::SELF_SERVICE_PERMISSIONS) && ! $viewer->canAny(self::SUPERVISORY_PERMISSIONS);
    }

    /**
     * The registry payload for one dashboard: only authorized widgets, each with its (cached) data.
     *
     * @return array<string, mixed>
     */
    public function payloadFor(User $viewer, string $dashboard): array
    {
        $widgets = $this->visibleFor($viewer, $dashboard);
        $fingerprint = $this->scopeFingerprint($viewer);
        $rendered = $widgets->map(fn (DashboardWidget $widget): array => $this->render($widget, $viewer, $fingerprint))->all();

        $present = collect($rendered)->pluck('section')->unique()->all();

        return [
            'dashboard' => $dashboard,
            'generated_at' => now()->toIso8601String(),
            'refresh_seconds' => (int) config('dashboard.refresh_seconds', 120),
            'personas' => $widgets->flatMap(fn (DashboardWidget $widget) => $widget->personas())->unique()->values()->all(),
            'sections' => collect(self::SECTIONS)
                ->filter(fn (string $label, string $key) => in_array($key, $present, true))
                ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'widgets' => $rendered,
        ];
    }

    /**
     * One authorized widget's rendered entry (null when the viewer may not see it) - the
     * single path behind GET /dashboard/command as well as the full payloads.
     *
     * @return array<string, mixed>|null
     */
    public function widgetFor(User $viewer, string $key): ?array
    {
        $widget = $this->all()->first(fn (DashboardWidget $w) => $w->key() === $key);

        return $widget !== null && $widget->authorizes($viewer)
            ? $this->render($widget, $viewer, $this->scopeFingerprint($viewer))
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function render(DashboardWidget $widget, User $viewer, string $fingerprint): array
    {
        $route = $widget->route($viewer);
        $route = $this->routes->canOpen($viewer, $route) ? $route : null;

        $data = null;
        $error = null;
        $asOf = now()->toIso8601String();

        try {
            // ttl 0 = the widget's data source already caches (CommandCenterService). The entry carries the moment
            // it was computed, so every card can say how fresh its numbers are.
            $compute = fn (): array => ['at' => now()->toIso8601String(), 'data' => $widget->data($viewer)];
            $entry = $widget->ttl() > 0
                ? $this->cache()->remember($this->cacheKey($widget, $viewer, $fingerprint), $widget->ttl(), $compute)
                : $compute();
            $asOf = $entry['at'];
            $data = $this->resolveLinks($entry['data'], $viewer);
        } catch (Throwable $e) {
            report($e);
            $error = 'This widget is temporarily unavailable.';
        }

        return [
            'key' => $widget->key(),
            'title' => $widget->title(),
            'section' => $widget->section(),
            'dashboard' => $widget->dashboard(),
            'type' => $widget->type(),
            'personas' => $widget->personas(),
            'ttl' => $widget->ttl(),
            'priority' => $widget->priority(),
            'span' => $widget->span(),
            'as_of' => $asOf,
            'route' => $route,
            'href' => $route !== null ? $this->routes->url($route) : null,
            'mobile_route' => $widget->mobileRoute(),
            'data' => $data,
            'error' => $error,
        ];
    }

    /**
     * Turn `route` names inside stats / items / rows into `href`s — but only for pages
     * the viewer can actually open, so no card ever links to a 403.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveLinks(array $data, User $viewer): array
    {
        foreach (['stats', 'kpis', 'items', 'rows'] as $bucket) {
            foreach ($data[$bucket] ?? [] as $index => $entry) {
                $route = is_array($entry) ? ($entry['route'] ?? null) : null;
                if ($route === null) {
                    continue;
                }

                $data[$bucket][$index]['href'] = $this->routes->canOpen($viewer, $route)
                    ? $this->routes->url($route, $entry['route_params'] ?? [])
                    : null;
            }
        }

        return $data;
    }

    /**
     * What a viewer's numbers depend on besides who they are: company-wide reach and
     * the departments they administer and their reporting subtree.
     */
    public function scopeFingerprint(User $viewer): string
    {
        return substr(md5(json_encode([
            $this->scope->isGlobal($viewer),
            $this->scope->isAttendanceAdmin($viewer),
            $this->scope->managedDepartmentIds($viewer),
            $this->scope->reportingSubtreeIds($viewer),
        ])), 0, 10);
    }

    /** Per section, per widget, per employee and per scope fingerprint. */
    public static function cacheKeyFor(string $dashboard, string $widgetKey, string $employeeId, string $fingerprint): string
    {
        return sprintf('dashboard:v3:%s:%s:%s:%s', $dashboard, $widgetKey, $employeeId, $fingerprint);
    }

    public function cacheKey(DashboardWidget $widget, User $viewer, ?string $fingerprint = null): string
    {
        $fingerprint ??= $this->scopeFingerprint($viewer);

        return self::cacheKeyFor($widget->dashboard(), $widget->key(), (string) $viewer->getKey(), $fingerprint);
    }

    /**
     * The application's default store — unless caching is switched off (`null` driver,
     * production today), in which case the file store keeps the polling cost bounded.
     */
    private function cache(): Repository
    {
        $configured = config('dashboard.cache_store');
        if (is_string($configured) && $configured !== '') {
            return Cache::store($configured);
        }

        $default = (string) config('cache.default');

        return config("cache.stores.{$default}.driver") === 'null'
            ? Cache::store('file')
            : Cache::store();
    }
}
