<?php

namespace App\Services\Operations;

use App\Models\OmEquipment;
use Illuminate\Support\Facades\Schema;

/**
 * ITS equipment reliability (RCM, SAE JA1011). MTBF, MTTR, failure modes and Weibull life-phase classification need a
 * failure-event history per equipment item (failure time, repair time, operating hours), which Guardian does not record
 * yet. Until it does, this service reports only what the equipment register actually holds (status, recorded uptime,
 * last ping) and states plainly that reliability metrics are not available. It never returns illustrative equipment,
 * MTBF/MTTR figures or maintenance recommendations (owner rule 2026-10-08: no fabricated figures).
 */
class OmRcmService
{
    public function getRcmDashboard(): array
    {
        $empty = [
            'available' => false,
            'reason' => 'No ITS equipment is registered yet. Reliability metrics (MTBF, MTTR, availability) are computed from the equipment register and its failure history.',
            'stats' => [
                'total_monitored_assets' => 0,
                'reporting_assets' => 0,
                'average_recorded_uptime_pct' => null,
                'degraded_or_offline' => 0,
                'average_mtbf_hours' => null,
                'average_mttr_hours' => null,
            ],
            'equipment' => [],
        ];

        if (! Schema::hasTable('om_equipment_status')) {
            return $empty;
        }

        $equipment = OmEquipment::query()->orderBy('equipment_code')->get();
        if ($equipment->isEmpty()) {
            return $empty;
        }

        // Only items that have actually pinged carry a measured uptime; the column default is not a measurement.
        $reporting = $equipment->whereNotNull('last_ping_at');
        $avgUptime = $reporting->isEmpty() ? null : round((float) $reporting->avg('uptime_pct'), 2);

        return [
            'available' => true,
            'reason' => 'MTBF and MTTR need a per-equipment failure history (failure and repair timestamps), which is not recorded yet. Showing the equipment register with its recorded status and uptime.',
            'stats' => [
                'total_monitored_assets' => $equipment->count(),
                'reporting_assets' => $reporting->count(),
                'average_recorded_uptime_pct' => $avgUptime,
                'degraded_or_offline' => $equipment->whereIn('status', ['degraded', 'offline'])->count(),
                'average_mtbf_hours' => null,
                'average_mttr_hours' => null,
            ],
            'equipment' => $equipment->map(fn ($e) => [
                'id' => $e->id,
                'code' => $e->equipment_code,
                'name' => $e->name,
                'category' => $e->category,
                'location' => $e->location,
                'status' => $e->status,
                'uptime_pct' => $e->last_ping_at ? (float) $e->uptime_pct : null,
                'last_ping_at' => $e->last_ping_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }
}
