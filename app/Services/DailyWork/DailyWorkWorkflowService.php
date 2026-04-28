<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use App\Notifications\DailyWorkStatusChangeNotification;
use Illuminate\Support\Facades\Log;

class DailyWorkWorkflowService
{
    /**
     * Handle automatic status transitions based on business rules.
     */
    public function handleAutomaticTransitions(DailyWork $dailyWork, array $changedAttributes = []): void
    {
        $currentStatus = $dailyWork->status;
        $newStatus = null;

        // Rule 1: Completed work with passing inspection should move to final state
        if ($this->shouldAutoComplete($dailyWork)) {
            $newStatus = DailyWork::STATUS_COMPLETED;
        }

        // Rule 2: Work with active objections should be blocked
        elseif ($this->shouldBlockForObjections($dailyWork)) {
            $newStatus = DailyWork::STATUS_PENDING;
        }

        // Rule 3: Rejected RFI responses should trigger resubmission
        elseif ($this->shouldRequireResubmission($dailyWork)) {
            $newStatus = DailyWork::STATUS_RESUBMISSION;
        }

        // Rule 4: Overdue work should be escalated
        elseif ($this->shouldEscalateOverdue($dailyWork)) {
            $newStatus = DailyWork::STATUS_EMERGENCY;
        }

        // Apply the status change if needed
        if ($newStatus && $newStatus !== $currentStatus) {
            $this->applyStatusTransition($dailyWork, $newStatus, 'Automatic workflow transition');
        }
    }

    /**
     * Check if work should be auto-completed based on inspection results.
     */
    private function shouldAutoComplete(DailyWork $dailyWork): bool
    {
        // Only apply to completed work
        if ($dailyWork->status !== DailyWork::STATUS_COMPLETED) {
            return false;
        }

        // Must have a passing inspection result
        return in_array($dailyWork->inspection_result, [
            DailyWork::INSPECTION_PASS,
            DailyWork::INSPECTION_APPROVED,
        ], true);
    }

    /**
     * Check if work should be blocked due to active objections.
     */
    private function shouldBlockForObjections(DailyWork $dailyWork): bool
    {
        // Don't override completed work unless there are critical objections
        if ($dailyWork->status === DailyWork::STATUS_COMPLETED) {
            return false;
        }

        // Check for active objections
        return $dailyWork->active_objections_count > 0;
    }

    /**
     * Check if work should require resubmission based on RFI response.
     */
    private function shouldRequireResubmission(DailyWork $dailyWork): bool
    {
        return in_array($dailyWork->rfi_response_status, [
            DailyWork::RFI_RESPONSE_REJECTED,
            DailyWork::RFI_RESPONSE_RETURNED,
        ], true);
    }

    /**
     * Check if overdue work should be escalated.
     */
    private function shouldEscalateOverdue(DailyWork $dailyWork): bool
    {
        // Only apply to in-progress work
        if ($dailyWork->status !== DailyWork::STATUS_IN_PROGRESS) {
            return false;
        }

        // Check if planned completion time has passed
        if (!$dailyWork->planned_time || !$dailyWork->date) {
            return false;
        }

        $plannedCompletion = $dailyWork->date->copy()->setTimeFromTimeString($dailyWork->planned_time);
        $now = now();

        // Escalate if overdue by more than 2 hours
        return $now->greaterThan($plannedCompletion->addHours(2));
    }

    /**
     * Apply a status transition with logging and notifications.
     */
    private function applyStatusTransition(DailyWork $dailyWork, string $newStatus, string $reason): void
    {
        $oldStatus = $dailyWork->status;

        // Update the status
        $dailyWork->update(['status' => $newStatus]);

        // Log the transition
        Log::info('Daily Work status auto-transition', [
            'daily_work_id' => $dailyWork->id,
            'number' => $dailyWork->number,
            'from_status' => $oldStatus,
            'to_status' => $newStatus,
            'reason' => $reason,
            'triggered_at' => now()->toISOString(),
        ]);

        // Notify relevant users
        $this->notifyStatusChange($dailyWork, $oldStatus, $newStatus, $reason);
    }

    /**
     * Send notifications for status changes.
     */
    private function notifyStatusChange(DailyWork $dailyWork, string $oldStatus, string $newStatus, string $reason): void
    {
        $usersToNotify = collect();

        // Always notify the incharge
        if ($dailyWork->inchargeUser) {
            $usersToNotify->push($dailyWork->inchargeUser);
        }

        // Notify the assigned user if different
        if ($dailyWork->assignedUser && $dailyWork->assigned !== $dailyWork->incharge) {
            $usersToNotify->push($dailyWork->assignedUser);
        }

        // For escalated items, notify managers
        if ($newStatus === DailyWork::STATUS_EMERGENCY) {
            // This would need to be implemented based on your role system
            // For now, we'll skip manager notifications
        }

        $usersToNotify->unique('id')->each(function (User $user) use ($dailyWork, $oldStatus, $newStatus, $reason) {
            $user->notify(new DailyWorkStatusChangeNotification(
                $dailyWork,
                $oldStatus,
                $newStatus,
                $reason
            ));
        });
    }

    /**
     * Check for overdue work that needs reminders.
     */
    public function getOverdueWorkForReminders(): \Illuminate\Support\Collection
    {
        $oneHourAgo = now()->subHour();

        return DailyWork::query()
            ->where('status', DailyWork::STATUS_IN_PROGRESS)
            ->whereNotNull('planned_time')
            ->whereNotNull('date')
            ->where(function ($query) use ($oneHourAgo) {
                // Check if the planned completion time has passed
                $query->where(function ($q) use ($oneHourAgo) {
                    // For today's date, check if planned time has passed
                    $q->where('date', $oneHourAgo->toDateString())
                      ->where('planned_time', '<', $oneHourAgo->format('H:i:s'));
                })
                ->orWhere(function ($q) use ($oneHourAgo) {
                    // For past dates, always overdue
                    $q->where('date', '<', $oneHourAgo->toDateString());
                });
            })
            ->with(['inchargeUser', 'assignedUser'])
            ->get();
    }

    /**
     * Check for pending objections that need follow-up.
     */
    public function getPendingObjectionsForReminders(): \Illuminate\Support\Collection
    {
        return RfiObjection::query()
            ->where('status', RfiObjection::STATUS_SUBMITTED)
            ->where('created_at', '<', now()->subDays(2))
            ->with(['dailyWorks.inchargeUser', 'dailyWorks.assignedUser'])
            ->get();
    }
}