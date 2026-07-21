<?php

namespace App\Notifications;

use App\Models\HRM\Leave;
use App\Models\User;
use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * "No action needed" courtesy notice sent to an approver who was still PENDING
 * on a leave when an administrator OVERRODE the chain and finalized it. Without
 * this, the item silently vanishes from the manager's pending queue and they are
 * left wondering where it went. It carries no action — it just closes the loop.
 *
 * It deliberately reuses an already-registered notification type key
 * (leave.approved / leave.rejected) so the channel resolver actually delivers it;
 * an unregistered key resolves to zero channels and would be dropped.
 */
class LeaveOverrideNoticeNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    /**
     * @param  string  $action  The override outcome: 'approved' or 'rejected'.
     */
    public function __construct(
        public Leave $leave,
        public User $actor,
        public string $action = 'approved',
    ) {}

    public function typeKey(): string
    {
        // Piggy-back on a registered, active type so delivery works. The override
        // is semantically an approval or a rejection, just addressed to the
        // superseded approver as an FYI rather than to the employee.
        return $this->action === 'rejected' ? 'leave.rejected' : 'leave.approved';
    }

    private function employeeName(): string
    {
        return $this->leave->employee?->name
            ?? $this->leave->user?->name
            ?? 'an employee';
    }

    public function toMail(object $notifiable): MailMessage
    {
        $employee = $this->employeeName();
        $leaveType = $this->leave->leaveSetting?->type ?? 'Leave';

        return (new MailMessage)
            ->subject('Leave request finalized by administrator')
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->actor->name} {$this->action} {$employee}'s {$leaveType} request (admin override).")
            ->line('This item has been removed from your pending approvals — no action is needed from you.');
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->employeeName();

        return [
            'type_key' => $this->typeKey(),
            'title' => 'Leave finalized (admin override)',
            'body' => "{$this->actor->name} {$this->action} {$employee}'s leave request (admin override). No action needed.",
            'url' => "/leaves?view={$this->leave->id}",
            'leave_id' => $this->leave->id,
            'employee_name' => $employee,
            'admin_override' => true,
            'action' => $this->action,
            'action_required' => false,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $data = $this->toArray($notifiable);

        return new PushMessage($data['title'], $data['body'], [
            'type_key' => $data['type_key'],
            'leave_id' => (string) $this->leave->id,
            'url' => $data['url'],
        ]);
    }
}
