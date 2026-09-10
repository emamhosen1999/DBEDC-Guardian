<?php

namespace App\Services\Operations;

use App\Models\OmWimFatigueLog;
use Illuminate\Support\Facades\Schema;

class OmWimAnalysisService
{
    // Standard statutory axle limit (8.16 tonnes standard ESAL reference)
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

        // Estimated financial damage wear cost per km of passage in BDT
        // Based on highway pavement rehabilitation lifecycle depreciation
        $damageCostBdt = round(max(0, ($fourthPowerFactor - 1.0)) * 480, 2);

        return [
            'overload_percentage' => $overloadPct,
            'fourth_power_damage_factor' => $fourthPowerFactor,
            'esal_equivalent' => $esal,
            'damage_cost_bdt' => $damageCostBdt,
        ];
    }

    public function getWimFatigueOverview(): array
    {
        if (! Schema::hasTable('om_wim_fatigue_logs')) {
            return $this->getMockOverview();
        }

        $logs = OmWimFatigueLog::latest('log_date')->take(100)->get();
        if ($logs->isEmpty()) {
            return [
                'total_weighed_trucks' => 0,
                'overloaded_trucks' => 0,
                'overload_rate_pct' => 0.0,
                'critical_overloads' => 0,
                'cumulative_esal' => 0.0,
                'total_damage_cost_bdt' => 0.0,
                'by_plaza' => [],
                'recent_overloads' => [],
            ];
        }

        $totalVehicles = $logs->count();
        $overloadedVehicles = $logs->where('overload_percentage', '>', 0)->count();
        $criticalOverloads = $logs->where('overload_percentage', '>=', 20)->count();
        $cumulativeEsal = round((float) $logs->sum('esal_equivalent'), 2);
        $totalDamageCost = round((float) $logs->sum('estimated_damage_cost_bdt'), 2);

        // Group by toll plaza
        $byPlaza = $logs->groupBy('toll_plaza')->map(function ($group, $plaza) {
            return [
                'plaza' => $plaza,
                'total_weighed' => $group->count(),
                'overload_count' => $group->where('overload_percentage', '>', 0)->count(),
                'avg_damage_factor' => round($group->avg('fourth_power_damage_factor'), 2),
                'total_damage_bdt' => round($group->sum('estimated_damage_cost_bdt'), 2),
            ];
        })->values()->all();

        return [
            'total_weighed_trucks' => $totalVehicles,
            'overloaded_trucks' => $overloadedVehicles,
            'overload_rate_pct' => $totalVehicles > 0 ? round(($overloadedVehicles / $totalVehicles) * 100, 1) : 0,
            'critical_overloads' => $criticalOverloads,
            'cumulative_esal' => $cumulativeEsal,
            'total_damage_cost_bdt' => $totalDamageCost,
            'by_plaza' => $byPlaza,
            'recent_overloads' => $logs->where('overload_percentage', '>', 0)->take(15)->values()->all(),
        ];
    }
}
