<?php

namespace App\Observers;

use App\Models\DailyWork;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Auth;

class DailyWorkRealtimeObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RealtimeSignal $signal) {}

    public function created(DailyWork $dailyWork): void
    {
        $this->publish('created');
    }

    public function updated(DailyWork $dailyWork): void
    {
        $this->publish('updated');
    }

    public function deleted(DailyWork $dailyWork): void
    {
        $this->publish('deleted');
    }

    public function restored(DailyWork $dailyWork): void
    {
        $this->publish('restored');
    }

    private function publish(string $verb): void
    {
        $this->signal->touch('dailywork', 'all', Auth::id(), $verb);
    }
}
