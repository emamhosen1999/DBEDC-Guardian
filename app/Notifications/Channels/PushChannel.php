<?php
// app/Notifications/Channels/PushChannel.php
namespace App\Notifications\Channels;

use Illuminate\Notifications\Notification;

class PushChannel
{
    // Implemented in Task 5. Declared now so resolveForUser() can reference ::class.
    public function send($notifiable, Notification $notification): void {}
}
