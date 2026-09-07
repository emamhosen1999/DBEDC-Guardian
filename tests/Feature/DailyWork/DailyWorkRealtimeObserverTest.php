<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\User;
use App\Observers\DailyWorkRealtimeObserver;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Mockery;
use Tests\TestCase;

class DailyWorkRealtimeObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        auth()->forgetGuards();
        Mockery::close();
        parent::tearDown();
    }

    public function test_daily_work_observer_is_registered_and_runs_after_commit(): void
    {
        new DailyWork;
        $listeners = DailyWork::getEventDispatcher()?->getListeners('eloquent.created: '.DailyWork::class) ?? [];

        $this->assertNotEmpty($listeners);
        $this->assertInstanceOf(
            ShouldHandleEventsAfterCommit::class,
            app(DailyWorkRealtimeObserver::class)
        );
    }

    public function test_observer_publishes_the_shared_dailywork_marker_with_string_actor(): void
    {
        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')
            ->once()
            ->with('dailywork', 'all', 'EMP-00088', 'updated');

        auth()->setUser(new User([
            'employee_id' => 'EMP-00088',
            'name' => 'Daily Work User',
        ]));

        $observer = new DailyWorkRealtimeObserver($signal);

        $observer->updated(new DailyWork);
        $this->addToAssertionCount(1);
    }
}
