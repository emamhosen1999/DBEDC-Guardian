<?php

namespace App\Services\Realtime;

use Kreait\Firebase\Contract\Database;

class RealtimeSignal
{
    /**
     * Publish a bounded "last-change" marker for a resource bucket.
     * ID-only: never include names/PII. Fail-open: never throws, never blocks the caller.
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
