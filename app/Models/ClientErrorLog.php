<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One row per unique client-side error GROUP (not per occurrence).
 *
 * See the migration for the grouping rationale. The short version: a mobile app
 * in a crash loop emits the same error thousands of times, so the server folds
 * occurrences into a fingerprinted group and keeps a counter plus the latest
 * sample. `record()` is the single write path.
 */
class ClientErrorLog extends Model
{
    use HasFactory;

    /** Allowed severities. App-level validation — the column is a plain string. */
    public const SEVERITIES = ['fatal', 'error', 'warning'];

    /** Cap on the distinct device/user id sets kept per group (bounded JSON). */
    protected const BLAST_RADIUS_CAP = 100;

    /** Cap on breadcrumbs retained on the latest sample. */
    public const BREADCRUMB_CAP = 50;

    protected $fillable = [
        'fingerprint', 'message', 'error_type', 'severity', 'stack', 'screen',
        'platform', 'os_version', 'device_model', 'app_version', 'build',
        'device_id', 'user_id', 'session_id', 'breadcrumbs', 'context',
        'affected_devices', 'affected_users', 'platform_counts',
        'count', 'occurred_at', 'received_at', 'last_seen_at',
        'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'breadcrumbs' => 'array',
        'context' => 'array',
        'affected_devices' => 'array',
        'affected_users' => 'array',
        'platform_counts' => 'array',
        'count' => 'integer',
        'user_id' => 'integer',
        'resolved_by' => 'integer',
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /* ───────────────────────────── fingerprint ───────────────────────────── */

    /**
     * Server-side grouping key: error_type + normalized message + top stack frame.
     *
     * Computed HERE and never trusted from the client — a buggy or malicious app
     * build could otherwise split one bug into a million groups (defeating the
     * whole point) or collapse unrelated bugs into one.
     *
     * Message normalization strips the volatile parts that would otherwise make
     * every occurrence unique: numbers, hex/uuid ids, quoted literals, urls.
     */
    public static function fingerprintFor(?string $errorType, ?string $message, ?string $stack): string
    {
        $parts = [
            strtolower(trim((string) $errorType)),
            static::normalizeMessage((string) $message),
            static::topStackFrame($stack),
        ];

        return hash('sha256', implode('|', $parts));
    }

    public static function normalizeMessage(string $message): string
    {
        $value = strtolower(trim($message));

        // uuids -> {id}
        $value = preg_replace('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '{id}', $value);
        // long hex blobs (object addresses, hashes) -> {hex}
        $value = preg_replace('/\b(?:0x)?[0-9a-f]{8,}\b/i', '{hex}', $value);
        // urls -> {url}
        $value = preg_replace('#https?://\S+#i', '{url}', $value);
        // quoted literals -> {str}
        $value = preg_replace('/([\'"])(?:\\\\.|(?!\1).)*\1/', '{str}', $value);
        // bare numbers -> {n}
        $value = preg_replace('/\b\d+(?:\.\d+)?\b/', '{n}', $value);
        // collapse whitespace
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
    }

    /**
     * First meaningful stack line, normalized. Discriminates two different bugs
     * that happen to share a generic message ("Network request failed").
     */
    public static function topStackFrame(?string $stack): string
    {
        $stack = trim((string) $stack);

        if ($stack === '') {
            return '';
        }

        foreach (preg_split('/\r\n|\r|\n/', $stack) ?: [] as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            // Drop the leading "Error: message" header line when present — that
            // is already covered by the message component.
            if (preg_match('/^[\w.]*(?:error|exception)\b.*?:/i', $line) && ! str_contains($line, '(')) {
                continue;
            }

            // Strip line:column so a one-line code shift does not fork the group.
            $line = preg_replace('/:\d+:\d+/', '', $line);
            $line = preg_replace('/\s+/', ' ', (string) $line);

            return strtolower(trim((string) mb_substr((string) $line, 0, 300)));
        }

        return '';
    }

    /* ─────────────────────────────── ingest ─────────────────────────────── */

    /**
     * Upsert one occurrence into its group.
     *
     * First sighting inserts. Every repeat bumps `count`, refreshes `last_seen_at`
     * and overwrites the latest-sample columns, while MERGING the blast-radius
     * aggregates (device/user sets, platform tally).
     *
     * Runs in a transaction with a locking read so two concurrent batches for the
     * same fingerprint cannot lose a count increment.
     *
     * @param  array<string, mixed>  $event  Already-validated event payload.
     */
    public static function record(array $event, ?int $userId = null): self
    {
        $now = Carbon::now();

        $fingerprint = static::fingerprintFor(
            $event['error_type'] ?? null,
            $event['message'] ?? '',
            $event['stack'] ?? null,
        );

        $occurredAt = static::parseTimestamp($event['occurred_at'] ?? null) ?? $now;
        $deviceId = static::str($event['device_id'] ?? null, 191);
        $platform = strtolower((string) static::str($event['platform'] ?? null, 32));

        $severity = strtolower(trim((string) ($event['severity'] ?? 'error')));
        if (! in_array($severity, static::SEVERITIES, true)) {
            $severity = 'error';
        }

        $breadcrumbs = $event['breadcrumbs'] ?? null;
        if (is_array($breadcrumbs)) {
            // Newest breadcrumbs are the useful ones; keep the tail.
            $breadcrumbs = array_slice($breadcrumbs, -self::BREADCRUMB_CAP);
        } else {
            $breadcrumbs = null;
        }

        $sample = [
            'message' => (string) mb_substr(trim((string) ($event['message'] ?? '')), 0, 2000),
            'error_type' => static::str($event['error_type'] ?? null, 191),
            'severity' => $severity,
            'stack' => $event['stack'] !== null && $event['stack'] !== ''
                ? (string) mb_substr((string) $event['stack'], 0, 20000)
                : null,
            'screen' => static::str($event['screen'] ?? null, 191),
            'platform' => $platform !== '' ? $platform : null,
            'os_version' => static::str($event['os_version'] ?? null, 64),
            // Wire field is `model`; column is `device_model` (`model` collides
            // with Eloquent-ish naming and reads ambiguously in the admin table).
            'device_model' => static::str($event['model'] ?? null, 191),
            'app_version' => static::str($event['app_version'] ?? null, 64),
            'build' => static::str($event['build'] ?? null, 64),
            'device_id' => $deviceId,
            'user_id' => $userId,
            'session_id' => static::str($event['session_id'] ?? null, 191),
            'breadcrumbs' => $breadcrumbs,
            'context' => is_array($event['context'] ?? null) ? $event['context'] : null,
            'occurred_at' => $occurredAt,
            'last_seen_at' => $now,
        ];

        return DB::transaction(function () use ($fingerprint, $sample, $deviceId, $platform, $userId, $now) {
            /** @var self|null $existing */
            $existing = static::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();

            if ($existing === null) {
                return static::query()->create($sample + [
                    'fingerprint' => $fingerprint,
                    'count' => 1,
                    'received_at' => $now,
                    'affected_devices' => $deviceId !== null ? [$deviceId] : [],
                    'affected_users' => $userId !== null ? [$userId] : [],
                    'platform_counts' => $platform !== '' ? [$platform => 1] : [],
                ]);
            }

            $existing->fill($sample);
            $existing->count = (int) $existing->count + 1;
            $existing->affected_devices = static::mergeSet($existing->affected_devices, $deviceId);
            $existing->affected_users = static::mergeSet($existing->affected_users, $userId);
            $existing->platform_counts = static::bumpTally($existing->platform_counts, $platform);

            // A bug that reappears after being marked resolved is a REGRESSION —
            // reopen it rather than letting new occurrences hide under a green row.
            if ($existing->resolved_at !== null) {
                $existing->resolved_at = null;
                $existing->resolved_by = null;
            }

            $existing->save();

            return $existing;
        });
    }

    /**
     * Add a value to a bounded distinct set.
     *
     * @param  array<int, mixed>|null  $set
     * @return array<int, mixed>
     */
    protected static function mergeSet(?array $set, int|string|null $value): array
    {
        $set = array_values(array_unique(array_filter((array) $set, fn ($v) => $v !== null && $v !== '')));

        if ($value === null || $value === '') {
            return $set;
        }

        if (in_array($value, $set, false)) {
            return $set;
        }

        if (count($set) >= self::BLAST_RADIUS_CAP) {
            // Saturated: the exact identities stop mattering past 100, the
            // "100+" signal does. Keep the set bounded rather than growing a
            // JSON blob without limit.
            return $set;
        }

        $set[] = $value;

        return $set;
    }

    /**
     * @param  array<string, int>|null  $tally
     * @return array<string, int>
     */
    protected static function bumpTally(?array $tally, string $key): array
    {
        $tally = is_array($tally) ? $tally : [];

        if ($key === '') {
            return $tally;
        }

        $tally[$key] = (int) ($tally[$key] ?? 0) + 1;

        return $tally;
    }

    protected static function str(mixed $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : (string) mb_substr($value, 0, $limit);
    }

    /** Never throw on a junk client clock — fall back to the server clock. */
    protected static function parseTimestamp(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }

        // Reject absurd clocks (device date set to 1970 or 2099) but keep the
        // event itself — the server clock takes over.
        if ($parsed->year < 2000 || $parsed->greaterThan(Carbon::now()->addDay())) {
            return null;
        }

        return $parsed;
    }

    /* ────────────────────────────── relations ────────────────────────────── */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /* ─────────────────────────────── scopes ─────────────────────────────── */

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    /* ─────────────────────────────── helpers ────────────────────────────── */

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function affectedDeviceCount(): int
    {
        return count($this->affected_devices ?? []);
    }

    public function affectedUserCount(): int
    {
        return count($this->affected_users ?? []);
    }
}
