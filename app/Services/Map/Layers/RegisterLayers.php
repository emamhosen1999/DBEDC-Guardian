<?php

namespace App\Services\Map\Layers;

use App\Services\Access\DepartmentScope;
use App\Services\Map\MapLayer;
use App\Support\Corridor\Chainage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The register-backed layers (Daily Works & Quality, O&M, Safety) as declarative TableLayer configs.
 * Registers that are not employee-owned (assets, defects, incidents, ...) are governed by their module
 * permission alone, as the dashboard widgets are; Daily Works follow DepartmentScope like DailyWorksSummary.
 */
final class RegisterLayers
{
    /** Statuses that mean the item is finished, per register (an "open" layer excludes them). */
    private const DONE = ['completed', 'complete', 'closed', 'cleared', 'resolved', 'rectified', 'verified', 'cancelled', 'rejected', 'withdrawn', 'done', 'approved_closed'];

    /** @return array<int, MapLayer> */
    public static function make(DepartmentScope $scope): array
    {
        $notDone = fn (string $column = 'status'): \Closure => fn (Builder $q) => $q->whereNotIn($column, self::DONE);
        $title = fn (string ...$columns): \Closure => fn (object $r): string => (string) collect($columns)->map(fn ($c) => $r->{$c} ?? null)->filter()->first();
        $span = fn (string $column): \Closure => fn (object $r): ?array => Chainage::span($r->{$column} ?? null);
        $tones = fn (string $column, array $map): \Closure => fn (object $r): string => $map[$r->{$column} ?? ''] ?? 'theme';
        $severity = ['critical' => 'crit', 'high' => 'crit', 'major' => 'crit', 'medium' => 'warn', 'moderate' => 'warn', 'low' => 'info', 'minor' => 'info'];
        $ucfirst = fn (?string $v): ?string => $v === null ? null : ucfirst(str_replace('_', ' ', $v));
        $when = fn (?string $v): ?string => $v === null ? null : substr($v, 0, 16);

        $defs = [
            // ── Daily Works & Quality ─────────────────────────────
            [
                'key' => 'works.daily_works', 'label' => 'Daily works', 'group' => 'works', 'geometry' => [MapLayer::CHAINAGE_POINT, MapLayer::CHAINAGE_BAND],
                'permissions' => ['daily-works.view'], 'tables' => ['daily_works'], 'icon' => 'hammer', 'route' => 'daily-works-unified',
                'fields' => ['number' => 'Work', 'type' => 'Type', 'status' => 'Status', 'date' => 'Date', 'incharge' => 'In charge'],
                'period' => 'date', 'search' => ['number', 'location', 'type'],
                'scope' => fn (Builder $q, $viewer, DepartmentScope $s) => $s->isGlobal($viewer) ? $q : $q->where(fn (Builder $w) => $w->whereIn('incharge', $s->visibleUserIdsQuery($viewer))->orWhereIn('assigned', $s->visibleUserIdsQuery($viewer))),
                'span' => $span('location'), 'title' => $title('number'),
                'values' => fn (object $r): array => ['number' => $r->number, 'type' => $r->type, 'status' => $ucfirst($r->status), 'date' => substr((string) $r->date, 0, 10), 'incharge' => $r->incharge],
                'row_tone' => $tones('status', ['completed' => 'good', 'emergency' => 'crit', 'resubmission' => 'warn', 'new' => 'info']),
            ],
            [
                'key' => 'works.rfi_objections', 'label' => 'RFI objections', 'group' => 'works', 'geometry' => [MapLayer::CHAINAGE_BAND],
                'permissions' => ['daily-works.view'], 'tables' => ['rfi_objections'], 'icon' => 'exclamation-diamond', 'route' => 'daily-works-unified', 'tone' => 'warn',
                'fields' => ['title' => 'Objection', 'category' => 'Category', 'status' => 'Status'],
                'query' => fn () => DB::table('rfi_objections')->whereNull('deleted_at')->whereNotNull('chainage_from'),
                'open' => $notDone(), 'search' => ['title', 'category'],
                'span' => fn (object $r): ?array => ($a = Chainage::parse($r->chainage_from)) === null ? null : ['from' => $a, 'to' => Chainage::parse($r->chainage_to) ?? $a],
                'title' => $title('title'),
                'values' => fn (object $r): array => ['title' => $r->title, 'category' => $ucfirst($r->category), 'status' => $ucfirst($r->status)],
            ],
            [
                'key' => 'works.objection_chainages', 'label' => 'Objected chainages', 'group' => 'works', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['daily-works.view'], 'tables' => ['objection_chainages'], 'icon' => 'pin-map', 'route' => 'daily-works-unified', 'tone' => 'warn',
                'fields' => ['objection' => 'Objection', 'status' => 'Status', 'entry' => 'Entry'],
                'query' => fn () => DB::table('objection_chainages as oc')->join('rfi_objections as o', 'o.id', '=', 'oc.objection_id')->whereNull('o.deleted_at')
                    ->select('oc.id', 'oc.chainage_meters', 'oc.entry_type', 'o.title as objection', 'o.status as status'),
                'open' => fn (Builder $q) => $q->whereNotIn('o.status', self::DONE), 'search' => ['o.title'],
                'span' => fn (object $r): ?array => Chainage::span($r->chainage_meters),
                'title' => fn (object $r): string => (string) $r->objection,
                'values' => fn (object $r): array => ['objection' => $r->objection, 'status' => $ucfirst($r->status), 'entry' => $ucfirst($r->entry_type)],
            ],
            [
                'key' => 'works.site_instructions', 'label' => 'Site instructions', 'group' => 'works', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['daily-works.view'], 'tables' => ['site_instructions'], 'icon' => 'clipboard-check', 'tone' => 'info',
                'fields' => ['si' => 'Instruction', 'category' => 'Category', 'status' => 'Status', 'issued' => 'Issued', 'summary' => 'Summary'],
                'query' => fn () => DB::table('site_instructions')->whereNotNull('chainage_meters'),
                'open' => fn (Builder $q) => $q->where('status', 'open'), 'search' => ['si_number', 'summary', 'location'],
                'span' => fn (object $r): ?array => Chainage::span($r->chainage_meters),
                'title' => $title('si_number'),
                'values' => fn (object $r): array => ['si' => $r->si_number, 'category' => $r->category, 'status' => $ucfirst($r->status), 'issued' => $r->issued_date ? substr((string) $r->issued_date, 0, 10) : null, 'summary' => $r->summary ? mb_substr((string) $r->summary, 0, 140) : null],
            ],
            [
                'key' => 'works.calibrations', 'label' => 'Calibrations', 'group' => 'works', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['quality.calibrations.view'], 'tables' => ['quality_calibrations'], 'icon' => 'speedometer2', 'tone' => 'info',
                'fields' => ['equipment' => 'Equipment', 'due' => 'Next calibration', 'status' => 'Status'],
                'query' => fn () => DB::table('quality_calibrations')->whereNull('deleted_at')->whereNotNull('location'),
                'search' => ['equipment_name', 'location'], 'span' => $span('location'),
                'title' => $title('equipment_name'),
                'values' => fn (object $r): array => ['equipment' => $r->equipment_name, 'due' => $r->next_calibration_date ? substr((string) $r->next_calibration_date, 0, 10) : null, 'status' => $ucfirst($r->status)],
            ],
            // ── O&M ───────────────────────────────────────────────
            [
                'key' => 'om.assets', 'label' => 'Assets', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.equipment.view'], 'tables' => ['om_assets'], 'icon' => 'building', 'route' => 'om.assets', 'tone' => 'info',
                'fields' => ['code' => 'Code', 'category' => 'Category', 'condition' => 'Condition', 'status' => 'Status', 'where' => 'Location'],
                'coords' => ['latitude', 'longitude'], 'search' => ['asset_code', 'name'],
                'span' => fn (object $r): ?array => ($a = Chainage::parse($r->start_chainage)) === null ? null : ['from' => $a, 'to' => Chainage::parse($r->end_chainage) ?? $a],
                'title' => $title('name'),
                'values' => fn (object $r): array => ['code' => $r->asset_code, 'category' => $ucfirst($r->category), 'condition' => $r->condition_score !== null ? $r->condition_score.'/100 ('.$ucfirst($r->condition_grade).')' : null, 'status' => $ucfirst($r->operational_status), 'where' => $r->location_description],
                'row_tone' => $tones('operational_status', ['active' => 'good', 'under_maintenance' => 'warn', 'out_of_service' => 'crit']),
            ],
            [
                'key' => 'om.defects', 'label' => 'Defects', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT, MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.maintenance.view'], 'tables' => ['om_defects'], 'icon' => 'cone-striped', 'route' => 'om.defects', 'tone' => 'warn',
                'fields' => ['number' => 'Defect', 'type' => 'Distress', 'severity' => 'Severity', 'status' => 'Status', 'direction' => 'Direction', 'due' => 'SLA due'],
                'coords' => ['latitude', 'longitude'], 'open' => $notDone(), 'search' => ['defect_number', 'title', 'distress_type'],
                'span' => $span('chainage'), 'title' => $title('title', 'defect_number'),
                'values' => fn (object $r): array => ['number' => $r->defect_number, 'type' => $r->distress_type, 'severity' => $ucfirst($r->severity), 'status' => $ucfirst($r->status), 'direction' => $ucfirst($r->direction), 'due' => $when($r->sla_due_at)],
                'row_tone' => $tones('severity', $severity),
            ],
            [
                'key' => 'om.incidents', 'label' => 'Incidents', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.incidents.view'], 'tables' => ['om_incidents'], 'icon' => 'exclamation-triangle', 'route' => 'om.incidents', 'tone' => 'crit',
                'fields' => ['number' => 'Incident', 'type' => 'Type', 'severity' => 'Severity', 'status' => 'Status', 'reported' => 'Reported', 'unit' => 'Dispatched unit'],
                'coords' => ['latitude', 'longitude'], 'open' => $notDone(), 'search' => ['incident_number', 'title'],
                'span' => $span('chainage'), 'title' => $title('title', 'incident_number'),
                'values' => fn (object $r): array => ['number' => $r->incident_number, 'type' => $ucfirst($r->incident_type), 'severity' => $ucfirst($r->severity), 'status' => $ucfirst($r->status), 'reported' => $when($r->reported_at), 'unit' => $r->dispatched_unit],
                'row_tone' => $tones('severity', $severity),
            ],
            [
                'key' => 'om.inspections', 'label' => 'Inspections', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.maintenance.view'], 'tables' => ['om_inspections'], 'icon' => 'search', 'route' => 'om.pm', 'tone' => 'info',
                'fields' => ['number' => 'Inspection', 'date' => 'Date', 'result' => 'Result', 'score' => 'Score'],
                'coords' => ['latitude', 'longitude'], 'period' => 'inspection_date', 'search' => ['inspection_number'],
                'span' => $span('chainage'), 'title' => $title('inspection_number'),
                'values' => fn (object $r): array => ['number' => $r->inspection_number, 'date' => substr((string) $r->inspection_date, 0, 10), 'result' => $ucfirst($r->result), 'score' => $r->total_score],
            ],
            [
                'key' => 'om.iri', 'label' => 'Roughness (IRI)', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.maintenance.view'], 'tables' => ['om_iri_readings'], 'icon' => 'activity', 'tone' => 'info',
                'fields' => ['iri' => 'IRI', 'band' => 'Condition', 'speed' => 'Speed', 'at' => 'Recorded'],
                'coords' => ['latitude', 'longitude'], 'period' => 'recorded_at',
                'span' => fn (object $r): ?array => ($m = Chainage::fromKm($r->chainage_km)) === null ? null : ['from' => $m, 'to' => $m],
                'title' => fn (object $r): string => 'IRI '.$r->iri_value,
                'values' => fn (object $r): array => ['iri' => $r->iri_value, 'band' => $ucfirst($r->condition_band), 'speed' => $r->speed_kmh !== null ? $r->speed_kmh.' km/h' : null, 'at' => $when($r->recorded_at)],
            ],
            [
                'key' => 'om.ai_detections', 'label' => 'AI detections', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.ai.manage'], 'tables' => ['om_ai_detections'], 'icon' => 'cpu', 'tone' => 'warn',
                'fields' => ['code' => 'Detection', 'type' => 'Distress', 'confidence' => 'Confidence', 'severity' => 'Severity', 'status' => 'Status'],
                'coords' => ['latitude', 'longitude'], 'open' => fn (Builder $q) => $q->whereNotIn('status', ['dismissed', 'converted', ...self::DONE]),
                'span' => $span('chainage'), 'title' => $title('detection_code'),
                'values' => fn (object $r): array => ['code' => $r->detection_code, 'type' => $r->distress_type, 'confidence' => $r->confidence_score !== null ? round((float) $r->confidence_score * ((float) $r->confidence_score <= 1 ? 100 : 1)).'%' : null, 'severity' => $ucfirst($r->severity), 'status' => $ucfirst($r->status)],
                'row_tone' => $tones('severity', $severity),
            ],
            [
                'key' => 'om.environmental', 'label' => 'Environmental logs', 'group' => 'om', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.safety.view'], 'tables' => ['om_environmental_logs'], 'icon' => 'tree', 'tone' => 'good',
                'fields' => ['type' => 'Monitoring', 'value' => 'Measured', 'status' => 'Compliance', 'date' => 'Date'],
                'coords' => ['latitude', 'longitude'], 'period' => 'log_date',
                'span' => $span('chainage'), 'title' => $title('log_code', 'monitoring_type'),
                'values' => fn (object $r): array => ['type' => $ucfirst($r->monitoring_type), 'value' => $r->measured_value !== null ? $r->measured_value.' '.$r->measured_unit : null, 'status' => $ucfirst($r->compliance_status), 'date' => substr((string) $r->log_date, 0, 10)],
                'row_tone' => $tones('compliance_status', ['compliant' => 'good', 'non_compliant' => 'crit', 'warning' => 'warn']),
            ],
            [
                'key' => 'om.lane_closures', 'label' => 'Lane closures', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.maintenance.view'], 'tables' => ['om_lane_closure_permits'], 'icon' => 'sign-stop', 'route' => 'om.work-orders', 'tone' => 'warn',
                'fields' => ['number' => 'Permit', 'lanes' => 'Lanes closed', 'direction' => 'Direction', 'status' => 'Status', 'start' => 'From', 'end' => 'Until'],
                'open' => fn (Builder $q) => $q->whereIn('status', ['active', 'approved', 'scheduled', 'pending']),
                'span' => fn (object $r): ?array => ($a = Chainage::parse($r->chainage_from)) === null ? null : ['from' => $a, 'to' => Chainage::parse($r->chainage_to) ?? $a],
                'title' => $title('title', 'permit_number'),
                'values' => fn (object $r): array => ['number' => $r->permit_number, 'lanes' => $r->lanes_closed, 'direction' => $ucfirst($r->direction), 'status' => $ucfirst($r->status), 'start' => $when($r->scheduled_start), 'end' => $when($r->scheduled_end)],
            ],
            [
                'key' => 'om.preventive', 'label' => 'Preventive maintenance', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.maintenance.view'], 'tables' => ['om_preventive_schedules'], 'icon' => 'calendar-check', 'route' => 'om.pm', 'tone' => 'info',
                'fields' => ['code' => 'Schedule', 'frequency' => 'Frequency', 'priority' => 'Priority', 'next' => 'Next due'],
                'open' => fn (Builder $q) => $q->where('is_active', true), 'search' => ['schedule_code', 'title'],
                'span' => fn (object $r): ?array => ($a = Chainage::parse($r->chainage_from)) === null ? null : ['from' => $a, 'to' => Chainage::parse($r->chainage_to) ?? $a],
                'title' => $title('title', 'schedule_code'),
                'values' => fn (object $r): array => ['code' => $r->schedule_code, 'frequency' => $ucfirst($r->frequency_type), 'priority' => $ucfirst($r->priority), 'next' => $r->next_due_at ? substr((string) $r->next_due_at, 0, 10) : null],
            ],
            [
                'key' => 'om.work_orders', 'label' => 'Work orders', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_POINT, MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.maintenance.view'], 'tables' => ['om_work_orders'], 'icon' => 'tools', 'route' => 'om.work-orders', 'tone' => 'info',
                'fields' => ['number' => 'Work order', 'type' => 'Type', 'priority' => 'Priority', 'status' => 'Status', 'where' => 'Location'],
                'open' => $notDone(), 'search' => ['work_order_number', 'title'],
                'span' => $span('location'), 'title' => $title('title', 'work_order_number'),
                'values' => fn (object $r): array => ['number' => $r->work_order_number, 'type' => $ucfirst($r->work_type), 'priority' => $ucfirst($r->priority), 'status' => $ucfirst($r->status), 'where' => $r->location],
                'row_tone' => $tones('priority', ['critical' => 'crit', 'high' => 'crit', 'medium' => 'warn', 'low' => 'info']),
            ],
            [
                'key' => 'om.equipment', 'label' => 'Equipment status', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.equipment.view'], 'tables' => ['om_equipment_status'], 'icon' => 'hdd-network', 'route' => 'om.equipment', 'tone' => 'info',
                'fields' => ['code' => 'Equipment', 'category' => 'Category', 'status' => 'Status', 'uptime' => 'Uptime', 'ping' => 'Last ping'],
                'search' => ['equipment_code', 'name'], 'span' => $span('location'), 'title' => $title('name', 'equipment_code'),
                'values' => fn (object $r): array => ['code' => $r->equipment_code, 'category' => $ucfirst($r->category), 'status' => $ucfirst($r->status), 'uptime' => $r->uptime_pct !== null ? $r->uptime_pct.'%' : null, 'ping' => $when($r->last_ping_at)],
                'row_tone' => $tones('status', ['online' => 'good', 'operational' => 'good', 'offline' => 'crit', 'fault' => 'crit', 'degraded' => 'warn']),
            ],
            [
                'key' => 'om.vms', 'label' => 'Variable message signs', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.traffic.view'], 'tables' => ['om_vms_messages'], 'icon' => 'display', 'route' => 'om.traffic', 'tone' => 'info',
                'fields' => ['code' => 'Sign', 'line1' => 'Line 1', 'line2' => 'Line 2', 'type' => 'Type'],
                'open' => fn (Builder $q) => $q->where('is_active', true), 'search' => ['vms_code', 'location'],
                'span' => $span('location'), 'title' => $title('vms_code'),
                'values' => fn (object $r): array => ['code' => $r->vms_code, 'line1' => $r->message_line1, 'line2' => $r->message_line2, 'type' => $ucfirst($r->type)],
            ],
            [
                'key' => 'om.patrols', 'label' => 'Patrol shifts', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.patrol.manage', 'om.dashboard.view'], 'tables' => ['om_patrol_shifts'], 'icon' => 'truck', 'route' => 'om.shift-logs', 'tone' => 'theme',
                'fields' => ['code' => 'Patrol', 'shift' => 'Shift', 'call' => 'Call sign', 'status' => 'Status', 'date' => 'Date'],
                'period' => 'patrol_date', 'search' => ['patrol_code', 'call_sign'],
                'span' => fn (object $r): ?array => ($a = Chainage::parse($r->assigned_zone_from)) === null ? null : ['from' => $a, 'to' => Chainage::parse($r->assigned_zone_to) ?? $a],
                'title' => $title('patrol_code'),
                'values' => fn (object $r): array => ['code' => $r->patrol_code, 'shift' => $ucfirst($r->shift_type), 'call' => $r->call_sign, 'status' => $ucfirst($r->status), 'date' => substr((string) $r->patrol_date, 0, 10)],
            ],
            [
                'key' => 'om.toolbox_talks', 'label' => 'Toolbox talks', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.safety.view'], 'tables' => ['om_toolbox_talks'], 'icon' => 'megaphone', 'tone' => 'info',
                'fields' => ['code' => 'Talk', 'topic' => 'Topic', 'attendees' => 'Attendees', 'date' => 'Date'],
                'period' => 'talk_date', 'search' => ['talk_code', 'topic'], 'span' => $span('chainage'), 'title' => $title('talk_code'),
                'values' => fn (object $r): array => ['code' => $r->talk_code, 'topic' => $r->topic, 'attendees' => $r->attendee_count, 'date' => substr((string) $r->talk_date, 0, 10)],
            ],
            [
                'key' => 'om.tppd_claims', 'label' => 'Third-party damage claims', 'group' => 'om', 'geometry' => [MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.tppd.view'], 'tables' => ['om_tppd_claims'], 'icon' => 'cash-stack', 'tone' => 'warn',
                'fields' => ['number' => 'Claim', 'status' => 'Status', 'date' => 'Incident date', 'claimed' => 'Claimed'],
                'open' => $notDone(), 'search' => ['claim_number'], 'span' => $span('chainage'), 'title' => $title('claim_number'),
                'values' => fn (object $r): array => ['number' => $r->claim_number, 'status' => $ucfirst($r->status), 'date' => substr((string) $r->incident_date, 0, 10), 'claimed' => $r->claimed_amount !== null ? 'BDT '.number_format((float) $r->claimed_amount) : null],
            ],
            // ── Safety ────────────────────────────────────────────
            [
                'key' => 'safety.om_incidents', 'label' => 'O&M safety incidents', 'group' => 'safety', 'geometry' => [MapLayer::POINT, MapLayer::CHAINAGE_POINT],
                'permissions' => ['om.safety.view'], 'tables' => ['om_safety_incidents'], 'icon' => 'shield-exclamation', 'tone' => 'crit',
                'fields' => ['number' => 'Incident', 'type' => 'Type', 'severity' => 'Severity', 'status' => 'Status', 'at' => 'Occurred'],
                'coords' => ['latitude', 'longitude'], 'period' => 'occurred_at', 'search' => ['safety_number', 'title'],
                'span' => fn (object $r): ?array => Chainage::span($r->chainage) ?? Chainage::span($r->location),
                'title' => $title('title', 'safety_number'),
                'values' => fn (object $r): array => ['number' => $r->safety_number, 'type' => $ucfirst($r->incident_type), 'severity' => $ucfirst($r->severity), 'status' => $ucfirst($r->status), 'at' => $when($r->occurred_at)],
                'row_tone' => $tones('severity', $severity),
            ],
            [
                'key' => 'safety.incidents', 'label' => 'Safety incidents', 'group' => 'safety', 'geometry' => [MapLayer::CHAINAGE_POINT, MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.safety.view'], 'tables' => ['safety_incidents'], 'icon' => 'shield-exclamation', 'tone' => 'crit',
                'fields' => ['type' => 'Type', 'severity' => 'Severity', 'status' => 'Status', 'date' => 'Date'],
                'period' => 'incident_date', 'span' => $span('location'),
                'title' => fn (object $r): string => (string) ($r->incident_type ?: 'Safety incident'),
                'values' => fn (object $r): array => ['type' => $ucfirst($r->incident_type), 'severity' => $ucfirst($r->severity), 'status' => $ucfirst($r->status), 'date' => substr((string) $r->incident_date, 0, 10)],
                'row_tone' => $tones('severity', $severity),
            ],
            [
                'key' => 'safety.inspections', 'label' => 'Safety inspections', 'group' => 'safety', 'geometry' => [MapLayer::CHAINAGE_POINT, MapLayer::CHAINAGE_BAND],
                'permissions' => ['om.safety.view'], 'tables' => ['safety_inspections'], 'icon' => 'clipboard2-check', 'tone' => 'info',
                'fields' => ['type' => 'Type', 'status' => 'Status', 'date' => 'Date', 'next' => 'Next inspection'],
                'period' => 'inspection_date', 'span' => $span('location'),
                'title' => fn (object $r): string => (string) ($r->inspection_type ?: 'Safety inspection'),
                'values' => fn (object $r): array => ['type' => $ucfirst($r->inspection_type), 'status' => $ucfirst($r->status), 'date' => substr((string) $r->inspection_date, 0, 10), 'next' => $r->next_inspection_date ? substr((string) $r->next_inspection_date, 0, 10) : null],
            ],
        ];

        return array_map(fn (array $c): MapLayer => new TableLayer($c, $scope), $defs);
    }
}
