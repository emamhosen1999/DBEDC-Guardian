<?php

namespace App\Services\Operations;

use App\Models\OmDefect;
use App\Models\OmSlaBreach;

class OmSlaService
{
    /**
     * Check all defects for SLA breaches and record them.
     */
    public function checkAndRecordBreaches(): array
    {
        $breached = [];

        $overdueDefects = OmDefect::whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNotIn('status', ['rectified', 'verified_closed', 'rejected'])
            ->get();

        foreach ($overdueDefects as $defect) {
            // Check if breach already recorded
            $existing = OmSlaBreach::where('entity_type', 'defect')
                ->where('entity_id', $defect->id)
                ->first();

            if ($existing) {
                // Update overdue hours
                $existing->update([
                    'overdue_hours' => (int) now()->diffInHours($defect->sla_due_at),
                    'escalation_level' => $this->calculateEscalationLevel($defect),
                ]);

                continue;
            }

            $breach = OmSlaBreach::create([
                'entity_type' => 'defect',
                'entity_id' => $defect->id,
                'entity_number' => $defect->defect_number,
                'sla_hours' => $defect->sla_hours,
                'sla_started_at' => $defect->created_at,
                'sla_due_at' => $defect->sla_due_at,
                'breached_at' => $defect->sla_due_at,
                'overdue_hours' => (int) now()->diffInHours($defect->sla_due_at),
                'escalation_level' => $this->calculateEscalationLevel($defect),
            ]);

            $breached[] = $breach;

            // Dispatch Push & In-App Notification to Maintenance Managers
            try {
                $managers = \App\Models\User::permission('om.maintenance.manage')->get();
                if ($managers->isNotEmpty()) {
                    \Illuminate\Support\Facades\Notification::send($managers, new \App\Notifications\OmAlertNotification(
                        "SLA Breach: Defect {$defect->defect_number}",
                        "Defect at {$defect->chainage} exceeded its {$defect->sla_hours}h SLA limit.",
                        'sla_breach',
                        $defect->defect_number,
                        '/om/sla-compliance'
                    ));
                }
            } catch (\Throwable) {
                // Fail-safe: don't break SLA check if notification fails
            }
        }

        return $breached;
    }

    /**
     * Get SLA compliance dashboard data.
     */
    public function getComplianceDashboard(): array
    {
        $totalWithSla = OmDefect::whereNotNull('sla_due_at')->count();
        $resolved = OmDefect::whereNotNull('sla_due_at')
            ->whereIn('status', ['rectified', 'verified_closed'])
            ->count();

        $resolvedOnTime = OmDefect::whereNotNull('sla_due_at')
            ->whereNotNull('rectified_at')
            ->whereIn('status', ['rectified', 'verified_closed'])
            ->whereColumn('rectified_at', '<=', 'sla_due_at')
            ->count();

        $currentlyBreached = OmDefect::whereNotNull('sla_due_at')
            ->where('sla_due_at', '<', now())
            ->whereNotIn('status', ['rectified', 'verified_closed', 'rejected'])
            ->count();

        $atRisk = OmDefect::whereNotNull('sla_due_at')
            ->where('sla_due_at', '>', now())
            ->where('sla_due_at', '<=', now()->addHours(4))
            ->whereNotIn('status', ['rectified', 'verified_closed', 'rejected'])
            ->count();

        $complianceRate = $resolved > 0 ? round($resolvedOnTime / $resolved * 100, 1) : 100;

        $breachesBySeverity = OmSlaBreach::select('escalation_level')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('escalation_level')
            ->pluck('count', 'escalation_level')
            ->toArray();

        $activeBreaches = OmSlaBreach::with(['acknowledgedByUser'])
            ->where('acknowledged', false)
            ->latest('breached_at')
            ->take(20)
            ->get();

        return [
            'total_with_sla' => $totalWithSla,
            'resolved_total' => $resolved,
            'resolved_on_time' => $resolvedOnTime,
            'currently_breached' => $currentlyBreached,
            'at_risk' => $atRisk,
            'compliance_rate' => $complianceRate,
            'breaches_by_severity' => $breachesBySeverity,
            'active_breaches' => $activeBreaches,
        ];
    }

    /**
     * Acknowledge an SLA breach.
     */
    public function acknowledgeBreach(OmSlaBreach $breach, int $userId, ?string $notes = null): OmSlaBreach
    {
        $breach->update([
            'acknowledged' => true,
            'acknowledged_by' => $userId,
            'acknowledged_at' => now(),
            'notes' => $notes,
        ]);

        return $breach->fresh();
    }

    private function calculateEscalationLevel(OmDefect $defect): string
    {
        $overdueHours = now()->diffInHours($defect->sla_due_at);

        if ($overdueHours > $defect->sla_hours * 2) {
            return 'critical_breach';
        }

        return 'breach';
    }
}
