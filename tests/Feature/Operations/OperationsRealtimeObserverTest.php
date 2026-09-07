<?php

namespace Tests\Feature\Operations;

use App\Models\OmAsset;
use App\Models\OmAssetConditionSurvey;
use App\Models\OmDefect;
use App\Models\OmEquipment;
use App\Models\OmIncident;
use App\Models\OmIncidentPhoto;
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
use App\Observers\OperationsRealtimeObserver;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Mockery;
use Tests\TestCase;

class OperationsRealtimeObserverTest extends TestCase
{
    /** @var array<int, class-string> */
    private array $models = [
        OmAsset::class,
        OmAssetConditionSurvey::class,
        OmDefect::class,
        OmEquipment::class,
        OmIncident::class,
        OmIncidentPhoto::class,
        OmIncidentVehicle::class,
        OmLaneClosurePermit::class,
        OmPatrolShift::class,
        OmShiftLog::class,
        OmTollExemption::class,
        OmTollRecord::class,
        OmTollShiftAudit::class,
        OmTrafficLog::class,
        OmVmsMessage::class,
        OmWorkOrder::class,
        OmWorkOrderCrew::class,
        OmWorkOrderMaterial::class,
    ];

    protected function tearDown(): void
    {
        auth()->forgetGuards();
        Mockery::close();
        parent::tearDown();
    }

    public function test_every_operations_model_has_the_after_commit_observer_registered(): void
    {
        foreach ($this->models as $model) {
            $listeners = $model::getEventDispatcher()?->getListeners("eloquent.created: {$model}") ?? [];

            $this->assertNotEmpty($listeners, "{$model} must publish operations invalidations.");
        }

        $this->assertInstanceOf(
            ShouldHandleEventsAfterCommit::class,
            app(OperationsRealtimeObserver::class)
        );
    }

    public function test_observer_publishes_an_id_only_operations_marker_for_string_employee_actor(): void
    {
        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')
            ->once()
            ->with('operations', 'all', 'EMP-00042', 'om-defect.created');

        auth()->setUser(new User([
            'employee_id' => 'EMP-00042',
            'name' => 'Operations User',
        ]));

        (new OperationsRealtimeObserver($signal))->created(new OmDefect(['title' => 'Pothole']));

        $this->addToAssertionCount(1); // Mockery verifies the exact marker contract in tearDown().
    }
}
