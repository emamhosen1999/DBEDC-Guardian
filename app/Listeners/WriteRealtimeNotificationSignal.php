<?php
// app/Listeners/WriteRealtimeNotificationSignal.php
namespace App\Listeners;

use App\Services\Notification\RealtimeNotificationSignal;
use Illuminate\Notifications\Events\NotificationSent;

class WriteRealtimeNotificationSignal
{
    public function __construct(private RealtimeNotificationSignal $signal) {}

    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database') {
            return; // one signal per notification, tied to the canonical in-app record
        }
        if (isset($event->notifiable->id)) {
            $this->signal->ping($event->notifiable->id);
        }
    }
}
