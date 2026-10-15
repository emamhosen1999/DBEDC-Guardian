<?php

namespace App\Policies;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\DailyWork\DailyWorkVisibility;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * One named permission per Daily Works action, the same names the routes and the UI use, plus the one visibility rule
 * (DailyWorkVisibility) that decides WHICH works the viewer may act on:
 *
 *   view / viewAny           daily-works.view
 *   create                   daily-works.create
 *   update                   daily-works.update
 *   delete                   daily-works.delete   (a soft delete: nothing is ever removed for good)
 *   restore                  daily-works.restore
 *   updateStatus *           daily-works.status.update   (status, inspection result, completion time, RFI dates)
 *   reopen                   daily-works.status.reopen
 *   updateInspectionDetails  daily-works.inspection.update
 *   updateAssigned           daily-works.assign
 *   updateIncharge           daily-works.incharge.update
 *   files                    daily-works.files.manage
 *   history                  daily-works.history.view
 *   bulk                     daily-works.bulk (and the action's own permission)
 *   import / export          daily-works.import / daily-works.export; reverseImport: daily-works.import.reverse
 */
class DailyWorkPolicy
{
    use HandlesAuthorization;

    private readonly DailyWorkVisibility $scope;

    /** The scope service is injected by the container; code that builds the policy by hand gets the same shared instance. */
    public function __construct(?DailyWorkVisibility $scope = null)
    {
        $this->scope = $scope ?? app(DailyWorkVisibility::class);
    }

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.view');
    }

    public function view(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.view') && $this->scope->allows($user, $dailyWork, 'view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.create');
    }

    public function update(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.update') && $this->scope->allows($user, $dailyWork, 'incharge');
    }

    public function delete(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.delete') && $this->scope->allows($user, $dailyWork, 'incharge');
    }

    /** Restore a soft-deleted work: its own permission, and the work must be one the viewer could see. */
    public function restore(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.restore') && $this->scope->allows($user, $dailyWork, 'view');
    }

    /** There are no hard deletes. */
    public function forceDelete(User $user, DailyWork $dailyWork): bool
    {
        return false;
    }

    public function updateStatus(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.status.update') && $this->scope->allows($user, $dailyWork, 'assigned');
    }

    public function updateCompletionTime(User $user, DailyWork $dailyWork): bool
    {
        return $this->updateStatus($user, $dailyWork);
    }

    public function updateSubmissionTime(User $user, DailyWork $dailyWork): bool
    {
        return $this->updateStatus($user, $dailyWork);
    }

    /** Moving a closed RFI back to an open state. */
    public function reopen(User $user, DailyWork $dailyWork): bool
    {
        return $this->updateStatus($user, $dailyWork) && $user->hasPermissionTo('daily-works.status.reopen');
    }

    public function updateInspectionDetails(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.inspection.update') && $this->scope->allows($user, $dailyWork, 'assigned');
    }

    /** Reassignment is an event (a recorded revision), and a completed work never changes hands. */
    public function updateIncharge(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.incharge.update') && $this->scope->allows($user, $dailyWork, 'incharge');
    }

    public function updateAssigned(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.assign') && $this->scope->allows($user, $dailyWork, 'incharge');
    }

    public function manageFiles(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.files.manage') && $this->scope->allows($user, $dailyWork, 'assigned');
    }

    public function history(User $user, DailyWork $dailyWork): bool
    {
        return $user->hasPermissionTo('daily-works.history.view') && $this->scope->allows($user, $dailyWork, 'view');
    }

    public function bulk(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.bulk');
    }

    public function export(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.export');
    }

    /** Importing creates works, so it needs daily-works.create as before; the route also needs daily-works.import. */
    public function import(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.create');
    }

    public function reverseImport(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.import.reverse');
    }
}
