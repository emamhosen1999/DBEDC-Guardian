<?php

namespace App\Services\Operations;

use App\Models\OmWimFatigueLog;
use Illuminate\Support\Facades\Schema;

class OmWimAnalysisService
{
    // AASHO Road Test standard axle: 18,000 lb = 8.16 tonnes (the ESAL reference load)
    public const STANDARD_AXLE_TONNES = 8.16;

    /**
     * Compute fourth power law damage factor and ESAL equivalent
     */
    public function calculateDamageFactor(float $actualWeight, float $statutoryLimit): array
    {
        $overloadPct = $actualWeight > $statutoryLimit
            ? round((($actualWeight - $statutoryLimit) / $statutoryLimit) * 100, 2)
            : 0.0;

        // AASHO Fourth Power Law: Damage = (Weight / Limit)^4
        $fourthPowerFactor = round(pow($actualWeight / max($statutoryLimit, 1.0), 4), 2);

        // ESAL Equivalent: based on standard 8.16 tonne reference
        $esal = round(pow($actualWeight / self::STANDARD_AXLE_TONNES, 4), 2);

        return [
            'overload_percentage' => $overloadPct,
            'fourth_power_damage_factor' => $fourthPowerFactor,
            'esal_equivalent' => $esal,
        ];
    }

    /**
     * WIM overload and pavement-fatigue overview, aggregated from every recorded weigh-in-motion log. When nothing has
     * been recorded it says so (available:false) and carries no figures; no illustrative numbers (owner rule 2026-10-08).
     */
    public function getWimFatigueOverview(): array
    {
        $empty = [
            'available' => false,
            'reason' => 'No weigh-in-motion readings have been recorded yet. Overload and fatigue figures appear once WIM logs are captured.',
            'total_weighed_trucks' => 0,
            'total_heavy_vehicles' => 0,
            'overloaded_trucks' => 0,
            'overload_rate_pct' => null,
            'overload_percentage' => null,
            'critical_overloads' => 0,
            'cumulative_esal' => null,
            'total_esal_accumulated' => null,
            'total_damage_cost_bdt' => null,
            'structural_damage_cost_bdt' => null,
            'first_log_date' => null,
            'last_log_date' => null,
            'axle_class_breakdown' => [],
            'lane_distribution' => [],
            'by_plaza' => [],
            'recent_overloads' => [],
        ];

        if (! Schema::hasTable('om_wim_fatigue_logs')) {
            return $empty;
        }

        $logs = OmWimFatigueLog::query()->get();
        if ($logs->isEmpty()) {
            return $empty;
        }

        $total = $logs->count();
        $overloaded = $logs->where('overload_percentage', '>', 0)->count();
        $totalEsal = round((float) $logs->sum('esal_equivalent'), 2);
        $totalCost = round((float) $logs->sum('estimated_damage_cost_bdt'), 2);

        $group = fn ($set) => [
            'vehicle_count' => $set->count(),
            'overloaded_count' => $set->where('overload_percentage', '>', 0)->count(),
            'overload_rate' => round($set->where('overload_percentage', '>', 0)->count() / max($set->count(), 1) * 100, 1),
            'avg_damage_factor' => round((float) $set->avg('fourth_power_damage_factor'), 2),
            'total_esal' => round((float) $set->sum('esal_equivalent'), 2),
            'damage_cost_bdt' => round((float) $set->sum('estimated_damage_cost_bdt'), 2),
        ];

        $axleClasses = $logs->groupBy('axle_class')
            ->map(fn ($set, $class) => ['axle_class' => (string) $class] + $group($set))
            ->sortByDesc('vehicle_count')->values()->all();

        $lanes = $logs->groupBy('lane_number')->map(function ($set, $lane) use ($totalEsal, $group) {
            $g = $group($set);

            return [
                'lane_name' => (string) $lane,
                'esal_count' => $g['total_esal'],
                'esal_percentage' => $totalEsal > 0 ? round($g['total_esal'] / $totalEsal * 100, 1) : 0.0,
                'overload_rate' => $g['overload_rate'],
            ];
        })->sortByDesc('esal_count')->values()->all();

        $byPlaza = $logs->groupBy('toll_plaza')->map(fn ($set, $plaza) => [
            'plaza' => $plaza,
            'total_weighed' => $set->count(),
            'overload_count' => $set->where('overload_percentage', '>', 0)->count(),
            'avg_damage_factor' => round((float) $set->avg('fourth_power_damage_factor'), 2),
            'total_damage_bdt' => round((float) $set->sum('estimated_damage_cost_bdt'), 2),
        ])->values()->all();

        $rate = round($overloaded / $total * 100, 1);

        return [
            'available' => true,
            'reason' => null,
            'total_weighed_trucks' => $total,
            'total_heavy_vehicles' => $total,
            'overloaded_trucks' => $overloaded,
            'overload_rate_pct' => $rate,
            'overload_percentage' => $rate,
            'critical_overloads' => $logs->where('overload_percentage', '>=', 20)->count(),
            'cumulative_esal' => $totalEsal,
            'total_esal_accumulated' => $totalEsal,
            'total_damage_cost_bdt' => $totalCost,
            'structural_damage_cost_bdt' => $totalCost,
            'first_log_date' => OmWimFatigueLog::query()->min('log_date'),
            'last_log_date' => OmWimFatigueLog::query()->max('log_date'),
            'axle_class_breakdown' => $axleClasses,
            'lane_distribution' => $lanes,
            'by_plaza' => $byPlaza,
            'recent_overloads' => $logs->where('overload_percentage', '>', 0)->sortByDesc('log_date')->take(15)->values()->all(),
        ];
    }
}
