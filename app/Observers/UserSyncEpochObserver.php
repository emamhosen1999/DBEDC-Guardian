<?php

namespace App\Observers;

use App\Models\User;

/**
 * Bumps a user's monotonic sync_epoch when one of their daily-work VISIBILITY
 * INPUTS changes on the user row itself.
 *
 * The mobile delta-sync engine scopes daily_works by report_to (team visibility),
 * designation title, and role (see DataSyncService::baseDailyWorksQuery). When any
 * of those inputs changes, the user's whole visible set shifts at once with NO
 * per-row event to tombstone — so instead the epoch is advanced, forcing every one
 * of the user's devices to re-bootstrap on its next pull.
 *
 * This observer covers only the direct COLUMN changes. Role assignment/removal is a
 * Spatie pivot write that fires no `updated` model event, so it is bumped separately
 * from the role-assignment service (UserManagementService).
 *
 * department_id is watched because it is a declared scope input even though the
 * current predicate keys on designation/role — bumping on it keeps the epoch honest
 * if the scope query ever starts consulting department.
 *
 * Best-effort: a bump failure must never roll back or break the profile update that
 * triggered it. bumpSyncEpoch() writes through the query builder (not a model save),
 * so it fires no further `updated` event and cannot recurse into this handler.
 */
class UserSyncEpochObserver
{
    public function updated(User $user): void
    {
        if (! $user->wasChanged(['report_to', 'department_id', 'designation_id'])) {
            return;
        }

        try {
            $user->bumpSyncEpoch();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
