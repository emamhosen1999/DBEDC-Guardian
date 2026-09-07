<?php

namespace Tests\Feature\Holiday;

use App\Models\HRM\Holiday;
use App\Models\User;
use App\Observers\HolidayRealtimeObserver;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Mockery;
use Tests\TestCase;

class HolidayRealtimeObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        auth()->forgetGuards();
        Mockery::close();
        parent::tearDown();
    }

    public function test_holiday_changes_are_observed_after_commit(): void
    {
        new Holiday;
        $listeners = Holiday::getEventDispatcher()?->getListeners('eloquent.created: '.Holiday::class) ?? [];

        $this->assertNotEmpty($listeners);
        $this->assertInstanceOf(
            ShouldHandleEventsAfterCommit::class,
            app(HolidayRealtimeObserver::class)
        );
    }

    public function test_observer_publishes_the_shared_holiday_marker_with_string_actor(): void
    {
        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')
            ->once()
            ->with('holiday', 'all', 'EMP-HOLIDAY-7', 'updated');

        auth()->setUser(new User([
            'employee_id' => 'EMP-HOLIDAY-7',
            'name' => 'Holiday Manager',
        ]));

        (new HolidayRealtimeObserver($signal))->updated(new Holiday);
        $this->addToAssertionCount(1);
    }
}
