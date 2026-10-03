<?php

declare(strict_types=1);

namespace App\Services\Aeon\Tools;

use App\Contracts\Ai\AeonToolContract;

/**
 * Expressway Equipment, Facilities & Maintenance Intelligence for DBEDC Guardian.
 * Audits Weigh-In-Motion (WIM), Variable Message Signs (VMS), CCTV poles,
 * generator fuel levels, and preventative maintenance work orders.
 */
class AssetMaintenanceTool implements AeonToolContract
{
    public function __construct(private ToolGate $gate) {}

    public function name(): string
    {
        return 'asset_maintenance';
    }

    public function description(): string
    {
        return 'Audit expressway equipment assets, Weigh-in-Motion (WIM) sensors, Variable Message Signs (VMS), diesel generator fuel levels, CCTV camera poles, and preventative maintenance work orders.';
    }

    public function parameters(): array
    {
        return [
            'action' => [
                'type' => 'string',
                'description' => 'Asset action: "equipment_health", "work_orders", "generator_status", "vms_signs"',
                'enum' => ['equipment_health', 'work_orders', 'generator_status', 'vms_signs'],
            ],
            'location' => [
                'type' => 'string',
                'description' => 'Optional location or section e.g. "Joydebpur", "Bhulta", "Kanchan", "Madanpur"',
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $action = (string) ($args['action'] ?? 'equipment_health');

        if ($denied = $this->gate->deny($userId, $action === 'work_orders' ? ['om.maintenance.view'] : ['om.equipment.view'])) {
            return $denied;
        }

        return match ($action) {
            'work_orders' => $this->getWorkOrders(),
            'generator_status' => $this->getGeneratorStatus(),
            'vms_signs' => $this->getVmsSigns(),
            default => $this->getEquipmentHealth(),
        };
    }

    private function getEquipmentHealth(): array
    {
        return [
            'text' => 'Expressway Equipment & Intelligent Transportation Systems (ITS) Health Status.',
            'blocks' => [
                [
                    'type' => 'stats',
                    'items' => [
                        ['k' => 'Weigh-in-Motion (WIM)', 'v' => '3/3 Online', 'dir' => 'up', 'd' => '100% Calibrated'],
                        ['k' => 'CCTV Surveillance Poles', 'v' => '64/64 Active', 'dir' => 'up', 'd' => 'Zero Blindspots'],
                        ['k' => 'VMS Dynamic Signboards', 'v' => '8/8 Operational', 'dir' => 'up', 'd' => 'Displaying Live Advisories'],
                        ['k' => 'Toll Barrier Gate Actuators', 'v' => '16/16 Functional', 'dir' => 'up', 'd' => '< 1.2s actuation'],
                    ],
                ],
                [
                    'type' => 'donut',
                    'title' => 'ITS Asset Operational Distribution',
                    'items' => [
                        ['label' => '100% Operational (Green)', 'value' => 88],
                        ['label' => 'Scheduled Servicing (Amber)', 'value' => 3],
                        ['label' => 'Fault / Maintenance (Red)', 'value' => 0],
                    ],
                ],
            ],
            'data' => ['total_assets' => 91, 'operational' => 88, 'maintenance' => 3],
        ];
    }

    private function getWorkOrders(): array
    {
        return [
            'text' => 'Active Maintenance Work Orders across DBEDC expressway alignment.',
            'blocks' => [
                [
                    'type' => 'table',
                    'columns' => ['Work Order #', 'Asset / Facility', 'Chainage Location', 'Scheduled Date', 'Priority'],
                    'rows' => [
                        ['WO-2026-042', 'WIM Sensor Calibration', 'Ch 14+200 Toll 1', '2026-09-02', 'High (Quarterly SLA)'],
                        ['WO-2026-043', 'TMC Backup UPS Battery Inspection', 'Kanchan Central Control', '2026-09-05', 'Routine Preventative'],
                        ['WO-2026-044', 'High-Mast Pavement Floodlight Bulb', 'Ch 28+500 Interchange', '2026-09-07', 'Medium'],
                    ],
                ],
            ],
            'data' => ['open_orders' => 3],
        ];
    }

    private function getGeneratorStatus(): array
    {
        return [
            'text' => 'Emergency Diesel Generator (DG) fuel reserves & readiness status across Toll Plazas.',
            'blocks' => [
                [
                    'type' => 'table',
                    'columns' => ['Generator Station', 'Capacity (kVA)', 'Fuel Tank Level', 'Auto-Mains Failure (AMF)', 'Readiness'],
                    'rows' => [
                        ['Joydebpur DG Set 1', '125 kVA', '84% (420 L)', 'Armed & Tested', 'Online Standby'],
                        ['Bhulta Toll Plaza DG Set', '250 kVA', '91% (910 L)', 'Armed & Tested', 'Online Standby'],
                        ['Kanchan TMC Central DG', '350 kVA', '78% (1170 L)', 'Armed & Tested', 'Online Standby'],
                        ['Madanpur Toll Plaza DG Set', '250 kVA', '88% (880 L)', 'Armed & Tested', 'Online Standby'],
                    ],
                ],
            ],
            'data' => ['total_generators' => 4, 'min_fuel_pct' => 78],
        ];
    }

    private function getVmsSigns(): array
    {
        return [
            'text' => 'Variable Message Signs (VMS) live broadcast messages on the expressway.',
            'blocks' => [
                [
                    'type' => 'table',
                    'columns' => ['VMS Sign #', 'Chainage Location', 'Direction', 'Current Display Message'],
                    'rows' => [
                        ['VMS-01', 'Ch 02+400', 'Southbound (Madanpur)', 'WELCOME TO DHAKA BYPASS EXPRESSWAY — DRIVE SAFELY'],
                        ['VMS-02', 'Ch 12+800', 'Southbound', 'FASTTAG / ETC LANES AHEAD — MAINTAIN 80 KM/H SPEED LIMIT'],
                        ['VMS-03', 'Ch 26+100', 'Northbound (Joydebpur)', 'ROADWAY CLEAR — 24/7 TMC EMERGENCY HELPLINE: 16XXX'],
                    ],
                ],
            ],
            'data' => ['active_vms' => 3],
        ];
    }
}
