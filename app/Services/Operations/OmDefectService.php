<?php

namespace App\Services\Operations;

use App\Models\OmDefect;
use App\Models\OmWorkOrder;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OmDefectService
{
    /**
     * Default SLA Hours by Distress Type
     */
    public const SLA_MATRIX = [
        'pothole' => 4,
        'debris_illegal_dumping' => 1,
        'guardrail_crash_damage' => 24,
        'cable_theft_cut' => 12,
        'drain_clogged_flooding' => 12,
        'lighting_fixture_outage' => 24,
        'fence_breached' => 12,
        'signboard_damaged_missing' => 48,
        'expansion_joint_failure' => 48,
        'rutting_depression' => 72,
        'alligator_cracking' => 168, // 7 days
        'road_marking_faded' => 168,
        'vegetation_overgrowth' => 72,
        'other' => 48,
    ];

    /**
     * Get paginated defects with search and filters.
     */
    public function getDefects(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmDefect::query()->with(['asset', 'patrolShift', 'reporter', 'verifier', 'workOrder']);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['severity']) && $filters['severity'] !== 'all') {
            $query->where('severity', $filters['severity']);
        }

        if (! empty($filters['distress_type']) && $filters['distress_type'] !== 'all') {
            $query->where('distress_type', $filters['distress_type']);
        }

        if (! empty($filters['direction']) && $filters['direction'] !== 'all') {
            $query->where('direction', $filters['direction']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('defect_number', 'like', "%{$search}%")
                    ->orWhere('chainage', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get defect KPIs and SLA compliance.
     */
    public function getDefectStats(): array
    {
        $total = OmDefect::count();
        $open = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])->count();
        $rectifiedToday = OmDefect::whereDate('rectified_at', Carbon::today())->count();

        $overdueCount = OmDefect::whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
            ->where('sla_due_at', '<', now())
            ->count();

        $criticalCount = OmDefect::where('severity', 'critical')
            ->whereIn('status', ['reported', 'investigating', 'work_order_created', 'in_repair'])
            ->count();

        return [
            'total_defects' => $total,
            'open_defects' => $open,
            'rectified_today' => $rectifiedToday,
            'sla_overdue_count' => $overdueCount,
            'critical_unresolved' => $criticalCount,
        ];
    }

    /**
     * Create a new defect and compute SLA deadline.
     */
    public function createDefect(array $data, ?string $userId = null): OmDefect
    {
        $distressType = $data['distress_type'] ?? 'pothole';
        $slaHours = self::SLA_MATRIX[$distressType] ?? 24;

        $data['defect_number'] = 'DEF-'.date('Ymd').'-'.rand(100, 999);
        $data['sla_hours'] = $slaHours;
        $data['sla_due_at'] = now()->addHours($slaHours);
        $data['reported_by'] = $userId ?? $data['reported_by'] ?? null;
        $data['status'] = 'reported';

        return OmDefect::create($data);
    }

    /**
     * Convert defect into a Maintenance Work Order.
     */
    public function convertToWorkOrder(OmDefect $defect, array $woData, string $userId, int $expectedVersion): OmWorkOrder
    {
        return DB::transaction(function () use ($defect, $woData, $userId, $expectedVersion) {
            $locked = OmDefect::query()->lockForUpdate()->findOrFail($defect->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);

            if (! in_array($locked->status, ['reported', 'investigating'], true)
                || OmWorkOrder::query()->where('defect_id', $locked->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'defect' => 'This defect already has a work order or is no longer eligible for conversion.',
                ]);
            }

            $woNumber = 'WO-'.rand(10000, 99999);

            $workOrder = OmWorkOrder::create([
                'work_order_number' => $woNumber,
                'defect_id' => $locked->id,
                'asset_id' => $locked->asset_id,
                'title' => $woData['title'] ?? ('Rectification: '.$locked->title),
                'work_type' => $woData['work_type'] ?? 'routine_corrective',
                'category' => $woData['category'] ?? $this->mapDistressToCategory($locked->distress_type),
                'location' => $locked->chainage.' ('.ucfirst($locked->direction).')',
                'priority' => $woData['priority'] ?? $locked->severity,
                'status' => 'assigned',
                'assigned_to' => $woData['assigned_to'] ?? 'Road Maintenance Crew A',
                'contractor_name' => $woData['contractor_name'] ?? null,
                'description' => $locked->description,
                'reported_by' => $userId,
                'assigned_by' => $userId,
                'target_start_at' => $woData['target_start_at'] ?? now(),
                'target_end_at' => $woData['target_end_at'] ?? $locked->sla_due_at,
                'estimated_cost' => $woData['estimated_cost'] ?? 0,
                'requires_lane_closure' => $woData['requires_lane_closure'] ?? false,
            ]);

            $locked->update([
                'status' => 'work_order_created',
                'lock_version' => OmVersionGuard::next($locked),
            ]);

            return $workOrder;
        });
    }

    /**
     * Mark defect as rectified with before/after photos.
     */
    public function markRectified(OmDefect $defect, array $data, ?string $userId = null): OmDefect
    {
        $defect->update([
            'status' => 'rectified',
            'rectified_at' => now(),
            'rectification_notes' => $data['rectification_notes'] ?? $defect->rectification_notes,
            'after_photos' => $data['after_photos'] ?? $defect->after_photos,
        ]);

        return $defect->fresh();
    }

    /**
     * Verify and close defect.
     */
    public function verifyAndClose(OmDefect $defect, string $verifierUserId, ?string $notes = null): OmDefect
    {
        $defect->update([
            'status' => 'verified_closed',
            'verified_by' => $verifierUserId,
            'verified_at' => now(),
            'rectification_notes' => $notes ? ($defect->rectification_notes."\nVerification: ".$notes) : $defect->rectification_notes,
        ]);

        return $defect->fresh();
    }

    private function mapDistressToCategory(string $distress): string
    {
        return match ($distress) {
            'guardrail_crash_damage' => 'guardrail',
            'lighting_fixture_outage', 'cable_theft_cut' => 'lighting',
            'drain_clogged_flooding', 'culvert_obstruction' => 'drainage',
            'expansion_joint_failure' => 'bridge',
            'signboard_damaged_missing' => 'signage',
            default => 'pavement',
        };
    }
}
