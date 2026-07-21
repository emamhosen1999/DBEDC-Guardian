<?php
// app/Notifications/Concerns/DeliversProactiveAttendanceAlert.php
namespace App\Notifications\Concerns;

use App\Notifications\Channels\PushChannel;
use App\Services\Notification\NotificationChannelResolver;

/**
 * Channel routing for PROACTIVE, time-based attendance alerts (shift-start
 * reminder / overdue punch-in / absence escalation).
 *
 * These behave like the reactive attendance notifications — they resolve the
 * user's effective channels from the NotificationType registry + the user's
 * preferences (so a user can still mute push for a registered type). The one
 * difference from DeliversViaPreferences: if the registry row has NOT been
 * seeded yet (resolver returns []), we DO NOT go silent — a proactive
 * operational safety alert must still reach the device. In that fallback we
 * deliver in-app (database) + push.
 */
trait DeliversProactiveAttendanceAlert
{
    /** Each notification declares its registry key, e.g. 'attendance.shift_absence'. */
    abstract public function typeKey(): string;

    public function via(object $notifiable): array
    {
        $channels = app(NotificationChannelResolver::class)->resolveForUser($this->typeKey(), $notifiable);

        // Registered type → honour admin + user channel preferences as-is.
        if ($channels !== []) {
            return $channels;
        }

        // Unregistered / inactive type → never drop the alert silently.
        return ['database', PushChannel::class];
    }
}
