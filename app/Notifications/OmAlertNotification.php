<?php

namespace App\Notifications;

use App\Notifications\Channels\PushChannel;
use App\Notifications\Concerns\DeliversViaPreferences;
use App\Services\Notification\NotificationChannelResolver;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class OmAlertNotification extends Notification implements ShouldQueue
{
    use DeliversViaPreferences, Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public string $eventType, // 'sla_breach', 'incident_escalated', 'work_order_assigned', 'critical_inspection'
        public string $entityNumber,
        public ?string $url = '/om/dashboard',
        public array $extraData = []
    ) {}

    public function typeKey(): string
    {
        return 'om.alert';
    }

    public function via(object $notifiable): array
    {
        $resolved = app(NotificationChannelResolver::class)->resolveForUser($this->typeKey(), $notifiable);

        // Fail-safe default: deliver via database and push channel
        if (empty($resolved)) {
            return ['database', PushChannel::class];
        }

        return $resolved;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[O&M Alert] {$this->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->body)
            ->line("**Reference Number:** {$this->entityNumber}")
            ->action('View in O&M Center', url($this->url))
            ->line('This is an automated operational alert from Dhaka Bypass Expressway O&M Command.');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type_key' => $this->typeKey(),
            'event_type' => $this->eventType,
            'title' => $this->title,
            'body' => $this->body,
            'entity_number' => $this->entityNumber,
            'url' => $this->url,
            'extra' => $this->extraData,
        ];
    }

    public function toPush(object $notifiable): PushMessage
    {
        return new PushMessage($this->title, $this->body, array_merge([
            'type_key' => $this->typeKey(),
            'event_type' => $this->eventType,
            'entity_number' => $this->entityNumber,
            'url' => $this->url,
        ], array_map('strval', $this->extraData)));
    }
}
