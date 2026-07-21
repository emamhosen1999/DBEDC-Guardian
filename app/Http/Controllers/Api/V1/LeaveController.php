<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ApproveLeaveRequest;
use App\Http\Requests\Api\V1\BulkApproveLeavesRequest;
use App\Http\Requests\Api\V1\BulkRejectLeavesRequest;
use App\Http\Requests\Api\V1\LeaveAnalyticsRequest;
use App\Http\Requests\Api\V1\LeaveCalendarRequest;
use App\Http\Requests\Api\V1\LeaveSummaryRequest;
use App\Http\Requests\Api\V1\ListLeavesRequest;
use App\Http\Requests\Api\V1\RejectLeaveRequest;
use App\Http\Requests\Api\V1\StoreLeaveRequest;
use App\Http\Requests\Api\V1\UpdateLeaveRequest;
use App\Http\Responses\ApiResponse;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Services\Api\V1\LeaveApiService;
use App\Services\Leave\LeaveApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LeaveController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected LeaveApiService $leaveApiService
    ) {}

    public function types(): JsonResponse
    {
        return $this->successResponse($this->leaveApiService->getLeaveTypes());
    }

    public function index(ListLeavesRequest $request): JsonResponse
    {
        $perPage = (int) $request->input('perPage', 10);
        $page = (int) $request->input('page', 1);

        try {
            if (! $this->leaveApiService->leavesTableExists()) {
                $empty = $this->leaveApiService->emptyLeaveList($perPage);

                return $this->successResponse($empty);
            }

            $result = $this->leaveApiService->listLeavesForUser(
                $request->user(),
                array_filter([
                    'status' => $request->input('status'),
                    'year' => $request->input('year'),
                    'month' => $request->input('month'),
                ]),
                $page,
                $perPage
            );

            return $this->successResponse($result);
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 'LEAVE_SCHEMA_MISCONFIGURED', 500);
        }
    }

    public function show(Request $request, int $leaveId): JsonResponse
    {
        try {
            $leave = $this->leaveApiService->getLeaveForUser($request->user(), $leaveId);

            if ($leave === null) {
                return $this->notFoundResponse('Leave request not found.');
            }

            return $this->successResponse($leave);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 403) {
                return $this->forbiddenResponse($e->getMessage());
            }

            return $this->errorResponse($e->getMessage(), 'LEAVE_SCHEMA_MISCONFIGURED', 500);
        }
    }

    public function calendar(LeaveCalendarRequest $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);
        $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;
        $includeHolidays = $request->boolean('include_holidays', true);

        if ($leaveTypeId !== null && Schema::hasTable('leave_settings') && ! LeaveSetting::query()->whereKey($leaveTypeId)->exists()) {
            return $this->errorResponse('Invalid leave type selected.', 'INVALID_LEAVE_TYPE', 422);
        }

        try {
            return $this->successResponse(
                $this->leaveApiService->getCalendarForUser(
                    $request->user(),
                    $year,
                    $month,
                    $leaveTypeId,
                    $includeHolidays
                )
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 'LEAVE_SCHEMA_MISCONFIGURED', 500);
        }
    }

    public function summary(LeaveSummaryRequest $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $leaveTypeId = $request->filled('leave_type_id') ? $request->integer('leave_type_id') : null;

        try {
            return $this->successResponse(
                $this->leaveApiService->getSummaryForUser($request->user(), $year, $leaveTypeId)
            );
        } catch (\RuntimeException $e) {
            return $this->errorResponse($e->getMessage(), 'LEAVE_SCHEMA_MISCONFIGURED', 500);
        }
    }

    public function analytics(LeaveAnalyticsRequest $request): JsonResponse
    {
        $year = (int) $request->input('year', now()->year);
        $leaveTypeId = $request->filled('leave_type_id') ? (int) $request->input('leave_type_id') : null;

        try {
            return $this->successResponse(
                $this->leaveApiService->getAnalyticsForUser($request->user(), $year, $leaveTypeId)
            );
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 422) {
                return $this->errorResponse($e->getMessage(), 'INVALID_LEAVE_TYPE', 422);
            }

            return $this->errorResponse($e->getMessage(), 'LEAVE_SCHEMA_MISCONFIGURED', 500);
        }
    }

    public function store(StoreLeaveRequest $request): JsonResponse
    {
        try {
            $leave = $this->leaveApiService->createLeaveForUser(
                $request->user(),
                $request->integer('leave_type_id'),
                $request->input('from_date'),
                $request->input('to_date'),
                $request->input('reason')
            );

            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $request->user()->id, 'apply');

            return $this->successResponse($leave, 'Leave request submitted successfully.', 201);
        } catch (\RuntimeException $e) {
            return $this->mapLeaveRuntimeException($e);
        }
    }

    public function update(UpdateLeaveRequest $request, int $leaveId): JsonResponse
    {
        try {
            $leave = $this->leaveApiService->updateLeaveForUser(
                $request->user(),
                $leaveId,
                $request->integer('leave_type_id'),
                $request->input('from_date'),
                $request->input('to_date'),
                $request->input('reason')
            );

            return $this->successResponse($leave, 'Leave request updated successfully.');
        } catch (\RuntimeException $e) {
            return $this->mapLeaveRuntimeException($e);
        }
    }

    public function destroy(Request $request, int $leaveId): JsonResponse
    {
        try {
            $this->leaveApiService->cancelLeaveForUser($request->user(), $leaveId);

            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $request->user()->id, 'cancel');

            return $this->successResponse(null, 'Leave request cancelled successfully.');
        } catch (\RuntimeException $e) {
            return $this->mapLeaveRuntimeException($e);
        }
    }

    public function pendingApprovals(Request $request, LeaveApprovalService $approvalService): JsonResponse
    {
        if (! Schema::hasTable('leaves')) {
            return $this->successResponse([
                'pending_leaves' => [],
                'stats' => [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'total' => 0,
                ],
            ]);
        }

        $approver = $request->user();

        $pendingLeaves = Leave::query()
            ->with([
                // NB: `profile_image` is NOT a real column in production (it only
                // exists in the SQLite test schema). Selecting it here threw a
                // 500 as soon as a pending leave had a real employee row. The
                // avatar comes from the media library via the profile_image_url
                // accessor instead, so eager-load media to avoid an N+1.
                'employee:id,name,employee_id',
                'employee.media',
                'leaveSetting:id,type,symbol',
            ])
            ->whereNotNull('approval_chain')
            ->whereRaw('LOWER(status) = ?', ['pending'])
            ->orderByDesc('from_date')
            ->get()
            ->filter(function (Leave $leave) use ($approvalService, $approver): bool {
                $this->normalizePendingStatus($leave);

                return $approvalService->canApprove($leave, $approver);
            })
            ->values();

        $stats = $this->buildApprovalStats((int) $approver->id, $pendingLeaves);

        return $this->successResponse([
            'pending_leaves' => $pendingLeaves->map(function (Leave $leave) {
                return $this->transformApprovalLeave($leave);
            })->values(),
            'stats' => $stats,
        ]);
    }

    /**
     * Manager approval HISTORY — leaves this approver is/was in the approval
     * chain for that are now DECIDED (approved or rejected, including leaves an
     * administrator OVERRODE out of their pending queue). Mirrors the pending
     * queue's item shape exactly (transformApprovalLeave) so the mobile screen
     * reuses the same card, and adds decided metadata. Newest first.
     */
    public function decidedApprovals(Request $request, LeaveApprovalService $approvalService): JsonResponse
    {
        if (! Schema::hasTable('leaves')) {
            return $this->successResponse([
                'decided_leaves' => [],
                'stats' => [
                    'pending' => 0,
                    'approved' => 0,
                    'rejected' => 0,
                    'total' => 0,
                ],
            ]);
        }

        $approver = $request->user();

        $decidedLeaves = Leave::query()
            ->with([
                // Same eager-load contract as pendingApprovals — never select the
                // non-existent profile_image column; the avatar comes from media.
                'employee:id,name,employee_id',
                'employee.media',
                'leaveSetting:id,type,symbol',
            ])
            ->whereNotNull('approval_chain')
            ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'rejected'])
            // Newest decision first. The decision writes the row, so updated_at is
            // the most reliable "when was this decided" ordering key.
            ->orderByDesc('updated_at')
            ->get()
            // A manager must see items they are/were an approver on at ANY level —
            // including a level an admin marked 'superseded' when overriding.
            ->filter(fn (Leave $leave): bool => $this->isApprovalChainMember($leave, (int) $approver->id))
            ->values();

        // Stats mirror pendingApprovals exactly: the same approver-scoped
        // approved/rejected tallies plus the live pending count.
        $stats = $this->buildApprovalStats(
            (int) $approver->id,
            $approvalService->getPendingApprovalsForUser($approver)
        );

        return $this->successResponse([
            'decided_leaves' => $decidedLeaves->map(function (Leave $leave) {
                return array_merge(
                    $this->transformApprovalLeave($leave),
                    $this->decidedApprovalMeta($leave),
                );
            })->values(),
            'stats' => $stats,
        ]);
    }

    public function approve(ApproveLeaveRequest $request, int $leaveId, LeaveApprovalService $approvalService): JsonResponse
    {
        $leave = Leave::query()->with(['employee', 'leaveSetting'])->find($leaveId);

        if (! $leave) {
            return $this->notFoundResponse('Leave request not found.');
        }

        $this->normalizePendingStatus($leave);

        // Normal manager approval from the mobile queue — the service advances the
        // chain level by level and owns the notification + realtime signal (fired
        // once, post-commit), so the applicant's My Leaves updates live.
        $result = $approvalService->approve($leave, $request->user(), $request->input('comments'));

        if (! ($result['success'] ?? false)) {
            return $this->forbiddenResponse($result['message'] ?? 'Unable to approve leave request.');
        }

        return $this->successResponse([
            'leave' => $this->transformApprovalLeave($leave->fresh(['employee', 'leaveSetting'])),
            'status' => $result['status'] ?? null,
        ], $result['message'] ?? 'Leave approved successfully.');
    }

    public function reject(RejectLeaveRequest $request, int $leaveId, LeaveApprovalService $approvalService): JsonResponse
    {
        $leave = Leave::query()->with(['employee', 'leaveSetting'])->find($leaveId);

        if (! $leave) {
            return $this->notFoundResponse('Leave request not found.');
        }

        $this->normalizePendingStatus($leave);

        // Service owns the rejection notification + realtime signal (fired once).
        $result = $approvalService->reject($leave, $request->user(), $request->input('reason'));

        if (! ($result['success'] ?? false)) {
            return $this->forbiddenResponse($result['message'] ?? 'Unable to reject leave request.');
        }

        return $this->successResponse([
            'leave' => $this->transformApprovalLeave($leave->fresh(['employee', 'leaveSetting'])),
            'status' => $result['status'] ?? null,
        ], $result['message'] ?? 'Leave rejected successfully.');
    }

    public function bulkApprove(BulkApproveLeavesRequest $request, LeaveApprovalService $approvalService): JsonResponse
    {
        $approver = $request->user();
        $leaveIds = collect($request->input('leave_ids', []))
            ->map(fn ($leaveId) => (int) $leaveId)
            ->unique()
            ->values();

        $leaves = Leave::query()
            ->with(['employee', 'leaveSetting'])
            ->whereIn('id', $leaveIds->all())
            ->get()
            ->keyBy('id');

        $approvedLeaveIds = [];
        $failed = [];

        foreach ($leaveIds as $leaveId) {
            /** @var Leave|null $leave */
            $leave = $leaves->get($leaveId);

            if (! $leave) {
                $failed[] = [
                    'leave_id' => $leaveId,
                    'message' => 'Leave request not found.',
                ];

                continue;
            }

            $this->normalizePendingStatus($leave);

            $result = $approvalService->approve($leave, $approver, $request->input('comments'));

            if ($result['success'] ?? false) {
                $approvedLeaveIds[] = $leaveId;

                continue;
            }

            $failed[] = [
                'leave_id' => $leaveId,
                'message' => $result['message'] ?? 'Failed to approve leave request.',
            ];
        }

        if ($approvedLeaveIds === []) {
            return response()->json([
                'success' => false,
                'message' => 'No leave requests were approved.',
                'error_code' => 'BULK_APPROVE_FAILED',
                'data' => [
                    'approved_count' => 0,
                    'failed_count' => count($failed),
                    'total_requested' => $leaveIds->count(),
                    'approved_leave_ids' => [],
                    'failed' => $failed,
                ],
            ], 422);
        }

        // Each per-leave approve already emitted its own realtime signal from the
        // service (once, post-commit) — no extra controller-level touch here.

        return $this->successResponse([
            'approved_count' => count($approvedLeaveIds),
            'failed_count' => count($failed),
            'total_requested' => $leaveIds->count(),
            'approved_leave_ids' => $approvedLeaveIds,
            'failed' => $failed,
        ], count($approvedLeaveIds).' leave request(s) approved successfully.');
    }

    public function bulkReject(BulkRejectLeavesRequest $request, LeaveApprovalService $approvalService): JsonResponse
    {
        $approver = $request->user();
        $leaveIds = collect($request->input('leave_ids', []))
            ->map(fn ($leaveId) => (int) $leaveId)
            ->unique()
            ->values();

        $leaves = Leave::query()
            ->with(['employee', 'leaveSetting'])
            ->whereIn('id', $leaveIds->all())
            ->get()
            ->keyBy('id');

        $rejectedLeaveIds = [];
        $failed = [];

        foreach ($leaveIds as $leaveId) {
            /** @var Leave|null $leave */
            $leave = $leaves->get($leaveId);

            if (! $leave) {
                $failed[] = [
                    'leave_id' => $leaveId,
                    'message' => 'Leave request not found.',
                ];

                continue;
            }

            $this->normalizePendingStatus($leave);

            $result = $approvalService->reject($leave, $approver, $request->input('reason'));

            if ($result['success'] ?? false) {
                $rejectedLeaveIds[] = $leaveId;

                continue;
            }

            $failed[] = [
                'leave_id' => $leaveId,
                'message' => $result['message'] ?? 'Failed to reject leave request.',
            ];
        }

        if ($rejectedLeaveIds === []) {
            return response()->json([
                'success' => false,
                'message' => 'No leave requests were rejected.',
                'error_code' => 'BULK_REJECT_FAILED',
                'data' => [
                    'rejected_count' => 0,
                    'failed_count' => count($failed),
                    'total_requested' => $leaveIds->count(),
                    'rejected_leave_ids' => [],
                    'failed' => $failed,
                ],
            ], 422);
        }

        return $this->successResponse([
            'rejected_count' => count($rejectedLeaveIds),
            'failed_count' => count($failed),
            'total_requested' => $leaveIds->count(),
            'rejected_leave_ids' => $rejectedLeaveIds,
            'failed' => $failed,
        ], count($rejectedLeaveIds).' leave request(s) rejected successfully.');
    }

    private function resolveLeavesUserColumn(): ?string
    {
        return $this->leaveApiService->resolveLeavesUserColumn();
    }

    private function normalizePendingStatus(Leave $leave): void
    {
        if (strtolower((string) $leave->status) === 'pending') {
            $leave->status = 'pending';
        }
    }

    private function transformApprovalLeave(Leave $leave): array
    {
        return [
            'id' => $leave->id,
            'user_id' => $leave->user_id,
            'employee' => [
                'id' => $leave->employee?->id,
                'name' => $leave->employee?->name,
                'employee_id' => $leave->employee?->employee_id,
                // Media-backed URL — the legacy profile_image column does not
                // exist in production.
                'profile_image' => $leave->employee?->profile_image_url,
                'profile_image_url' => $leave->employee?->profile_image_url,
            ],
            'leave_type' => $leave->leave_type,
            'leave_type_name' => $leave->leaveSetting?->type,
            'leave_type_symbol' => $leave->leaveSetting?->symbol,
            'from_date' => $leave->from_date,
            'to_date' => $leave->to_date,
            'no_of_days' => $leave->no_of_days,
            'reason' => $leave->reason,
            'status' => $leave->status,
            'current_approval_level' => $leave->current_approval_level,
            'approval_chain' => $leave->approval_chain,
            'submitted_at' => $leave->submitted_at,
            'approved_at' => $leave->approved_at,
            'rejection_reason' => $leave->rejection_reason,
            'created_at' => $leave->created_at,
            'updated_at' => $leave->updated_at,
        ];
    }

    /**
     * True when the given user appears at ANY level of the leave's approval
     * chain — the decided-history counterpart to canApprove()'s current-level
     * check. Includes levels marked 'superseded' by an admin override, so the
     * manager still sees an item taken out of their hands.
     */
    private function isApprovalChainMember(Leave $leave, int $userId): bool
    {
        foreach (($leave->approval_chain ?? []) as $level) {
            if ((int) ($level['approver_id'] ?? 0) === $userId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decided metadata layered on top of the pending item shape: who finalized
     * it, when, and whether it was an administrator override.
     *
     * @return array{decided_by: ?string, decided_at: mixed, was_admin_override: bool}
     */
    private function decidedApprovalMeta(Leave $leave): array
    {
        $chain = collect($leave->approval_chain ?? []);

        $override = $chain->first(
            fn ($level) => strtolower((string) ($level['status'] ?? '')) === 'admin_override'
        );

        if ($override !== null) {
            return [
                'decided_by' => $override['approver_name'] ?? null,
                'decided_at' => $override['approved_at'] ?? $leave->approved_at,
                'was_admin_override' => true,
            ];
        }

        // Normal decision: the level whose status matches the final outcome,
        // taking the most recent one as the decisive action.
        $status = strtolower((string) $leave->status);
        $decisive = $chain
            ->filter(fn ($level) => strtolower((string) ($level['status'] ?? '')) === $status)
            ->sortByDesc(fn ($level) => (string) ($level['approved_at'] ?? ''))
            ->first();

        return [
            'decided_by' => $decisive['approver_name'] ?? null,
            'decided_at' => $decisive['approved_at'] ?? $leave->approved_at ?? $leave->updated_at,
            'was_admin_override' => false,
        ];
    }

    private function buildApprovalStats(int $approverId, Collection $pendingLeaves): array
    {
        $reviewedLeaves = Leave::query()
            ->whereNotNull('approval_chain')
            ->whereRaw('LOWER(status) IN (?, ?)', ['approved', 'rejected'])
            ->get();

        $approved = 0;
        $rejected = 0;

        foreach ($reviewedLeaves as $leave) {
            foreach (($leave->approval_chain ?? []) as $level) {
                if ((int) ($level['approver_id'] ?? 0) !== $approverId) {
                    continue;
                }

                $levelStatus = strtolower((string) ($level['status'] ?? ''));

                if ($levelStatus === 'approved') {
                    $approved++;
                    break;
                }

                if ($levelStatus === 'rejected') {
                    $rejected++;
                    break;
                }
            }
        }

        $pending = $pendingLeaves->count();

        return [
            'pending' => $pending,
            'approved' => $approved,
            'rejected' => $rejected,
            'total' => $pending + $approved + $rejected,
        ];
    }

    private function mapLeaveRuntimeException(\RuntimeException $e): JsonResponse
    {
        $code = (int) $e->getCode();

        return match ($code) {
            403 => $this->forbiddenResponse($e->getMessage()),
            404 => $this->notFoundResponse($e->getMessage()),
            422 => $this->errorResponse($e->getMessage(), 'LEAVE_VALIDATION_FAILED', 422),
            default => $this->errorResponse($e->getMessage(), 'LEAVE_OPERATION_FAILED', $code >= 400 && $code < 600 ? $code : 500),
        };
    }
}
