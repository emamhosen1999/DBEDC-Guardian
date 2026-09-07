<?php

namespace Tests\Feature\Operations;

use App\Models\OmIncident;
use App\Models\OmShiftLog;
use App\Models\OmWorkOrder;
use App\Services\Operations\OmIncidentService;
use App\Services\Operations\OmShiftService;
use App\Services\Operations\OmWorkOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OperationsWorkflowStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_order_cannot_skip_from_pending_to_in_progress(): void
    {
        $workOrder = $this->workOrder(['status' => 'pending']);

        $this->expectException(ValidationException::class);
        app(OmWorkOrderService::class)->startWorkOrder($workOrder, $workOrder->lock_version);
    }

    public function test_work_order_reporter_cannot_approve_their_own_work(): void
    {
        $workOrder = $this->workOrder([
            'status' => 'pending',
            'reported_by' => 'EMP-OM-01',
        ]);

        $this->expectException(ValidationException::class);
        app(OmWorkOrderService::class)->approveWorkOrder($workOrder, 'EMP-OM-01', $workOrder->lock_version);
    }

    public function test_work_order_follows_the_legal_lifecycle(): void
    {
        $service = app(OmWorkOrderService::class);
        $workOrder = $this->workOrder([
            'status' => 'pending',
            'reported_by' => 'EMP-OM-02',
        ]);

        $workOrder = $service->approveWorkOrder($workOrder, 'EMP-OM-03', $workOrder->lock_version);
        $this->assertSame('assigned', $workOrder->status);
        $this->assertSame(1, $workOrder->lock_version);

        $workOrder = $service->startWorkOrder($workOrder, $workOrder->lock_version);
        $this->assertSame('in_progress', $workOrder->status);

        $workOrder = $service->completeWorkOrder($workOrder, [], $workOrder->lock_version);
        $this->assertSame('completed', $workOrder->status);

        $workOrder = $service->verifyAndClose($workOrder, 'EMP-OM-04', 'Checked', $workOrder->lock_version);
        $this->assertSame('verified', $workOrder->status);
        $this->assertSame(4, $workOrder->lock_version);
    }

    public function test_incident_status_cannot_skip_forward(): void
    {
        $incident = OmIncident::create([
            'incident_number' => 'INC-STATE-01',
            'title' => 'Test incident',
            'chainage' => 'Ch 1+000',
            'direction' => 'northbound',
            'severity' => 'major',
            'status' => 'dispatched',
            'reported_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(OmIncidentService::class)->updateStatus($incident, 'closed', [], $incident->lock_version);
    }

    public function test_outgoing_operator_cannot_acknowledge_own_handover(): void
    {
        $shiftLog = OmShiftLog::create([
            'shift_date' => now()->toDateString(),
            'shift_type' => 'morning',
            'operator_id' => 'EMP-OM-05',
            'handover_notes' => 'Handover test',
            'is_acknowledged' => false,
        ]);

        $this->expectException(ValidationException::class);
        app(OmShiftService::class)->acknowledgeShiftLog($shiftLog, 'EMP-OM-05', $shiftLog->lock_version);
    }

    private function workOrder(array $overrides = []): OmWorkOrder
    {
        return OmWorkOrder::create(array_merge([
            'work_order_number' => 'WO-'.fake()->unique()->numberBetween(10000, 99999),
            'title' => 'Test work order',
            'category' => 'pavement',
            'location' => 'Ch 2+000',
            'priority' => 'medium',
            'status' => 'pending',
        ], $overrides));
    }
}
