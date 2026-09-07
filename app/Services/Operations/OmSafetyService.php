<?php

namespace App\Services\Operations;

use App\Models\OmSafetyIncident;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class OmSafetyService
{
    /**
     * Get paginated safety incidents with filters.
     */
    public function getSafetyIncidents(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmSafetyIncident::query()->with(['reporter', 'investigator', 'closer']);

        if (! empty($filters['incident_type']) && $filters['incident_type'] !== 'all') {
            $query->where('incident_type', $filters['incident_type']);
        }

        if (! empty($filters['severity']) && $filters['severity'] !== 'all') {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('safety_number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->latest('occurred_at')->paginate($perPage);
    }

    /**
     * Safety Dashboard KPIs.
     */
    public function getSafetyStats(): array
    {
        $total = OmSafetyIncident::count();
        $thisMonth = OmSafetyIncident::where('occurred_at', '>=', now()->startOfMonth())->count();
        $open = OmSafetyIncident::whereIn('status', ['reported', 'investigating', 'corrective_action'])->count();
        $nearMisses = OmSafetyIncident::where('incident_type', 'near_miss')->count();
        $injuries = OmSafetyIncident::where('incident_type', 'workplace_injury')->count();
        $lostTimeHours = OmSafetyIncident::sum('lost_time_hours');
        $ppeViolations = OmSafetyIncident::where('ppe_worn', false)->count();
        $toolboxCompliance = $total > 0
            ? round(OmSafetyIncident::where('toolbox_talk_done', true)->count() / $total * 100, 1)
            : 100;

        // Days since last recordable incident
        $lastIncident = OmSafetyIncident::where('incident_type', 'workplace_injury')
            ->latest('occurred_at')->first();
        $daysSinceLastIncident = $lastIncident
            ? (int) now()->diffInDays($lastIncident->occurred_at)
            : null;

        return [
            'total_incidents' => $total,
            'this_month' => $thisMonth,
            'open_incidents' => $open,
            'near_misses' => $nearMisses,
            'injuries' => $injuries,
            'lost_time_hours' => (int) $lostTimeHours,
            'ppe_violations' => $ppeViolations,
            'toolbox_compliance_pct' => $toolboxCompliance,
            'days_since_last_injury' => $daysSinceLastIncident,
        ];
    }

    /**
     * Report a new safety incident.
     */
    public function reportIncident(array $data, ?int $userId): OmSafetyIncident
    {
        $number = 'SAF-'.date('Y').'-'.str_pad(
            (OmSafetyIncident::whereYear('created_at', now()->year)->count() + 1), 4, '0', STR_PAD_LEFT
        );

        return OmSafetyIncident::create([
            'safety_number' => $number,
            'title' => $data['title'],
            'incident_type' => $data['incident_type'] ?? 'near_miss',
            'severity' => $data['severity'] ?? 'minor',
            'location' => $data['location'] ?? null,
            'chainage' => $data['chainage'] ?? null,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'occurred_at' => $data['occurred_at'] ?? now(),
            'reported_by' => $userId,
            'reported_at' => now(),
            'description' => $data['description'] ?? null,
            'immediate_action_taken' => $data['immediate_action_taken'] ?? null,
            'persons_involved' => $data['persons_involved'] ?? null,
            'photo_paths' => $data['photo_paths'] ?? null,
            'ppe_worn' => $data['ppe_worn'] ?? true,
            'toolbox_talk_done' => $data['toolbox_talk_done'] ?? false,
            'work_order_ref' => $data['work_order_ref'] ?? null,
            'status' => 'reported',
        ]);
    }

    /**
     * Update safety incident status (investigate, close).
     */
    public function updateStatus(OmSafetyIncident $incident, string $status, array $data, ?int $userId): OmSafetyIncident
    {
        $update = ['status' => $status];

        if ($status === 'investigating') {
            $update['investigated_by'] = $userId;
            $update['investigated_at'] = now();
            $update['root_cause'] = $data['root_cause'] ?? $incident->root_cause;
        }

        if ($status === 'corrective_action') {
            $update['corrective_action'] = $data['corrective_action'] ?? $incident->corrective_action;
        }

        if ($status === 'closed') {
            $update['closed_by'] = $userId;
            $update['closed_at'] = now();
            $update['lost_time_hours'] = $data['lost_time_hours'] ?? $incident->lost_time_hours;
            $update['corrective_action'] = $data['corrective_action'] ?? $incident->corrective_action;
        }

        $incident->update($update);

        return $incident->fresh();
    }
}
