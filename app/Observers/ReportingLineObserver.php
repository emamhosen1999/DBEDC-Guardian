<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Org\ReportingLineService;

/** Records every change of users.report_to (and the first line of a new employee) in employee_reporting_lines. */
class ReportingLineObserver
{
    public function saved(User $user): void
    {
        if (! $user->wasChanged('report_to') && ! ($user->wasRecentlyCreated && $user->report_to)) {
            return;
        }
        try {
            app(ReportingLineService::class)->record((string) $user->employee_id, $user->report_to, auth()->id() !== null ? (string) auth()->id() : null);
        } catch (\Throwable $e) {
            report($e); // a history failure must never break the profile update that triggered it
        }
    }
}
