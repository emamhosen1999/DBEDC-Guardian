<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncBootstrapRequest;
use App\Http\Requests\Api\V1\SyncPullRequest;
use App\Http\Requests\Api\V1\SyncPushRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Sync\DataSyncService;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    use ApiResponse;

    protected DataSyncService $syncService;

    public function __construct(DataSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    public function bootstrap(SyncBootstrapRequest $request): JsonResponse
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 25);
        $modules = $this->syncService->resolveModules($request->input('modules', []));

        $moduleData = [];
        $cursor = [];
        $hasMore = [];

        foreach ($modules as $module) {
            $bootstrap = $this->syncService->bootstrapModule($user, $module, $limit);

            $moduleData[$module] = [
                'records' => $bootstrap['records'],
                'total' => $bootstrap['total'],
            ];
            $cursor[$module] = $bootstrap['cursor'];
            $hasMore[$module] = $bootstrap['has_more'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                // Per-module continuation token; feed each back to /sync/pull as cursor[module].
                'cursor' => $cursor,
                'has_more' => $hasMore,
                'server_time' => now()->toAtomString(),
                'modules' => $moduleData,
            ],
        ]);
    }

    public function pull(SyncPullRequest $request): JsonResponse
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 100);
        $modules = $this->syncService->resolveModules($request->input('modules', []));
        $cursors = $this->resolveCursors($request->input('cursor'));

        $changes = [];
        $counts = [];
        $nextCursor = [];
        $hasMore = [];

        foreach ($modules as $module) {
            // A per-module token is preferred; a scalar cursor (legacy) applies to all.
            $token = $cursors[$module] ?? $cursors['*'] ?? null;

            $result = $this->syncService->pullModule($user, $module, $token, $limit);

            $changes[$module] = $result['changes'];
            $counts[$module] = $result['count'];
            $nextCursor[$module] = $result['next_cursor'];
            $hasMore[$module] = $result['has_more'];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'changes' => $changes,
                'counts' => $counts,
                // Echo each module's continuation token; the client loops while any
                // has_more is true, feeding next_cursor back as cursor[module].
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
                'server_time' => now()->toAtomString(),
            ],
        ]);
    }

    /**
     * Server-side kill switch for the offline outbox flush.
     *
     * If a bad client build starts hammering /sync/push (or a mutation handler
     * is corrupting data), an admin flips this flag OFF and every device stops
     * being accepted on its NEXT foreground — no store release, no OTA. The
     * outbox is designed to hold: a 503 is transient, so queued punches stay
     * pending and drain once the flag is back on. Nothing is dropped.
     *
     * Fail-open: an absent row means enabled, so a missing seed can never brick
     * attendance sync.
     */
    public const OFFLINE_PUSH_FLAG = 'mobile.offline_sync_push_enabled';

    public function push(SyncPushRequest $request): JsonResponse
    {
        $user = $request->user();

        if (! app(\App\Services\FeatureFlagService::class)->isEnabled(self::OFFLINE_PUSH_FLAG, $user, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Offline sync is temporarily paused by the administrator. Your queued items are safe and will be sent automatically.',
                'error_code' => 'SYNC_PUSH_DISABLED',
                'retry_after_seconds' => 900,
            ], 503);
        }

        $mutations = collect($request->input('mutations', []));

        $results = [];
        $applied = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($mutations as $mutation) {
            $idempotencyKey = (string) ($mutation['idempotency_key'] ?? '');
            $module = (string) ($mutation['module'] ?? '');
            $action = (string) ($mutation['action'] ?? '');

            $processed = $this->syncService->processMutation($user, $mutation);
            $outcome = (string) ($processed['outcome'] ?? 'applied');
            $result = is_array($processed['result'] ?? null) ? $processed['result'] : ['status' => 'failed'];

            if ($outcome === 'duplicate') {
                $duplicates++;

                $results[] = [
                    'idempotency_key' => $idempotencyKey,
                    'module' => $module,
                    'action' => $action,
                    'status' => 'duplicate',
                    'message' => 'Mutation already processed.',
                    'result' => $result,
                ];

                continue;
            }

            if (($result['status'] ?? 'failed') === 'applied') {
                $applied++;
            } else {
                $failed++;
            }

            $results[] = [
                'idempotency_key' => $idempotencyKey,
                'module' => $module,
                'action' => $action,
                ...$result,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'results' => $results,
                'summary' => [
                    'total' => $mutations->count(),
                    'applied' => $applied,
                    'duplicate' => $duplicates,
                    'failed' => $failed,
                ],
            ],
        ]);
    }

    /**
     * Normalize the incoming cursor into a module => token map.
     * Accepts a per-module object (`cursor[attendance]=...`) or a single scalar
     * watermark applied to every module (`*`).
     *
     * @return array<string, ?string>
     */
    private function resolveCursors(mixed $cursor): array
    {
        if (is_array($cursor)) {
            $out = [];

            foreach ($cursor as $key => $value) {
                $out[(string) $key] = is_scalar($value) ? (string) $value : null;
            }

            return $out;
        }

        if (is_string($cursor) && $cursor !== '') {
            return ['*' => $cursor];
        }

        return [];
    }
}
