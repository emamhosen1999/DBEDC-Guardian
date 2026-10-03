<?php

namespace App\Services\Access;

use App\Models\AccessAuditLog;
use Illuminate\Support\Facades\Schema;

/**
 * Writes the access audit ledger. A command that changes access in bulk opens a context
 * (actor, reason, plan hash) so every row it causes, including the ones written by the Spatie event
 * listener, carries the same actor and plan hash.
 *
 * Registered as a singleton (AppServiceProvider); the context is always restored in a finally block. Auditing must never break the change it records:
 * a failed write is reported, not thrown.
 */
class AccessAudit
{
    private ?string $actor = null;

    private ?string $reason = null;

    private ?string $planHash = null;

    private bool $hasContext = false;

    /** Run $callback with an explicit actor / reason / plan hash on every audit row. */
    public function withContext(?string $actor, ?string $reason, ?string $planHash, callable $callback): mixed
    {
        [$a, $r, $p, $h] = [$this->actor, $this->reason, $this->planHash, $this->hasContext];
        [$this->actor, $this->reason, $this->planHash, $this->hasContext] = [$actor, $reason, $planHash, true];

        try {
            return $callback();
        } finally {
            [$this->actor, $this->reason, $this->planHash, $this->hasContext] = [$a, $r, $p, $h];
        }
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(string $action, ?string $subjectType, string|int|null $subjectId, ?array $before = null, ?array $after = null): void
    {
        try {
            if (! Schema::hasTable('access_audit_logs')) {
                return; // a deploy that has not migrated yet must still be able to change access
            }

            $request = app()->runningInConsole() ? null : request();

            AccessAuditLog::create([
                'actor_id' => $this->hasContext ? $this->actor : (auth()->id() !== null ? (string) auth()->id() : null),
                'subject_type' => $subjectType,
                'subject_id' => $subjectId !== null ? (string) $subjectId : null,
                'action' => $action,
                'before' => $before,
                'after' => $after,
                'reason' => $this->reason,
                'plan_hash' => $this->planHash,
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
            ]);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
