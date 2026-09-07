<?php

namespace App\Observers;

use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PettyCashRealtimeObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(private readonly RealtimeSignal $signal) {}

    public function created(Model $model): void
    {
        $this->publish($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->publish($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->publish($model, 'deleted');
    }

    private function publish(Model $model, string $verb): void
    {
        $resource = Str::kebab(class_basename($model));

        $this->signal->touch('pettycash', 'all', Auth::id(), "{$resource}.{$verb}");
    }
}
