<?php

namespace App\Services\Operations;

use App\Models\OmPreventiveSchedule;
use App\Models\OmWorkOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class OmPreventiveMaintenanceService
{
    /**
     * Get paginated PM schedules with filters.
     */
    public function getSchedules(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmPreventiveSchedule::query()->with(['asset', 'creator']);

        if (! empty($filters['asset_category']) && $filters['asset_category'] !== 'all') {
            $query->where('asset_category', $filters['asset_category']);
        }

        if (! empty($filters['frequency_type']) && $filters['frequency_type'] !== 'all') {
            $query->where('frequency_type', $filters['frequency_type']);
        }

        if (! empty($filters['status'])) {
            if ($filters['status'] === 'active') {
                $query->where('is_active', true);
            } elseif ($filters['status'] === 'overdue') {
                $query->where('is_active', true)
                    ->where('next_due_at', '<=', now()->startOfDay());
            } elseif ($filters['status'] === 'inactive') {
                $query->where('is_active', false);
            }
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('schedule_code', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * PM Dashboard Statistics.
     */
    public function getScheduleStats(): array
    {
        $active = OmPreventiveSchedule::where('is_active', true)->count();
        $overdue = OmPreventiveSchedule::where('is_active', true)
            ->where('next_due_at', '<=', now()->startOfDay())
            ->count();
        $dueThisWeek = OmPreventiveSchedule::where('is_active', true)
            ->whereBetween('next_due_at', [now()->startOfDay(), now()->addWeek()])
            ->count();
        $dueThisMonth = OmPreventiveSchedule::where('is_active', true)
            ->whereBetween('next_due_at', [now()->startOfDay(), now()->addMonth()])
            ->count();
        $totalGenerated = OmWorkOrder::whereNotNull('preventive_schedule_id')->count();
        $completionRate = $totalGenerated > 0
            ? round(OmWorkOrder::whereNotNull('preventive_schedule_id')
                ->whereIn('status', ['completed', 'verified'])->count() / $totalGenerated * 100, 1)
            : 0;

        return [
            'active_schedules' => $active,
            'overdue' => $overdue,
            'due_this_week' => $dueThisWeek,
            'due_this_month' => $dueThisMonth,
            'total_pm_work_orders' => $totalGenerated,
            'pm_completion_rate' => $completionRate,
        ];
    }

    /**
     * Create a new Preventive Maintenance Schedule.
     */
    public function createSchedule(array $data, ?int $userId): OmPreventiveSchedule
    {
        $code = 'PM-'.strtoupper(substr($data['asset_category'] ?? 'GEN', 0, 4)).'-'.str_pad(
            (OmPreventiveSchedule::count() + 1), 3, '0', STR_PAD_LEFT
        );

        $schedule = OmPreventiveSchedule::create([
            'schedule_code' => $code,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'asset_category' => $data['asset_category'] ?? 'pavement_civil',
            'asset_id' => $data['asset_id'] ?? null,
            'frequency_type' => $data['frequency_type'] ?? 'monthly',
            'frequency_interval_days' => $data['frequency_interval_days'] ?? 30,
            'priority' => $data['priority'] ?? 'medium',
            'assigned_to' => $data['assigned_to'] ?? null,
            'contractor_name' => $data['contractor_name'] ?? null,
            'estimated_cost' => $data['estimated_cost'] ?? 0,
            'estimated_duration_hours' => $data['estimated_duration_hours'] ?? 2,
            'chainage_from' => $data['chainage_from'] ?? null,
            'chainage_to' => $data['chainage_to'] ?? null,
            'direction' => $data['direction'] ?? 'both',
            'requires_lane_closure' => ! empty($data['requires_lane_closure']),
            'checklist_items' => $data['checklist_items'] ?? null,
            'required_materials' => $data['required_materials'] ?? null,
            'next_due_at' => $data['next_due_at'] ?? now()->addDays($data['frequency_interval_days'] ?? 30),
            'is_active' => true,
            'created_by' => $userId,
        ]);

        return $schedule->fresh(['asset', 'creator']);
    }

    /**
     * Generate Work Orders from overdue PM schedules.
     */
    public function generateDueWorkOrders(?string $userId = null): array
    {
        $dueSchedules = OmPreventiveSchedule::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('next_due_at')
                    ->orWhere('next_due_at', '<=', now()->startOfDay());
            })
            ->get();

        $generated = [];

        foreach ($dueSchedules as $schedule) {
            $wo = DB::transaction(function () use ($schedule, $userId) {
                $woNumber = 'WO-PM-'.rand(10000, 99999);

                $workOrder = OmWorkOrder::create([
                    'work_order_number' => $woNumber,
                    'preventive_schedule_id' => $schedule->id,
                    'asset_id' => $schedule->asset_id,
                    'title' => '[PM] '.$schedule->title,
                    'work_type' => 'preventive_scheduled',
                    'category' => $this->mapAssetCategoryToWoCategory($schedule->asset_category),
                    'location' => trim(($schedule->chainage_from ?? '').' - '.($schedule->chainage_to ?? ''), ' -'),
                    'priority' => $schedule->priority,
                    'status' => 'pending',
                    'assigned_to' => $schedule->assigned_to,
                    'contractor_name' => $schedule->contractor_name,
                    'description' => "Preventive Maintenance: {$schedule->description}\n\nSchedule: {$schedule->schedule_code} ({$schedule->frequency_type})",
                    'reported_by' => $userId,
                    'assigned_by' => $userId,
                    'target_start_at' => now(),
                    'target_end_at' => now()->addHours((int) $schedule->estimated_duration_hours),
                    'estimated_cost' => $schedule->estimated_cost,
                    'requires_lane_closure' => $schedule->requires_lane_closure,
                ]);

                // Advance schedule dates
                $schedule->update([
                    'last_generated_at' => now(),
                    'next_due_at' => $schedule->calculateNextDueDate(),
                ]);

                return $workOrder;
            });

            $generated[] = $wo;
        }

        return $generated;
    }

    /**
     * Toggle active/inactive status.
     */
    public function toggleActive(OmPreventiveSchedule $schedule): OmPreventiveSchedule
    {
        $schedule->update(['is_active' => ! $schedule->is_active]);

        return $schedule->fresh();
    }

    private function mapAssetCategoryToWoCategory(string $assetCategory): string
    {
        return match ($assetCategory) {
            'pavement_civil' => 'pavement',
            'bridge_structure' => 'bridge',
            'guardrail_safety' => 'guardrail',
            'signage_marking' => 'signage',
            'drainage_slope' => 'drainage',
            'lighting_electrical' => 'lighting',
            default => 'pavement',
        };
    }
}
