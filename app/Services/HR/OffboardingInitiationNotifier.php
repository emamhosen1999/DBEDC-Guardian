<?php

namespace App\Services\HR;

use App\Jobs\ProcessOffboardingLwd;
use App\Models\HRM\Department;
use App\Models\HRM\Offboarding;
use App\Models\User;
use App\Models\UserDepartmentScope;
use App\Notifications\Attendance\OffboardingInitiatedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Tells the employee's manager (report_to) and their department's managers right
 * away that an offboarding was initiated. ProcessOffboardingLwd notifies HR at the
 * end of the last working day; the people running the department must not learn
 * about a departure weeks later. Queued, dispatched after the surrounding commit.
 */
class OffboardingInitiationNotifier
{
    public function send(Offboarding $offboarding, User $employee): void
    {
        try {
            $managerIds = collect([$employee->report_to]);

            if ($employee->department_id) {
                $managerIds->push(Department::find($employee->department_id)?->manager_id);
                $managerIds = $managerIds->merge(
                    UserDepartmentScope::query()->active()->where('department_id', $employee->department_id)->pluck('user_id')
                );
            }

            $recipients = User::whereIn('employee_id', $managerIds->filter()->unique()->values())
                ->where('employee_id', '!=', $employee->employee_id)
                ->get();

            if ($recipients->isEmpty()) {
                return;
            }

            Notification::send($recipients, (new OffboardingInitiatedNotification(
                $employee->name,
                ProcessOffboardingLwd::reasonLabel($offboarding->reason),
                $offboarding->last_working_date->toDateString(),
                $offboarding->id,
            ))->afterCommit());
        } catch (\Throwable $e) {
            Log::error('Offboarding: initiation notification failed', [
                'offboarding_id' => $offboarding->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
