<?php

namespace App\Observers;

use App\Models\HRM\Holiday;
use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Support\Facades\Auth;

class HolidayRealtimeObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RealtimeSignal $signal) {}

    public function created(Holiday $holiday): void
    {
        $this->publish('created');
    }

    public function updated(Holiday $holiday): void
    {
        $this->publish('updated');
    }

    public function deleted(Holiday $holiday): void
    {
        $this->publish('deleted');
    }

    public function restored(Holiday $holiday): void
    {
        $this->publish('restored');
    }

    private function publish(string $action): void
    {
        $this->signal->touch('holiday', 'all', Auth::id(), $action);
    }
}
