<?php

namespace App\Http\Controllers;

use App\Models\OmDefect;
use App\Models\OmEquipment;
use App\Models\OmIncident;
use App\Models\OmLaneClosurePermit;
use App\Models\OmShiftLog;
use App\Models\OmTrafficLog;
use App\Models\OmWorkOrder;
use App\Services\Operations\OmAssetService;
use App\Services\Operations\OmDefectService;
use App\Services\Operations\OmIncidentService;
use App\Services\Operations\OmLookupService;
use App\Services\Operations\OmShiftService;
use App\Services\Operations\OmVersionGuard;
use App\Services\Operations\OmWorkOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class OperationsMaintenanceController extends Controller
{
    public function __construct(
        protected OmAssetService $assetService,
        protected OmDefectService $defectService,
        protected OmWorkOrderService $workOrderService,
        protected OmIncidentService $incidentService,
        protected OmShiftService $shiftService,
        protected OmLookupService $lookupService
    ) {}

    /**
     * O&M Command Center Dashboard Overview
     */
    public function dashboard(Request $request): Response|JsonResponse
    {
        $equipmentUptime = OmEquipment::avg('uptime_pct');
        $stats = [
            'total_defects_count' => OmDefect::count(),
            'open_defects_count' => OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])->count(),
            'rectified_defects_count' => OmDefect::whereIn('status', ['rectified', 'verified_closed'])->count(),
            'sla_overdue_count' => OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
                ->where('sla_due_at', '<', now())
                ->count(),
            'active_incidents_count' => OmIncident::whereIn('status', ['detected', 'dispatched', 'on_scene'])->count(),
            'open_work_orders_count' => OmWorkOrder::whereIn('status', ['pending', 'assigned', 'in_progress'])->count(),
            'active_lane_closures_count' => OmLaneClosurePermit::where('status', 'active')->count(),
            'equipment_uptime_pct' => $equipmentUptime === null ? 99.2 : round((float) $equipmentUptime, 2),
            'avg_patrol_response_min' => $this->incidentService->getIncidentStats()['avg_response_time_min'],
        ];

        $recentIncidents = OmIncident::with('vehicles')->latest('reported_at')->take(5)->get();
        $trafficFlowSections = OmTrafficLog::latest('recorded_at')->take(4)->get();
        $recentWorkOrders = OmWorkOrder::with(['defect', 'materials', 'laneClosurePermit'])->latest()->take(5)->get();
        $recentDefects = OmDefect::latest()->take(10)->get();
        $activeLaneClosures = OmLaneClosurePermit::whereIn('status', ['requested', 'approved', 'active'])->latest()->take(5)->get();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'recent_incidents' => $recentIncidents,
                'traffic_sections' => $trafficFlowSections,
                'recent_work_orders' => $recentWorkOrders,
                'recent_defects' => $recentDefects,
                'active_lane_closures' => $activeLaneClosures,
            ]);
        }

        return Inertia::render('Operations/OmDashboard', [
            'stats' => $stats,
            'recentIncidents' => $recentIncidents,
            'trafficSections' => $trafficFlowSections,
            'recentWorkOrders' => $recentWorkOrders,
            'recentDefects' => $recentDefects,
            'activeLaneClosures' => $activeLaneClosures,
        ]);
    }

    /**
     * Defects & Roadway Distress Management Page
     */
    public function defects(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['status', 'severity', 'distress_type', 'direction', 'search']);
        $defects = $this->defectService->getDefects($filters, 20);
        $stats = $this->defectService->getDefectStats();
        $lookups = $this->lookupService->getGroupedLookups();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'defects' => $defects,
                'lookups' => $lookups,
            ]);
        }

        return Inertia::render('Operations/DefectsManagement', [
            'defects' => $defects,
            'stats' => $stats,
            'filters' => $filters,
            'lookups' => $lookups,
        ]);
    }

    /**
     * Store new Road Distress Defect
     */
    public function storeDefect(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'distress_type' => 'required|string|max:100',
            'chainage' => 'required|string|max:50',
            'direction' => 'nullable|string|max:50',
            'location_carriageway' => 'nullable|string|max:100',
            'severity' => 'required|string|max:50',
            'sla_hours' => 'nullable|integer|min:1',
            'target_repair_date' => 'nullable|date',
            'responsible_party' => 'nullable|string|max:100',
            'recommended_action' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'asset_id' => 'nullable|exists:om_assets,id',
            'before_photos' => 'nullable|array',
        ]);

        $defect = $this->defectService->createDefect($validated, $request->user()?->id);

        return $this->mutationResponse(
            $request,
            "Defect {$defect->defect_number} logged successfully with {$defect->sla_hours}h SLA.",
            'defect',
            $defect
        );
    }

    /**
     * Daily Road Maintenance Monitoring Report (CEO Format parity)
     */
    public function dailyMaintenanceReport(Request $request): Response|JsonResponse
    {
        $today = now()->toDateString();
        $date = $request->get('date', $today);

        // Daily counts
        $newToday = OmDefect::whereDate('created_at', $date)->count();
        $repairedToday = OmDefect::whereDate('rectified_at', $date)->count();
        $totalOpen = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])->count();

        // Severity breakdown
        $criticalOpen = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
            ->where('severity', 'critical')
            ->count();
        $majorOpen = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
            ->where('severity', 'high')
            ->count();
        $moderateOpen = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
            ->where('severity', 'medium')
            ->count();
        $minorOpen = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
            ->where('severity', 'low')
            ->count();

        $defects = OmDefect::with(['reporter', 'verifier'])
            ->orderBy('id', 'desc')
            ->take(100)
            ->get();

        $reportSummary = [
            'report_date' => $date,
            'section_opened' => 'K-4+000 to K-22+000, N-105 Dhaka Bypass Expressway',
            'inspection_team' => 'SE: Prodip - Habib (QC & Highway Patrol)',
            'weather' => 'Partly Cloudy / Fair',
            'traffic_condition' => 'Normal Corridor Flow, Morning Peak near K-9 Kanchan',
            'overall_pavement_condition' => 'Fair to Good',
            'new_defects_today' => $newToday,
            'repaired_today' => $repairedToday,
            'cumulative_open_defects' => $totalOpen,
            'open_by_severity' => [
                'critical' => $criticalOpen,
                'major' => $majorOpen,
                'moderate' => $moderateOpen,
                'minor' => $minorOpen,
            ],
            'reported_to_ceo' => '09:15 AM - Executive Briefing Dossier',
            'report_prepared_by' => $request->user()?->name ?? 'Md. Habibur Rahman',
        ];

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'summary' => $reportSummary,
                'defects' => $defects,
            ]);
        }

        return Inertia::render('Operations/DailyMaintenanceReport', [
            'summary' => $reportSummary,
            'defects' => $defects,
            'date' => $date,
        ]);
    }

    /**
     * Convert Defect to Maintenance Work Order
     */
    public function convertDefectToWorkOrder(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $defect = OmDefect::findOrFail($id);
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'work_type' => 'nullable|string',
            'priority' => 'nullable|string',
            'assigned_to' => 'nullable|string',
            'contractor_name' => 'nullable|string',
            'estimated_cost' => 'nullable|numeric',
            'requires_lane_closure' => 'nullable|boolean',
            'lock_version' => 'required|integer|min:0',
        ]);

        $wo = $this->defectService->convertToWorkOrder(
            $defect,
            $validated,
            (string) $request->user()->getKey(),
            (int) $validated['lock_version']
        );

        return $this->mutationResponse(
            $request,
            "Work Order {$wo->work_order_number} generated from Defect {$defect->defect_number}.",
            'work_order',
            $wo
        );
    }

    /**
     * Routine, Preventive & Emergency Maintenance Work Orders Page
     */
    public function workOrders(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['status', 'priority', 'category', 'work_type', 'search']);
        $workOrders = $this->workOrderService->getWorkOrders($filters, 15);
        $stats = $this->workOrderService->getWorkOrderStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'work_orders' => $workOrders,
            ]);
        }

        return Inertia::render('Operations/MaintenanceWorkOrders', [
            'workOrders' => $workOrders,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    /**
     * Store new Work Order
     */
    public function storeWorkOrder(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'work_type' => 'nullable|string',
            'category' => 'required|in:pavement,guardrail,lighting,drainage,bridge,signage',
            'location' => 'required|string|max:100',
            'priority' => 'required|in:low,medium,high,emergency',
            'assigned_to' => 'nullable|string',
            'contractor_name' => 'nullable|string',
            'description' => 'nullable|string',
            'estimated_cost' => 'nullable|numeric',
            'requires_lane_closure' => 'nullable|boolean',
            'materials' => 'nullable|array',
            'lane_closure' => 'nullable|array',
        ]);

        $wo = $this->workOrderService->createWorkOrder($validated, (string) $request->user()->getKey());

        return $this->mutationResponse(
            $request,
            "Maintenance Work Order {$wo->work_order_number} issued successfully.",
            'work_order',
            $wo
        );
    }

    /**
     * Approve Work Order
     */
    public function approveWorkOrder(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $wo = OmWorkOrder::findOrFail($id);
        $validated = $request->validate(['lock_version' => 'required|integer|min:0']);
        $wo = $this->workOrderService->approveWorkOrder(
            $wo,
            (string) $request->user()->getKey(),
            (int) $validated['lock_version']
        );

        return $this->mutationResponse(
            $request,
            "Work Order {$wo->work_order_number} approved and dispatched to crew.",
            'work_order',
            $wo
        );
    }

    /**
     * Start Work Order
     */
    public function startWorkOrder(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $wo = OmWorkOrder::findOrFail($id);
        $validated = $request->validate(['lock_version' => 'required|integer|min:0']);
        $wo = $this->workOrderService->startWorkOrder($wo, (int) $validated['lock_version']);

        return $this->mutationResponse(
            $request,
            "Work Order {$wo->work_order_number} is now IN PROGRESS with active safety zone.",
            'work_order',
            $wo
        );
    }

    /**
     * Complete Work Order (Submit for QC Verification)
     */
    public function completeWorkOrder(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $wo = OmWorkOrder::findOrFail($id);
        $validated = $request->validate([
            'actual_cost' => 'nullable|numeric',
            'materials' => 'nullable|array',
            'lock_version' => 'required|integer|min:0',
        ]);

        $wo = $this->workOrderService->completeWorkOrder($wo, $validated, (int) $validated['lock_version']);

        return $this->mutationResponse(
            $request,
            "Work Order {$wo->work_order_number} marked COMPLETED. Pending QC verification.",
            'work_order',
            $wo
        );
    }

    /**
     * Verify and Sign Off Work Order (QA/QC Close)
     */
    public function verifyWorkOrder(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $wo = OmWorkOrder::findOrFail($id);
        $validated = $request->validate([
            'qc_notes' => 'nullable|string',
            'lock_version' => 'required|integer|min:0',
        ]);

        $wo = $this->workOrderService->verifyAndClose(
            $wo,
            (string) $request->user()->getKey(),
            $validated['qc_notes'] ?? null,
            (int) $validated['lock_version']
        );

        return $this->mutationResponse(
            $request,
            "Work Order {$wo->work_order_number} verified and closed successfully.",
            'work_order',
            $wo
        );
    }

    /**
     * Incidents & Emergency Patrol Dispatch Page
     */
    public function incidents(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['status', 'severity', 'incident_type', 'direction', 'search']);
        $incidents = $this->incidentService->getIncidents($filters, 15);
        $metrics = $this->incidentService->getIncidentStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'metrics' => $metrics,
                'incidents' => $incidents,
            ]);
        }

        return Inertia::render('Operations/IncidentsPatrol', [
            'metrics' => $metrics,
            'incidents' => $incidents,
            'filters' => $filters,
        ]);
    }

    /**
     * Store new Incident & Dispatch Patrol
     */
    public function storeIncident(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'incident_type' => 'nullable|string',
            'detection_source' => 'nullable|string',
            'chainage' => 'required|string|max:50',
            'direction' => 'required|in:northbound,southbound,both',
            'severity' => 'required|in:minor,major,critical',
            'dispatched_unit' => 'nullable|string',
            'description' => 'nullable|string',
            'casualties_fatalities' => 'nullable|integer',
            'casualties_injured' => 'nullable|integer',
            'vehicles_involved_count' => 'nullable|integer',
            'has_asset_damage' => 'nullable|boolean',
            'asset_damage_cost_est' => 'nullable|numeric',
            'police_case_number' => 'nullable|string',
            'vehicles' => 'nullable|array',
        ]);

        $incident = $this->incidentService->createIncident($validated, (string) $request->user()->getKey());

        return $this->mutationResponse(
            $request,
            "Incident {$incident->incident_number} logged and patrol units dispatched.",
            'incident',
            $incident
        );
    }

    /**
     * Update Incident Status
     */
    public function updateIncidentStatus(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $incident = OmIncident::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:detected,dispatched,on_scene,cleared,closed',
            'description' => 'nullable|string',
            'dispatched_unit' => 'nullable|string',
            'lock_version' => 'required|integer|min:0',
        ]);

        $incident = $this->incidentService->updateStatus(
            $incident,
            $validated['status'],
            $validated,
            (int) $validated['lock_version']
        );

        return $this->mutationResponse(
            $request,
            "Incident {$incident->incident_number} updated to {$validated['status']}.",
            'incident',
            $incident
        );
    }

    /**
     * Create Post-Incident Damage Work Order
     */
    public function createIncidentDamageWorkOrder(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $incident = OmIncident::findOrFail($id);
        $validated = $request->validate(['lock_version' => 'required|integer|min:0']);
        $wo = $this->incidentService->createDamageRepairWorkOrder(
            $incident,
            (string) $request->user()->getKey(),
            (int) $validated['lock_version']
        );

        return $this->mutationResponse(
            $request,
            "TPPD Emergency Repair Work Order {$wo->work_order_number} initiated.",
            'work_order',
            $wo
        );
    }

    /**
     * Linear Asset Inventory & Infrastructure Health Page
     */
    public function assets(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['category', 'status', 'condition', 'direction', 'search']);
        $assets = $this->assetService->getAssets($filters, 15);
        $stats = $this->assetService->getAssetStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'assets' => $assets,
            ]);
        }

        return Inertia::render('Operations/AssetInventory', [
            'assets' => $assets,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    /**
     * Store new Asset
     */
    public function storeAsset(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|string',
            'start_chainage' => 'required|string|max:50',
            'end_chainage' => 'nullable|string|max:50',
            'direction' => 'required|in:northbound,southbound,both,median,interchange,toll_plaza',
            'location_description' => 'nullable|string',
            'purchase_cost' => 'nullable|numeric',
            'replacement_cost' => 'nullable|numeric',
            'expected_lifespan_years' => 'nullable|integer',
            'condition_score' => 'nullable|integer',
            'condition_grade' => 'nullable|string',
            'operational_status' => 'nullable|string',
            'technical_specs' => 'nullable|string',
        ]);

        $asset = $this->assetService->createAsset($validated);

        return $this->mutationResponse(
            $request,
            "Asset {$asset->asset_code} registered in expressway inventory.",
            'asset',
            $asset
        );
    }

    /**
     * Traffic Monitoring Center (TMC / ITS) Page
     */
    public function trafficMonitoring(Request $request): Response|JsonResponse
    {
        $trafficSections = OmTrafficLog::latest('recorded_at')->get();
        $overloadAlerts = OmTrafficLog::where('overload_count', '>', 0)->latest()->get();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'traffic_sections' => $trafficSections,
                'overload_alerts' => $overloadAlerts,
            ]);
        }

        return Inertia::render('Operations/TrafficMonitoring', [
            'trafficSections' => $trafficSections,
            'overloadAlerts' => $overloadAlerts,
        ]);
    }

    /**
     * Equipment & Facilities Hardware Status Page
     */
    public function equipment(Request $request): Response|JsonResponse
    {
        $equipment = OmEquipment::all();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'equipment' => $equipment,
            ]);
        }

        return Inertia::render('Operations/EquipmentFacilities', [
            'equipment' => $equipment,
        ]);
    }

    /**
     * Digital Shift Handover Logs Page
     */
    public function shiftLogs(Request $request): Response|JsonResponse
    {
        $shiftLogs = $this->shiftService->getShiftLogs(15);
        $activeMetrics = $this->shiftService->getCurrentShiftMetrics();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'shift_logs' => $shiftLogs,
                'active_metrics' => $activeMetrics,
            ]);
        }

        return Inertia::render('Operations/ShiftHandoverLogs', [
            'shiftLogs' => $shiftLogs,
            'activeMetrics' => $activeMetrics,
        ]);
    }

    /**
     * Store new Shift Handover Record
     */
    public function storeShiftLog(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'shift_date' => 'required|date',
            'shift_type' => 'required|in:morning,evening,night',
            'weather_condition' => 'nullable|in:clear,rain,heavy_fog,storm_high_winds',
            'handover_notes' => 'required|string',
            'equipment_exceptions' => 'nullable|string',
            'incoming_operator_id' => 'nullable|exists:users,employee_id',
        ]);

        $log = $this->shiftService->createShiftLog($validated, (string) $request->user()->getKey());

        return $this->mutationResponse(
            $request,
            "Shift Handover {$log->shift_code} logged successfully.",
            'shift_log',
            $log
        );
    }

    /**
     * Acknowledge / Dual Sign-off Shift Log
     */
    public function acknowledgeShiftLog(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $log = OmShiftLog::findOrFail($id);
        $validated = $request->validate(['lock_version' => 'required|integer|min:0']);
        $log = $this->shiftService->acknowledgeShiftLog(
            $log,
            (string) $request->user()->getKey(),
            (int) $validated['lock_version']
        );

        return $this->mutationResponse(
            $request,
            "Shift Handover {$log->shift_code} acknowledged and signed off.",
            'shift_log',
            $log
        );
    }

    private function mutationResponse(
        Request $request,
        string $message,
        string $resourceKey,
        mixed $resource
    ): JsonResponse|RedirectResponse {
        if ($request->header('X-Inertia')) {
            return back()->with('success', $message);
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            $resourceKey => $resource,
        ]);
    }
}
