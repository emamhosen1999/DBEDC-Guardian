<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\OmDefect;
use App\Models\OmIncident;
use App\Models\OmIriReading;
use App\Models\OmWorkOrder;
use App\Models\User;
use App\Services\Dashboard\DashboardWidget;

/**
 * Operations & Maintenance: open incidents and open work orders.
 *
 * Permissions: om.* (each figure only for the viewer who holds that module's view
 * permission: om.incidents.view, om.maintenance.view; om.dashboard.view sees both).
 * The O&M registers are corridor records, not employee-owned, so they are governed by
 * the permission alone and are not narrowed by DepartmentScope. Two aggregate queries.
 */
final class OmOperations extends DashboardWidget
{
    public function key(): string
    {
        return 'ops.om';
    }

    public function title(): string
    {
        return 'Operations & maintenance';
    }

    public function permissions(): array
    {
        return ['om.dashboard.view', 'om.incidents.view', 'om.maintenance.view'];
    }

    public function personas(): array
    {
        return ['operator', 'project_manager', 'administrator'];
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
        return 20;
    }

    public function span(): int
    {
        return 12;
    }

    public function ttl(): int
    {
        return 60;
    }

    public function route(User $viewer): ?string
    {
        return $viewer->canAny(['om.dashboard.view']) ? 'om.dashboard' : ($viewer->can('om.incidents.view') ? 'om.incidents' : 'om.work-orders');
    }

    public function type(): string
    {
        return 'analytics';
    }

    /** The corridor is 48 km, cut into 6 km bands for the distribution chart. */
    private const ROAD_KM = 48;

    private const BAND_KM = 6;

    private const OPEN_DEFECT = ['reported', 'investigating', 'work_order_created', 'in_repair'];

    public function data(User $viewer): array
    {
        $stats = [];
        $kpis = [];
        $charts = [];
        $total = 0;
        $canIncidents = $viewer->canAny(['om.dashboard.view', 'om.incidents.view']);
        $canMaintenance = $viewer->canAny(['om.dashboard.view', 'om.maintenance.view']);

        if ($canIncidents) {
            $r = OmIncident::query()
                ->whereIn('status', ['detected', 'dispatched', 'on_scene'])
                ->selectRaw("COUNT(*) as open_n, SUM(severity = 'critical') as critical_n")
                ->first();
            $open = (int) ($r->open_n ?? 0);
            $critical = (int) ($r->critical_n ?? 0);
            $total += $open;
            $stats[] = $this->stat('incidents', 'Open incidents', $open, $this->toneFor($open), 'om.incidents');
            $stats[] = $this->stat('critical', 'Critical incidents', $critical, $this->toneFor($critical, 'crit'), 'om.incidents');

            $daily = $this->incidentsPerDay();
            $thisWeek = array_sum(array_slice($daily, -7));
            $lastWeek = array_sum(array_slice($daily, 0, 7));
            $kpis[] = $this->kpi('incidents', 'Open incidents', $open, $this->toneFor($open, 'crit'), 'om.incidents',
                array_sum($daily) > 0 ? $this->delta($thisWeek, $lastWeek, 'reported vs previous 7 days', 'down') : null,
                array_sum($daily) > 0 ? $daily : null, 'Incidents reported per day, last 14 days',
                $critical > 0 ? $critical.' critical' : null);
        }

        if ($canMaintenance) {
            $r = OmWorkOrder::query()
                ->whereIn('status', ['pending', 'assigned', 'in_progress'])
                ->selectRaw("COUNT(*) as open_n, SUM(priority = 'emergency') as emergency_n")
                ->first();
            $open = (int) ($r->open_n ?? 0);
            $emergency = (int) ($r->emergency_n ?? 0);
            $total += $open;
            $stats[] = $this->stat('work_orders', 'Open work orders', $open, $this->toneFor($open, 'info'), 'om.work-orders');
            $stats[] = $this->stat('emergency', 'Emergency work orders', $emergency, $this->toneFor($emergency, 'crit'), 'om.work-orders');
            $charts[] = $this->workOrdersByStatus();
            $charts[] = $this->slaGauge();
        }

        if ($canIncidents || $canMaintenance) {
            $charts[] = $this->chainageBands($canIncidents, $canMaintenance);
        }

        if ($viewer->can('om.dashboard.view')) {
            $charts[] = $this->iriTrend();
        }

        return [
            'scope' => ['kind' => 'project', 'label' => 'Expressway corridor'],
            'total' => $total,
            'stats' => $stats,
            'kpis' => $kpis,
            'charts' => $charts,
        ];
    }

    /** @return array<int, int> incidents reported per day, oldest first, the last 14 days (one grouped query) */
    private function incidentsPerDay(): array
    {
        $from = now()->startOfDay()->subDays(13);
        $rows = OmIncident::query()->where('reported_at', '>=', $from)
            ->selectRaw('DATE(reported_at) as d, COUNT(*) as n')->groupBy('d')->pluck('n', 'd');

        return array_map(fn ($i) => (int) ($rows[$from->copy()->addDays($i)->toDateString()] ?? 0), range(0, 13));
    }

    private function workOrdersByStatus(): array
    {
        $labels = ['pending' => 'Pending', 'assigned' => 'Assigned', 'in_progress' => 'In progress', 'completed' => 'Completed', 'verified' => 'Verified'];
        $by = OmWorkOrder::query()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $present = array_filter($labels, fn ($l, $status) => ($by[$status] ?? 0) > 0, ARRAY_FILTER_USE_BOTH);

        return $this->chart('om_work_orders', 'donut', 'Work orders by status',
            'Work orders by status: '.implode(', ', array_map(fn ($l, $st) => $l.' '.$by[$st], $present, array_keys($present))),
            ['labels' => array_values($present), 'series' => array_map(fn ($st) => (int) $by[$st], array_keys($present)), 'unit' => 'work orders'], null, 'No work orders yet.');
    }

    /**
     * SLA compliance: of defects that carry an SLA, the share resolved by their deadline or still inside it.
     * One aggregate query; a defect without sla_due_at is not counted.
     */
    private function slaGauge(): array
    {
        $r = OmDefect::query()->whereNotNull('sla_due_at')->selectRaw(
            'COUNT(*) as n, SUM(CASE WHEN rectified_at IS NOT NULL THEN (rectified_at <= sla_due_at) ELSE (sla_due_at >= ?) END) as ok',
            [now()],
        )->first();
        $n = (int) ($r->n ?? 0);
        $ok = (int) ($r->ok ?? 0);
        $pct = $n > 0 ? round($ok / $n * 100, 1) : 0;

        return $this->chart('om_sla', 'radial', 'SLA compliance',
            $n > 0 ? "{$ok} of {$n} defects with an SLA are resolved on time or still within it ({$pct}%)" : 'No defects carry an SLA yet',
            ['value' => $pct, 'unit' => '%', 'detail' => $n > 0 ? "{$ok} of {$n} defects within SLA" : null, 'total' => $n], null, 'No defects with an SLA yet.');
    }

    /** Incidents (last 90 days) and open defects per 6 km band along the corridor. */
    private function chainageBands(bool $incidents, bool $defects): array
    {
        $bands = (int) ceil(self::ROAD_KM / self::BAND_KM);
        $categories = array_map(fn ($b) => 'K'.($b * self::BAND_KM).'-'.min(self::ROAD_KM, ($b + 1) * self::BAND_KM), range(0, $bands - 1));
        $series = [];

        $bucket = function ($chainages) use ($bands): array {
            $counts = array_fill(0, $bands, 0);
            foreach ($chainages as $chainage) {
                $km = $this->chainageKm((string) $chainage);
                if ($km !== null) {
                    $counts[min($bands - 1, (int) floor($km / self::BAND_KM))]++;
                }
            }

            return $counts;
        };

        if ($incidents) {
            $series[] = ['name' => 'Incidents (90 days)', 'tone' => 'crit', 'data' => $bucket(OmIncident::query()->where('reported_at', '>=', now()->subDays(90))->pluck('chainage'))];
        }
        if ($defects) {
            $series[] = ['name' => 'Open defects', 'tone' => 'warn', 'data' => $bucket(OmDefect::query()->whereIn('status', self::OPEN_DEFECT)->pluck('chainage'))];
        }

        return $this->chart('om_chainage', 'bar', 'Incidents and defects along the corridor',
            'Incidents of the last 90 days and open defects per 6 km band of the 48 km corridor',
            ['categories' => $categories, 'series' => $series], ['from' => now()->subDays(90)->toDateString(), 'to' => now()->toDateString()], 'No incidents or open defects on the corridor.');
    }

    /** Kilometre of a chainage such as "K4+100", "4+100 to 4+320" or "12.5" (null when unreadable). */
    private function chainageKm(string $chainage): ?float
    {
        if (preg_match('/K?\s*(\d+(?:\.\d+)?)\s*\+/i', $chainage, $m) || preg_match('/(\d+(?:\.\d+)?)/', $chainage, $m)) {
            return (float) $m[1];
        }

        return null;
    }

    /** Mean IRI per week over the last 12 weeks from the patrol roughness readings (one grouped query). */
    private function iriTrend(): array
    {
        $first = now()->startOfWeek()->subWeeks(11);
        $rows = OmIriReading::query()->where('recorded_at', '>=', $first)->get(['recorded_at', 'iri_value']);
        $sum = array_fill(0, 12, 0.0);
        $count = array_fill(0, 12, 0);
        foreach ($rows as $row) {
            $i = (int) floor($first->diffInDays($row->recorded_at->copy()->startOfDay()) / 7);
            if ($i >= 0 && $i < 12) {
                $sum[$i] += (float) $row->iri_value;
                $count[$i]++;
            }
        }
        $data = array_map(fn ($s, $c) => $c > 0 ? round($s / $c, 2) : null, $sum, $count);

        return $this->chart('om_iri', 'line', 'Road roughness (IRI), weekly mean',
            'Mean International Roughness Index per week, last 12 weeks',
            ['categories' => array_map(fn ($w) => $first->copy()->addWeeks($w)->format('d M'), range(0, 11)), 'unit' => 'm/km',
                'series' => [['name' => 'Mean IRI', 'tone' => 'theme', 'data' => $data]]],
            ['from' => $first->toDateString(), 'to' => now()->toDateString()], 'No roughness readings in this period.');
    }
}
