<?php
// app/Services/Notification/NotificationChannelResolver.php
namespace App\Services\Notification;

use App\Models\NotificationType;
use App\Models\User;
use App\Notifications\Channels\PushChannel;

class NotificationChannelResolver
{
    /**
     * Pure: compute the effective logical channels.
     * - start from admin-enabled channels
     * - drop any the user disabled, UNLESS that channel is locked
     * - always include 'database'
     *
     * @param  string[]  $enabled
     * @param  string[]  $locked
     * @param  string[]  $userDisabled
     * @return string[]
     */
    public function effectiveLogicalChannels(array $enabled, array $locked, array $userDisabled): array
    {
        $out = array_filter($enabled, function ($channel) use ($locked, $userDisabled) {
            if (in_array($channel, $locked, true)) {
                return true;
            }
            return ! in_array($channel, $userDisabled, true);
        });

        if (! in_array('database', $out, true)) {
            $out[] = 'database';
        }

        return array_values(array_unique($out));
    }

    /**
     * Resolve Laravel channel identifiers for a user + type key.
     *
     * @return array<int, string> e.g. ['database', 'mail', PushChannel::class]
     */
    public function resolveForUser(string $typeKey, User $user): array
    {
        $type = NotificationType::where('key', $typeKey)->first();
        if (! $type || ! $type->is_active) {
            return [];
        }

        $userDisabled = $user->notificationPreferences
            ->where('category', $type->category)
            ->where('enabled', false)
            ->pluck('channel')
            ->all();

        $logical = $this->effectiveLogicalChannels(
            $type->default_channels ?? ['database'],
            $type->locked_channels ?? ['database'],
            $userDisabled,
        );

        $map = ['database' => 'database', 'mail' => 'mail', 'push' => PushChannel::class];

        return array_values(array_filter(array_map(fn ($c) => $map[$c] ?? null, $logical)));
    }
}
