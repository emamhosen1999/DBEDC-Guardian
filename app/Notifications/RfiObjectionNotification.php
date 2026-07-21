<?php

namespace App\Notifications;

use App\Models\RfiObjection;
use App\Notifications\Channels\PushChannel;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class RfiObjectionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    protected RfiObjection $objection;

    protected string $event;

    /**
     * Event type constants
     */
    public const EVENT_SUBMITTED = 'submitted';

    public const EVENT_UNDER_REVIEW = 'under_review';

    public const EVENT_RESOLVED = 'resolved';

    public const EVENT_REJECTED = 'rejected';

    public function __construct(RfiObjection $objection, string $event)
    {
        $this->objection = $objection;
        $this->event = $event;
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database', PushChannel::class];
    }

    /**
     * The representative linked RFI/daily-work. RfiObjection links daily works
     * via a BelongsToMany `dailyWorks()` relationship — the old code referenced a
     * non-existent singular `dailyWork`, so every render hit null->number and
     * threw. Return the first linked work (or null when none is linked).
     */
    protected function primaryDailyWork()
    {
        return $this->objection->dailyWorks()->first();
    }

    /** Human RFI label, null-safe (falls back to the objection id). */
    protected function rfiNumber(): string
    {
        return $this->primaryDailyWork()?->number ?? "#{$this->objection->id}";
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage(
            $this->getMailSubject(),
            $this->getNotificationMessage(),
            [
                'type' => 'rfi_objection',
                'event' => $this->event,
                'objection_id' => (string) $this->objection->id,
                'url' => '/mobile/my-objections',
            ],
        );
    }

    public function toMail(object $notifiable): MailMessage
    {
        $dailyWork = $this->primaryDailyWork();
        $rfiNumber = $this->rfiNumber();
        $creator = $this->objection->createdBy;

        $subject = $this->getMailSubject();
        $message = (new MailMessage)
            ->subject($subject)
            ->greeting("Hello {$notifiable->name},");

        switch ($this->event) {
            case self::EVENT_SUBMITTED:
                $message
                    ->line("A new objection has been raised for RFI **{$rfiNumber}**.")
                    ->line('**Objection Details:**')
                    ->line("Title: {$this->objection->title}")
                    ->line("Category: {$this->objection->category_label}")
                    ->line("Raised by: {$creator->name}")
                    ->line('Description: '.Str::limit($this->objection->description, 200))
                    ->line('')
                    ->line('**RFI Details:**')
                    ->line("Location: {$dailyWork?->location}")
                    ->line("Work Type: {$dailyWork?->type}")
                    ->action('Review Objection', url("/daily-works?objection={$this->objection->id}"))
                    ->line('Please review this objection and take appropriate action.');
                break;

            case self::EVENT_UNDER_REVIEW:
                $message
                    ->line("Your objection for RFI **{$rfiNumber}** is now under review.")
                    ->line("Objection: {$this->objection->title}")
                    ->action('View Objection', url("/daily-works?objection={$this->objection->id}"))
                    ->line('You will be notified once a decision has been made.');
                break;

            case self::EVENT_RESOLVED:
                $message
                    ->line("Your objection for RFI **{$rfiNumber}** has been **resolved**.")
                    ->line("Objection: {$this->objection->title}")
                    ->line('')
                    ->line('**Resolution Notes:**')
                    ->line($this->objection->resolution_notes ?? 'No notes provided.')
                    ->action('View Details', url("/daily-works?objection={$this->objection->id}"))
                    ->line('Thank you for your attention to quality and safety.');
                break;

            case self::EVENT_REJECTED:
                $message
                    ->line("Your objection for RFI **{$rfiNumber}** has been **rejected**.")
                    ->line("Objection: {$this->objection->title}")
                    ->line('')
                    ->line('**Rejection Reason:**')
                    ->line($this->objection->resolution_notes ?? 'No reason provided.')
                    ->action('View Details', url("/daily-works?objection={$this->objection->id}"))
                    ->line('If you have additional concerns, please raise a new objection with more details.');
                break;
        }

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        $dailyWork = $this->primaryDailyWork();

        return [
            'type' => 'rfi_objection',
            'event' => $this->event,
            'objection_id' => $this->objection->id,
            'objection_title' => $this->objection->title,
            'objection_category' => $this->objection->category,
            'objection_status' => $this->objection->status,
            'daily_work_id' => $dailyWork?->id,
            'daily_work_number' => $dailyWork?->number,
            'daily_work_location' => $dailyWork?->location,
            'url' => '/mobile/my-objections',
            'created_by_id' => $this->objection->created_by,
            'created_by_name' => $this->objection->createdBy?->name,
            'resolved_by_id' => $this->objection->resolved_by,
            'resolved_by_name' => $this->objection->resolvedBy?->name,
            'resolution_notes' => $this->objection->resolution_notes,
            'message' => $this->getNotificationMessage(),
            'action_required' => $this->event === self::EVENT_SUBMITTED,
        ];
    }

    protected function getMailSubject(): string
    {
        $rfiNumber = $this->rfiNumber();

        return match ($this->event) {
            self::EVENT_SUBMITTED => "[Action Required] New Objection Raised - RFI {$rfiNumber}",
            self::EVENT_UNDER_REVIEW => "Objection Under Review - RFI {$rfiNumber}",
            self::EVENT_RESOLVED => "Objection Resolved - RFI {$rfiNumber}",
            self::EVENT_REJECTED => "Objection Rejected - RFI {$rfiNumber}",
            default => "RFI Objection Update - {$rfiNumber}",
        };
    }

    protected function getNotificationMessage(): string
    {
        $rfiNumber = $this->rfiNumber();

        return match ($this->event) {
            self::EVENT_SUBMITTED => "New objection raised for RFI {$rfiNumber}: {$this->objection->title}",
            self::EVENT_UNDER_REVIEW => "Your objection for RFI {$rfiNumber} is under review",
            self::EVENT_RESOLVED => "Objection for RFI {$rfiNumber} has been resolved",
            self::EVENT_REJECTED => "Objection for RFI {$rfiNumber} has been rejected",
            default => "Objection update for RFI {$rfiNumber}",
        };
    }
}
