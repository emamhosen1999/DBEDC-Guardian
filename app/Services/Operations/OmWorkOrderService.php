<?php

namespace App\Services\Operations;

use App\Models\OmLaneClosurePermit;
use App\Models\OmWorkOrder;
use App\Models\OmWorkOrderMaterial;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OmWorkOrderService
{
    /**
     * Get paginated work orders with relations and filters.
     */
    public function getWorkOrders(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = OmWorkOrder::query()->with([
            'defect',
            'asset',
            'reporter',
            'assigner',
            'approver',
            'verifier',
            'laneClosurePermit',
            'materials',
            'crews',
            'photos',
        ]);

        if (! empty($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['priority']) && $filters['priority'] !== 'all') {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }

        if (! empty($filters['work_type']) && $filters['work_type'] !== 'all') {
            $query->where('work_type', $filters['work_type']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('work_order_number', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%")
                    ->orWhere('assigned_to', 'like', "%{$search}%")
                    ->orWhere('contractor_name', 'like', "%{$search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Get KPI Summary for Work Orders.
     */
    public function getWorkOrderStats(): array
    {
        $total = OmWorkOrder::count();
        $inProgress = OmWorkOrder::where('status', 'in_progress')->count();
        $pendingApproval = OmWorkOrder::where('status', 'pending')->count();
        $completedPendingQc = OmWorkOrder::where('status', 'completed')->count();
        $verifiedClosed = OmWorkOrder::where('status', 'verified')->count();
        $highPriority = OmWorkOrder::whereIn('priority', ['high', 'emergency'])->whereNotIn('status', ['verified'])->count();
        $activeLaneClosures = OmLaneClosurePermit::where('status', 'active')->count();

        return [
            'total_work_orders' => $total,
            'in_progress' => $inProgress,
            'pending_approval' => $pendingApproval,
            'completed_pending_qc' => $completedPendingQc,
            'verified_closed' => $verifiedClosed,
            'high_priority_open' => $highPriority,
            'active_lane_closures' => $activeLaneClosures,
        ];
    }

    /**
     * Create Work Order with optional Materials and Lane Closure Request.
     */
    public function createWorkOrder(array $data, string $userId): OmWorkOrder
    {
        return DB::transaction(function () use ($data, $userId) {
            $woNumber = 'WO-'.rand(10000, 99999);

            $workOrder = OmWorkOrder::create([
                'work_order_number' => $woNumber,
                'defect_id' => $data['defect_id'] ?? null,
                'asset_id' => $data['asset_id'] ?? null,
                'title' => $data['title'],
                'work_type' => $data['work_type'] ?? 'routine_corrective',
                'category' => $data['category'] ?? 'pavement',
                'location' => $data['location'],
                'priority' => $data['priority'] ?? 'medium',
                'status' => $data['status'] ?? 'pending',
                'assigned_to' => $data['assigned_to'] ?? null,
                'contractor_name' => $data['contractor_name'] ?? null,
                'description' => $data['description'] ?? null,
                'reported_by' => $userId,
                'assigned_by' => $userId,
                'target_start_at' => $data['target_start_at'] ?? now(),
                'target_end_at' => $data['target_end_at'] ?? now()->addDays(2),
                'estimated_cost' => $data['estimated_cost'] ?? 0,
                'requires_lane_closure' => ! empty($data['requires_lane_closure']),
            ]);

            // Save BOQ Materials if provided
            if (! empty($data['materials']) && is_array($data['materials'])) {
                foreach ($data['materials'] as $mat) {
                    if (! empty($mat['item_name'])) {
                        $qty = (float) ($mat['quantity_planned'] ?? 1);
                        $unitCost = (float) ($mat['unit_cost'] ?? 0);
                        OmWorkOrderMaterial::create([
                            'work_order_id' => $workOrder->id,
                            'item_name' => $mat['item_name'],
                            'item_code' => $mat['item_code'] ?? null,
                            'unit' => $mat['unit'] ?? 'Nos',
                            'quantity_planned' => $qty,
                            'quantity_used' => (float) ($mat['quantity_used'] ?? 0),
                            'unit_cost' => $unitCost,
                            'total_cost' => $qty * $unitCost,
                        ]);
                    }
                }
            }

            // Create Lane Closure Permit Request if required
            if (! empty($data['requires_lane_closure']) && ! empty($data['lane_closure'])) {
                $lc = $data['lane_closure'];
                OmLaneClosurePermit::create([
                    'permit_number' => 'LCP-'.date('Ymd').'-'.rand(100, 999),
                    'work_order_id' => $workOrder->id,
                    'title' => 'Safety Zone: '.$workOrder->title,
                    'chainage_from' => $lc['chainage_from'] ?? $workOrder->location,
                    'chainage_to' => $lc['chainage_to'] ?? $workOrder->location,
                    'direction' => $lc['direction'] ?? 'northbound',
                    'lanes_closed' => $lc['lanes_closed'] ?? 'shoulder_only',
                    'scheduled_start' => $lc['scheduled_start'] ?? $workOrder->target_start_at,
                    'scheduled_end' => $lc['scheduled_end'] ?? $workOrder->target_end_at,
                    'status' => 'requested',
                    'requested_by' => $userId,
                    'traffic_control_plan' => $lc['traffic_control_plan'] ?? 'Standard Expressway Lane Closure Scheme (IRC:SP:55)',
                    'safety_cones_deployed' => (int) ($lc['safety_cones_deployed'] ?? 30),
                    'traffic_marshals_deployed' => (int) ($lc['traffic_marshals_deployed'] ?? 2),
                    'flashing_arrow_board_present' => (bool) ($lc['flashing_arrow_board_present'] ?? true),
                ]);
            }

            // Dispatch Push & In-App Notification for High/Emergency Work Orders
            if (in_array($workOrder->priority, ['emergency', 'high'], true)) {
                try {
                    $techs = \App\Models\User::permission('om.maintenance.manage')->get();
                    if ($techs->isNotEmpty()) {
                        \Illuminate\Support\Facades\Notification::send($techs, new \App\Notifications\OmAlertNotification(
                            "Work Order: {$workOrder->work_order_number}",
                            "{$workOrder->title} at {$workOrder->location}. Priority: {$workOrder->priority}.",
                            'work_order_assigned',
                            $workOrder->work_order_number,
                            '/om/work-orders'
                        ));
                    }
                } catch (\Throwable) {
                    // Fail-safe
                }
            }

            return $workOrder->fresh(['materials', 'laneClosurePermit']);
        });
    }

    /**
     * Approve Work Order.
     */
    public function approveWorkOrder(OmWorkOrder $workOrder, string $approverId, int $expectedVersion): OmWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $approverId, $expectedVersion) {
            $locked = OmWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);
            $this->assertStatus($locked, 'pending', 'approve');

            if ($locked->reported_by !== null && (string) $locked->reported_by === $approverId) {
                throw ValidationException::withMessages([
                    'approver' => 'The work-order reporter cannot approve the same work order.',
                ]);
            }

            $locked->update([
                'status' => 'assigned',
                'approved_by' => $approverId,
                'approved_at' => now(),
                'lock_version' => OmVersionGuard::next($locked),
            ]);

            return $locked->fresh();
        });
    }

    /**
     * Start execution of Work Order (Work Zone Active).
     */
    public function startWorkOrder(OmWorkOrder $workOrder, ?int $expectedVersion): OmWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $expectedVersion) {
            $locked = OmWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);
            $this->assertStatus($locked, 'assigned', 'start');

            $locked->update([
                'status' => 'in_progress',
                'actual_start_at' => now(),
                'lock_version' => OmVersionGuard::next($locked),
            ]);

            if ($locked->laneClosurePermit) {
                $locked->laneClosurePermit->update([
                    'status' => 'active',
                    'actual_start' => now(),
                ]);
            }

            return $locked->fresh();
        });
    }

    /**
     * Complete Work Order (submit for QC inspection).
     */
    public function completeWorkOrder(OmWorkOrder $workOrder, array $data, ?int $expectedVersion): OmWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $data, $expectedVersion) {
            $locked = OmWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);
            $this->assertStatus($locked, 'in_progress', 'complete');

            $locked->update([
                'status' => 'completed',
                'completed_at' => now(),
                'actual_cost' => $data['actual_cost'] ?? $locked->actual_cost,
                'lock_version' => OmVersionGuard::next($locked),
            ]);

            // Update materials used
            if (! empty($data['materials']) && is_array($data['materials'])) {
                foreach ($data['materials'] as $matData) {
                    if (! empty($matData['id'])) {
                        $mat = OmWorkOrderMaterial::where('work_order_id', $locked->id)->find($matData['id']);
                        if ($mat) {
                            $used = (float) ($matData['quantity_used'] ?? $mat->quantity_used);
                            $mat->update([
                                'quantity_used' => $used,
                                'total_cost' => $used * (float) $mat->unit_cost,
                            ]);
                        }
                    }
                }
            }

            // Close lane closure if active
            if ($locked->laneClosurePermit && $locked->laneClosurePermit->status === 'active') {
                $locked->laneClosurePermit->update([
                    'status' => 'cleared',
                    'actual_end' => now(),
                ]);
            }

            // Update linked defect if any
            if ($locked->defect) {
                $locked->defect->update([
                    'status' => 'rectified',
                    'rectified_at' => now(),
                ]);
            }

            return $locked->fresh(['materials', 'laneClosurePermit']);
        });
    }

    /**
     * Joint QC Verification and Final Signoff.
     */
    public function verifyAndClose(OmWorkOrder $workOrder, string $verifierId, ?string $qcNotes, int $expectedVersion): OmWorkOrder
    {
        return DB::transaction(function () use ($workOrder, $verifierId, $qcNotes, $expectedVersion) {
            $locked = OmWorkOrder::query()->lockForUpdate()->findOrFail($workOrder->getKey());
            OmVersionGuard::assertMatches($locked, $expectedVersion);
            $this->assertStatus($locked, 'completed', 'verify');

            if (collect([$locked->reported_by, $locked->approved_by])
                ->filter(fn ($id) => $id !== null)
                ->contains(fn ($id) => (string) $id === $verifierId)) {
                throw ValidationException::withMessages([
                    'verifier' => 'The reporter or approver cannot verify the same work order.',
                ]);
            }

            $locked->update([
                'status' => 'verified',
                'verified_by' => $verifierId,
                'verified_at' => now(),
                'qc_notes' => $qcNotes,
                'lock_version' => OmVersionGuard::next($locked),
            ]);

            if ($locked->defect) {
                $locked->defect->update([
                    'status' => 'verified_closed',
                    'verified_by' => $verifierId,
                    'verified_at' => now(),
                ]);
            }

            return $locked->fresh();
        });
    }

    private function assertStatus(OmWorkOrder $workOrder, string $expected, string $action): void
    {
        if ($workOrder->status !== $expected) {
            throw ValidationException::withMessages([
                'status' => "Work order must be {$expected} before it can {$action}.",
            ]);
        }
    }
}
