<?php

namespace App\Observers;

use App\Models\DailyWork;
use App\Services\Sync\DataSyncService;

/**
 * Turns a daily work's ownership changes into per-user sync tombstones.
 *
 * WHY AN OBSERVER RATHER THAN A SERVICE HOOK: `incharge`/`assigned` are written
 * from several independent paths — the web DailyWorkController, the API
 * DailyWorkController, DailyWorkRepository::updateIncharge/updateAssigned, the
 * bulk import, and the sync push channel. Every one of them writes through the
 * Eloquent model (verified: only reads use DB::table('daily_works')), so the
 * model event is the single choke point that no current — or future — call site
 * can bypass. A service hook would have to be wired into each path and would
 * silently miss the next one added.
 *
 * The tombstone write is best-effort: a failure here must never roll back or
 * break the reassignment that triggered it.
 */
class DailyWorkSyncVisibilityObserver
{
    public function __construct(private readonly DataSyncService $sync) {}

    /**
     * A reassignment is a DEPARTURE for whoever lost the row. The arrival side
     * needs nothing: the same write bumps `updated_at`, so the new owner's
     * visibility-scoped pull returns it as an ordinary row.
     */
    public function updated(DailyWork $dailyWork): void
    {
        if (! $dailyWork->wasChanged(['incharge', 'assigned'])) {
            return;
        }

        $this->guard(fn () => $this->sync->recordDailyWorkVisibilityDepartures($dailyWork, [
            'incharge' => $dailyWork->getOriginal('incharge'),
            'assigned' => $dailyWork->getOriginal('assigned'),
        ]));
    }

    /**
     * Soft delete: the row leaves EVERY viewer's scope (including privileged
     * roles) while still existing in the table, so `pull` would otherwise never
     * mention it again.
     *
     * No `forceDeleted` handler on purpose — SoftDeletes::forceDelete() routes
     * through the normal delete, so `deleted` already fires for it and a second
     * handler would emit every tombstone twice.
     */
    public function deleted(DailyWork $dailyWork): void
    {
        $this->guard(fn () => $this->sync->recordDailyWorkVisibilityDepartures(
            $dailyWork,
            [
                'incharge' => $dailyWork->getOriginal('incharge'),
                'assigned' => $dailyWork->getOriginal('assigned'),
            ],
            removed: true,
        ));
    }

    private function guard(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
