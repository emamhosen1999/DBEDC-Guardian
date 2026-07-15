<?php

namespace App\Services\Realtime;

use Kreait\Firebase\Contract\Database;

/**
 * CANONICAL REALTIME SIGNAL SCHEME (server = single source of truth).
 *
 * Every DOMAIN change-marker this app writes lives at exactly:
 *
 *     signals/{ns}/{entity}/{bucket}
 *
 *   {ns}      config('realtime.namespace') — defaults to FIREBASE_PROJECT_ID.
 *             The SAME value is shared to web clients via the Inertia prop
 *             `realtime.namespace` (see resources/js/api/useRealtimeSignals.js),
 *             so client and server can never drift to different namespaces.
 *   {entity}  the resource type — a fixed, singular, no-underscore vocabulary:
 *               attendance | dailywork | leave | objection | roster
 *             (NOTE: it is "dailywork", never "daily_works" — the old mobile
 *              listener drifted to "daily_works/{userId}" and could never fire.)
 *   {bucket}  the scope key WITHIN an entity. Today's buckets, per entity:
 *               attendance  → a date,  Y-m-d   (e.g. "2026-07-16")
 *               dailywork   → "all"    (team-wide global bucket)
 *               leave       → "all"
 *               objection   → "all"
 *               roster      → a month, Y-m     (e.g. "2026-07")
 *             "all" is a deliberate team-wide bucket: every dashboard viewer
 *             wakes on any peer's write. Making buckets per-user (to fan a
 *             signal only to affected users) is a caller-side change in the
 *             controllers — out of scope here — not a change to this writer.
 *
 * Marker payload is ALWAYS { ts, actor_id, action } and is ID-ONLY:
 * never put names / PII on Firebase. A marker says "something in this bucket
 * changed" — clients react by re-pulling from the authenticated Laravel API.
 *
 * SEPARATE per-user family (App\Services\Notification\RealtimeNotificationSignal):
 *
 *     signals/notif/{userId}
 *
 * This one is addressed per recipient (its own bucket IS the user id) and is
 * intentionally NOT under {ns} because a live web reader
 * (resources/js/Hooks/useRealtimeNotifications.js) hardcodes that exact path.
 * It shares this class's marker contract and fail-open/enabled-gate behaviour.
 *
 * READ AUTHORISATION lives in database.rules.json: the notif family is scoped
 * strictly to auth.uid === {userId}; domain buckets are readable by any
 * authenticated app user (they are shared team markers, ID-only); NO client
 * may ever WRITE (server writes go through the Firebase Admin SDK, which
 * bypasses security rules).
 */
class RealtimeSignal
{
    /**
     * Publish a bounded "last-change" marker at signals/{ns}/{entity}/{bucket}.
     * ID-only: never include names/PII. Fail-open: never throws, never blocks the caller.
     *
     * @param string   $entity  one of: attendance|dailywork|leave|objection|roster
     * @param string   $bucket  scope key — "all", a date (Y-m-d) or a month (Y-m)
     * @param int|null $actorId id of the user who caused the change (ID-only)
     * @param string   $action  short verb describing the change (e.g. "status", "apply")
     */
    public function touch(string $entity, string $bucket, ?int $actorId, string $action = 'update'): void
    {
        if (! config('realtime.enabled')) {
            return;
        }

        try {
            $ns = config('realtime.namespace');
            /** @var Database $db */
            $db = app(Database::class);
            $db->getReference("signals/{$ns}/{$entity}/{$bucket}")->set([
                'ts' => now()->toIso8601String(),
                'actor_id' => $actorId,
                'action' => $action,
            ]);
        } catch (\Throwable $e) {
            report($e); // log and swallow — realtime must not break the write path
        }
    }
}
