<?php
// app/Notifications/Concerns/DeliversViaPreferences.php
namespace App\Notifications\Concerns;

use App\Services\Notification\NotificationChannelResolver;

trait DeliversViaPreferences
{
    /** Each notification declares its registry key, e.g. 'leave.approved'. */
    abstract public function typeKey(): string;

    public function via(object $notifiable): array
    {
        return app(NotificationChannelResolver::class)->resolveForUser($this->typeKey(), $notifiable);
    }
}
