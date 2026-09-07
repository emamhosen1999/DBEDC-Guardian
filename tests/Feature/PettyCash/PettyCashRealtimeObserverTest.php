<?php

namespace Tests\Feature\PettyCash;

use App\Models\PettyCashAuditLog;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\User;
use App\Observers\PettyCashRealtimeObserver;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Mockery;
use Tests\TestCase;

class PettyCashRealtimeObserverTest extends TestCase
{
    protected function tearDown(): void
    {
        auth()->forgetGuards();
        Mockery::close();
        parent::tearDown();
    }

    public function test_all_petty_cash_records_publish_after_commit_invalidations(): void
    {
        foreach ([PettyCashLoan::class, PettyCashTransaction::class, PettyCashAuditLog::class] as $model) {
            $listeners = $model::getEventDispatcher()?->getListeners("eloquent.created: {$model}") ?? [];
            $this->assertNotEmpty($listeners, "{$model} must publish petty-cash invalidations.");
        }

        $this->assertInstanceOf(
            ShouldHandleEventsAfterCommit::class,
            app(PettyCashRealtimeObserver::class)
        );
    }

    public function test_observer_uses_the_shared_pettycash_bucket_and_string_actor(): void
    {
        $signal = Mockery::mock(RealtimeSignal::class);
        $signal->shouldReceive('touch')
            ->once()
            ->with('pettycash', 'all', 'EMP-00077', 'petty-cash-transaction.updated');

        auth()->setUser(new User([
            'employee_id' => 'EMP-00077',
            'name' => 'Petty Cash User',
        ]));

        (new PettyCashRealtimeObserver($signal))->updated(new PettyCashTransaction);
        $this->addToAssertionCount(1);
    }
}
