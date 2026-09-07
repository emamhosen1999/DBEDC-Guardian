<?php

namespace App\Services\Operations;

class OmRcmService
{
    /**
     * Get Reliability-Centered Maintenance (RCM) equipment metrics for ITS & Toll assets
     */
    public function getRcmDashboard(): array
    {
        // Equipment categories audited under SAE JA1011 RCM standard:
        $equipmentList = [
            [
                'id' => 1,
                'code' => 'CCTV-PTZ-KNC-01',
                'name' => 'PTZ Surveillance Camera - Kanchan Interchange',
                'category' => 'CCTV Surveillance',
                'criticality' => 'high',
                'operating_hours' => 17400,
                'failures_last_year' => 2,
                'mtbf_hours' => 8700,
                'mttr_hours' => 3.5,
                'availability_pct' => 99.96,
                'failure_rate_per_k_hours' => 0.11,
                'lifecycle_phase' => 'random_failure_useful_life', // 'infant_mortality', 'useful_life', 'wear_out'
                'wear_out_risk' => 'low',
                'recommended_action' => 'Routine lens cleaning & wiper mechanism lubrication',
            ],
            [
                'id' => 2,
                'code' => 'VMS-FULL-BHG-02',
                'name' => 'Full Matrix LED VMS - Bhogra Junction',
                'category' => 'Variable Message Signs',
                'criticality' => 'high',
                'operating_hours' => 22000,
                'failures_last_year' => 4,
                'mtbf_hours' => 5500,
                'mttr_hours' => 6.0,
                'availability_pct' => 99.89,
                'failure_rate_per_k_hours' => 0.18,
                'lifecycle_phase' => 'approaching_wear_out',
                'wear_out_risk' => 'medium',
                'recommended_action' => 'Proactive power supply module & cooling fan replacement',
            ],
            [
                'id' => 3,
                'code' => 'WIM-PIEZO-KNC-L01',
                'name' => 'High-Speed Quartz WIM Sensor - Kanchan L1',
                'category' => 'Weigh-In-Motion',
                'criticality' => 'critical',
                'operating_hours' => 26000,
                'failures_last_year' => 5,
                'mtbf_hours' => 5200,
                'mttr_hours' => 8.0,
                'availability_pct' => 99.85,
                'failure_rate_per_k_hours' => 0.19,
                'lifecycle_phase' => 'wear_out_phase',
                'wear_out_risk' => 'high',
                'recommended_action' => 'Scheduled recalibration and epoxy grouting replacement',
            ],
            [
                'id' => 4,
                'code' => 'TOLL-BARRIER-KNC-L03',
                'name' => 'Automatic High-Speed Barrier Gate - Lane 3',
                'category' => 'Toll Plazas',
                'criticality' => 'critical',
                'operating_hours' => 19500,
                'failures_last_year' => 6,
                'mtbf_hours' => 3250,
                'mttr_hours' => 1.8,
                'availability_pct' => 99.94,
                'failure_rate_per_k_hours' => 0.31,
                'lifecycle_phase' => 'wear_out_phase',
                'wear_out_risk' => 'high',
                'recommended_action' => 'Brushless torque motor & balance spring assembly replacement',
            ],
            [
                'id' => 5,
                'code' => 'GENSET-250KVA-MAIN',
                'name' => '250 kVA Standby Diesel Generator - Operations Center',
                'category' => 'Power Backup',
                'criticality' => 'critical',
                'operating_hours' => 1850,
                'failures_last_year' => 0,
                'mtbf_hours' => 12000,
                'mttr_hours' => 2.0,
                'availability_pct' => 100.0,
                'failure_rate_per_k_hours' => 0.08,
                'lifecycle_phase' => 'useful_life',
                'wear_out_risk' => 'low',
                'recommended_action' => 'Fuel injector testing & automatic transfer switch (ATS) exercise',
            ],
        ];

        $avgAvailability = round(collect($equipmentList)->avg('availability_pct'), 2);
        $avgMtbf = round(collect($equipmentList)->avg('mtbf_hours'), 0);
        $wearOutCount = collect($equipmentList)->where('wear_out_risk', 'high')->count();

        return [
            'stats' => [
                'total_monitored_assets' => count($equipmentList),
                'overall_availability_pct' => $avgAvailability,
                'average_mtbf_hours' => $avgMtbf,
                'wear_out_action_required' => $wearOutCount,
            ],
            'equipment' => $equipmentList,
        ];
    }
}
