<?php

namespace App\Services;

use App\Models\FeatureFlag;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the effective flag set for a user.
 *
 * Resolution (deliberately simple — global + optional single-role override):
 *   1. start from every GLOBAL row (role IS NULL),
 *   2. overlay rows whose `role` is one of the user's role names.
 * Last role write wins; role order is stable (alphabetical) so the result is
 * deterministic for a user holding two overriding roles.
 *
 * CACHING AND `CACHE_STORE=null` (which is what production actually runs):
 * the null store is a black hole — every write is discarded and every read
 * misses. So the cache is used ONLY as an optimisation, never as the source of
 * truth: on a miss we recompute from the database and return that. Correctness
 * therefore does not depend on the store existing. A per-request memo keeps the
 * null-store path from re-querying within one request.
 *
 * Cache keys embed the flag-table VERSION (max updated_at + row count), so an
 * admin edit invalidates every cached payload implicitly — no manual flush, and
 * no stale flags on a store that does support writes.
 */
class FeatureFlagService
{
    /** How long a resolved payload may be cached (only ever hit on a real store). */
    protected const CACHE_TTL_SECONDS = 300;

    /** @var array<string, array<string, mixed>> per-request memo, keyed by cache key */
    protected array $memo = [];

    /** @var string|null per-request memo of the table version stamp */
    protected ?string $versionStamp = null;

    /**
     * The full effective payload for one user.
     *
     * @return array{flags: array<string, array{enabled: bool, value: mixed}>, updated_at: string|null, etag: string}
     */
    public function payloadFor(?Authenticatable $user): array
    {
        $roles = $this->roleNamesFor($user);
        $version = $this->versionStamp();
        $cacheKey = 'feature_flags:v1:'.sha1($version.'|'.implode(',', $roles));

        if (isset($this->memo[$cacheKey])) {
            return $this->memo[$cacheKey];
        }

        $cached = $this->safeCacheGet($cacheKey);

        if (is_array($cached) && isset($cached['flags'])) {
            return $this->memo[$cacheKey] = $cached;
        }

        $payload = $this->buildPayload($roles);

        $this->safeCachePut($cacheKey, $payload);

        return $this->memo[$cacheKey] = $payload;
    }

    /**
     * Is a flag on for this user? `$default` is returned whenever the flag is
     * unknown — a missing row must never change behaviour by accident.
     */
    public function isEnabled(string $key, ?Authenticatable $user = null, bool $default = true): bool
    {
        $flags = $this->payloadFor($user)['flags'];

        if (! array_key_exists($key, $flags)) {
            return $default;
        }

        return (bool) $flags[$key]['enabled'];
    }

    /**
     * Config value for a flag. A disabled row is treated as absent, so one
     * switch turns an override off and restores the client-side default.
     */
    public function value(string $key, ?Authenticatable $user = null, mixed $default = null): mixed
    {
        $flags = $this->payloadFor($user)['flags'];

        if (! array_key_exists($key, $flags) || ! $flags[$key]['enabled']) {
            return $default;
        }

        $value = $flags[$key]['value'];

        return $value === null ? $default : $value;
    }

    /** Drops the per-request memo (used by tests and by the admin write path). */
    public function forgetMemo(): void
    {
        $this->memo = [];
        $this->versionStamp = null;
    }

    /**
     * @param  array<int, string>  $roles
     * @return array{flags: array<string, array{enabled: bool, value: mixed}>, updated_at: string|null, etag: string}
     */
    protected function buildPayload(array $roles): array
    {
        $rows = $this->allRows();

        $flags = [];
        $latest = null;

        // Global first, then role overlays — so a role row always wins.
        foreach ([true, false] as $globalPass) {
            foreach ($rows as $row) {
                $rowIsGlobal = trim((string) $row->role) === '';

                if ($rowIsGlobal !== $globalPass) {
                    continue;
                }

                if (! $rowIsGlobal && ! in_array($row->role, $roles, true)) {
                    continue;
                }

                $flags[$row->key] = [
                    'enabled' => (bool) $row->is_enabled,
                    'value' => $this->decodeValue($row->value),
                ];

                if ($row->updated_at !== null && ($latest === null || $row->updated_at > $latest)) {
                    $latest = $row->updated_at;
                }
            }
        }

        ksort($flags);

        return [
            'flags' => $flags,
            'updated_at' => $latest,
            // Stable across identical payloads: the client sends this back as
            // If-None-Match and gets a 304 instead of a re-download.
            'etag' => sha1(json_encode($flags) ?: ''),
        ];
    }

    /**
     * Raw rows, ordered so the overlay pass is deterministic.
     *
     * @return array<int, object>
     */
    protected function allRows(): array
    {
        if (! $this->tableExists()) {
            return [];
        }

        return DB::table('feature_flags')
            ->select('key', 'value', 'is_enabled', 'role', 'updated_at')
            ->orderBy('key')
            ->orderBy('role')
            ->get()
            ->all();
    }

    /**
     * Version stamp for the whole table: any insert/update/delete moves it.
     */
    protected function versionStamp(): string
    {
        if ($this->versionStamp !== null) {
            return $this->versionStamp;
        }

        if (! $this->tableExists()) {
            return $this->versionStamp = 'absent';
        }

        $row = DB::table('feature_flags')
            ->selectRaw('COUNT(*) as row_count, MAX(updated_at) as last_updated')
            ->first();

        return $this->versionStamp = ($row->row_count ?? 0).':'.($row->last_updated ?? '0');
    }

    /**
     * Role names for the user, alphabetical for deterministic overlay order.
     *
     * @return array<int, string>
     */
    protected function roleNamesFor(?Authenticatable $user): array
    {
        if ($user === null || ! method_exists($user, 'getRoleNames')) {
            return [];
        }

        try {
            $names = $user->getRoleNames()->map(fn ($name) => (string) $name)->all();
        } catch (\Throwable) {
            // Never let a roles lookup failure take the config endpoint down.
            return [];
        }

        sort($names);

        return array_values(array_unique($names));
    }

    /** DB rows arrive as raw JSON strings (query builder does no casting). */
    protected function decodeValue(mixed $raw): mixed
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = json_decode((string) $raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }

    protected function tableExists(): bool
    {
        try {
            return Schema::hasTable('feature_flags');
        } catch (\Throwable) {
            return false;
        }
    }

    protected function safeCacheGet(string $key): mixed
    {
        try {
            return Cache::get($key);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function safeCachePut(string $key, array $payload): void
    {
        try {
            Cache::put($key, $payload, self::CACHE_TTL_SECONDS);
        } catch (\Throwable) {
            // A dead/absent cache store must never break flag resolution.
        }
    }
}
