<?php

namespace App\Services\Operations;

use App\Notifications\OmAlertNotification;
use App\Services\Notification\NotificationRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * One place for O&M alerts: permission holders (not the actor, not inactive users), sent only after the
 * surrounding transaction commits so a rolled-back incident/work order never alerts anyone. Failures are
 * logged at error level; an alert must not break the operation that raised it.
 */
class OmAlertDispatcher
{
    public function __construct(private NotificationRecipients $recipients) {}

    public function send(string $permission, string|int|null $actorId, string $title, string $body, string $type, string $reference, string $url): void
    {
        DB::afterCommit(function () use ($permission, $actorId, $title, $body, $type, $reference, $url) {
            try {
                $to = $this->recipients->forPermission($permission, null, $actorId !== null ? (string) $actorId : null);

                if ($to->isNotEmpty()) {
                    Notification::send($to, new OmAlertNotification($title, $body, $type, $reference, $url));
                }
            } catch (\Throwable $e) {
                Log::error("O&M alert {$type} ({$reference}) failed", [
                    'permission' => $permission,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }
}
