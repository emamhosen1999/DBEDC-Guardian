<?php

namespace App\Policies;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class DailyWorkPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any daily works.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.view');
    }

    /**
     * Determine whether the user can view the daily work.
     */
    public function view(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.view')) {
            return false;
        }

        // Admins can view any
        if ($this->isAdmin($user)) {
            return true;
        }

        // Incharge or assigned user can view
        return $this->isInchargeOrAssigned($user, $dailyWork);
    }

    /**
     * Determine whether the user can create daily works.
     */
    public function create(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.create');
    }

    /**
     * Determine whether the user can update the daily work.
     */
    public function update(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.update')) {
            return false;
        }

        // Admins can update any
        if ($this->isAdmin($user)) {
            return true;
        }

        // Incharge can update
        return $this->isIncharge($user, $dailyWork);
    }

    /**
     * Determine whether the user can delete the daily work.
     */
    public function delete(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.delete')) {
            return false;
        }

        // Admins can delete any
        if ($this->isAdmin($user)) {
            return true;
        }

        // Incharge can delete
        return $this->isIncharge($user, $dailyWork);
    }

    /**
     * Determine whether the user can restore the daily work.
     */
    public function restore(User $user, DailyWork $dailyWork): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determine whether the user can permanently delete the daily work.
     */
    public function forceDelete(User $user, DailyWork $dailyWork): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * Determine whether the user can update the status of the daily work.
     */
    public function updateStatus(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.view')) {
            return false;
        }

        // Admins can update status
        if ($this->isAdmin($user)) {
            return true;
        }

        // Incharge or assigned can update status
        return $this->isInchargeOrAssigned($user, $dailyWork);
    }

    /**
     * Determine whether the user can update the completion time.
     */
    public function updateCompletionTime(User $user, DailyWork $dailyWork): bool
    {
        return $this->updateStatus($user, $dailyWork);
    }

    /**
     * Determine whether the user can update the submission time.
     */
    public function updateSubmissionTime(User $user, DailyWork $dailyWork): bool
    {
        return $this->updateStatus($user, $dailyWork);
    }

    /**
     * Determine whether the user can update the inspection details.
     */
    public function updateInspectionDetails(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.view')) {
            return false;
        }

        // Admins can update
        if ($this->isAdmin($user)) {
            return true;
        }

        // Incharge or assigned can update inspection details
        return $this->isInchargeOrAssigned($user, $dailyWork);
    }

    /**
     * Determine whether the user can update the incharge.
     */
    public function updateIncharge(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.update')) {
            return false;
        }

        // Only admins can change incharge
        return $this->isAdmin($user);
    }

    /**
     * Determine whether the user can update the assigned user.
     */
    public function updateAssigned(User $user, DailyWork $dailyWork): bool
    {
        if (! $user->hasPermissionTo('daily-works.view')) {
            return false;
        }

        // Admins can assign
        if ($this->isAdmin($user)) {
            return true;
        }

        // Incharge can assign
        return $this->isIncharge($user, $dailyWork);
    }

    /**
     * Determine whether the user can export daily works.
     */
    public function export(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.export');
    }

    /**
     * Determine whether the user can import daily works.
     */
    public function import(User $user): bool
    {
        return $user->hasPermissionTo('daily-works.create');
    }

    /**
     * Check if user is an admin.
     */
    private function isAdmin(User $user): bool
    {
        return $user->hasRole('Super Administrator') || $user->hasRole('Administrator');
    }

    /**
     * Check if user is the incharge for this daily work.
     */
    private function isIncharge(User $user, DailyWork $dailyWork): bool
    {
        return (int) $dailyWork->incharge === (int) $user->id;
    }

    /**
     * Check if user is the assigned user for this daily work.
     */
    private function isAssigned(User $user, DailyWork $dailyWork): bool
    {
        return (int) $dailyWork->assigned === (int) $user->id;
    }

    /**
     * Check if user is either incharge or assigned.
     */
    private function isInchargeOrAssigned(User $user, DailyWork $dailyWork): bool
    {
        return $this->isIncharge($user, $dailyWork) || $this->isAssigned($user, $dailyWork);
    }
}
