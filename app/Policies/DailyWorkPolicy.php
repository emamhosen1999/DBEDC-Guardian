<?php

namespace App\Policies;

use App\Models\DailyWork;
use App\Models\Jurisdiction;
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

        // Department Manager can view works where incharge or assigned user is in their department
        if ($this->isDepartmentManager($user)) {
            return $this->isDepartmentWork($user, $dailyWork);
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::sections()->where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can view works where they are incharge
                return (string) $dailyWork->incharge === (string) $user->id;
            } else {
                // Employee has no jurisdiction: can view works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can view own works
                return (string) $dailyWork->incharge === (string) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): can view if incharge/assigned OR manager is incharge
        if ($this->isInchargeOrAssigned($user, $dailyWork)) {
            return true;
        }

        // User can view if their manager (report_to) is the incharge
        return $this->isReportsToIncharge($user, $dailyWork);
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

        // Department Manager can update works where incharge or assigned user is in their department
        if ($this->isDepartmentManager($user)) {
            return $this->isDepartmentWork($user, $dailyWork);
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::sections()->where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can update works where they are incharge
                return (string) $dailyWork->incharge === (string) $user->id;
            } else {
                // Employee has no jurisdiction: can update works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can update own works
                return (string) $dailyWork->incharge === (string) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): incharge can update
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

        // Department Manager can delete works where incharge or assigned user is in their department
        if ($this->isDepartmentManager($user)) {
            return $this->isDepartmentWork($user, $dailyWork);
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::sections()->where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can delete works where they are incharge
                return (string) $dailyWork->incharge === (string) $user->id;
            } else {
                // Employee has no jurisdiction: can delete works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can delete own works
                return (string) $dailyWork->incharge === (string) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): incharge can delete
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

        // Department Manager can update status for works in their department
        if ($this->isDepartmentManager($user)) {
            return $this->isDepartmentWork($user, $dailyWork);
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::sections()->where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can update status of works where they are incharge
                return (string) $dailyWork->incharge === (string) $user->id;
            } else {
                // Employee has no jurisdiction: can update status of works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can update status of own works
                return (string) $dailyWork->incharge === (string) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): incharge or assigned can update status
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

        // Department Manager can update inspection details for works in their department
        if ($this->isDepartmentManager($user)) {
            return $this->isDepartmentWork($user, $dailyWork);
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::sections()->where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can update inspection details of works where they are incharge
                return (string) $dailyWork->incharge === (string) $user->id;
            } else {
                // Employee has no jurisdiction: can update inspection details of works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can update inspection details of own works
                return (string) $dailyWork->incharge === (string) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): incharge or assigned can update inspection details
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

        // Department Manager can assign users for works in their department
        if ($this->isDepartmentManager($user)) {
            return $this->isDepartmentWork($user, $dailyWork);
        }

        // Employee logic based on jurisdiction incharge
        if ($user->hasRole('Employee')) {
            // Check if user is incharge of any jurisdiction
            $hasJurisdiction = Jurisdiction::sections()->where('incharge', $user->id)->exists();

            if ($hasJurisdiction) {
                // Employee has jurisdiction (is incharge of a jurisdiction): can assign users to works where they are incharge
                return (string) $dailyWork->incharge === (string) $user->id;
            } else {
                // Employee has no jurisdiction: can assign users to works where their manager (report_to) is incharge
                if ($user->report_to) {
                    return (string) $dailyWork->incharge === (string) $user->report_to;
                }

                // No jurisdiction and no manager: can assign users to own works
                return (string) $dailyWork->incharge === (string) $user->id;
            }
        }

        // For other roles (non-employee, non-admin): incharge can assign
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
     * Check if user is a Department Manager with a department assignment.
     */
    private function isDepartmentManager(User $user): bool
    {
        return $user->hasRole('Department Manager') && $user->department_id !== null;
    }

    /**
     * Check if a daily work belongs to the department manager's department.
     * A work "belongs" to a department if either its incharge user or assigned
     * user is a member of that department.
     */
    private function isDepartmentWork(User $manager, DailyWork $dailyWork): bool
    {
        $deptId = $manager->department_id;

        // Check if the incharge user is in the manager's department
        if ($dailyWork->incharge) {
            $inchargeUser = User::find($dailyWork->incharge);
            if ($inchargeUser && (int) $inchargeUser->department_id === (int) $deptId) {
                return true;
            }
        }

        // Check if the assigned user is in the manager's department
        if ($dailyWork->assigned) {
            $assignedUser = User::find($dailyWork->assigned);
            if ($assignedUser && (int) $assignedUser->department_id === (int) $deptId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user is the incharge for this daily work.
     */
    private function isIncharge(User $user, DailyWork $dailyWork): bool
    {
        return (string) $dailyWork->incharge === (string) $user->id;
    }

    /**
     * Check if user is the assigned user for this daily work.
     */
    private function isAssigned(User $user, DailyWork $dailyWork): bool
    {
        return (string) $dailyWork->assigned === (string) $user->id;
    }

    /**
     * Check if user is either incharge or assigned.
     */
    private function isInchargeOrAssigned(User $user, DailyWork $dailyWork): bool
    {
        return $this->isIncharge($user, $dailyWork) || $this->isAssigned($user, $dailyWork);
    }

    /**
     * Check if user's manager (report_to) is the incharge for this daily work.
     */
    private function isReportsToIncharge(User $user, DailyWork $dailyWork): bool
    {
        // If user has no manager, they can't view through this relationship
        if (! $user->report_to) {
            return false;
        }

        // Check if the user's manager is the incharge of this daily work
        return (string) $dailyWork->incharge === (string) $user->report_to;
    }
}

