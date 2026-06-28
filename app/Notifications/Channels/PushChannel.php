<?php
// app/Notifications/Channels/PushChannel.php
namespace App\Notifications\Channels;

use App\Services\Notification\Push\PushDispatcher;
use Illuminate\Notifications\Notification;

class PushChannel
{
    public function __construct(private PushDispatcher $dispatcher) {}

    public function send($notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toPush')) {
            return;
        }
        $message = $notification->toPush($notifiable);
        $tokens = $notifiable->notificationTokens()->get();
        if ($tokens->isNotEmpty()) {
            $this->dispatcher->send($tokens, $message);
        }
    }
}
