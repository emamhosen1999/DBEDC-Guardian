<?php

namespace App\Services\Dashboard;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One dashboard widget: what it is, who may see it, and how it computes its numbers.
 *
 * Contract (enforced by WidgetRegistry and pinned by tests/Feature/Dashboard):
 *  - permissions() lists PERMISSION names, any-of. Never role names — roles are
 *    bundles of permissions an administrator can edit, so a role check silently
 *    drifts from what the person was actually granted.
 *  - data() runs only for an authorized viewer and MUST scope employee-owned rows
 *    through DepartmentScope (applyToUsers / applyToEmployeeOwned / isGlobal …).
 *    Registers that are not employee-owned (project, O&M, system) are governed by
 *    their permission alone; say so in the widget's docblock.
 *  - One aggregate (or a small constant number of queries) per widget — never a
 *    query per employee. The registry's query-ceiling test fails a widget that
 *    grows with team size.
 *  - Figures link to the module page that explains them by naming a route in a
 *    stat/item (`route`, optional `route_params`, `mobile_route`). The registry
 *    turns it into an `href` only when the viewer can really open that page.
 */
abstract class DashboardWidget
{
    /** Registry sections (also the display order). */
    public const SECTION_ME = 'me';

    public const SECTION_TEAM = 'team';

    public const SECTION_HR = 'hr';

    public const SECTION_PROJECT = 'project';

    public const SECTION_OPS = 'ops';

    public const SECTION_FINANCE = 'finance';

    public const SECTION_ADMIN = 'admin';

    /** The two dashboards. A widget lives on exactly one: personal widgets on the employee home, organisational ones on the main dashboard. */
    public const DASHBOARD_EMPLOYEE = 'employee';

    public const DASHBOARD_MAIN = 'main';

    /** @var array<string, array<string, mixed>> per-request memo of scopeMeta() */
    private array $scopeMetaMemo = [];

    public function __construct(protected readonly DepartmentScope $scope) {}

    /** Stable machine key, `<section>.<name>`. */
    abstract public function key(): string;

    abstract public function title(): string;

    /**
     * ANY-of permission names that authorize the widget.
     *
     * @return array<int, string>
     */
    abstract public function permissions(): array;

    /**
     * Personas the widget is designed for (documentation + client hints):
     * employee, line_manager, department_admin, hr_manager, administrator,
     * project_manager, operator, finance.
     *
     * @return array<int, string>
     */
    abstract public function personas(): array;

    abstract public function section(): string;

    /** Which dashboard renders it: DASHBOARD_EMPLOYEE (about me) or DASHBOARD_MAIN (the organisation within my scope). */
    abstract public function dashboard(): string;

    /**
     * The widget's numbers. Only called for an authorized viewer.
     *
     * @return array<string, mixed>
     */
    abstract public function data(User $viewer): array;

    /** Display order within its dashboard: lower comes first (the owner's priority list). */
    public function priority(): int
    {
        return 100;
    }

    /** Preferred width in Cyber's 12-column grid (4, 6, 8 or 12); the client packs rows so none is left short. */
    public function span(): int
    {
        return 4;
    }

    /** Renderer hint: stats | list | bars | progress | trend | command_project | command_feed. */
    public function type(): string
    {
        return 'stats';
    }

    /** Server-side cache lifetime in seconds (keyed per viewer + scope). */
    public function ttl(): int
    {
        return 120;
    }

    /** Drill-down route NAME for the whole widget, or null when there is no page to open. */
    public function route(User $viewer): ?string
    {
        return null;
    }

    /** Expo-router path for the same drill-down, when the mobile app has a screen for it. */
    public function mobileRoute(): ?string
    {
        return null;
    }

    public function authorizes(User $viewer): bool
    {
        return $viewer->canAny($this->permissions());
    }

    // ──────────────────────────────────────────────
    //  Scope helpers — every one goes through DepartmentScope
    // ──────────────────────────────────────────────

    /**
     * Active (non-deleted) employees the viewer may see, the viewer excluded — "my team".
     * Global roles get everybody; anyone else their managed departments ∪ reporting subtree.
     *
     * @return Builder<User>
     */
    protected function team(User $viewer): Builder
    {
        return $this->scope->applyToUsers(User::query(), $viewer)
            ->where('users.is_active', true)
            ->where('users.employee_id', '!=', $this->id($viewer));
    }

    /**
     * Active employees the viewer may see, the viewer included (headcount, data quality).
     *
     * @return Builder<User>
     */
    protected function people(User $viewer): Builder
    {
        return $this->scope->applyToUsers(User::query(), $viewer)->where('users.is_active', true);
    }

    /**
     * Restrict a query over an employee-keyed table (leaves.user_id, assets.assignee_id …)
     * to employees the viewer may see. Former employees stay visible to their department.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    protected function owned(Builder $query, User $viewer, string $employeeFk): Builder
    {
        return $this->scope->applyToEmployeeOwned($query, $viewer, $employeeFk);
    }

    /** The viewer's employee_id as a string (the users PK). */
    protected function id(User $viewer): string
    {
        return (string) $viewer->getKey();
    }

    /**
     * Who the numbers cover, for the card subtitle: the whole organization, the
     * departments the viewer administers, their reporting line, or just themselves.
     *
     * @return array{kind: string, label: string}
     */
    protected function scopeMeta(User $viewer): array
    {
        return $this->scopeMetaMemo[$this->id($viewer)] ??= $this->buildScopeMeta($viewer);
    }

    /** @return array{kind: string, label: string} */
    private function buildScopeMeta(User $viewer): array
    {
        if ($this->scope->isGlobal($viewer)) {
            return ['kind' => 'organization', 'label' => 'Whole organization'];
        }

        $departmentIds = $this->scope->managedDepartmentIds($viewer);
        if ($departmentIds !== []) {
            $names = Department::withTrashed()->whereIn('id', $departmentIds)->orderBy('name')->pluck('name')->all();

            return ['kind' => 'department', 'label' => implode(', ', array_slice($names, 0, 3)).(count($names) > 3 ? ' +'.(count($names) - 3) : '')];
        }

        return $this->scope->reportingSubtreeIds($viewer) !== []
            ? ['kind' => 'reports', 'label' => 'Your reporting line']
            : ['kind' => 'self', 'label' => 'Only you'];
    }

    // ──────────────────────────────────────────────
    //  Portable date predicates
    //  DATE columns (MySQL) and 'Y-m-d' / 'Y-m-d H:i:s' text (SQLite) both satisfy
    //  `col >= day AND col < nextDay`, and it stays index-friendly — unlike DATE(col) = ?.
    // ──────────────────────────────────────────────

    /** @param  Builder<Model>  $query */
    protected function onDay(Builder $query, string $column, CarbonInterface $day): Builder
    {
        return $query->where($column, '>=', $day->toDateString())
            ->where($column, '<', $day->copy()->addDay()->toDateString());
    }

    /** Rows whose [from, to] span covers $day. */
    protected function spans(Builder $query, string $fromColumn, string $toColumn, CarbonInterface $day): Builder
    {
        return $query->where($fromColumn, '<', $day->copy()->addDay()->toDateString())
            ->where($toColumn, '>=', $day->toDateString());
    }

    /** Rows whose date falls in [$from, $to] inclusive (whole days). */
    protected function between(Builder $query, string $column, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return $query->where($column, '>=', $from->toDateString())
            ->where($column, '<', $to->copy()->addDay()->toDateString());
    }

    // ──────────────────────────────────────────────
    //  Payload builders
    // ──────────────────────────────────────────────

    /**
     * One figure. `route` (+ `route_params`) is resolved by the registry into an
     * `href` only when the viewer can open the page; `mobile_route` is the Expo path.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function stat(string $key, string $label, int|float|string|null $value, string $tone = 'neutral', ?string $route = null, ?string $mobileRoute = null, array $extra = []): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'tone' => $tone,
            'route' => $route,
            'mobile_route' => $mobileRoute,
        ], fn ($v) => $v !== null) + $extra + ['value' => $value];
    }

    /**
     * One list row.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function item(string $key, string $title, ?string $subtitle = null, ?string $meta = null, string $tone = 'neutral', ?string $route = null, array $routeParams = [], array $extra = []): array
    {
        return array_filter([
            'key' => $key,
            'title' => $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'tone' => $tone,
            'route' => $route,
            'route_params' => $routeParams === [] ? null : $routeParams,
        ], fn ($v) => $v !== null) + $extra;
    }

    /**
     * A KPI tile: the figure, its change versus the previous period and a real sparkline.
     * `strip` puts it in the dashboard's top KPI strip (the owning widget's card still shows its charts).
     *
     * @param  array{text: string, direction: string, good: string}|null  $delta
     * @param  array<int, int|float>|null  $spark  a real series, or null to omit the sparkline
     * @return array<string, mixed>
     */
    protected function kpi(string $key, string $label, int|float|string|null $value, string $tone = 'neutral', ?string $route = null, ?array $delta = null, ?array $spark = null, ?string $sparkLabel = null, ?string $hint = null, bool $strip = true): array
    {
        return array_filter([
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'tone' => $tone,
            'route' => $route,
            'delta' => $delta,
            'spark' => $spark !== null && count($spark) > 1 ? array_values($spark) : null,
            'spark_label' => $sparkLabel,
            'hint' => $hint,
            'strip' => $strip,
        ], fn ($v) => $v !== null) + ['value' => $value];
    }

    /**
     * Change versus the previous period: `+3 vs yesterday`. Null when there is no previous value to compare with,
     * so a tile never claims a trend it cannot back. `$good` says which direction is an improvement.
     *
     * @return array{text: string, direction: string, good: string}|null
     */
    protected function delta(int|float $current, int|float|null $previous, string $versus, string $good = 'up'): ?array
    {
        if ($previous === null) {
            return null;
        }

        $diff = $current - $previous;
        $direction = $diff > 0 ? 'up' : ($diff < 0 ? 'down' : 'flat');
        $figure = is_float($diff) ? rtrim(rtrim(number_format(abs($diff), 1), '0'), '.') : (string) abs($diff);

        return [
            'text' => $direction === 'flat' ? "no change {$versus}" : ($diff > 0 ? '+' : '-').$figure." {$versus}",
            'direction' => $direction,
            'good' => $good,
        ];
    }

    /**
     * A declarative chart the web (ApexCharts) and mobile clients both render. `kind`: area | line | bar | donut |
     * radial | heatstrip | timeline. Series are real aggregates over `period`; a chart without any non-zero value
     * is flagged `empty` so the client shows an honest empty state instead of a flat line.
     *
     * @param  array<string, mixed>  $spec  categories, series [{name, data, tone}], labels, tones, value, unit, stacked, days, items
     * @param  array{from: string, to: string}|null  $period
     * @return array<string, mixed>
     */
    protected function chart(string $key, string $kind, string $title, string $summary, array $spec, ?array $period = null, ?string $emptyMessage = null): array
    {
        $values = [];
        foreach ((array) ($spec['series'] ?? []) as $series) {
            foreach (is_array($series) ? ($series['data'] ?? []) : [$series] as $point) {
                $values[] = abs((float) $point);
            }
        }
        foreach ((array) ($spec['days'] ?? []) as $day) {
            $values[] = ($day['state'] ?? 'none') === 'none' || ($day['state'] ?? '') === 'future' ? 0 : 1;
        }
        $values[] = abs((float) ($spec['value'] ?? 0));
        $values[] = abs((float) ($spec['total'] ?? 0));
        $values[] = count((array) ($spec['items'] ?? []));

        return ['key' => $key, 'kind' => $kind, 'title' => $title, 'summary' => $summary, 'period' => $period,
            'empty' => array_sum($values) <= 0, 'empty_message' => $emptyMessage] + $spec;
    }

    /** A count's tone: `neutral` at zero, otherwise the given attention tone. */
    protected function toneFor(int|float $value, string $attention = 'warn'): string
    {
        return $value > 0 ? $attention : 'neutral';
    }
}
