<?php

namespace App\Services\Sync;

use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\LeaveSetting;
use App\Models\RfiObjection;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Attendance\AttendanceValidatorFactory;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class DataSyncService
{
    /** Offline capture-time bounds for the sync push channel. */
    private const CAPTURE_FUTURE_SKEW_MINUTES = 2;

    private const CAPTURE_MAX_AGE_HOURS = 72;

    // ──────────────────────────────────────────────
    //  Module resolution
    // ──────────────────────────────────────────────

    public function resolveModules(array $modules): array
    {
        $allowedModules = ['attendance', 'leaves', 'daily_works'];

        if ($modules === []) {
            return $allowedModules;
        }

        return collect($modules)
            ->map(fn ($module) => (string) $module)
            ->filter(fn (string $module): bool => in_array($module, $allowedModules, true))
            ->unique()
            ->values()
            ->all();
    }

    // ──────────────────────────────────────────────
    //  Sync epoch (whole-set visibility shifts)
    // ──────────────────────────────────────────────

    /**
     * Current monotonic sync epoch for a user.
     *
     * WHY THIS EXISTS: per-row tombstones close the case where a ROW leaves a
     * user's scope (reassignment/delete). They cannot close the mirror case where
     * the USER'S OWN scope inputs change — report_to, department_id, designation_id
     * or their role set. Those reshape `baseDailyWorksQuery` wholesale, so the
     * device's cache both holds rows it may no longer see and misses rows it now
     * should. Emitting per-row tombstones for a whole-set shift is unbounded, so the
     * epoch is bumped instead and the device is told to re-bootstrap.
     *
     * Floors at 1 and tolerates a pre-migration schema (column absent ⇒ 1, i.e.
     * nothing is ever considered stale, so the read path can never break on deploy
     * ordering).
     */
    public function currentSyncEpoch(User $user): int
    {
        if (! Schema::hasColumn('users', 'sync_epoch')) {
            return 1;
        }

        $epoch = $user->getAttribute('sync_epoch');

        if ($epoch === null) {
            // The instance was hydrated without the column (a partial select, or a
            // model built in memory that never re-read its DB defaults). Fetch the
            // authoritative value rather than silently reporting epoch 1, which
            // would suppress every reset directive for that device.
            $epoch = DB::table('users')->where('id', $user->getKey())->value('sync_epoch');
        }

        return max(1, (int) ($epoch ?? 1));
    }

    /**
     * Must a pull carrying $clientEpoch discard its cursors/cache and re-bootstrap?
     *
     * True only when the client presents an epoch OLDER than the server's, meaning
     * the user's visibility set shifted wholesale since that device last synced.
     *
     * Backward-compatible by design: a legacy client that sends no epoch (null) is
     * NEVER force-reset — it keeps working exactly as before on the row+tombstone
     * delta path. A client that sends an epoch >= the server's is also untouched.
     */
    public function pullRequiresReset(User $user, ?int $clientEpoch): bool
    {
        if ($clientEpoch === null) {
            return false;
        }

        return $clientEpoch < $this->currentSyncEpoch($user);
    }

    // ──────────────────────────────────────────────
    //  Bootstrap payloads
    // ──────────────────────────────────────────────

    /**
     * First page of a fresh full sync for one module.
     *
     * Rows are ordered ASC by the monotonic (updated_at, id) cursor so the client
     * can page forward to `pull` without ever skipping a row (the historic DESC +
     * limit + cursor=now() bug permanently dropped every row beyond the first N).
     * The returned `cursor` also parks the tombstone watermark at the current max,
     * so a brand-new device is never handed deletes for rows it has never seen.
     */
    public function bootstrapModule(User $user, string $module, int $limit): array
    {
        [$records, $lastTs, $lastId, $saturated] = $this->pullModuleRows($user, $module, null, 0, $limit);

        $maxTombstone = Schema::hasTable('sync_tombstones')
            ? (int) DB::table('sync_tombstones')
                ->where('user_id', $user->id)
                ->where('module', $module)
                ->max('id')
            : 0;

        return [
            'records' => $records,
            'total' => count($records),
            'cursor' => $this->buildCursorToken($lastTs, $lastId, $maxTombstone),
            'has_more' => $saturated,
        ];
    }

    // ──────────────────────────────────────────────
    //  Pull (delta) payloads
    // ──────────────────────────────────────────────

    /**
     * Delta pull for one module against an opaque per-module cursor token.
     *
     * Correctness rules (fixing the historic silent-data-loss bugs):
     *   1. Rows are ordered ASC by the composite (updated_at, id) cursor.
     *   2. `next_cursor` is the LAST RETURNED row's (updated_at, id) — never now() —
     *      so a page boundary never advances past unseen rows.
     *   3. `has_more` is true whenever a page came back full; the client loops
     *      until it is false before considering itself in sync.
     *   4. Deletions surface as `{id, deleted:true}` tombstones drawn from the
     *      append-only `sync_tombstones` log, paged by its own monotonic id.
     *
     * @return array{changes: array<int, array>, next_cursor: string, has_more: bool, count: int}
     */
    public function pullModule(User $user, string $module, ?string $token, int $limit): array
    {
        $cursor = $this->parseCursorToken($token);

        [$rows, $lastTs, $lastId, $rowsSaturated] = $this->pullModuleRows(
            $user,
            $module,
            $cursor['ts'],
            $cursor['id'],
            $limit
        );

        [$tombstones, $lastTombstoneId, $tombstonesSaturated] = $this->pullModuleTombstones(
            $user,
            $module,
            $cursor['tombstone'],
            $limit
        );

        $changes = array_merge($rows, $tombstones);

        return [
            'changes' => $changes,
            'next_cursor' => $this->buildCursorToken($lastTs, $lastId, $lastTombstoneId),
            'has_more' => $rowsSaturated || $tombstonesSaturated,
            'count' => count($changes),
        ];
    }

    /**
     * Fetch one ASC page of changed rows for a module.
     *
     * @return array{0: array<int, array>, 1: ?string, 2: int, 3: bool}
     *               [records, lastUpdatedAt, lastId, pageWasFull]
     */
    private function pullModuleRows(User $user, string $module, ?string $ts, int $id, int $limit): array
    {
        $records = [];
        $lastTs = $ts;   // If nothing comes back the cursor must not move.
        $lastId = $id;

        if ($module === 'attendance') {
            if (! Schema::hasTable('attendances')) {
                return [[], $lastTs, $lastId, false];
            }

            $query = Attendance::query()->where('user_id', $user->id);
            $this->applyCompositeCursor($query, 'updated_at', 'id', $ts, $id);

            $models = $query
                ->orderBy('updated_at', 'asc')
                ->orderBy('id', 'asc')
                ->limit($limit)
                ->get(['id', 'user_id', 'date', 'punchin', 'punchout', 'updated_at']);

            foreach ($models as $model) {
                $records[] = $this->transformAttendanceRecord($model);
                $lastTs = $this->cursorTimestamp($model->updated_at);
                $lastId = (int) $model->id;
            }

            return [$records, $lastTs, $lastId, $models->count() === $limit];
        }

        if ($module === 'leaves') {
            if (! Schema::hasTable('leaves') || ! $this->resolveLeavesUserColumn()) {
                return [[], $lastTs, $lastId, false];
            }

            $hasUpdatedAt = Schema::hasColumn('leaves', 'updated_at');
            $query = $this->baseLeavesQuery($user);

            if ($hasUpdatedAt) {
                $this->applyCompositeCursor($query, 'leaves.updated_at', 'leaves.id', $ts, $id);
                $query->orderBy('leaves.updated_at', 'asc');
            }

            $rows = $query->orderBy('leaves.id', 'asc')->limit($limit)->get();

            foreach ($rows as $row) {
                $records[] = $this->transformLeaveRecord($row);
                $lastTs = $hasUpdatedAt ? $this->cursorTimestamp($row->updated_at) : $lastTs;
                $lastId = (int) $row->id;
            }

            return [$records, $lastTs, $lastId, $rows->count() === $limit];
        }

        if ($module === 'daily_works') {
            if (! Schema::hasTable('daily_works')) {
                return [[], $lastTs, $lastId, false];
            }

            $query = $this->baseDailyWorksQuery($user)->withCount(['activeObjections']);
            $this->applyCompositeCursor($query, 'daily_works.updated_at', 'daily_works.id', $ts, $id);

            $models = $query
                ->orderBy('daily_works.updated_at', 'asc')
                ->orderBy('daily_works.id', 'asc')
                ->limit($limit)
                ->get();

            foreach ($models as $model) {
                $records[] = $this->transformDailyWorkRecord($model);
                $lastTs = $this->cursorTimestamp($model->updated_at);
                $lastId = (int) $model->id;
            }

            return [$records, $lastTs, $lastId, $models->count() === $limit];
        }

        return [[], $lastTs, $lastId, false];
    }

    /**
     * Fetch one ASC page of tombstones (deletions) for a module.
     *
     * @return array{0: array<int, array{id:int, deleted:bool}>, 1: int, 2: bool}
     *               [tombstones, lastTombstoneId, pageWasFull]
     */
    private function pullModuleTombstones(User $user, string $module, int $afterId, int $limit): array
    {
        if (! Schema::hasTable('sync_tombstones')) {
            return [[], $afterId, false];
        }

        $rows = DB::table('sync_tombstones')
            ->where('user_id', $user->id)
            ->where('module', $module)
            ->where('id', '>', $afterId)
            ->orderBy('id', 'asc')
            ->limit($limit)
            ->get(['id', 'entity_id']);

        $tombstones = [];
        $lastId = $afterId;

        foreach ($rows as $row) {
            $tombstones[] = [
                'id' => (int) $row->entity_id,
                'deleted' => true,
            ];
            $lastId = (int) $row->id;
        }

        return [$tombstones, $lastId, $rows->count() === $limit];
    }

    /**
     * Append a compare-and-swap style (updated_at, id) predicate so a page never
     * re-reads a row it already emitted and never skips a row that shares the
     * boundary second with the last row of the previous page.
     */
    private function applyCompositeCursor($query, string $tsColumn, string $idColumn, ?string $ts, int $id): void
    {
        if ($ts === null) {
            return;
        }

        $query->where(function ($outer) use ($tsColumn, $idColumn, $ts, $id) {
            $outer->where($tsColumn, '>', $ts)
                ->orWhere(function ($inner) use ($tsColumn, $idColumn, $ts, $id) {
                    $inner->where($tsColumn, '=', $ts)->where($idColumn, '>', $id);
                });
        });
    }

    private function cursorTimestamp(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value)->toDateTimeString();
            } catch (\Throwable) {
                return '1970-01-01 00:00:00';
            }
        }

        return '1970-01-01 00:00:00';
    }

    private function buildCursorToken(?string $ts, int $id, int $tombstoneId): string
    {
        return ($ts ?? '0').'|'.$id.'|'.$tombstoneId;
    }

    /**
     * Parse an opaque cursor token "<updated_at>|<id>|<tombstoneId>".
     * A bare timestamp (legacy) or "0"/empty (initial sync) are both accepted.
     *
     * @return array{ts: ?string, id: int, tombstone: int}
     */
    private function parseCursorToken(?string $token): array
    {
        $token = trim((string) $token);

        if ($token === '' || $token === '0') {
            return ['ts' => null, 'id' => 0, 'tombstone' => 0];
        }

        $parts = explode('|', $token);

        if (count($parts) === 1) {
            return ['ts' => $this->normalizeCursorTimestamp($parts[0]), 'id' => 0, 'tombstone' => 0];
        }

        $ts = ($parts[0] === '0' || $parts[0] === '')
            ? null
            : $this->normalizeCursorTimestamp($parts[0]);

        return [
            'ts' => $ts,
            'id' => (int) ($parts[1] ?? 0),
            'tombstone' => (int) ($parts[2] ?? 0),
        ];
    }

    private function normalizeCursorTimestamp(string $raw): ?string
    {
        try {
            return Carbon::parse($raw)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    // ──────────────────────────────────────────────
    //  Mutation routing & application
    // ──────────────────────────────────────────────

    public function applyMutation(User $user, string $module, string $action, array $payload): array
    {
        if ($module === 'attendance' && $action === 'punch') {
            return $this->applyAttendancePunchMutation($user, $payload);
        }

        if ($module === 'leaves' && $action === 'apply') {
            return $this->applyLeaveApplyMutation($user, $payload);
        }

        if ($module === 'leaves' && $action === 'cancel') {
            return $this->applyLeaveCancelMutation($user, $payload);
        }

        if ($module === 'daily_works' && $action === 'update_status') {
            return $this->applyDailyWorkStatusMutation($user, $payload);
        }

        if ($module === 'daily_works' && $action === 'submit_objection') {
            return $this->applyDailyWorkObjectionSubmitMutation($user, $payload);
        }

        if ($module === 'daily_works' && $action === 'review_objection') {
            return $this->applyDailyWorkObjectionReviewMutation($user, $payload);
        }

        if ($module === 'daily_works' && $action === 'resolve_objection') {
            return $this->applyDailyWorkObjectionResolveMutation($user, $payload);
        }

        if ($module === 'daily_works' && $action === 'reject_objection') {
            return $this->applyDailyWorkObjectionRejectMutation($user, $payload);
        }

        return [
            'status' => 'failed',
            'message' => 'Unsupported mutation action for selected module.',
        ];
    }

    // ──────────────────────────────────────────────
    //  Idempotent mutation processing (atomic reserve-then-apply)
    // ──────────────────────────────────────────────

    /**
     * Process one pushed mutation exactly once.
     *
     * The idempotency guarantee is ATOMIC: the unique (user_id, idempotency_key)
     * row is INSERTED as the very first act inside a single DB transaction — that
     * insert IS the lock. Apply and the terminal-result write happen in the SAME
     * transaction, so the historic check→apply→store race (request dies after
     * apply but before store ⇒ double-apply on retry) cannot occur:
     *   - duplicate key  → the mutation already ran; return the stored result.
     *   - transient crash → the whole transaction (including the reservation row)
     *     rolls back, so a retry is free to apply cleanly.
     *   - business rejection (validation/authorization) is a deterministic,
     *     terminal outcome, so it is committed as the idempotent result.
     *
     * @return array{outcome: string, result: array}
     */
    public function processMutation(User $user, array $mutation): array
    {
        $idempotencyKey = (string) ($mutation['idempotency_key'] ?? '');
        $module = (string) ($mutation['module'] ?? '');
        $action = (string) ($mutation['action'] ?? '');
        $payload = is_array($mutation['payload'] ?? null) ? $mutation['payload'] : [];

        // No idempotency store / no key: degrade to a best-effort single apply.
        if ($idempotencyKey === '' || ! Schema::hasTable('mobile_sync_mutations')) {
            return [
                'outcome' => 'applied',
                'result' => $this->applyMutation($user, $module, $action, $payload),
            ];
        }

        try {
            return DB::transaction(function () use ($user, $idempotencyKey, $module, $action, $payload) {
                // 1. Reserve. The unique index is the mutex.
                try {
                    DB::table('mobile_sync_mutations')->insert([
                        'user_id' => (int) $user->id,
                        'idempotency_key' => $idempotencyKey,
                        'module' => $module,
                        'action' => $action,
                        'status' => 'in_progress',
                        'result' => null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (QueryException $exception) {
                    if (! $this->isUniqueViolation($exception)) {
                        throw $exception;
                    }

                    return [
                        'outcome' => 'duplicate',
                        'result' => $this->fetchStoredResult((int) $user->id, $idempotencyKey),
                    ];
                }

                // 2. Apply inside the same transaction.
                $result = $this->applyMutation($user, $module, $action, $payload);

                // 3. Persist the terminal result atomically with the apply.
                DB::table('mobile_sync_mutations')
                    ->where('user_id', (int) $user->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->update([
                        'status' => (string) ($result['status'] ?? 'failed'),
                        'result' => json_encode($result),
                        'updated_at' => now(),
                    ]);

                return ['outcome' => 'applied', 'result' => $result];
            });
        } catch (\Throwable $exception) {
            // Transient/unexpected failure: the transaction rolled back, taking the
            // reservation row with it, so the client may safely retry the same key.
            report($exception);

            return [
                'outcome' => 'transient_error',
                'result' => [
                    'status' => 'failed',
                    'message' => 'A temporary error occurred while applying this change. Please retry.',
                ],
            ];
        }
    }

    private function fetchStoredResult(int $userId, string $idempotencyKey): array
    {
        $row = DB::table('mobile_sync_mutations')
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($row && $row->result) {
            $decoded = json_decode((string) $row->result, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Reserved by a concurrent request that has not yet committed its result.
        return ['status' => (string) ($row->status ?? 'in_progress')];
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        if ((string) $exception->getCode() === '23000') {
            return true;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || str_contains($message, 'integrity constraint');
    }

    // ──────────────────────────────────────────────
    //  Attendance mutation
    // ──────────────────────────────────────────────

    private function applyAttendancePunchMutation(User $user, array $payload): array
    {
        $attendanceType = $user->attendanceType;

        if (! $attendanceType || ! $attendanceType->is_active) {
            return [
                'status' => 'failed',
                'message' => 'No active attendance type assigned to user.',
            ];
        }

        $syncRequest = HttpRequest::create('/api/v1/sync/push', 'POST', $payload);
        $syncRequest->setUserResolver(static fn () => $user);

        // Mark this as the sync offline-capture channel. This attribute is set ONLY
        // here (server-side) and can never originate from client input, so it does
        // not weaken GuardsServerAuthoritativePunchTime — a human punch request has
        // no way to reach the captured_at branch of resolvePunchTime().
        $syncRequest->attributes->set('sync_capture', true);

        // A queued offline punch carries the REAL moment it was captured. Honour it,
        // but only when it is plausible: not in the future and not older than the
        // offline window. An out-of-bounds capture time is rejected outright rather
        // than silently recorded at server time (which would corrupt worked-minutes,
        // late flags, OT and overnight detection).
        if (array_key_exists('captured_at', $payload) && $payload['captured_at'] !== null && $payload['captured_at'] !== '') {
            $capturedAt = $this->boundedCaptureTime((string) $payload['captured_at']);

            if ($capturedAt === null) {
                return [
                    'status' => 'failed',
                    'message' => 'captured_at is outside the allowed offline window (must be within the last 72h and not in the future).',
                ];
            }

            $syncRequest->merge(['captured_at' => $capturedAt->toDateTimeString()]);
        }

        try {
            $validator = AttendanceValidatorFactory::create($attendanceType, $syncRequest);
            $validation = $validator->validate();

            if (($validation['status'] ?? 'error') === 'error') {
                return [
                    'status' => 'failed',
                    'message' => $validation['message'] ?? 'Attendance validation failed.',
                ];
            }
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'failed',
                'message' => 'Invalid attendance type configuration: '.$exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Validation failed. Please try again.',
            ];
        }

        $punchService = new AttendancePunchService;
        $result = $punchService->processPunch($user, $syncRequest);

        if (($result['status'] ?? 'error') === 'error') {
            return [
                'status' => 'failed',
                'message' => $result['message'] ?? 'Failed to record attendance. Please try again.',
            ];
        }

        $attendanceId = (int) ($result['attendance_id'] ?? 0);
        $attendance = Attendance::query()->find($attendanceId);

        return [
            'status' => 'applied',
            'message' => $result['message'] ?? 'Attendance mutation applied.',
            'data' => [
                'attendance_id' => $attendanceId,
                'action' => $result['action'] ?? null,
                'record' => $attendance ? $this->transformAttendanceRecord($attendance) : null,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    //  Leave mutations
    // ──────────────────────────────────────────────

    private function applyLeaveApplyMutation(User $user, array $payload): array
    {
        if (! Schema::hasTable('leaves') || ! Schema::hasTable('leave_settings')) {
            return [
                'status' => 'failed',
                'message' => 'Leave configuration is unavailable.',
            ];
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return [
                'status' => 'failed',
                'message' => 'Leave schema is misconfigured.',
            ];
        }

        $validator = Validator::make($payload, [
            'leave_type_id' => ['required', 'integer', 'exists:leave_settings,id'],
            'from_date' => ['required', 'date', 'before_or_equal:to_date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date', 'before_or_equal:'.now()->addYear()->format('Y-m-d')],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if ($validator->fails()) {
            return [
                'status' => 'failed',
                'message' => $validator->errors()->first(),
            ];
        }

        $validated = $validator->validated();
        $leaveType = LeaveSetting::query()->find((int) $validated['leave_type_id']);

        if (! $leaveType) {
            return [
                'status' => 'failed',
                'message' => 'Invalid leave type selected.',
            ];
        }

        $fromDate = Carbon::parse((string) $validated['from_date'])->startOfDay();
        $toDate = Carbon::parse((string) $validated['to_date'])->startOfDay();

        if ($fromDate->lt(now()->startOfDay())) {
            return [
                'status' => 'failed',
                'message' => 'Leave cannot be applied for past dates.',
            ];
        }

        if ($this->hasOverlappingLeave($userColumn, (int) $user->id, $fromDate, $toDate)) {
            return [
                'status' => 'failed',
                'message' => 'Leave dates overlap with an existing leave request.',
            ];
        }

        if ($this->hasOverlappingHoliday($fromDate, $toDate)) {
            return [
                'status' => 'failed',
                'message' => 'Selected dates overlap with a holiday.',
            ];
        }

        $requiresApproval = (bool) ($leaveType->requires_approval ?? true);
        $autoApprove = (bool) ($leaveType->auto_approve ?? false);
        // Canonical status set {pending, approved, rejected, cancelled} (Phase 2).
        $status = (! $requiresApproval || $autoApprove) ? 'approved' : 'pending';

        $insertPayload = [
            'leave_type' => (int) $leaveType->id,
            'from_date' => $fromDate->toDateString(),
            'to_date' => $toDate->toDateString(),
            'no_of_days' => $fromDate->diffInDays($toDate) + 1,
            'reason' => (string) $validated['reason'],
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        $insertPayload[$userColumn] = (int) $user->id;

        if ($userColumn === 'user_id' && Schema::hasColumn('leaves', 'user')) {
            $insertPayload['user'] = (int) $user->id;
        }

        if ($userColumn === 'user' && Schema::hasColumn('leaves', 'user_id')) {
            $insertPayload['user_id'] = (int) $user->id;
        }

        if (Schema::hasColumn('leaves', 'submitted_at')) {
            $insertPayload['submitted_at'] = now();
        }

        if ($status === 'approved' && Schema::hasColumn('leaves', 'approved_at')) {
            $insertPayload['approved_at'] = now();
        }

        $leaveId = (int) DB::table('leaves')->insertGetId($insertPayload);

        $createdLeave = $this->baseLeavesQuery($user)
            ->where('leaves.id', $leaveId)
            ->first();

        return [
            'status' => 'applied',
            'message' => 'Leave request submitted successfully.',
            'data' => [
                'leave_id' => $leaveId,
                'record' => $createdLeave ? $this->transformLeaveRecord($createdLeave) : null,
            ],
        ];
    }

    private function applyLeaveCancelMutation(User $user, array $payload): array
    {
        $leaveId = (int) ($payload['leave_id'] ?? 0);

        if ($leaveId <= 0) {
            return [
                'status' => 'failed',
                'message' => 'Invalid leave_id in mutation payload.',
            ];
        }

        if (! Schema::hasTable('leaves')) {
            return [
                'status' => 'failed',
                'message' => 'Leave module is unavailable.',
            ];
        }

        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return [
                'status' => 'failed',
                'message' => 'Leave schema is misconfigured.',
            ];
        }

        $leave = DB::table('leaves')->where('id', $leaveId)->first();

        if (! $leave) {
            return [
                'status' => 'failed',
                'message' => 'Leave request not found.',
            ];
        }

        $ownerId = (int) ($leave->{$userColumn} ?? 0);

        if ($ownerId !== (int) $user->id) {
            return [
                'status' => 'failed',
                'message' => 'You are not authorized to cancel this leave request.',
            ];
        }

        $status = strtolower((string) ($leave->status ?? ''));

        if (in_array($status, ['approved', 'declined', 'rejected'], true)) {
            return [
                'status' => 'failed',
                'message' => 'This leave request can no longer be cancelled.',
            ];
        }

        DB::table('leaves')->where('id', $leaveId)->delete();

        // Hard deletes leave no trace for an already-synced device to act on, so
        // emit a tombstone the pull can return. Without this the cancelled leave
        // lives forever in the client's local store.
        $this->recordTombstone((int) $user->id, 'leaves', $leaveId);

        return [
            'status' => 'applied',
            'message' => 'Leave request cancelled successfully.',
            'data' => [
                'leave_id' => $leaveId,
                'cancelled' => true,
            ],
        ];
    }

    // ──────────────────────────────────────────────
    //  Daily work mutations
    // ──────────────────────────────────────────────

    private function applyDailyWorkStatusMutation(User $user, array $payload): array
    {
        $dailyWorkId = (int) ($payload['daily_work_id'] ?? 0);
        $status = (string) ($payload['status'] ?? '');
        $inspectionResult = $payload['inspection_result'] ?? null;

        if ($dailyWorkId <= 0) {
            return [
                'status' => 'failed',
                'message' => 'Invalid daily_work_id in mutation payload.',
            ];
        }

        if (! in_array($status, DailyWork::$statuses, true)) {
            return [
                'status' => 'failed',
                'message' => 'Invalid daily work status in mutation payload.',
            ];
        }

        if ($inspectionResult !== null && ! in_array((string) $inspectionResult, DailyWork::$inspectionResults, true)) {
            return [
                'status' => 'failed',
                'message' => 'Invalid inspection_result in mutation payload.',
            ];
        }

        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return [
                'status' => 'failed',
                'message' => 'Daily work not found.',
            ];
        }

        if (! $this->canAccessDailyWork($user, $dailyWork)) {
            return [
                'status' => 'failed',
                'message' => 'You are not authorized to update this daily work.',
            ];
        }

        $updateData = [
            'status' => $status,
        ];

        if ($inspectionResult !== null) {
            $updateData['inspection_result'] = (string) $inspectionResult;
        }

        if ($status === DailyWork::STATUS_COMPLETED && ! $dailyWork->completion_time) {
            $updateData['completion_time'] = now();
        }

        if ($status === DailyWork::STATUS_NEW) {
            $updateData['completion_time'] = null;
            $updateData['inspection_result'] = null;
        }

        $dailyWork->update($updateData);
        $dailyWork->refresh();

        return [
            'status' => 'applied',
            'message' => 'Daily work status updated successfully.',
            'data' => [
                'daily_work_id' => (int) $dailyWork->id,
                'status' => $dailyWork->status,
                'inspection_result' => $dailyWork->inspection_result,
                'completion_time' => $this->normalizeDateTime($dailyWork->completion_time),
            ],
        ];
    }

    private function applyDailyWorkObjectionSubmitMutation(User $user, array $payload): array
    {
        $target = $this->resolveDailyWorkObjectionTarget($user, $payload);

        if (isset($target['error'])) {
            return $target['error'];
        }

        /** @var RfiObjection $objection */
        $objection = $target['objection'];
        $dailyWorkId = (int) $target['daily_work_id'];

        if (! $this->canSubmitObjection($user, $objection)) {
            return [
                'status' => 'failed',
                'message' => 'You are not authorized to submit this objection.',
            ];
        }

        try {
            $objection->submit('Submitted for review');
            $objection->refresh();

            return [
                'status' => 'applied',
                'message' => 'Objection submitted for review.',
                'data' => $this->transformObjectionMutationData($objection, $dailyWorkId),
            ];
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Failed to submit objection.',
            ];
        }
    }

    private function applyDailyWorkObjectionReviewMutation(User $user, array $payload): array
    {
        $target = $this->resolveDailyWorkObjectionTarget($user, $payload);

        if (isset($target['error'])) {
            return $target['error'];
        }

        /** @var RfiObjection $objection */
        $objection = $target['objection'];
        $dailyWorkId = (int) $target['daily_work_id'];

        if (! $this->canReviewObjection($user)) {
            return [
                'status' => 'failed',
                'message' => 'You are not authorized to review this objection.',
            ];
        }

        try {
            $objection->startReview('Review started');
            $objection->refresh();

            return [
                'status' => 'applied',
                'message' => 'Objection is now under review.',
                'data' => $this->transformObjectionMutationData($objection, $dailyWorkId),
            ];
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Failed to start objection review.',
            ];
        }
    }

    private function applyDailyWorkObjectionResolveMutation(User $user, array $payload): array
    {
        $target = $this->resolveDailyWorkObjectionTarget($user, $payload);

        if (isset($target['error'])) {
            return $target['error'];
        }

        /** @var RfiObjection $objection */
        $objection = $target['objection'];
        $dailyWorkId = (int) $target['daily_work_id'];

        if (! $this->canReviewObjection($user)) {
            return [
                'status' => 'failed',
                'message' => 'You are not authorized to review this objection.',
            ];
        }

        $resolutionNotes = trim((string) ($payload['resolution_notes'] ?? ''));

        if ($resolutionNotes === '') {
            return [
                'status' => 'failed',
                'message' => 'The resolution_notes field is required.',
            ];
        }

        try {
            $objection->resolve($resolutionNotes);
            $objection->refresh();

            return [
                'status' => 'applied',
                'message' => 'Objection resolved successfully.',
                'data' => $this->transformObjectionMutationData($objection, $dailyWorkId),
            ];
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Failed to resolve objection.',
            ];
        }
    }

    private function applyDailyWorkObjectionRejectMutation(User $user, array $payload): array
    {
        $target = $this->resolveDailyWorkObjectionTarget($user, $payload);

        if (isset($target['error'])) {
            return $target['error'];
        }

        /** @var RfiObjection $objection */
        $objection = $target['objection'];
        $dailyWorkId = (int) $target['daily_work_id'];

        if (! $this->canReviewObjection($user)) {
            return [
                'status' => 'failed',
                'message' => 'You are not authorized to review this objection.',
            ];
        }

        $rejectionReason = trim((string) ($payload['resolution_notes'] ?? $payload['rejection_reason'] ?? ''));

        if ($rejectionReason === '') {
            return [
                'status' => 'failed',
                'message' => 'The rejection_reason field is required.',
            ];
        }

        try {
            $objection->reject($rejectionReason);
            $objection->refresh();

            return [
                'status' => 'applied',
                'message' => 'Objection rejected.',
                'data' => $this->transformObjectionMutationData($objection, $dailyWorkId),
            ];
        } catch (\InvalidArgumentException $exception) {
            return [
                'status' => 'failed',
                'message' => $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            report($exception);

            return [
                'status' => 'failed',
                'message' => 'Failed to reject objection.',
            ];
        }
    }

    // ──────────────────────────────────────────────
    //  Objection helpers
    // ──────────────────────────────────────────────

    private function resolveDailyWorkObjectionTarget(User $user, array $payload): array
    {
        $dailyWorkId = (int) ($payload['daily_work_id'] ?? 0);
        $objectionId = (int) ($payload['objection_id'] ?? 0);

        if ($dailyWorkId <= 0) {
            return [
                'error' => [
                    'status' => 'failed',
                    'message' => 'Invalid daily_work_id in mutation payload.',
                ],
            ];
        }

        if ($objectionId <= 0) {
            return [
                'error' => [
                    'status' => 'failed',
                    'message' => 'Invalid objection_id in mutation payload.',
                ],
            ];
        }

        $dailyWork = DailyWork::query()->find($dailyWorkId);

        if (! $dailyWork) {
            return [
                'error' => [
                    'status' => 'failed',
                    'message' => 'Daily work not found.',
                ],
            ];
        }

        if (! $this->canAccessDailyWork($user, $dailyWork)) {
            return [
                'error' => [
                    'status' => 'failed',
                    'message' => 'You are not authorized to access this daily work.',
                ],
            ];
        }

        $objection = $this->findObjectionForDailyWork($dailyWorkId, $objectionId);

        if (! $objection) {
            return [
                'error' => [
                    'status' => 'failed',
                    'message' => 'Objection not found for this daily work.',
                ],
            ];
        }

        return [
            'daily_work_id' => $dailyWorkId,
            'objection' => $objection,
        ];
    }

    private function findObjectionForDailyWork(int $dailyWorkId, int $objectionId): ?RfiObjection
    {
        if (! Schema::hasTable('rfi_objections')) {
            return null;
        }

        $hasPivotTable = Schema::hasTable('daily_work_objection');
        $hasLegacyColumn = Schema::hasColumn('rfi_objections', 'daily_work_id');

        if (! $hasPivotTable && ! $hasLegacyColumn) {
            return null;
        }

        return RfiObjection::query()
            ->where('id', $objectionId)
            ->where(function ($objectionQuery) use ($dailyWorkId, $hasPivotTable, $hasLegacyColumn) {
                if ($hasPivotTable) {
                    $objectionQuery->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWorkId) {
                        $dailyWorkQuery->where('daily_works.id', $dailyWorkId);
                    });
                }

                if ($hasLegacyColumn) {
                    if ($hasPivotTable) {
                        $objectionQuery->orWhere('daily_work_id', $dailyWorkId);
                    } else {
                        $objectionQuery->where('daily_work_id', $dailyWorkId);
                    }
                }
            })
            ->first();
    }

    // ──────────────────────────────────────────────
    //  Authorization helpers
    // ──────────────────────────────────────────────

    private function canAccessDailyWork(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        if ((int) $dailyWork->incharge === (int) $user->id
            || (int) $dailyWork->assigned === (int) $user->id) {
            return true;
        }

        if ($user->report_to && (int) $dailyWork->incharge === (int) $user->report_to) {
            return true;
        }

        return false;
    }

    private function canSubmitObjection(User $user, RfiObjection $objection): bool
    {
        return (int) $objection->created_by === (int) $user->id || $this->isPrivilegedUser($user);
    }

    private function canReviewObjection(User $user): bool
    {
        return $this->isPrivilegedUser($user);
    }

    private function isPrivilegedUser(User $user): bool
    {
        return $user->hasRole([
            'Super Admin',
            'Admin',
            'HR Manager',
            'Project Manager',
            'Consultant',
            'Super Administrator',
            'Administrator',
        ]);
    }

    // ──────────────────────────────────────────────
    //  Query builders
    // ──────────────────────────────────────────────

    private function baseLeavesQuery(User $user): Builder
    {
        $userColumn = $this->resolveLeavesUserColumn();

        if (! $userColumn) {
            return DB::table('leaves')->whereRaw('1 = 0');
        }

        $query = DB::table('leaves')
            ->where('leaves.'.$userColumn, $user->id)
            ->select([
                'leaves.id',
                'leaves.leave_type',
                'leaves.from_date',
                'leaves.to_date',
                'leaves.no_of_days',
                'leaves.reason',
                'leaves.status',
                'leaves.updated_at',
                'leaves.created_at',
                DB::raw('NULL as leave_type_name'),
            ]);

        if (Schema::hasTable('leave_settings')) {
            $query->leftJoin('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
                ->addSelect('leave_settings.type as leave_type_name');
        }

        return $query;
    }

    private function baseDailyWorksQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = DailyWork::query();

        if ($this->isPrivilegedUser($user)) {
            return $query;
        }

        $userDesignationTitle = '';
        if (\Schema::hasColumn('users', 'designation_id') && \Schema::hasTable('designations')) {
            if (! $user->relationLoaded('designation')) {
                $user->load('designation:id,title');
            }
            $userDesignationTitle = trim((string) ($user->designation?->title ?? ''));
        }

        if ($userDesignationTitle === 'Supervision Engineer') {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id);
                if ($user->report_to) {
                    $q->orWhere('incharge', $user->report_to);
                }
            });
        } elseif (in_array($userDesignationTitle, ['Quality Control Inspector', 'Asst. Quality Control Inspector'])) {
            if ($user->report_to) {
                $query->where('incharge', $user->report_to);
            } else {
                $query->where('assigned', $user->id);
            }
        } elseif ($user->hasRole('Employee')) {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);

                if ($user->report_to) {
                    $q->orWhere('incharge', $user->report_to);
                }
            });
        } elseif ($user->report_to) {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id)
                    ->orWhere('incharge', $user->report_to);
            });
        } else {
            $query->where(function ($q) use ($user) {
                $q->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);
            });
        }

        return $query;
    }

    private function hasOverlappingLeave(string $userColumn, int $userId, Carbon $fromDate, Carbon $toDate): bool
    {
        return DB::table('leaves')
            ->where($userColumn, $userId)
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhereBetween('to_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhere(function ($nestedQuery) use ($fromDate, $toDate) {
                        $nestedQuery->whereDate('from_date', '<=', $fromDate->toDateString())
                            ->whereDate('to_date', '>=', $toDate->toDateString());
                    });
            })
            ->exists();
    }

    private function hasOverlappingHoliday(Carbon $fromDate, Carbon $toDate): bool
    {
        if (! Schema::hasTable('holidays')) {
            return false;
        }

        return DB::table('holidays')
            ->where(function ($query) use ($fromDate, $toDate) {
                $query->whereBetween('from_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhereBetween('to_date', [$fromDate->toDateString(), $toDate->toDateString()])
                    ->orWhere(function ($nestedQuery) use ($fromDate, $toDate) {
                        $nestedQuery->whereDate('from_date', '<=', $fromDate->toDateString())
                            ->whereDate('to_date', '>=', $toDate->toDateString());
                    });
            })
            ->exists();
    }

    private function resolveLeavesUserColumn(): ?string
    {
        if (Schema::hasColumn('leaves', 'user_id')) {
            return 'user_id';
        }

        if (Schema::hasColumn('leaves', 'user')) {
            return 'user';
        }

        return null;
    }

    /**
     * Append a deletion tombstone so a pull can tell already-synced devices to
     * evict a row that no longer exists (or is no longer visible) to the user.
     */
    private function recordTombstone(int $userId, string $module, int $entityId): void
    {
        if (! Schema::hasTable('sync_tombstones')) {
            return;
        }

        DB::table('sync_tombstones')->insert([
            'user_id' => $userId,
            'module' => $module,
            'entity_id' => $entityId,
            'created_at' => now(),
        ]);
    }

    // ──────────────────────────────────────────────
    //  Visibility tombstones (scope departures)
    // ──────────────────────────────────────────────

    /**
     * Emit per-user tombstones for a daily work that has LEFT a user's visibility
     * without being deleted from their point of view.
     *
     * Why this exists: `pull` returns only currently-visible rows plus deletion
     * tombstones. A daily work reassigned from user A to user B is not deleted,
     * so A's next pull simply stops mentioning it — and A's device caches it
     * forever, showing work that is no longer theirs (and, for a soft delete,
     * work that no longer exists at all).
     *
     * ARRIVALS need no mechanism: the same write bumps `updated_at`, so B's
     * visibility-scoped ASC cursor pull picks the row up as a normal row. Only
     * DEPARTURES need an explicit signal, because the departing user's own query
     * can no longer see the row to report on it.
     *
     * Cost: this runs on the (rare) reassignment/removal write, never on pull.
     * The candidate set is bounded — the old/new owners, the direct reports of
     * the old/new incharge (they see via `incharge = report_to`), and on removal
     * the privileged roles that see everything. Each candidate is then filtered
     * through the REAL visibility predicate (`baseDailyWorksQuery`) so this can
     * never drift out of step with what `pull` actually returns.
     *
     * @param  array{incharge?: int|string|null, assigned?: int|string|null}  $previousOwners
     * @param  bool  $removed  True when the row left EVERY user's visibility
     *                         (soft/force delete), not just the old owner's.
     */
    public function recordDailyWorkVisibilityDepartures(DailyWork $dailyWork, array $previousOwners, bool $removed = false): void
    {
        if (! Schema::hasTable('sync_tombstones')) {
            return;
        }

        $candidateIds = $this->dailyWorkVisibilityCandidateIds($dailyWork, $previousOwners, $removed);

        if ($candidateIds === []) {
            return;
        }

        // Eager-load roles + designation: baseDailyWorksQuery() consults both, and
        // Model::preventLazyLoading() (active outside production) throws on a
        // lazy load from a queried — i.e. not freshly created — model.
        $relations = ['roles'];

        if (Schema::hasColumn('users', 'designation_id') && Schema::hasTable('designations')) {
            $relations[] = 'designation';
        }

        $candidates = User::query()
            ->with($relations)
            ->whereIn('id', $candidateIds)
            ->get();

        $rows = [];
        $entityId = (int) $dailyWork->id;
        $now = now();

        foreach ($candidates as $candidate) {
            if ($this->dailyWorkVisibleTo($candidate, $entityId)) {
                continue; // Still theirs — an arrival, or unaffected.
            }

            $rows[] = [
                'user_id' => (int) $candidate->id,
                'module' => 'daily_works',
                'entity_id' => $entityId,
                'created_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('sync_tombstones')->insert($rows);
        }
    }

    /**
     * Does this daily work still satisfy the user's pull visibility predicate?
     *
     * Deliberately reuses baseDailyWorksQuery() — the exact query `pull` pages —
     * so a tombstone is emitted if and only if the row really has left the pull
     * result set. A soft-deleted row fails here for everyone (SoftDeletes global
     * scope), which is what makes deletes tombstone correctly too.
     */
    private function dailyWorkVisibleTo(User $user, int $dailyWorkId): bool
    {
        return $this->baseDailyWorksQuery($user)
            ->whereKey($dailyWorkId)
            ->exists();
    }

    /**
     * Bounded set of users whose visibility of this row may have changed.
     *
     * @param  array{incharge?: int|string|null, assigned?: int|string|null}  $previousOwners
     * @return array<int, int>
     */
    private function dailyWorkVisibilityCandidateIds(DailyWork $dailyWork, array $previousOwners, bool $includePrivileged): array
    {
        $previousIncharge = (int) ($previousOwners['incharge'] ?? 0);
        $previousAssigned = (int) ($previousOwners['assigned'] ?? 0);
        $currentIncharge = (int) $dailyWork->incharge;
        $currentAssigned = (int) $dailyWork->assigned;

        $ids = array_filter([$previousIncharge, $previousAssigned, $currentIncharge, $currentAssigned]);

        // Subordinates see a row through `incharge = their report_to`, so an
        // incharge change (or a removal) moves the row for the whole team.
        $inchargeIds = array_values(array_filter([$previousIncharge, $currentIncharge]));

        if ($inchargeIds !== [] && Schema::hasColumn('users', 'report_to')) {
            $ids = array_merge(
                $ids,
                DB::table('users')->whereIn('report_to', $inchargeIds)->pluck('id')->all()
            );
        }

        if ($includePrivileged) {
            $ids = array_merge($ids, $this->privilegedUserIds());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Ids of users who see every daily work (mirrors isPrivilegedUser()).
     *
     * Read straight off the Spatie pivot rather than the `role()` scope: an
     * absent role name makes that scope THROW, and a sync side-effect must never
     * be able to break the write that triggered it. The morph type is derived via
     * getMorphClass() so a morph-map alias is honoured.
     *
     * @return array<int, int>
     */
    private function privilegedUserIds(): array
    {
        if (! Schema::hasTable('model_has_roles') || ! Schema::hasTable('roles')) {
            return [];
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', (new User)->getMorphClass())
            ->whereIn('roles.name', [
                'Super Admin',
                'Admin',
                'HR Manager',
                'Project Manager',
                'Consultant',
                'Super Administrator',
                'Administrator',
            ])
            ->pluck('model_has_roles.model_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Bound a client-asserted offline capture time. Returns null (⇒ reject) when
     * the timestamp is unparseable, in the future beyond a small skew, or older
     * than the maximum offline window.
     */
    private function boundedCaptureTime(string $raw): ?Carbon
    {
        try {
            $parsed = Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        $now = Carbon::now();

        if ($parsed->greaterThan($now->copy()->addMinutes(self::CAPTURE_FUTURE_SKEW_MINUTES))) {
            return null;
        }

        if ($parsed->lessThan($now->copy()->subHours(self::CAPTURE_MAX_AGE_HOURS))) {
            return null;
        }

        return $parsed;
    }

    // ──────────────────────────────────────────────
    //  Record transformers
    // ──────────────────────────────────────────────

    private function transformAttendanceRecord(Attendance $attendance): array
    {
        return [
            'id' => (int) $attendance->id,
            'user_id' => (int) $attendance->user_id,
            'date' => $this->normalizeDate($attendance->date),
            'punchin' => $this->normalizeDateTime($attendance->punchin),
            'punchout' => $this->normalizeDateTime($attendance->punchout),
            'updated_at' => $this->normalizeDateTime($attendance->updated_at),
        ];
    }

    private function transformLeaveRecord(mixed $leave): array
    {
        return [
            'id' => (int) $leave->id,
            'leave_type' => $leave->leave_type,
            'leave_type_name' => $leave->leave_type_name,
            'from_date' => $this->normalizeDate($leave->from_date),
            'to_date' => $this->normalizeDate($leave->to_date),
            'no_of_days' => (int) ($leave->no_of_days ?? 0),
            'reason' => $leave->reason,
            'status' => $leave->status,
            'created_at' => $this->normalizeDateTime($leave->created_at),
            'updated_at' => $this->normalizeDateTime($leave->updated_at),
        ];
    }

    private function transformDailyWorkRecord(DailyWork $dailyWork): array
    {
        return [
            'id' => (int) $dailyWork->id,
            'number' => $dailyWork->number,
            'date' => $this->normalizeDate($dailyWork->date),
            'status' => $dailyWork->status,
            'type' => $dailyWork->type,
            'active_objections_count' => (int) ($dailyWork->active_objections_count ?? 0),
            'updated_at' => $this->normalizeDateTime($dailyWork->updated_at),
        ];
    }

    private function transformObjectionMutationData(RfiObjection $objection, int $dailyWorkId): array
    {
        return [
            'daily_work_id' => $dailyWorkId,
            'objection_id' => (int) $objection->id,
            'status' => $objection->status,
            'status_label' => $objection->status_label,
            'resolution_notes' => $objection->resolution_notes,
            'resolved_at' => $this->normalizeDateTime($objection->resolved_at),
        ];
    }

    // ──────────────────────────────────────────────
    //  Normalizers
    // ──────────────────────────────────────────────

    private function normalizeDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            return substr($value, 0, 10);
        }

        return null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
