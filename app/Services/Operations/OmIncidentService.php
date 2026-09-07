<?php

namespace App\Services\Operations;

use App\Models\OmIncident;
use App\Models\OmIncidentVehicle;
use App\Models\OmWorkOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OmIncidentService
{
    /**
     * Get paginated incidents with relations.
     */
    public function getIncidents(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmIncident::query()->with([
            'reporter',
            'escalator',
            'vehicles',
            'photos',
            'escalations',
        ]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['severity']) && $filters['severity'] !== 'all') {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['incident_type']) && $filters['incident_type'] !== 'all') {
            $query->where('incident_type', $filters['incident_type']);
        }

        if (! empty($filters['direction']) && $filters['direction'] !== 'all') {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('incident_number', 'like', "%{$search}%")
                    ->orWhere('chainage', 'like', "%{$search}%")
                    ->orWhere('dispatched_unit', 'like', "%{$search}%")
                    ->orWhere('police_case_number', 'like', "%{$search}%");
            });
        }

        return $query->latest('reported_at')->paginate($perPage);
    }

    /**
     * Get Incident & Response SLA KPIs.
     */
    public function getIncidentStats(): array
    {
        $activeCount = OmIncident::whereIn('status', ['detected', 'dispatched', 'on_scene'])->count();
        $clearedToday = OmIncident::whereDate('cleared_at', Carbon::today())->count();
        $criticalCount = OmIncident::where('severity', 'critical')->whereIn('status', ['detected', 'dispatched', 'on_scene'])->count();
        $rawAverageResponse = OmIncident::whereNotNull('response_time_minutes')->avg('response_time_minutes');
        $avgResponse = $rawAverageResponse === null ? null : round((float) $rawAverageResponse, 1);
        $totalTppdClaim = OmIncident::where('has_asset_damage', true)->sum('asset_damage_cost_est');

        return [
            'active_incidents' => $activeCount,
            'cleared_today' => $clearedToday,
            'critical_active' => $criticalCount,
            'avg_response_time_min' => $avgResponse,
            'total_tppd_damage_claims' => $totalTppdClaim,
        ];
    }

    /**
     * Report and Dispatch new Incident.
     */
    public function createIncident(array $data, string $userId): OmIncident
    {
        return DB::transaction(function () use ($data, $userId) {
            $incNumber = 'INC-'.date('Ymd').'-'.rand(100, 999);

            $incident = OmIncident::create([
                'incident_number' => $incNumber,
                'title' => $data['title'],
                'incident_type' => $data['incident_type'] ?? 'vehicle_breakdown',
                'detection_source' => $data['detection_source'] ?? 'patrol_unit',
                'chainage' => $data['chainage'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'direction' => $data['direction'] ?? 'northbound',
                'severity' => $data['severity'] ?? 'minor',
                'status' => 'dispatched',
                'dispatched_unit' => $data['dispatched_unit'] ?? 'Patrol Unit 1',
                'reported_by' => $userId,
                'reported_at' => now(),
                'dispatched_at' => now(),
                'description' => $data['description'] ?? null,
                'casualties_fatalities' => (int) ($data['casualties_fatalities'] ?? 0),
                'casualties_injured' => (int) ($data['casualties_injured'] ?? 0),
                'vehicles_involved_count' => (int) ($data['vehicles_involved_count'] ?? 1),
                'has_asset_damage' => ! empty($data['has_asset_damage']),
                'asset_damage_cost_est' => (float) ($data['asset_damage_cost_est'] ?? 0),
                'tppd_claim_status' => ! empty($data['has_asset_damage']) ? 'claim_prepared' : 'not_applicable',
                'police_case_number' => $data['police_case_number'] ?? null,
            ]);

            // Save involved vehicles if provided
            if (! empty($data['vehicles']) && is_array($data['vehicles'])) {
                foreach ($data['vehicles'] as $v) {
                    if (! empty($v['vehicle_reg_number'])) {
                        OmIncidentVehicle::create([
                            'incident_id' => $incident->id,
                            'vehicle_reg_number' => $v['vehicle_reg_number'],
                            'vehicle_type' => $v['vehicle_type'] ?? 'Private Car',
                            'driver_name' => $v['driver_name'] ?? null,
                            'driver_license_number' => $v['driver_license_number'] ?? null,
                            'driver_phone' => $v['driver_phone'] ?? null,
                            'insurance_company' => $v['insurance_company'] ?? null,
                            'insurance_policy_number' => $v['insurance_policy_number'] ?? null,
                            'towed_by_expressway_wrecker' => ! empty($v['towed_by_expressway_wrecker']),
                            'towing_fee_charged' => (float) ($v['towing_fee_charged'] ?? 0),
                            'damage_to_vehicle_description' => $v['damage_to_vehicle_description'] ?? null,
                            'damage_to_expressway_asset' => $v['damage_to_expressway_asset'] ?? null,
                            'estimated_asset_repair_cost' => (float) ($v['estimated_asset_repair_cost'] ?? 0),
                        ]);
                    }
                }
            }

            // Dispatch Push & In-App Notification for Major/Critical Incidents
            if (in_array($incident->severity, ['critical', 'major'], true)) {
                try {
                    $recipients = \App\Models\User::permission('om.incidents.manage')->get();
                    if ($recipients->isNotEmpty()) {
                        \Illuminate\Support\Facades\Notification::send($recipients, new \App\Notifications\OmAlertNotification(
                            "EMERGENCY: {$incident->title} ({$incident->incident_number})",
                            "Severity: {$incident->severity} at {$incident->chainage} ({$incident->direction}). Unit: {$incident->dispatched_unit}.",
                            'incident_escalated',
                            $incident->incident_number,
                            '/om/incidents'
                        ));
                    }
                } catch (\Throwable) {
                    // Fail-safe
                }
            }

            return $incident->fresh(['vehicles']);
        });
    }

    /**
     * Update incident status (e.g., On-Scene, Lane Reopened, Cleared).
     */
    public function updateStatus(OmIncident $incident, string $status, array $extra, ?int $expectedVersion): OmIncident
    {
        return DB::transaction(function () use ($incident, $status, $extra, $expectedVersion) {
            $locked = OmIncident::query()->lockForUpdate()->findOrFail($incident->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);
            $allowedNextStatus = [
                'detected' => 'dispatched',
                'dispatched' => 'on_scene',
                'on_scene' => 'cleared',
                'cleared' => 'closed',
                'closed' => null,
            ];

            if ($status === $locked->status) {
                return $locked->fresh();
            }

            if (($allowedNextStatus[$locked->status] ?? null) !== $status) {
                throw ValidationException::withMessages([
                    'status' => "Incident cannot transition from {$locked->status} to {$status}.",
                ]);
            }

            $update = [
                'status' => $status,
                'lock_version' => OmVersionGuard::next($locked),
            ];

            if ($status === 'on_scene' && ! $locked->on_scene_at) {
                $update['on_scene_at'] = now();
                if ($locked->reported_at) {
                    $update['response_time_minutes'] = (int) $locked->reported_at->diffInMinutes(now());
                }
            } elseif ($status === 'cleared' && ! $locked->cleared_at) {
                $update['cleared_at'] = now();
                $update['lane_cleared_at'] = now();
            }

            if (! empty($extra['description'])) {
                $update['description'] = $extra['description'];
            }
            if (! empty($extra['dispatched_unit'])) {
                $update['dispatched_unit'] = $extra['dispatched_unit'];
            }

            $locked->update($update);

            return $locked->fresh();
        });
    }

    /**
     * Create Work Order from Incident damage.
     */
    public function createDamageRepairWorkOrder(OmIncident $incident, string $userId, int $expectedVersion): OmWorkOrder
    {
        return DB::transaction(function () use ($incident, $userId, $expectedVersion) {
            $locked = OmIncident::query()->lockForUpdate()->findOrFail($incident->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);

            if (! $locked->has_asset_damage) {
                throw ValidationException::withMessages([
                    'incident' => 'A damage-repair work order requires recorded expressway asset damage.',
                ]);
            }

            $workOrder = OmWorkOrder::create([
                'work_order_number' => 'WO-'.rand(10000, 99999),
                'title' => 'Emergency Crash Repair: '.$locked->title,
                'work_type' => 'tppd_restoration',
                'category' => 'guardrail',
                'location' => $locked->chainage.' ('.ucfirst($locked->direction).')',
                'priority' => 'emergency',
                'status' => 'assigned',
                'assigned_to' => 'Rapid Emergency Repair Crew',
                'description' => "Post-incident repair for {$locked->incident_number}. Estimated damage: ৳".number_format($locked->asset_damage_cost_est, 2),
                'reported_by' => $userId,
                'assigned_by' => $userId,
                'target_start_at' => now(),
                'target_end_at' => now()->addHours(24),
                'estimated_cost' => $locked->asset_damage_cost_est,
                'requires_lane_closure' => true,
            ]);

            $locked->update(['lock_version' => OmVersionGuard::next($locked)]);

            return $workOrder;
        });
    }
}
