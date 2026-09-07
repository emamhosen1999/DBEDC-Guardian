<?php

namespace App\Services\Operations;

use App\Models\OmDefect;
use App\Models\OmIncident;
use App\Models\OmInspection;
use App\Models\OmSafetyIncident;
use App\Models\OmSlaBreach;
use App\Models\OmWorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OmAnalyticsService
{
    /**
     * Comprehensive O&M Analytics Dashboard Data.
     */
    public function getDashboardAnalytics(array $filters = []): array
    {
        $period = $filters['period'] ?? 'month'; // week, month, quarter, year
        $startDate = $this->resolveStartDate($period);

        return [
            'period' => $period,
            'period_label' => $this->resolvePeriodLabel($period),
            'kpis' => $this->getKpis($startDate),
            'defect_trends' => $this->getDefectTrends($startDate),
            'work_order_trends' => $this->getWorkOrderTrends($startDate),
            'incident_trends' => $this->getIncidentTrends($startDate),
            'sla_compliance' => $this->getSlaCompliance($startDate),
            'category_breakdown' => $this->getCategoryBreakdown($startDate),
            'top_defect_types' => $this->getTopDefectTypes($startDate),
            'safety_summary' => $this->getSafetySummary($startDate),
        ];
    }

    /**
     * Core KPIs.
     */
    private function getKpis(Carbon $startDate): array
    {
        // Mean Time to Repair (hours)
        $completedWOs = OmWorkOrder::where('completed_at', '>=', $startDate)
            ->whereNotNull('actual_start_at')
            ->whereNotNull('completed_at')
            ->get();

        $mttr = $completedWOs->count() > 0
            ? round($completedWOs->avg(function ($wo) {
                return $wo->actual_start_at->diffInMinutes($wo->completed_at) / 60;
            }), 1)
            : 0;

        // WO Completion Rate
        $totalWOs = OmWorkOrder::where('created_at', '>=', $startDate)->count();
        $closedWOs = OmWorkOrder::where('created_at', '>=', $startDate)
            ->whereIn('status', ['completed', 'verified'])->count();
        $woCompletionRate = $totalWOs > 0 ? round($closedWOs / $totalWOs * 100, 1) : 0;

        // Defect Resolution Rate
        $totalDefects = OmDefect::where('created_at', '>=', $startDate)->count();
        $resolvedDefects = OmDefect::where('created_at', '>=', $startDate)
            ->whereIn('status', ['rectified', 'verified_closed'])->count();
        $defectResolutionRate = $totalDefects > 0 ? round($resolvedDefects / $totalDefects * 100, 1) : 0;

        // Avg Incident Response Time (minutes)
        $respondedIncidents = OmIncident::where('reported_at', '>=', $startDate)
            ->whereNotNull('response_time_minutes')
            ->where('response_time_minutes', '>', 0);
        $avgResponseTime = round((float) ($respondedIncidents->avg('response_time_minutes') ?? 0), 1);

        // SLA Compliance
        $slaDefects = OmDefect::where('created_at', '>=', $startDate)
            ->whereNotNull('sla_due_at')
            ->count();
        $slaBreached = OmSlaBreach::where('entity_type', 'defect')
            ->where('breached_at', '>=', $startDate)
            ->count();
        $slaComplianceRate = $slaDefects > 0 ? round((1 - ($slaBreached / $slaDefects)) * 100, 1) : 100;

        // Inspection Pass Rate
        $totalInspections = OmInspection::where('inspection_date', '>=', $startDate)->count();
        $passedInspections = OmInspection::where('inspection_date', '>=', $startDate)
            ->where('result', 'pass')->count();
        $inspectionPassRate = $totalInspections > 0 ? round($passedInspections / $totalInspections * 100, 1) : 0;

        return [
            'mttr_hours' => $mttr,
            'wo_completion_rate' => $woCompletionRate,
            'defect_resolution_rate' => $defectResolutionRate,
            'avg_incident_response_min' => $avgResponseTime,
            'sla_compliance_rate' => $slaComplianceRate,
            'inspection_pass_rate' => $inspectionPassRate,
            'total_work_orders' => $totalWOs,
            'total_defects' => $totalDefects,
            'total_incidents' => OmIncident::where('reported_at', '>=', $startDate)->count(),
            'total_inspections' => $totalInspections,
        ];
    }

    /**
     * Defect trend data (per week/month).
     */
    private function getDefectTrends(Carbon $startDate): array
    {
        return OmDefect::where('created_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d') as date"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN status IN ('rectified','verified_closed') THEN 1 ELSE 0 END) as resolved"),
                DB::raw("SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Work order trend data.
     */
    private function getWorkOrderTrends(Carbon $startDate): array
    {
        return OmWorkOrder::where('created_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m-%d') as date"),
                DB::raw('COUNT(*) as created'),
                DB::raw("SUM(CASE WHEN status IN ('completed','verified') THEN 1 ELSE 0 END) as completed")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * Incident trend data.
     */
    private function getIncidentTrends(Carbon $startDate): array
    {
        return OmIncident::where('reported_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(reported_at, '%Y-%m-%d') as date"),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    /**
     * SLA compliance data.
     */
    private function getSlaCompliance(Carbon $startDate): array
    {
        $breaches = OmSlaBreach::where('breached_at', '>=', $startDate)
            ->select(
                DB::raw("DATE_FORMAT(breached_at, '%Y-%m-%d') as date"),
                DB::raw('COUNT(*) as breaches'),
                DB::raw("SUM(overdue_hours) as total_overdue_hours")
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        $totalBreaches = OmSlaBreach::where('breached_at', '>=', $startDate)->count();
        $acknowledged = OmSlaBreach::where('breached_at', '>=', $startDate)
            ->where('acknowledged', true)->count();

        return [
            'trends' => $breaches,
            'total_breaches' => $totalBreaches,
            'acknowledged' => $acknowledged,
            'unacknowledged' => $totalBreaches - $acknowledged,
        ];
    }

    /**
     * Breakdown by asset category.
     */
    private function getCategoryBreakdown(Carbon $startDate): array
    {
        return OmWorkOrder::where('created_at', '>=', $startDate)
            ->select('category', DB::raw('COUNT(*) as count'), DB::raw('SUM(actual_cost) as total_cost'))
            ->groupBy('category')
            ->orderByDesc('count')
            ->get()
            ->toArray();
    }

    /**
     * Top defect types.
     */
    private function getTopDefectTypes(Carbon $startDate): array
    {
        return OmDefect::where('created_at', '>=', $startDate)
            ->select('distress_type', DB::raw('COUNT(*) as count'))
            ->groupBy('distress_type')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();
    }

    /**
     * Safety summary.
     */
    private function getSafetySummary(Carbon $startDate): array
    {
        return [
            'total' => OmSafetyIncident::where('occurred_at', '>=', $startDate)->count(),
            'near_misses' => OmSafetyIncident::where('occurred_at', '>=', $startDate)->where('incident_type', 'near_miss')->count(),
            'injuries' => OmSafetyIncident::where('occurred_at', '>=', $startDate)->where('incident_type', 'workplace_injury')->count(),
            'lost_time_hours' => (int) OmSafetyIncident::where('occurred_at', '>=', $startDate)->sum('lost_time_hours'),
        ];
    }

    private function resolveStartDate(string $period): Carbon
    {
        return match ($period) {
            'week' => now()->subWeek(),
            'month' => now()->subMonth(),
            'quarter' => now()->subMonths(3),
            'year' => now()->subYear(),
            default => now()->subMonth(),
        };
    }

    private function resolvePeriodLabel(string $period): string
    {
        return match ($period) {
            'week' => 'Last 7 Days',
            'month' => 'Last 30 Days',
            'quarter' => 'Last 3 Months',
            'year' => 'Last 12 Months',
            default => 'Last 30 Days',
        };
    }
}
