<?php

namespace App\Notifications\Attachments;

use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** An approver returned the application because its document is not clear enough: tells the employee why, with a direct link. */
class ReturnedForReuploadNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    /** @param string $subject leave | overtime | replacement */
    public function __construct(public string $subject, public int $applicationId, public string $reason) {}

    public function typeKey(): string
    {
        return 'attachments.reupload_requested';
    }

    public function url(): string
    {
        return $this->subject === 'leave' ? '/leaves-employee?reupload='.$this->applicationId : '/attendance-employee?reupload='.$this->subject.'-'.$this->applicationId;
    }

    public function toArray(object $notifiable): array
    {
        $what = match ($this->subject) {
            'leave' => 'leave application',
            'replacement' => 'replacement leave request',
            default => 'overtime claim',
        };

        return [
            'type_key' => $this->typeKey(),
            'title' => __('attachments.reupload.notification_title'),
            'body' => __('attachments.reupload.notification_body', ['what' => $what, 'reason' => $this->reason]),
            'url' => $this->url(),
            'subject' => $this->subject,
            'application_id' => $this->applicationId,
            'reason' => $this->reason,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        $d = $this->toArray($notifiable);

        return new PushMessage($d['title'], $d['body'], [
            'type_key' => $d['type_key'], 'subject' => $this->subject, 'application_id' => (string) $this->applicationId, 'url' => $d['url'],
        ]);
    }
}
