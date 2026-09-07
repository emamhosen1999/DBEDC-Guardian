<?php

namespace App\Observers;

use App\Services\Realtime\RealtimeSignal;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Publishes one ID-only invalidation marker after an O&M record commits.
 *
 * O&M writes often touch several related records in one transaction. Each marker
 * is a SET to the same path, so a burst remains bounded and client subscribers
 * debounce it into one authenticated API refresh.
 */
class OperationsRealtimeObserver implements ShouldHandleEventsAfterCommit
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

    public function restored(Model $model): void
    {
        $this->publish($model, 'restored');
    }

    private function publish(Model $model, string $verb): void
    {
        $resource = Str::kebab(class_basename($model));

        $this->signal->touch('operations', 'all', Auth::id(), "{$resource}.{$verb}");
    }
}
