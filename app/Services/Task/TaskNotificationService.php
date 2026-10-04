<?php

namespace App\Services\Task;

use App\Models\DailyWork as Tasks;
use App\Models\User;
use App\Notifications\TaskAssignedNotification;
use App\Notifications\TaskStatusChangedNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Fail-soft: the task has already been saved by the time these run, so a notification failure is
 * logged at error level and never turns a successful legacy task endpoint into a 500.
 */
class TaskNotificationService
{
    /**
     * Send task assignment notification
     */
    public function sendTaskAssignmentNotification(Tasks $task, string $assignedTo): void
    {
        $actor = Auth::user();
        $assignedUser = User::find($assignedTo);

        if (! $assignedUser || $assignedUser->employee_id === $actor?->employee_id) {
            return;
        }

        $this->safely('task.assigned', $task, fn () => $assignedUser->notify(new TaskAssignedNotification(
            $task->id,
            (string) $task->number,
            (string) ($actor?->name ?? 'a colleague'),
            $actor?->employee_id,
        )));
    }

    /**
     * Send task status update notification (completion is the 'completed' status of the same type).
     */
    public function sendTaskStatusUpdateNotification(Tasks $task, string $oldStatus, string $newStatus): void
    {
        $this->notifyIncharge($task, $oldStatus, $newStatus, null);
    }

    /**
     * Send task resubmission notification
     */
    public function sendTaskResubmissionNotification(Tasks $task, int $resubmissionCount): void
    {
        $this->notifyIncharge($task, null, 'resubmission', $resubmissionCount);
    }

    private function notifyIncharge(Tasks $task, ?string $oldStatus, string $newStatus, ?int $resubmissionCount): void
    {
        $actor = Auth::user();
        $inchargeUser = $task->incharge ? User::find($task->incharge) : null;

        if (! $inchargeUser || $inchargeUser->employee_id === $actor?->employee_id) {
            return;
        }

        $this->safely('task.status_changed', $task, fn () => $inchargeUser->notify(new TaskStatusChangedNotification(
            $task->id,
            (string) $task->number,
            $oldStatus,
            $newStatus,
            (string) ($actor?->name ?? 'a colleague'),
            $actor?->employee_id,
            $resubmissionCount,
        )));
    }

    private function safely(string $type, Tasks $task, \Closure $send): void
    {
        try {
            $send();
        } catch (\Throwable $e) {
            Log::error("Notification {$type} failed for task #{$task->id}", [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
