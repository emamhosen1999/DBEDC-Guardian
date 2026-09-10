<?php

namespace App\Http\Controllers;

use App\Models\OmAsset;
use App\Models\OmAssetConditionSurvey;
use App\Models\OmDefect;
use App\Models\OmInspection;
use App\Models\OmSlaBreach;
use App\Models\OmTppdClaim;
use App\Models\OmWorkOrder;
use App\Services\Operations\OmAnalyticsService;
use App\Services\Operations\OmContractorService;
use App\Services\Operations\OmEnvironmentalService;
use App\Services\Operations\OmInspectionService;
use App\Services\Operations\OmInventoryIntegrationService;
use App\Services\Operations\OmIriProfilingService;
use App\Services\Operations\OmPavementDeteriorationService;
use App\Services\Operations\OmPreventiveMaintenanceService;
use App\Services\Operations\OmRcmService;
use App\Services\Operations\OmSafetyService;
use App\Services\Operations\OmSlaService;
use App\Services\Operations\OmToolboxService;
use App\Services\Operations\OmTppdService;
use App\Services\Operations\OmWimAnalysisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OmRenovationController extends Controller
{
    public function __construct(
        protected OmPreventiveMaintenanceService $pmService,
        protected OmInspectionService $inspectionService,
        protected OmSafetyService $safetyService,
        protected OmAnalyticsService $analyticsService,
        protected OmSlaService $slaService,
        protected OmContractorService $contractorService,
        protected OmEnvironmentalService $environmentalService,
        protected OmToolboxService $toolboxService,
        protected OmInventoryIntegrationService $inventoryService,
        protected OmTppdService $tppdService,
        protected OmIriProfilingService $iriService,
        protected OmWimAnalysisService $wimService,
        protected OmPavementDeteriorationService $pavementDeteriorationService,
        protected OmRcmService $rcmService
    ) {}

    // ───────────────────────────────────────────────
    // Preventive Maintenance Scheduler
    // ───────────────────────────────────────────────

    public function preventiveMaintenance(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['asset_category', 'frequency_type', 'status', 'search']);
        $schedules = $this->pmService->getSchedules($filters, 15);
        $stats = $this->pmService->getScheduleStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'stats' => $stats, 'schedules' => $schedules]);
        }

        return Inertia::render('Operations/PreventiveMaintenanceScheduler', [
            'schedules' => $schedules,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    public function storePreventiveSchedule(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'asset_category' => 'required|string',
            'asset_id' => 'nullable|exists:om_assets,id',
            'frequency_type' => 'required|in:daily,weekly,biweekly,monthly,quarterly,semi_annual,annual,condition_based',
            'frequency_interval_days' => 'nullable|integer|min:1',
            'priority' => 'required|in:low,medium,high,critical',
            'assigned_to' => 'nullable|string',
            'contractor_name' => 'nullable|string',
            'estimated_cost' => 'nullable|numeric',
            'estimated_duration_hours' => 'nullable|numeric',
            'chainage_from' => 'nullable|string',
            'chainage_to' => 'nullable|string',
            'direction' => 'nullable|string',
            'requires_lane_closure' => 'nullable|boolean',
            'checklist_items' => 'nullable|array',
            'required_materials' => 'nullable|array',
            'next_due_at' => 'nullable|date',
        ]);

        $schedule = $this->pmService->createSchedule($validated, $request->user()?->id);

        return $this->mutationResponse($request, "PM Schedule {$schedule->schedule_code} created successfully.", 'schedule', $schedule);
    }

    public function generatePmWorkOrders(Request $request): JsonResponse|RedirectResponse
    {
        $generated = $this->pmService->generateDueWorkOrders((string) $request->user()?->getKey());

        $count = count($generated);
        $message = $count > 0
            ? "{$count} preventive maintenance work orders generated."
            : 'No overdue PM schedules found.';

        return $this->mutationResponse($request, $message, 'generated_count', $count);
    }

    public function togglePmSchedule(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $schedule = \App\Models\OmPreventiveSchedule::findOrFail($id);
        $schedule = $this->pmService->toggleActive($schedule);

        $status = $schedule->is_active ? 'activated' : 'deactivated';

        return $this->mutationResponse($request, "PM Schedule {$schedule->schedule_code} {$status}.", 'schedule', $schedule);
    }

    // ───────────────────────────────────────────────
    // Inspection Checklists
    // ───────────────────────────────────────────────

    public function inspections(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['result', 'status', 'asset_id', 'template_id', 'search']);
        $inspections = $this->inspectionService->getInspections($filters, 15);
        $stats = $this->inspectionService->getInspectionStats();
        $templates = $this->inspectionService->getTemplates();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'stats' => $stats,
                'inspections' => $inspections,
                'templates' => $templates,
            ]);
        }

        return Inertia::render('Operations/InspectionChecklists', [
            'inspections' => $inspections,
            'stats' => $stats,
            'templates' => $templates,
            'filters' => $filters,
        ]);
    }

    public function storeInspectionTemplate(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'asset_category' => 'required|string',
            'checklist_sections' => 'required|array|min:1',
            'max_score' => 'nullable|integer|min:1',
            'pass_threshold' => 'nullable|integer|min:1',
            'auto_create_defect_on_fail' => 'nullable|boolean',
            'photo_required' => 'nullable|boolean',
        ]);

        $template = $this->inspectionService->createTemplate($validated, $request->user()?->id);

        return $this->mutationResponse($request, "Inspection Template {$template->template_code} created.", 'template', $template);
    }

    public function submitInspection(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'template_id' => 'nullable|exists:om_inspection_templates,id',
            'asset_id' => 'nullable|exists:om_assets,id',
            'preventive_schedule_id' => 'nullable|exists:om_preventive_schedules,id',
            'inspection_date' => 'nullable|date',
            'chainage' => 'nullable|string',
            'direction' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'checklist_responses' => 'nullable|array',
            'overall_notes' => 'nullable|string',
            'photo_paths' => 'nullable|array',
        ]);

        $inspection = $this->inspectionService->submitInspection($validated, $request->user()?->id);

        return $this->mutationResponse(
            $request,
            "Inspection {$inspection->inspection_number} submitted (Score: {$inspection->total_score}, Result: {$inspection->result}).",
            'inspection',
            $inspection
        );
    }

    public function reviewInspection(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $inspection = OmInspection::findOrFail($id);
        $validated = $request->validate(['notes' => 'nullable|string']);
        $inspection = $this->inspectionService->reviewInspection($inspection, (string) $request->user()?->getKey(), $validated['notes'] ?? null);

        return $this->mutationResponse($request, "Inspection {$inspection->inspection_number} reviewed.", 'inspection', $inspection);
    }

    // ───────────────────────────────────────────────
    // SLA Compliance Dashboard
    // ───────────────────────────────────────────────

    public function slaCompliance(Request $request): Response|JsonResponse
    {
        // Auto-check for new breaches
        $this->slaService->checkAndRecordBreaches();
        $dashboard = $this->slaService->getComplianceDashboard();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'dashboard' => $dashboard]);
        }

        return Inertia::render('Operations/SlaComplianceDashboard', [
            'dashboard' => $dashboard,
        ]);
    }

    public function acknowledgeSlaBreach(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $breach = OmSlaBreach::findOrFail($id);
        $validated = $request->validate(['notes' => 'nullable|string']);

        $breach = $this->slaService->acknowledgeBreach($breach, $request->user()?->id, $validated['notes'] ?? null);

        return $this->mutationResponse($request, "SLA breach for {$breach->entity_number} acknowledged.", 'breach', $breach);
    }

    // ───────────────────────────────────────────────
    // O&M Analytics & Reports
    // ───────────────────────────────────────────────

    public function analytics(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['period']);
        $data = $this->analyticsService->getDashboardAnalytics($filters);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'analytics' => $data]);
        }

        return Inertia::render('Operations/OmAnalytics', [
            'analytics' => $data,
            'filters' => $filters,
        ]);
    }

    // ───────────────────────────────────────────────
    // Asset Maintenance Timeline
    // ───────────────────────────────────────────────

    public function assetTimeline(Request $request, int $id): Response|JsonResponse
    {
        $asset = OmAsset::findOrFail($id);

        $timeline = collect();

        // Condition surveys
        $surveys = OmAssetConditionSurvey::where('asset_id', $id)->orderByDesc('survey_date')->get();
        foreach ($surveys as $s) {
            $timeline->push([
                'type' => 'survey',
                'date' => $s->survey_date,
                'title' => "Condition Survey — Score: {$s->condition_score} ({$s->condition_grade})",
                'details' => $s->findings,
                'id' => $s->id,
            ]);
        }

        // Defects
        $defects = OmDefect::where('asset_id', $id)->orderByDesc('created_at')->get();
        foreach ($defects as $d) {
            $timeline->push([
                'type' => 'defect',
                'date' => $d->created_at,
                'title' => "[{$d->defect_number}] {$d->title}",
                'details' => "Severity: {$d->severity} | Status: {$d->status} | SLA: {$d->sla_hours}h",
                'id' => $d->id,
            ]);
        }

        // Work Orders
        $workOrders = OmWorkOrder::where('asset_id', $id)->orderByDesc('created_at')->get();
        foreach ($workOrders as $wo) {
            $timeline->push([
                'type' => 'work_order',
                'date' => $wo->created_at,
                'title' => "[{$wo->work_order_number}] {$wo->title}",
                'details' => "Type: {$wo->work_type} | Status: {$wo->status} | Cost: ৳".number_format((float) $wo->actual_cost, 2),
                'id' => $wo->id,
            ]);
        }

        // Inspections
        $inspections = OmInspection::where('asset_id', $id)->orderByDesc('inspection_date')->get();
        foreach ($inspections as $insp) {
            $timeline->push([
                'type' => 'inspection',
                'date' => $insp->inspection_date,
                'title' => "[{$insp->inspection_number}] Score: {$insp->total_score} ({$insp->result})",
                'details' => $insp->overall_notes,
                'id' => $insp->id,
            ]);
        }

        // Sort by date descending
        $sortedTimeline = $timeline->sortByDesc('date')->values();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'success' => true,
                'asset' => $asset,
                'timeline' => $sortedTimeline,
            ]);
        }

        return Inertia::render('Operations/AssetTimeline', [
            'asset' => $asset,
            'timeline' => $sortedTimeline,
        ]);
    }

    // ───────────────────────────────────────────────
    // Safety Management
    // ───────────────────────────────────────────────

    public function safety(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['incident_type', 'severity', 'status', 'search']);
        $incidents = $this->safetyService->getSafetyIncidents($filters, 15);
        $stats = $this->safetyService->getSafetyStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'stats' => $stats, 'incidents' => $incidents]);
        }

        return Inertia::render('Operations/SafetyManagement', [
            'incidents' => $incidents,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    public function storeSafetyIncident(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'incident_type' => 'required|string',
            'severity' => 'required|in:negligible,minor,moderate,major,catastrophic',
            'location' => 'nullable|string',
            'chainage' => 'nullable|string',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'occurred_at' => 'nullable|date',
            'description' => 'nullable|string',
            'immediate_action_taken' => 'nullable|string',
            'persons_involved' => 'nullable|array',
            'photo_paths' => 'nullable|array',
            'ppe_worn' => 'nullable|boolean',
            'toolbox_talk_done' => 'nullable|boolean',
            'work_order_ref' => 'nullable|string',
        ]);

        $incident = $this->safetyService->reportIncident($validated, $request->user()?->id);

        return $this->mutationResponse($request, "Safety Incident {$incident->safety_number} reported.", 'incident', $incident);
    }

    public function updateSafetyStatus(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $incident = \App\Models\OmSafetyIncident::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:reported,investigating,corrective_action,closed',
            'root_cause' => 'nullable|string',
            'corrective_action' => 'nullable|string',
            'lost_time_hours' => 'nullable|integer',
        ]);

        $incident = $this->safetyService->updateStatus($incident, $validated['status'], $validated, $request->user()?->id);

        return $this->mutationResponse($request, "Safety Incident {$incident->safety_number} updated to {$validated['status']}.", 'incident', $incident);
    }

    // ───────────────────────────────────────────────
    // Phase 2: Work Order Interactive Calendar View
    // ───────────────────────────────────────────────

    public function workOrderCalendar(Request $request): Response|JsonResponse
    {
        $month = $request->input('month', now()->format('Y-m'));
        $startDate = \Carbon\Carbon::parse($month)->startOfMonth()->subDays(7);
        $endDate = \Carbon\Carbon::parse($month)->endOfMonth()->addDays(7);

        $workOrders = OmWorkOrder::query()
            ->where(function ($q) use ($startDate, $endDate) {
                $q->whereBetween('target_start_at', [$startDate, $endDate])
                    ->orWhereBetween('target_end_at', [$startDate, $endDate]);
            })
            ->with(['defect', 'asset'])
            ->get();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'month' => $month, 'work_orders' => $workOrders]);
        }

        return Inertia::render('Operations/WorkOrderCalendar', [
            'workOrders' => $workOrders,
            'currentMonth' => $month,
        ]);
    }

    // ───────────────────────────────────────────────
    // Phase 2: Contractor & Vendor Scorecards
    // ───────────────────────────────────────────────

    public function contractors(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['trade_specialty', 'status', 'search']);
        $contractors = $this->contractorService->getContractors($filters, 15);
        $stats = $this->contractorService->getContractorStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'stats' => $stats, 'contractors' => $contractors]);
        }

        return Inertia::render('Operations/ContractorScorecards', [
            'contractors' => $contractors,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    public function storeContractor(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'company_name' => 'required|string|max:255',
            'trade_specialty' => 'required|string|max:100',
            'contact_person' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:50',
            'contact_email' => 'nullable|email|max:255',
            'contract_reference' => 'nullable|string|max:100',
            'contract_start_date' => 'nullable|date',
            'contract_end_date' => 'nullable|date',
            'quality_score' => 'nullable|numeric|between:0,100',
            'status' => 'required|in:active,probation,blacklisted,inactive',
            'notes' => 'nullable|string',
        ]);

        $contractor = $this->contractorService->saveContractor($validated, $request->input('id'));

        return $this->mutationResponse($request, "Contractor {$contractor->company_name} saved.", 'contractor', $contractor);
    }

    // ───────────────────────────────────────────────
    // Phase 2: O&M Spare Parts & Materials Inventory
    // ───────────────────────────────────────────────

    public function inventory(Request $request): Response|JsonResponse
    {
        $overview = $this->inventoryService->getInventoryOverview();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'inventory' => $overview]);
        }

        return Inertia::render('Operations/OmSparePartsInventory', [
            'inventory' => $overview,
        ]);
    }

    public function logWorkOrderMaterial(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $workOrder = OmWorkOrder::findOrFail($id);
        $validated = $request->validate([
            'item_name' => 'required|string|max:255',
            'item_code' => 'nullable|string|max:50',
            'unit' => 'required|string|max:20',
            'quantity_used' => 'required|numeric|min:0.01',
            'unit_cost' => 'nullable|numeric|min:0',
            'inventory_item_id' => 'nullable|integer',
        ]);

        $material = $this->inventoryService->logMaterialConsumption($workOrder, $validated);

        return $this->mutationResponse($request, "Recorded {$material->quantity_used} {$material->unit} of {$material->item_name}.", 'material', $material);
    }

    // ───────────────────────────────────────────────
    // Phase 2: Environmental & Weather Monitoring
    // ───────────────────────────────────────────────

    public function environmental(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['monitoring_type', 'compliance_status', 'search']);
        $logs = $this->environmentalService->getLogs($filters, 15);
        $stats = $this->environmentalService->getEnvironmentalStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'stats' => $stats, 'logs' => $logs]);
        }

        return Inertia::render('Operations/EnvironmentalMonitoring', [
            'logs' => $logs,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    public function storeEnvironmentalLog(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'log_date' => 'required|date',
            'monitoring_type' => 'required|string',
            'location' => 'nullable|string|max:255',
            'chainage' => 'nullable|string|max:50',
            'direction' => 'nullable|string|max:20',
            'latitude' => 'nullable|numeric',
            'longitude' => 'nullable|numeric',
            'measured_value' => 'nullable|numeric',
            'measured_unit' => 'nullable|string|max:20',
            'regulatory_threshold' => 'nullable|numeric',
            'compliance_status' => 'required|in:compliant,minor_exceedance,critical_violation',
            'weather_condition' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'corrective_action_taken' => 'nullable|string',
            'photo_paths' => 'nullable|array',
        ]);

        $log = $this->environmentalService->createLog($validated, $request->user()?->id);

        return $this->mutationResponse($request, "Environmental Log {$log->log_code} recorded.", 'log', $log);
    }

    // ───────────────────────────────────────────────
    // Phase 2: Safety Toolbox Talks & Briefings
    // ───────────────────────────────────────────────

    public function toolboxTalks(Request $request): Response|JsonResponse
    {
        $talks = $this->toolboxService->getToolboxTalks($request->only('search'), 15);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'toolbox_talks' => $talks]);
        }

        return Inertia::render('Operations/ToolboxTalks', [
            'toolboxTalks' => $talks,
        ]);
    }

    public function storeToolboxTalk(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'topic' => 'required|string|max:255',
            'talk_date' => 'nullable|date',
            'work_order_id' => 'nullable|exists:om_work_orders,id',
            'chainage' => 'nullable|string|max:50',
            'attendees' => 'nullable|array',
            'ppe_verified' => 'nullable|boolean',
            'traffic_management_briefed' => 'nullable|boolean',
            'emergency_response_briefed' => 'nullable|boolean',
            'hazards_identified' => 'nullable|string',
            'photo_paths' => 'nullable|array',
        ]);

        $talk = $this->toolboxService->createToolboxTalk($validated, $request->user()?->id);

        return $this->mutationResponse($request, "Toolbox Talk {$talk->talk_code} saved.", 'toolbox_talk', $talk);
    }

    // ───────────────────────────────────────────────
    // Phase 2: QR Code / Barcode Asset Lookup
    // ───────────────────────────────────────────────

    public function lookupAsset(string $identifier): JsonResponse
    {
        $asset = OmAsset::where('asset_code', $identifier)
            ->orWhere('id', is_numeric($identifier) ? (int) $identifier : 0)
            ->with([
                'defects' => fn ($q) => $q->latest()->take(5),
                'workOrders' => fn ($q) => $q->latest()->take(5),
            ])
            ->first();

        if (! $asset) {
            return response()->json(['success' => false, 'message' => 'Asset not found for code: '.$identifier], 404);
        }

        return response()->json([
            'success' => true,
            'asset' => $asset,
        ]);
    }

    // ───────────────────────────────────────────────
    // Phase 3: Third-Party Property Damage (TPPD) Claims
    // ───────────────────────────────────────────────

    public function tppdClaims(Request $request): Response|JsonResponse
    {
        $filters = $request->only(['status', 'search']);
        $claims = $this->tppdService->getClaims($filters, 15);
        $stats = $this->tppdService->getClaimStats();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'stats' => $stats, 'claims' => $claims]);
        }

        return Inertia::render('Operations/TppdClaimsManagement', [
            'claims' => $claims,
            'stats' => $stats,
            'filters' => $filters,
        ]);
    }

    public function storeTppdClaim(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'incident_date' => 'required|date',
            'incident_id' => 'nullable|exists:om_incidents,id',
            'chainage' => 'nullable|string|max:50',
            'direction' => 'nullable|string|max:20',
            'vehicle_registration_number' => 'required|string|max:50',
            'driver_name' => 'nullable|string|max:255',
            'driver_license_number' => 'nullable|string|max:100',
            'insurance_company' => 'nullable|string|max:255',
            'insurance_policy_number' => 'nullable|string|max:100',
            'police_station' => 'nullable|string|max:100',
            'police_fir_number' => 'nullable|string|max:100',
            'damaged_components' => 'nullable|array',
            'estimated_repair_cost' => 'nullable|numeric|min:0',
            'claimed_amount' => 'nullable|numeric|min:0',
            'status' => 'required|in:drafted,submitted_police,submitted_insurance,settled,disputed,written_off',
            'notes' => 'nullable|string',
        ]);

        $claim = $this->tppdService->saveClaim($validated, $request->user()?->id, $request->input('id'));

        return $this->mutationResponse($request, "TPPD Claim {$claim->claim_number} saved successfully.", 'claim', $claim);
    }

    public function updateTppdStatus(Request $request, int $id): JsonResponse|RedirectResponse
    {
        $claim = OmTppdClaim::findOrFail($id);
        $validated = $request->validate([
            'status' => 'required|in:drafted,submitted_police,submitted_insurance,settled,disputed,written_off',
            'recovered_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $claim = $this->tppdService->updateStatus(
            $claim,
            $validated['status'],
            isset($validated['recovered_amount']) ? (float) $validated['recovered_amount'] : null,
            $validated['notes'] ?? null
        );

        return $this->mutationResponse($request, "TPPD Claim {$claim->claim_number} status updated to {$validated['status']}.", 'claim', $claim);
    }

    // ───────────────────────────────────────────────
    // Phase 3: Patrol Accelerometer IRI Heatmap
    // ───────────────────────────────────────────────

    public function iriHeatmap(Request $request): Response|JsonResponse
    {
        $direction = $request->input('direction', 'northbound');
        $profile = $this->iriService->getHeatmapProfile($direction);

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'profile' => $profile]);
        }

        return Inertia::render('Operations/IriRoughnessHeatmap', [
            'profile' => $profile,
            'direction' => $direction,
        ]);
    }

    public function storeIriTelemetry(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'patrol_shift_id' => 'nullable|integer',
            'readings' => 'required|array',
            'readings.*.chainage_km' => 'required|numeric',
            'readings.*.direction' => 'nullable|string',
            'readings.*.iri_value' => 'required|numeric',
            'readings.*.speed_kmh' => 'nullable|numeric',
            'readings.*.latitude' => 'nullable|numeric',
            'readings.*.longitude' => 'nullable|numeric',
        ]);

        $count = $this->iriService->recordTelemetryBatch(
            $validated['readings'],
            $validated['patrol_shift_id'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => "Logged {$count} IRI roughness telemetry samples.",
            'count' => $count,
        ]);
    }

    // ───────────────────────────────────────────────
    // Phase 3: WIM Fourth Power Law & Overload Fatigue
    // ───────────────────────────────────────────────

    public function wimOverloadAnalytics(Request $request): Response|JsonResponse
    {
        $analytics = $this->wimService->getWimFatigueOverview();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'analytics' => $analytics]);
        }

        return Inertia::render('Operations/WimOverloadAnalytics', [
            'analytics' => $analytics,
        ]);
    }

    // ───────────────────────────────────────────────
    // Phase 3: Markov Chain Pavement Deterioration Forecaster
    // ───────────────────────────────────────────────

    public function pavementDeterioration(Request $request): Response|JsonResponse
    {
        $forecast = $this->pavementDeteriorationService->getDeteriorationForecast();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'forecast' => $forecast]);
        }

        return Inertia::render('Operations/PavementDeteriorationForecast', [
            'forecast' => $forecast,
        ]);
    }

    // ───────────────────────────────────────────────
    // Phase 3: Reliability-Centered Maintenance (RCM) & MTBF
    // ───────────────────────────────────────────────

    public function rcmReliability(Request $request): Response|JsonResponse
    {
        $rcm = $this->rcmService->getRcmDashboard();

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['success' => true, 'rcm' => $rcm]);
        }

        return Inertia::render('Operations/ItsRcmReliability', [
            'rcm' => $rcm,
        ]);
    }

    // ───────────────────────────────────────────────
    // End Phase 3 Routes
    // ───────────────────────────────────────────────




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
