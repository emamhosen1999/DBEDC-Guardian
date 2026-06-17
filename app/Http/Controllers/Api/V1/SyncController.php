<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncBootstrapRequest;
use App\Http\Requests\Api\V1\SyncPullRequest;
use App\Http\Requests\Api\V1\SyncPushRequest;
use App\Http\Responses\ApiResponse;
use App\Services\Sync\DataSyncService;
use Carbon\Carbon;
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
        $cursor = now()->toAtomString();

        $moduleData = [];

        foreach ($modules as $module) {
            if ($module === 'attendance') {
                $moduleData['attendance'] = $this->syncService->bootstrapAttendance($user, $limit);

                continue;
            }

            if ($module === 'leaves') {
                $moduleData['leaves'] = $this->syncService->bootstrapLeaves($user, $limit);

                continue;
            }

            if ($module === 'daily_works') {
                $moduleData['daily_works'] = $this->syncService->bootstrapDailyWorks($user, $limit);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'cursor' => $cursor,
                'server_time' => $cursor,
                'modules' => $moduleData,
            ],
        ]);
    }

    public function pull(SyncPullRequest $request): JsonResponse
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 100);
        $modules = $this->syncService->resolveModules($request->input('modules', []));
        $cursor = Carbon::parse((string) $request->input('cursor'));
        $nextCursor = now()->toAtomString();

        $changes = [];
        $counts = [];

        foreach ($modules as $module) {
            if ($module === 'attendance') {
                $records = $this->syncService->pullAttendance($user, $cursor, $limit);
                $changes['attendance'] = $records;
                $counts['attendance'] = count($records);

                continue;
            }

            if ($module === 'leaves') {
                $records = $this->syncService->pullLeaves($user, $cursor, $limit);
                $changes['leaves'] = $records;
                $counts['leaves'] = count($records);

                continue;
            }

            if ($module === 'daily_works') {
                $records = $this->syncService->pullDailyWorks($user, $cursor, $limit);
                $changes['daily_works'] = $records;
                $counts['daily_works'] = count($records);
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'cursor' => $cursor->toAtomString(),
                'next_cursor' => $nextCursor,
                'changes' => $changes,
                'counts' => $counts,
            ],
        ]);
    }

    public function push(SyncPushRequest $request): JsonResponse
    {
        $user = $request->user();
        $mutations = collect($request->input('mutations', []));

        $results = [];
        $applied = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($mutations as $mutation) {
            $idempotencyKey = (string) ($mutation['idempotency_key'] ?? '');
            $module = (string) ($mutation['module'] ?? '');
            $action = (string) ($mutation['action'] ?? '');
            $payload = is_array($mutation['payload'] ?? null) ? $mutation['payload'] : [];

            $existingResult = $this->syncService->findStoredMutationResult((int) $user->id, $idempotencyKey);

            if ($existingResult !== null) {
                $duplicates++;

                $results[] = [
                    'idempotency_key' => $idempotencyKey,
                    'module' => $module,
                    'action' => $action,
                    'status' => 'duplicate',
                    'message' => 'Mutation already processed.',
                    'result' => $existingResult,
                ];

                continue;
            }

            $result = $this->syncService->applyMutation($user, $module, $action, $payload);

            if (($result['status'] ?? 'failed') === 'applied') {
                $applied++;
            } else {
                $failed++;
            }

            $this->syncService->storeMutationResult((int) $user->id, $idempotencyKey, $module, $action, $result);

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
                'next_cursor' => now()->toAtomString(),
            ],
        ]);
    }
}
