<?php

namespace Database\Seeders;

use App\Models\OmAsset;
use App\Models\OmInspectionTemplate;
use App\Models\OmPreventiveSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class OmRenovationSeeder extends Seeder
{
    /**
     * Seeds industry-standard inspection templates and preventive maintenance schedules
     * specifically tailored for the Dhaka Bypass Expressway (N-105).
     */
    public function run(): void
    {
        $adminId = DB::table('users')->min('id') ?: 1;

        // ───────────────────────────────────────────────
        // 1. Standard Inspection Templates
        // ───────────────────────────────────────────────
        $templates = [
            [
                'template_code' => 'INSP-PVMT-01',
                'name' => 'Routine Pavement & Surface Inspection',
                'description' => 'Weekly expressway carriageway, shoulder, and service road pavement condition survey.',
                'asset_category' => 'pavement_civil',
                'checklist_sections' => [
                    [
                        'section' => 'Carriageway Pavement',
                        'items' => [
                            ['label' => 'Rutting depth < 15mm in wheelpaths', 'type' => 'pass_fail', 'weight' => 20],
                            ['label' => 'No potholes > 50mm diameter or > 25mm depth', 'type' => 'pass_fail', 'weight' => 25],
                            ['label' => 'Crocodile / fatigue cracking absent or < 5% area', 'type' => 'pass_fail', 'weight' => 20],
                            ['label' => 'Longitudinal and transverse joint sealant intact', 'type' => 'pass_fail', 'weight' => 15],
                            ['label' => 'Riding quality and skid resistance acceptable', 'type' => 'pass_fail', 'weight' => 20],
                        ],
                    ],
                    [
                        'section' => 'Shoulders & Kerbs',
                        'items' => [
                            ['label' => 'Shoulder drop-off < 25mm relative to lane edge', 'type' => 'pass_fail', 'weight' => 50],
                            ['label' => 'Kerbs and concrete edge barriers undamaged', 'type' => 'pass_fail', 'weight' => 50],
                        ],
                    ],
                ],
                'max_score' => 100,
                'pass_threshold' => 75,
                'auto_create_defect_on_fail' => true,
                'photo_required' => true,
                'is_active' => true,
            ],
            [
                'template_code' => 'INSP-BRDG-01',
                'name' => 'Bridge & Overpass Structural Inspection',
                'description' => 'Quarterly visual inspection of flyovers, overpasses, expansion joints, and bearings.',
                'asset_category' => 'bridge_structure',
                'checklist_sections' => [
                    [
                        'section' => 'Superstructure & Deck',
                        'items' => [
                            ['label' => 'Bridge deck surface free of spalling or debonding', 'type' => 'pass_fail', 'weight' => 25],
                            ['label' => 'Expansion joints clear of debris and functioning properly', 'type' => 'pass_fail', 'weight' => 25],
                            ['label' => 'Parapets and crash barriers intact with no impact damage', 'type' => 'pass_fail', 'weight' => 25],
                            ['label' => 'Drainage scuppers free-flowing with no ponding', 'type' => 'pass_fail', 'weight' => 25],
                        ],
                    ],
                    [
                        'section' => 'Substructure & Bearings',
                        'items' => [
                            ['label' => 'Elastomeric/pot bearings aligned without excessive distortion', 'type' => 'pass_fail', 'weight' => 50],
                            ['label' => 'Piers and abutments free of structural cracks > 0.3mm or scour', 'type' => 'pass_fail', 'weight' => 50],
                        ],
                    ],
                ],
                'max_score' => 100,
                'pass_threshold' => 80,
                'auto_create_defect_on_fail' => true,
                'photo_required' => true,
                'is_active' => true,
            ],
            [
                'template_code' => 'INSP-GDRL-01',
                'name' => 'W-Beam Guardrail & Safety Barrier Audit',
                'description' => 'Bi-weekly verification of crash barrier integrity, end terminals, and delineators.',
                'asset_category' => 'guardrail_safety',
                'checklist_sections' => [
                    [
                        'section' => 'Guardrail Condition',
                        'items' => [
                            ['label' => 'W-beam posts firmly anchored and vertical', 'type' => 'pass_fail', 'weight' => 30],
                            ['label' => 'No vehicular crash damage or bent beams', 'type' => 'pass_fail', 'weight' => 30],
                            ['label' => 'Crash cushions / terminal ends undamaged and certified', 'type' => 'pass_fail', 'weight' => 20],
                            ['label' => 'Reflective delineators installed and clean', 'type' => 'pass_fail', 'weight' => 20],
                        ],
                    ],
                ],
                'max_score' => 100,
                'pass_threshold' => 80,
                'auto_create_defect_on_fail' => true,
                'photo_required' => true,
                'is_active' => true,
            ],
            [
                'template_code' => 'INSP-ELEC-01',
                'name' => 'High-Mast & Expressway Lighting Audit',
                'description' => 'Fortnightly night-time audit of highway illumination, feeder pillars, and cables.',
                'asset_category' => 'lighting_electrical',
                'checklist_sections' => [
                    [
                        'section' => 'Luminaires & Masts',
                        'items' => [
                            ['label' => 'Luminaire operational rate >= 95% on zone', 'type' => 'pass_fail', 'weight' => 40],
                            ['label' => 'Mast foundation and anchor bolts tight and corrosion-free', 'type' => 'pass_fail', 'weight' => 30],
                            ['label' => 'Feeder pillar doors locked, earthing verified', 'type' => 'pass_fail', 'weight' => 30],
                        ],
                    ],
                ],
                'max_score' => 100,
                'pass_threshold' => 85,
                'auto_create_defect_on_fail' => true,
                'photo_required' => true,
                'is_active' => true,
            ],
            [
                'template_code' => 'INSP-DRAN-01',
                'name' => 'Drainage Culverts & Slope Protection Survey',
                'description' => 'Pre-monsoon and routine monthly inspection of trapezoidal drains, outfalls, and embankment slopes.',
                'asset_category' => 'drainage_slope',
                'checklist_sections' => [
                    [
                        'section' => 'Drains & Culverts',
                        'items' => [
                            ['label' => 'Median and side drains silt-free and unobstructed', 'type' => 'pass_fail', 'weight' => 35],
                            ['label' => 'Box / pipe culvert barrels clear of debris or sediment', 'type' => 'pass_fail', 'weight' => 35],
                            ['label' => 'Geotextile / turfing on slopes stable without erosion gullies', 'type' => 'pass_fail', 'weight' => 30],
                        ],
                    ],
                ],
                'max_score' => 100,
                'pass_threshold' => 70,
                'auto_create_defect_on_fail' => true,
                'photo_required' => true,
                'is_active' => true,
            ],
        ];

        foreach ($templates as $t) {
            OmInspectionTemplate::updateOrCreate(
                ['template_code' => $t['template_code']],
                array_merge($t, ['created_by' => $adminId])
            );
        }

        // ───────────────────────────────────────────────
        // 2. Standard Preventive Maintenance Schedules
        // ───────────────────────────────────────────────
        $schedules = [
            [
                'schedule_code' => 'PM-PVMT-MONTHLY',
                'title' => 'Monthly Expressway Pothole & Joint Sealing Campaign',
                'description' => 'Systematic inspection and cold/hot-mix patching of minor asphalt surface defects and expansion joints.',
                'asset_category' => 'pavement_civil',
                'frequency_type' => 'monthly',
                'frequency_interval_days' => 30,
                'priority' => 'high',
                'assigned_to' => 'Pavement Civil Maintenance Crew A',
                'contractor_name' => 'Shamim Enterprise Ltd',
                'estimated_cost' => 150000.00,
                'estimated_duration_hours' => 6.0,
                'chainage_from' => 'KM 00+000',
                'chainage_to' => 'KM 38+000',
                'direction' => 'both',
                'requires_lane_closure' => true,
                'checklist_items' => [
                    'Deploy advance warning signs and arrow boards per traffic control manual',
                    'Clean and blow compressed air into open cracks and joints',
                    'Apply bituminous emulsion tack coat',
                    'Compact asphalt mix using vibrating plate compactor',
                    'Check smoothness with 3m straight-edge',
                ],
                'required_materials' => [
                    ['item' => 'Cold mix asphalt asphaltic concrete', 'qty' => 50, 'unit' => 'bags'],
                    ['item' => 'Bitumen Emulsion RS-1', 'qty' => 2, 'unit' => 'drums'],
                    ['item' => 'Polymer joint sealant', 'qty' => 10, 'unit' => 'buckets'],
                ],
                'next_due_at' => now()->addDays(5)->toDateString(),
                'is_active' => true,
            ],
            [
                'schedule_code' => 'PM-DRAN-PREMONSOON',
                'title' => 'Bi-Monthly Culvert & Median Drain De-Silting',
                'description' => 'Comprehensive mechanical and manual silt removal from longitudinal lined drains and cross-culverts.',
                'asset_category' => 'drainage_slope',
                'frequency_type' => 'monthly',
                'frequency_interval_days' => 60,
                'priority' => 'medium',
                'assigned_to' => 'Drainage Maintenance Gang 1',
                'contractor_name' => 'UDC Construction Ltd',
                'estimated_cost' => 85000.00,
                'estimated_duration_hours' => 8.0,
                'chainage_from' => 'KM 10+000',
                'chainage_to' => 'KM 25+000',
                'direction' => 'both',
                'requires_lane_closure' => false,
                'checklist_items' => [
                    'Clear vegetation and scrub within 1m of drain edge',
                    'Excavate accumulated silt and dispose outside expressway right-of-way',
                    'Inspect weep holes in retaining walls and clear blockages',
                    'Check catch pits and grating covers for damage',
                ],
                'required_materials' => [
                    ['item' => 'Concrete drain cover slabs (replacements)', 'qty' => 12, 'unit' => 'nos'],
                    ['item' => 'Cast iron ditch grating', 'qty' => 4, 'unit' => 'nos'],
                ],
                'next_due_at' => now()->addDays(12)->toDateString(),
                'is_active' => true,
            ],
            [
                'schedule_code' => 'PM-ELEC-FORTNIGHTLY',
                'title' => 'Fortnightly Feeder Pillar & High Mast Preventive Service',
                'description' => 'Testing of automatic timer controls, contactors, earth resistance, and luminaire wash.',
                'asset_category' => 'lighting_electrical',
                'frequency_type' => 'biweekly',
                'frequency_interval_days' => 14,
                'priority' => 'high',
                'assigned_to' => 'ITS & Electrical Tech Team',
                'contractor_name' => 'SRBG Electrical Division',
                'estimated_cost' => 65000.00,
                'estimated_duration_hours' => 4.0,
                'chainage_from' => 'KM 00+000',
                'chainage_to' => 'KM 38+000',
                'direction' => 'median',
                'requires_lane_closure' => true,
                'checklist_items' => [
                    'Verify MCCB trip functions and surge protection devices (SPD)',
                    'Measure earthing resistance (must be < 5 ohms)',
                    'Inspect photoelectric sensor / astronomical timer calibration',
                    'Clean luminaire lenses with telescopic washer',
                ],
                'required_materials' => [
                    ['item' => '150W LED driver modules', 'qty' => 6, 'unit' => 'nos'],
                    ['item' => 'Surge Protection Devices 40kA', 'qty' => 3, 'unit' => 'nos'],
                ],
                'next_due_at' => now()->addDays(2)->toDateString(),
                'is_active' => true,
            ],
            [
                'schedule_code' => 'PM-BRDG-QUARTERLY',
                'title' => 'Quarterly Bridge Expansion Joint & Bearings Service',
                'description' => 'High-pressure wash of expansion joints, lubricating rocker/roller bearings, and torque-check on barrier anchors.',
                'asset_category' => 'bridge_structure',
                'frequency_type' => 'quarterly',
                'frequency_interval_days' => 90,
                'priority' => 'critical',
                'assigned_to' => 'Structures Special Maintenance Team',
                'contractor_name' => 'SRBG Structural Engineering',
                'estimated_cost' => 320000.00,
                'estimated_duration_hours' => 12.0,
                'chainage_from' => 'KM 14+500',
                'chainage_to' => 'KM 16+200',
                'direction' => 'both',
                'requires_lane_closure' => true,
                'checklist_items' => [
                    'Pressure-wash elastomeric joint glands and clear accumulated gravel',
                    'Inspect neoprene seal for tears, punctures or hardening',
                    'Verify bearing gap clearance and tilt angle measurements',
                    'Torque-test anchor bolts to specified 450 Nm',
                ],
                'required_materials' => [
                    ['item' => 'Replacement neoprene joint gland profile', 'qty' => 24, 'unit' => 'meters'],
                    ['item' => 'Silicone elastomeric lubricant', 'qty' => 8, 'unit' => 'cans'],
                ],
                'next_due_at' => now()->addDays(20)->toDateString(),
                'is_active' => true,
            ],
        ];

        foreach ($schedules as $s) {
            OmPreventiveSchedule::updateOrCreate(
                ['schedule_code' => $s['schedule_code']],
                array_merge($s, ['created_by' => $adminId])
            );
        }

        // ───────────────────────────────────────────────
        // 3. Notification Type Registration
        // ───────────────────────────────────────────────
        if (\Illuminate\Support\Facades\Schema::hasTable('notification_types')) {
            \App\Models\NotificationType::updateOrCreate(
                ['key' => 'om.alert'],
                [
                    'category' => 'operations',
                    'label' => 'Operations & Maintenance Alerts',
                    'description' => 'Real-time push and in-app alerts for SLA breaches, emergency roadway incidents, and work order assignments.',
                    'default_channels' => ['database', 'push'],
                    'locked_channels' => ['database'],
                    'recipient_roles' => ['Super Administrator', 'General Manager', 'Project Director', 'Manager', 'Assistant Manager', 'Engineer', 'Sub Assistant Engineer'],
                    'is_active' => true,
                ]
            );
        }
    }
}
