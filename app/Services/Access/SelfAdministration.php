<?php

namespace App\Services\Access;

use App\Models\SelfAdministrationLog;
use App\Models\User;
use App\Notifications\SelfAdministrationNotification;
use App\Services\Notification\NotificationRecipients;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The governed exception to separation of duties: a holder of `access.self-administration` may act on
 * himself like on his department's employees. This is the ONE place that decides it and the one that
 * records it (log row + notification to the global admins, dispatched after commit).
 */
class SelfAdministration
{
    public const PERMISSION = 'access.self-administration';

    public function isSelf(User $actor, User|string $target): bool
    {
        $key = $target instanceof User ? $target->getKey() : $target;

        return (string) $actor->getKey() === (string) $key;
    }

    /** Actor acts on himself AND holds the permission. */
    public function allows(User $actor, User|string $target): bool
    {
        return $this->isSelf($actor, $target) && $actor->checkPermissionTo(self::PERMISSION);
    }

    /**
     * @param  array<string, array{0: mixed, 1: mixed}>|array<string, mixed>  $changes  field => [before, after]
     */
    public function record(User $actor, string $action, string $summary, ?string $subjectType = null, string|int|null $subjectId = null, array $changes = []): SelfAdministrationLog
    {
        $log = SelfAdministrationLog::create([
            'actor_id' => (string) $actor->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId !== null ? (string) $subjectId : null,
            'changes' => $changes ?: null,
            'ip' => request()?->ip(),
            'user_agent' => substr((string) request()?->userAgent(), 0, 255) ?: null,
        ]);

        Log::notice('Self-administration', ['actor' => $actor->getKey(), 'action' => $action, 'summary' => $summary]);

        DB::afterCommit(function () use ($actor, $summary, $log) {
            // Announced to every global admin (owner rule) - DepartmentScope's own definition, so an HR Manager or
            // Administrator is told even though access administration is Super Administrator only.
            $admins = app(NotificationRecipients::class)->globalAdmins($actor);
            if ($admins->isNotEmpty()) {
                Notification::send($admins, new SelfAdministrationNotification((string) $actor->name, (string) $actor->getKey(), $summary, $log->id));
            }
        });

        return $log;
    }
}
