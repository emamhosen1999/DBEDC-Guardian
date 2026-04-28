<?php

namespace App\Events;

use App\Models\DailyWork;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DailyWorkUpdated
{
    use Dispatchable, SerializesModels;

    public $dailyWork;
    public $action;
    public $userId;
    public $affectedUsers;

    /**
     * Create a new event instance.
     */
    public function __construct(DailyWork $dailyWork, string $action, int $userId, array $affectedUsers = [])
    {
        $this->dailyWork = $dailyWork;
        $this->action = $action; // 'created', 'updated', 'deleted', 'status_changed'
        $this->userId = $userId;
        $this->affectedUsers = $affectedUsers;
    }

    /**
     * Get the event data for processing.
     */
    public function getEventData(): array
    {
        return [
            'daily_work' => [
                'id' => $this->dailyWork->id,
                'number' => $this->dailyWork->number,
                'status' => $this->dailyWork->status,
                'type' => $this->dailyWork->type,
                'location' => $this->dailyWork->location,
                'description' => $this->dailyWork->description,
                'date' => $this->dailyWork->date,
                'incharge' => $this->dailyWork->incharge,
                'assigned' => $this->dailyWork->assigned,
                'completion_time' => $this->dailyWork->completion_time,
                'rfi_submission_date' => $this->dailyWork->rfi_submission_date,
                'inspection_result' => $this->dailyWork->inspection_result,
                'resubmission_count' => $this->dailyWork->resubmission_count,
                'incharge_user' => $this->dailyWork->inchargeUser?->only(['id', 'name']),
                'assigned_user' => $this->dailyWork->assignedUser?->only(['id', 'name']),
                'active_objections_count' => $this->dailyWork->active_objections_count,
            ],
            'action' => $this->action,
            'user_id' => $this->userId,
            'timestamp' => now()->toISOString(),
            'affected_users' => $this->affectedUsers,
        ];
    }
}
