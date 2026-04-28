<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SyncBootstrapRequest;
use App\Http\Requests\Api\V1\SyncPullRequest;
use App\Http\Requests\Api\V1\SyncPushRequest;
use App\Models\DailyWork;
use App\Models\HRM\Attendance;
use App\Models\HRM\LeaveSetting;
use App\Models\RfiObjection;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use App\Services\Attendance\AttendanceValidatorFactory;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

class SyncController extends Controller
{
    public function bootstrap(SyncBootstrapRequest $request): JsonResponse
    {
        $user = $request->user();
        $limit = (int) $request->input('limit', 25);
        $modules = $this->resolveModules($request->input('modules', []));
        $cursor = now()->toAtomString();

        $moduleData = [];

        foreach ($modules as $module) {
            if ($module === 'attendance') {
                $moduleData['attendance'] = $this->bootstrapAttendance($user, $limit);

                continue;
            }

            if ($module === 'leaves') {
                $moduleData['leaves'] = $this->bootstrapLeaves($user, $limit);

                continue;
            }

            if ($module === 'daily_works') {
                $moduleData['daily_works'] = $this->bootstrapDailyWorks($user, $limit);
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
        $modules = $this->resolveModules($request->input('modules', []));
        $cursor = Carbon::parse((string) $request->input('cursor'));
        $nextCursor = now()->toAtomString();

        $changes = [];
        $counts = [];

        foreach ($modules as $module) {
            if ($module === 'attendance') {
                $records = $this->pullAttendance($user, $cursor, $limit);
                $changes['attendance'] = $records;
                $counts['attendance'] = count($records);

                continue;
            }

            if ($module === 'leaves') {
                $records = $this->pullLeaves($user, $cursor, $limit);
                $changes['leaves'] = $records;
                $counts['leaves'] = count($records);

                continue;
            }

            if ($module === 'daily_works') {
                $records = $this->pullDailyWorks($user, $cursor, $limit);
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

            $existingResult = $this->findStoredMutationResult((int) $user->id, $idempotencyKey);

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

            $result = $this->applyMutation($user, $module, $action, $payload);

            if (($result['status'] ?? 'failed') === 'applied') {
                $applied++;
            } else {
                $failed++;
            }

            $this->storeMutationResult((int) $user->id, $idempotencyKey, $module, $action, $result);

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

    private function bootstrapAttendance(User $user, int $limit): array
    {
        if (! Schema::hasTable('attendances')) {
            return [
                'records' => [],
                'total' => 0,
            ];
        }

        $records = Attendance::query()
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'date', 'punchin', 'punchout', 'updated_at'])
            ->map(fn (Attendance $attendance): array => $this->transformAttendanceRecord($attendance))
            ->values()
            ->all();

        return [
            'records' => $records,
            'total' => count($records),
        ];
    }

    private function pullAttendance(User $user, Carbon $cursor, int $limit): array
    {
        if (! Schema::hasTable('attendances')) {
            return [];
        }

        return Attendance::query()
            ->where('user_id', $user->id)
            ->where('updated_at', '>', $cursor->toDateTimeString())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'user_id', 'date', 'punchin', 'punchout', 'updated_at'])
            ->map(fn (Attendance $attendance): array => $this->transformAttendanceRecord($attendance))
            ->values()
            ->all();
    }

    private function bootstrapLeaves(User $user, int $limit): array
    {
        if (! Schema::hasTable('leaves')) {
            return [
                'records' => [],
                'total' => 0,
            ];
        }

        if (! $this->resolveLeavesUserColumn()) {
            return [
                'records' => [],
                'total' => 0,
            ];
        }

        $records = $this->baseLeavesQuery($user)
            ->orderByDesc('leaves.updated_at')
            ->orderByDesc('leaves.id')
            ->limit($limit)
            ->get()
            ->map(fn ($leave): array => $this->transformLeaveRecord($leave))
            ->values()
            ->all();

        return [
            'records' => $records,
            'total' => count($records),
        ];
    }

    private function pullLeaves(User $user, Carbon $cursor, int $limit): array
    {
        if (! Schema::hasTable('leaves')) {
            return [];
        }

        if (! $this->resolveLeavesUserColumn()) {
            return [];
        }

        $query = $this->baseLeavesQuery($user);

        if (Schema::hasColumn('leaves', 'updated_at')) {
            $query->where('leaves.updated_at', '>', $cursor->toDateTimeString());
        }

        return $query
            ->orderByDesc('leaves.updated_at')
            ->orderByDesc('leaves.id')
            ->limit($limit)
            ->get()
            ->map(fn ($leave): array => $this->transformLeaveRecord($leave))
            ->values()
            ->all();
    }

    private function bootstrapDailyWorks(User $user, int $limit): array
    {
        if (! Schema::hasTable('daily_works')) {
            return [
                'records' => [],
                'total' => 0,
            ];
        }

        $records = $this->baseDailyWorksQuery($user)
            ->withCount(['activeObjections'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DailyWork $dailyWork): array => $this->transformDailyWorkRecord($dailyWork))
            ->values()
            ->all();

        return [
            'records' => $records,
            'total' => count($records),
        ];
    }

    private function pullDailyWorks(User $user, Carbon $cursor, int $limit): array
    {
        if (! Schema::hasTable('daily_works')) {
            return [];
        }

        return $this->baseDailyWorksQuery($user)
            ->withCount(['activeObjections'])
            ->where('updated_at', '>', $cursor->toDateTimeString())
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DailyWork $dailyWork): array => $this->transformDailyWorkRecord($dailyWork))
            ->values()
            ->all();
    }

    private function applyMutation(User $user, string $module, string $action, array $payload): array
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
        $status = (! $requiresApproval || $autoApprove) ? 'Approved' : 'New';

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

        if ($status === 'Approved' && Schema::hasColumn('leaves', 'approved_at')) {
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

        return [
            'status' => 'applied',
            'message' => 'Leave request cancelled successfully.',
            'data' => [
                'leave_id' => $leaveId,
                'cancelled' => true,
            ],
        ];
    }

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

    private function canAccessDailyWork(User $user, DailyWork $dailyWork): bool
    {
        if ($this->isPrivilegedUser($user)) {
            return true;
        }

        return (int) $dailyWork->incharge === (int) $user->id
            || (int) $dailyWork->assigned === (int) $user->id;
    }

    private function canSubmitObjection(User $user, RfiObjection $objection): bool
    {
        return (int) $objection->created_by === (int) $user->id || $this->isPrivilegedUser($user);
    }

    private function canReviewObjection(User $user): bool
    {
        return $this->isPrivilegedUser($user);
    }

    private function findObjectionForDailyWork(int $dailyWorkId, int $objectionId): ?RfiObjection
    {
        if (! Schema::hasTable('rfi_objections')) {
            return null;
        }

        $hasPivotTable = Schema::hasTable('daily_work_objection');
        if (! $hasPivotTable) {
            return null;
        }

        return RfiObjection::query()
            ->where('id', $objectionId)
            ->whereHas('dailyWorks', function ($dailyWorkQuery) use ($dailyWorkId) {
                $dailyWorkQuery->where('daily_works.id', $dailyWorkId);
            })
            ->first();
    }

    private function findStoredMutationResult(int $userId, string $idempotencyKey): ?array
    {
        if (! Schema::hasTable('mobile_sync_mutations')) {
            return null;
        }

        $mutation = DB::table('mobile_sync_mutations')
            ->where('user_id', $userId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $mutation || ! $mutation->result) {
            return null;
        }

        $decoded = json_decode((string) $mutation->result, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function storeMutationResult(int $userId, string $idempotencyKey, string $module, string $action, array $result): void
    {
        if (! Schema::hasTable('mobile_sync_mutations')) {
            return;
        }

        DB::table('mobile_sync_mutations')->updateOrInsert(
            [
                'user_id' => $userId,
                'idempotency_key' => $idempotencyKey,
            ],
            [
                'module' => $module,
                'action' => $action,
                'status' => (string) ($result['status'] ?? 'failed'),
                'result' => json_encode($result),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function baseLeavesQuery(User $user): \Illuminate\Database\Query\Builder
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

    private function baseDailyWorksQuery(User $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = DailyWork::query();

        if (! $this->isPrivilegedUser($user)) {
            $query->where(function ($dailyWorkQuery) use ($user) {
                $dailyWorkQuery->where('incharge', $user->id)
                    ->orWhere('assigned', $user->id);
            });
        }

        return $query;
    }

    private function resolveModules(array $modules): array
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
