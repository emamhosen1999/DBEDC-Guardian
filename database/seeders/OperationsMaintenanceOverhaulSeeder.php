<?php

namespace Database\Seeders;

use App\Models\OmAsset;
use App\Models\OmAssetConditionSurvey;
use App\Models\OmDefect;
use App\Models\OmIncident;
use App\Models\OmIncidentVehicle;
use App\Models\OmLaneClosurePermit;
use App\Models\OmPatrolShift;
use App\Models\OmShiftLog;
use App\Models\OmTollExemption;
use App\Models\OmTollRecord;
use App\Models\OmTollShiftAudit;
use App\Models\OmTrafficLog;
use App\Models\OmVmsMessage;
use App\Models\OmWorkOrder;
use App\Models\OmWorkOrderCrew;
use App\Models\OmWorkOrderMaterial;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class OperationsMaintenanceOverhaulSeeder extends Seeder
{
    public function run(): void
    {
        // Every record below is fictional demo data. It reached production once (2026-09-02) and showed up in the
        // O&M registers and dashboards as real; it must only ever seed a local or test database.
        if (app()->isProduction()) {
            $this->command?->warn('OperationsMaintenanceOverhaulSeeder holds demo data and never runs in production.');

            return;
        }

        $admin = User::first();
        $adminId = $admin ? $admin->id : 1;
        $now = Carbon::now();

        // 1. Seed Linear Expressway Assets (Ch 0+000 to Ch 48+000)
        $assets = [
            [
                'asset_code' => 'AST-PVMT-001',
                'name' => 'Main Carriageway Flexible Pavement (Section 1)',
                'category' => 'pavement_civil',
                'start_chainage' => 'Ch 0+000',
                'end_chainage' => 'Ch 12+000',
                'direction' => 'both',
                'location_description' => 'Joydevpur Interchange to Bhulta Crossing',
                'manufacturer' => 'Sichuan Road & Bridge Group (SRBG)',
                'installation_date' => '2023-06-15',
                'purchase_cost' => 450000000.00,
                'replacement_cost' => 520000000.00,
                'expected_lifespan_years' => 15,
                'condition_score' => 92,
                'condition_grade' => 'excellent',
                'operational_status' => 'active',
                'last_inspected_at' => $now->copy()->subDays(5),
                'technical_specs' => 'SMA wearing course 50mm, DBM binder 100mm, WMM base 250mm',
            ],
            [
                'asset_code' => 'AST-BR-001',
                'name' => 'Kanchan Bridge Flyover Structure',
                'category' => 'bridge_structure',
                'start_chainage' => 'Ch 18+200',
                'end_chainage' => 'Ch 18+850',
                'direction' => 'both',
                'location_description' => 'Shitalakshya River Crossing at Kanchan',
                'installation_date' => '2022-11-20',
                'purchase_cost' => 890000000.00,
                'replacement_cost' => 980000000.00,
                'expected_lifespan_years' => 50,
                'condition_score' => 88,
                'condition_grade' => 'good',
                'operational_status' => 'active',
                'last_inspected_at' => $now->copy()->subDays(12),
                'technical_specs' => 'Prestressed concrete I-girder, elastomeric bearings, modular expansion joints',
            ],
            [
                'asset_code' => 'AST-GD-014',
                'name' => 'W-Beam Hot-Dip Galvanized Guardrail System',
                'category' => 'guardrail_safety',
                'start_chainage' => 'Ch 10+000',
                'end_chainage' => 'Ch 20+000',
                'direction' => 'northbound',
                'location_description' => 'Outer Shoulder Median Barrier',
                'installation_date' => '2023-01-10',
                'purchase_cost' => 32000000.00,
                'replacement_cost' => 38000000.00,
                'expected_lifespan_years' => 12,
                'condition_score' => 85,
                'condition_grade' => 'good',
                'operational_status' => 'active',
                'last_inspected_at' => $now->copy()->subDays(3),
            ],
            [
                'asset_code' => 'AST-ITS-CCTV-01',
                'name' => '360° PTZ High Definition Traffic Monitoring Camera',
                'category' => 'its_telecom',
                'start_chainage' => 'Ch 18+400',
                'direction' => 'median',
                'location_description' => 'Kanchan Bridge Toll Approach Gantry',
                'manufacturer' => 'Hikvision Highway Series',
                'model_number' => 'DS-2DF8836IX-AELW',
                'installation_date' => '2024-03-01',
                'warranty_expiry' => '2027-03-01',
                'purchase_cost' => 450000.00,
                'expected_lifespan_years' => 7,
                'condition_score' => 98,
                'condition_grade' => 'excellent',
                'operational_status' => 'active',
                'last_inspected_at' => $now->copy()->subDays(1),
            ],
            [
                'asset_code' => 'AST-WIM-001',
                'name' => 'High-Speed Quartz Sensor Weigh-In-Motion System',
                'category' => 'toll_equipment',
                'start_chainage' => 'Ch 0+250',
                'direction' => 'southbound',
                'location_description' => 'Main Toll Plaza Entry Screening Lane',
                'manufacturer' => 'Kistler Linea Sensors',
                'installation_date' => '2023-08-15',
                'purchase_cost' => 8500000.00,
                'expected_lifespan_years' => 8,
                'condition_score' => 90,
                'condition_grade' => 'good',
                'operational_status' => 'active',
                'last_inspected_at' => $now->copy()->subDays(2),
            ],
        ];

        foreach ($assets as $astData) {
            OmAsset::updateOrCreate(['asset_code' => $astData['asset_code']], $astData);
        }

        $pavementAsset = OmAsset::where('asset_code', 'AST-PVMT-001')->first();
        $guardrailAsset = OmAsset::where('asset_code', 'AST-GD-014')->first();

        // 2. Seed Scheduled Patrol Shift
        $patrol = OmPatrolShift::updateOrCreate(
            ['patrol_code' => 'PTR-' . date('Ymd') . '-M01'],
            [
                'patrol_date' => $now->toDateString(),
                'shift_type' => 'morning',
                'vehicle_reg_number' => 'Dhaka Metro-Ga 33-8901',
                'call_sign' => 'Patrol Unit 1 (Expressway Alpha)',
                'lead_officer_id' => $adminId,
                'assigned_zone_from' => 'Ch 0+000',
                'assigned_zone_to' => 'Ch 24+000',
                'start_odometer_km' => 45210.50,
                'fuel_liters_added' => 35.00,
                'started_at' => $now->copy()->subHours(4),
                'status' => 'in_progress',
                'incidents_attended_count' => 2,
                'defects_reported_count' => 3,
                'shift_summary' => 'Active morning patrol. Traffic smooth, two road debris cleared at Ch 14+200.',
            ]
        );

        // 3. Seed Roadway Distress Defects with SLA Timers
        $defects = [
            [
                'defect_number' => 'DEF-2026-0001',
                'asset_id' => $pavementAsset?->id,
                'patrol_shift_id' => $patrol->id,
                'title' => 'Severe Pothole on Outer Wheelpath (Diameter 45cm)',
                'distress_type' => 'pothole',
                'chainage' => 'Ch 14+250',
                'direction' => 'northbound',
                'severity' => 'critical',
                'sla_hours' => 4,
                'sla_due_at' => $now->copy()->addHours(2),
                'status' => 'work_order_created',
                'reported_by' => $adminId,
                'description' => 'Asphalt breakout on outer driving lane causing vehicle swerving. Requires instant cold mix patching.',
            ],
            [
                'defect_number' => 'DEF-2026-0002',
                'asset_id' => $guardrailAsset?->id,
                'patrol_shift_id' => $patrol->id,
                'title' => 'Guardrail Beam Impact Deformation (12m Length)',
                'distress_type' => 'guardrail_crash_damage',
                'chainage' => 'Ch 22+800',
                'direction' => 'southbound',
                'severity' => 'high',
                'sla_hours' => 24,
                'sla_due_at' => $now->copy()->addHours(18),
                'status' => 'reported',
                'reported_by' => $adminId,
                'description' => 'Three W-beam panels bent following a light commercial vehicle side-impact. Spacer blocks detached.',
            ],
            [
                'defect_number' => 'DEF-2026-0003',
                'asset_id' => null,
                'patrol_shift_id' => $patrol->id,
                'title' => 'Median High-Mast Light Fixture Driver Failure',
                'distress_type' => 'lighting_fixture_outage',
                'chainage' => 'Ch 31+100',
                'direction' => 'median',
                'severity' => 'medium',
                'sla_hours' => 24,
                'sla_due_at' => $now->copy()->addHours(20),
                'status' => 'reported',
                'reported_by' => $adminId,
                'description' => 'Two 250W LED floodlights flickering during night cycle.',
            ],
        ];

        foreach ($defects as $defData) {
            OmDefect::updateOrCreate(['defect_number' => $defData['defect_number']], $defData);
        }

        $potholeDefect = OmDefect::where('defect_number', 'DEF-2026-0001')->first();

        // 4. Seed Comprehensive Work Orders with BOQ Materials & Lane Closure Permit
        $wo = OmWorkOrder::updateOrCreate(
            ['work_order_number' => 'WO-90124'],
            [
                'defect_id' => $potholeDefect?->id,
                'asset_id' => $pavementAsset?->id,
                'title' => 'Emergency Asphalt Patching & Resurfacing at Ch 14+250',
                'work_type' => 'routine_corrective',
                'category' => 'pavement',
                'location' => 'Ch 14+250 (Northbound Slow Lane)',
                'priority' => 'emergency',
                'status' => 'in_progress',
                'assigned_to' => 'Roadside Crew Alpha',
                'contractor_name' => 'Expressway Routine Maintenance Ltd.',
                'description' => 'Saw cutting edges, applying tack coat emulsion, filling with Polymer Modified Cold Mix Asphalt, and vibratory plate compacting.',
                'reported_by' => $adminId,
                'assigned_by' => $adminId,
                'approved_by' => $adminId,
                'approved_at' => $now->copy()->subHours(2),
                'target_start_at' => $now->copy()->subHours(1),
                'target_end_at' => $now->copy()->addHours(3),
                'actual_start_at' => $now->copy()->subHours(1),
                'estimated_cost' => 45000.00,
                'requires_lane_closure' => true,
            ]
        );

        // Materials Consumed (BOQ)
        OmWorkOrderMaterial::updateOrCreate(
            ['work_order_id' => $wo->id, 'item_name' => 'Polymer Modified Cold Mix Asphalt 25kg'],
            [
                'unit' => 'Bags',
                'quantity_planned' => 12,
                'quantity_used' => 10,
                'unit_cost' => 1200.00,
                'total_cost' => 12000.00,
            ]
        );

        OmWorkOrderMaterial::updateOrCreate(
            ['work_order_id' => $wo->id, 'item_name' => 'Bituminous Tack Coat RS-1 Emulsion'],
            [
                'unit' => 'Liters',
                'quantity_planned' => 20,
                'quantity_used' => 18,
                'unit_cost' => 250.00,
                'total_cost' => 4500.00,
            ]
        );

        // Machinery & Crew
        OmWorkOrderCrew::updateOrCreate(
            ['work_order_id' => $wo->id, 'worker_or_machine_name' => 'Wacker Neuson Vibratory Plate Compactor (1 Ton)'],
            [
                'resource_type' => 'equipment_machinery',
                'hours_spent' => 2.5,
                'hourly_rate' => 1500.00,
                'total_cost' => 3750.00,
            ]
        );

        // Lane Closure Safety Permit
        OmLaneClosurePermit::updateOrCreate(
            ['permit_number' => 'LCP-20260902-01'],
            [
                'work_order_id' => $wo->id,
                'title' => 'Safety Zone: Ch 14+000 to Ch 14+500 Slow Lane Closure',
                'chainage_from' => 'Ch 14+000',
                'chainage_to' => 'Ch 14+500',
                'direction' => 'northbound',
                'lanes_closed' => 'slow_and_shoulder',
                'scheduled_start' => $now->copy()->subHours(1),
                'scheduled_end' => $now->copy()->addHours(3),
                'actual_start' => $now->copy()->subHours(1),
                'status' => 'active',
                'requested_by' => $adminId,
                'approved_by' => $adminId,
                'vms_alert_active' => true,
                'safety_cones_deployed' => 45,
                'traffic_marshals_deployed' => 2,
                'flashing_arrow_board_present' => true,
                'safety_checklist_notes' => 'Advance warning signs placed 500m & 200m ahead. Marshals equipped with illuminated batons.',
            ]
        );

        // 5. Seed Incidents with Crash Data & TPPD Claims
        $inc = OmIncident::updateOrCreate(
            ['incident_number' => 'INC-2026-001'],
            [
                'title' => 'Heavy Truck Tire Blowout & Guardrail Impact',
                'incident_type' => 'road_traffic_collision',
                'detection_source' => 'tmc_cctv',
                'chainage' => 'Ch 24+500',
                'direction' => 'southbound',
                'severity' => 'major',
                'status' => 'on_scene',
                'dispatched_unit' => 'Patrol Unit 2 & Heavy Wrecker 1',
                'response_time_minutes' => 9,
                'casualties_fatalities' => 0,
                'casualties_injured' => 1,
                'vehicles_involved_count' => 1,
                'has_asset_damage' => true,
                'asset_damage_cost_est' => 185000.00,
                'tppd_claim_status' => 'claim_prepared',
                'police_case_number' => 'GD-RUPGANJ-2026/09/104',
                'description' => 'Rear axle blowout on 10-wheeler truck resulting in collision with outer guardrail. Minor driver hand injury treated by highway patrol first aid.',
                'reported_by' => $adminId,
                'reported_at' => $now->copy()->subMinutes(45),
                'dispatched_at' => $now->copy()->subMinutes(42),
                'on_scene_at' => $now->copy()->subMinutes(33),
            ]
        );

        OmIncidentVehicle::updateOrCreate(
            ['incident_id' => $inc->id, 'vehicle_reg_number' => 'Dhaka Metro-Ta 18-4920'],
            [
                'vehicle_type' => 'Heavy Truck (3-Axle)',
                'driver_name' => 'Md. Rafiqul Islam',
                'driver_license_number' => 'DL-DH-901248',
                'driver_phone' => '+880 1711-234567',
                'insurance_company' => 'Green Delta Insurance Co.',
                'insurance_policy_number' => 'POL-CV-2026-8819',
                'towed_by_expressway_wrecker' => true,
                'towing_fee_charged' => 15000.00,
                'damage_to_vehicle_description' => 'Crushed bumper, shattered right headlight, damaged steer tire.',
                'damage_to_expressway_asset' => '16 meters of W-beam guardrail deformed, 4 steel posts sheared at base.',
                'estimated_asset_repair_cost' => 185000.00,
            ]
        );

        // 6. Seed Toll Shift Reconciliation Audits
        OmTollShiftAudit::updateOrCreate(
            ['audit_code' => 'TOLL-AUDIT-' . date('Ymd') . '-M01'],
            [
                'plaza_name' => 'Main Toll Plaza (Ch 0+000)',
                'shift_date' => $now->toDateString(),
                'shift_type' => 'morning',
                'auditor_id' => $adminId,
                'shift_supervisor_id' => $adminId,
                'system_calculated_total' => 485200.00,
                'cash_declared_by_collectors' => 104800.00,
                'etc_automatic_revenue' => 380400.00,
                'pos_card_mfs_revenue' => 0.00,
                'variance_amount' => 0.00,
                'total_vehicle_transactions' => 3840,
                'avc_physical_axle_count' => 3840,
                'exempted_vehicle_count' => 18,
                'evasion_violation_count' => 0,
                'bank_deposit_reference' => 'BRAC-DEP-20260902-881',
                'bank_deposit_amount' => 104800.00,
                'audit_status' => 'verified_matched',
                'auditor_notes' => '100% reconciliation matched. No revenue leakage detected.',
            ]
        );

        // 7. Seed Toll Exemptions
        OmTollExemption::updateOrCreate(
            ['vehicle_reg_number' => 'Gov-Ambulance-04', 'passed_at' => $now->copy()->subHours(3)],
            [
                'plaza_name' => 'Main Toll Plaza (Ch 0+000)',
                'lane_id' => 'Lane 01 (ETC/Express)',
                'exemption_category' => 'emergency_ambulance_fire',
                'authorizing_document_ref' => 'Hospital Emergency Code 1',
                'officer_or_driver_name' => 'Duty Paramedic Driver',
            ]
        );
    }
}
