<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OmDefect;
use App\Models\OmIncident;
use App\Models\OmPatrolShift;
use App\Models\OmWorkOrder;
use App\Services\Operations\OmDefectService;
use App\Services\Operations\OmIncidentService;
use App\Services\Operations\OmWorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OmMobileApiController extends Controller
{
    public function __construct(
        protected OmDefectService $defectService,
        protected OmIncidentService $incidentService,
        protected OmWorkOrderService $workOrderService
    ) {}

    /**
     * Mobile Field Operations Overview
     */
    public function fieldOverview(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewDashboard = (bool) $user?->can('om.dashboard.view');
        $canViewIncidents = $canViewDashboard
            || (bool) $user?->can('om.incidents.view')
            || (bool) $user?->can('om.incidents.manage');
        $canViewMaintenance = $canViewDashboard
            || (bool) $user?->can('om.maintenance.view')
            || (bool) $user?->can('om.maintenance.manage');

        $activeIncidents = $canViewIncidents
            ? OmIncident::whereIn('status', ['detected', 'dispatched', 'on_scene'])
                ->latest('reported_at')
                ->take(10)
                ->get()
            : collect();

        $assignedWorkOrders = $canViewMaintenance
            ? OmWorkOrder::whereIn('status', ['assigned', 'in_progress'])
                ->latest()
                ->take(10)
                ->get()
            : collect();

        $openDefects = $canViewMaintenance
            ? OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
                ->latest()
                ->take(10)
                ->get()
            : collect();

        $activePatrolShift = $canViewIncidents
            ? OmPatrolShift::where('status', 'in_progress')->latest()->first()
            : null;

        return response()->json([
            'success' => true,
            'data' => [
                'active_patrol_shift' => $activePatrolShift,
                'active_incidents' => $activeIncidents,
                'assigned_work_orders' => $assignedWorkOrders,
                'open_defects' => $openDefects,
                // Aliases for mobile components expecting short keys
                'incidents' => $activeIncidents,
                'work_orders' => $assignedWorkOrders,
                'defects' => $openDefects,
            ],
        ]);
    }

    /**
     * Mobile: Quick Log Roadway Defect with GPS Geotag
     */
    public function logDefect(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'distress_type' => 'required|string',
            'chainage' => 'required|string|max:50',
            'direction' => 'required|in:northbound,southbound,both,median,ramp',
            'severity' => 'required|in:low,medium,high,critical',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'description' => 'nullable|string',
            'before_photos' => 'nullable|array',
        ]);

        $defect = $this->defectService->createDefect($validated, $request->user()?->id);

        return response()->json([
            'success' => true,
            'message' => "Defect {$defect->defect_number} recorded via Mobile Field App.",
            'defect' => $defect,
        ]);
    }

    /**
     * Mobile: Update Incident On-Scene Status / Dispatch Triage
     */
    public function updateIncident(Request $request, int $id): JsonResponse
    {
        $incident = OmIncident::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:detected,dispatched,on_scene,cleared,closed',
            'dispatched_unit' => 'nullable|string',
            'description' => 'nullable|string',
            'lock_version' => 'sometimes|integer|min:0',
        ]);

        $incident = $this->incidentService->updateStatus(
            $incident,
            $validated['status'],
            $validated,
            isset($validated['lock_version']) ? (int) $validated['lock_version'] : null
        );

        return response()->json([
            'success' => true,
            'message' => "Incident {$incident->incident_number} updated to {$incident->status}.",
            'incident' => $incident,
        ]);
    }

    /**
     * Mobile: Work Order Progress & Evidence Upload
     */
    public function updateWorkOrder(Request $request, int $id): JsonResponse
    {
        $workOrder = OmWorkOrder::findOrFail($id);
        $validated = $request->validate([
            'action' => ['required', Rule::in(['start', 'complete'])],
            'actual_cost' => ['nullable', 'numeric', 'min:0'],
            'materials' => ['nullable', 'array'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
        ]);
        $action = $validated['action'];

        if ($action === 'start') {
            $workOrder = $this->workOrderService->startWorkOrder(
                $workOrder,
                isset($validated['lock_version']) ? (int) $validated['lock_version'] : null
            );
        } else {
            $workOrder = $this->workOrderService->completeWorkOrder(
                $workOrder,
                $validated,
                isset($validated['lock_version']) ? (int) $validated['lock_version'] : null
            );
        }

        return response()->json([
            'success' => true,
            'message' => "Work Order {$workOrder->work_order_number} status updated.",
            'work_order' => $workOrder,
        ]);
    }

    /**
     * Mobile: Full Incident Creation with GPS, Photos, Vehicle Details
     */
    public function createIncident(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'incident_type' => 'nullable|string',
            'detection_source' => 'nullable|string',
            'chainage' => 'required|string|max:50',
            'direction' => 'required|in:northbound,southbound,both',
            'severity' => 'required|in:minor,major,critical',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'dispatched_unit' => 'nullable|string',
            'description' => 'nullable|string',
            'casualties_fatalities' => 'nullable|integer',
            'casualties_injured' => 'nullable|integer',
            'vehicles_involved_count' => 'nullable|integer',
            'has_asset_damage' => 'nullable|boolean',
            'asset_damage_cost_est' => 'nullable|numeric',
            'police_case_number' => 'nullable|string',
            'vehicles' => 'nullable|array',
            'photo_paths' => 'nullable|array',
        ]);

        $incident = $this->incidentService->createIncident($validated, (string) $request->user()?->getKey());

        return response()->json([
            'success' => true,
            'message' => "Incident {$incident->incident_number} reported from Mobile Field App.",
            'incident' => $incident,
        ]);
    }

    /**
     * Mobile: Start Highway Patrol Shift with Initial Odometer & Route
     */
    public function startPatrol(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shift_type' => 'required|in:morning,evening,night',
            'vehicle_reg_number' => 'required|string|max:50',
            'call_sign' => 'nullable|string|max:50',
            'start_odometer_km' => 'required|numeric|min:0',
            'assigned_zone_from' => 'nullable|string|max:50',
            'assigned_zone_to' => 'nullable|string|max:50',
        ]);

        $patrolCode = 'PAT-'.date('Ymd').'-'.strtoupper(substr(uniqid(), -4));
        $shift = OmPatrolShift::create([
            'patrol_code' => $patrolCode,
            'patrol_date' => now()->toDateString(),
            'shift_type' => $validated['shift_type'],
            'vehicle_reg_number' => $validated['vehicle_reg_number'],
            'call_sign' => $validated['call_sign'] ?? null,
            'lead_officer_id' => $request->user()?->id,
            'assigned_zone_from' => $validated['assigned_zone_from'] ?? 'KM 00+000',
            'assigned_zone_to' => $validated['assigned_zone_to'] ?? 'KM 38+000',
            'start_odometer_km' => $validated['start_odometer_km'],
            'started_at' => now(),
            'status' => 'in_progress',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Patrol shift {$shift->patrol_code} started.",
            'patrol_shift' => $shift,
        ]);
    }

    /**
     * Mobile: End Highway Patrol Shift with Final Odometer & Shift Summary
     */
    public function endPatrol(Request $request, int $id): JsonResponse
    {
        $shift = OmPatrolShift::findOrFail($id);
        $validated = $request->validate([
            'end_odometer_km' => 'required|numeric|min:'.$shift->start_odometer_km,
            'shift_summary' => 'nullable|string',
        ]);

        $shift->update([
            'end_odometer_km' => $validated['end_odometer_km'],
            'shift_summary' => $validated['shift_summary'] ?? null,
            'ended_at' => now(),
            'status' => 'completed',
        ]);

        return response()->json([
            'success' => true,
            'message' => "Patrol shift {$shift->patrol_code} completed. Total distance: ".round($shift->end_odometer_km - $shift->start_odometer_km, 2).' km.',
            'patrol_shift' => $shift,
        ]);
    }
}

