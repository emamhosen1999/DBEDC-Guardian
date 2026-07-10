<?php

namespace App\Http\Controllers;

use App\Exports\LeaveSummaryExport;
use App\Http\Resources\LeaveResource;
use App\Http\Resources\LeaveResourceCollection;
use App\Models\HRM\Department;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Leave\LeaveApprovalService;
use App\Services\Leave\LeaveCrudService;
use App\Services\Leave\LeaveOverlapService;
use App\Services\Leave\LeaveQueryService;
use App\Services\Leave\LeaveSummaryService;
use App\Services\Leave\LeaveValidationService;
use App\Traits\HandlesApiExceptions;
use Barryvdh\DomPDF\Facade\Pdf as PDF;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

class LeaveController extends Controller
{
    use HandlesApiExceptions;

    protected LeaveValidationService $validationService;

    protected LeaveOverlapService $overlapService;

    protected LeaveCrudService $crudService;

    protected LeaveQueryService $queryService;

    protected LeaveSummaryService $summaryService;

    protected LeaveApprovalService $approvalService;

    public function __construct(
        LeaveValidationService $validationService,
        LeaveOverlapService $overlapService,
        LeaveCrudService $crudService,
        LeaveQueryService $queryService,
        LeaveSummaryService $summaryService,
        LeaveApprovalService $approvalService
    ) {
        $this->validationService = $validationService;
        $this->overlapService = $overlapService;
        $this->crudService = $crudService;
        $this->queryService = $queryService;
        $this->summaryService = $summaryService;
        $this->approvalService = $approvalService;
    }

    public function index1(): Response
    {
        return Inertia::render('LeavesEmployee', [
            'title' => 'Leaves',
            'allUsers' => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with('roles:id,name')->get(),

        ]);
    }

    public function index2(): Response
    {
        return Inertia::render('LeavesAdmin', [
            'title' => 'Leaves',
            'allUsers' => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->with('roles:id,name')->get(),
        ]);
    }

    public function paginate(Request $request): JsonResponse
    {
        try {
            $leaveData = $this->queryService->getLeaveRecords($request);

            // Debug log the structure returned by LeaveQueryService
            Log::info('LeaveController - leaveData structure:', [
                'leavesData_keys' => array_keys($leaveData['leavesData'] ?? []),
                'publicHolidays_count' => count($leaveData['leavesData']['publicHolidays'] ?? []),
                'publicHolidays_sample' => array_slice($leaveData['leavesData']['publicHolidays'] ?? [], 0, 3),
            ]);

            $response = [
                'leaves' => new LeaveResourceCollection($leaveData['leaveRecords']),
                'leavesData' => $leaveData['leavesData'],
                'departments' => Department::all('id', 'name'),
                'success' => true,
            ];

            // Add message if provided by the service
            if (isset($leaveData['message'])) {
                $response['message'] = $leaveData['message'];
            }

            return response()->json($response, 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while retrieving leave data.',
                'details' => $this->safeExceptionMessage($e, 'Internal server error'),
            ], 500);
        }
    }

    public function stats(Request $request): JsonResponse
    {
        try {
            $stats = $this->queryService->getLeaveStatistics($request);

            return response()->json([
                'stats' => $stats,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'An error occurred while retrieving leave data.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function create(Request $request): JsonResponse
    {
        $validator = $this->validationService->validateLeaveRequest($request);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
                'message' => 'Validation failed',
            ], 422);
        }

        try {
            $fromDate = Carbon::parse($request->input('fromDate'));
            $toDate = Carbon::parse($request->input('toDate'));

            // Build safe data array — never trust $request->all()
            $requestedUserId = $request->input('user_id');
            if ($requestedUserId && $requestedUserId != Auth::id() && ! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
                $data['user_id'] = Auth::id();
            } else {
                $data['user_id'] = $requestedUserId ?? Auth::id();
            }
            $data['leaveType'] = $request->input('leaveType');
            $data['fromDate'] = $request->input('fromDate');
            $data['toDate'] = $request->input('toDate');
            $data['daysCount'] = $request->input('daysCount');
            $data['leaveReason'] = $request->input('leaveReason');
            $data['month'] = $request->input('month');
            $data['isHalfDay'] = $request->boolean('isHalfDay');
            $data['halfDaySession'] = $request->input('halfDaySession');

            $this->validateAttachments($request);
            $data['hasAttachment'] = $request->hasFile('attachments');

            $userId = $data['user_id'];

            // Check for overlapping leaves
            $overlapError = $this->overlapService->getOverlapErrorMessage($userId, $fromDate, $toDate);
            if ($overlapError) {
                return response()->json([
                    'success' => false,
                    'error' => $overlapError,
                    'message' => 'Leave dates overlap with existing leave or holiday',
                ], 422);
            }

            // Create new leave (use sanitized data)
            $newLeave = $this->crudService->createLeave($data);

            $this->storeAttachments($request, $newLeave);

            // Get updated leave records using the same service as paginate method
            $leaveData = $this->queryService->getLeaveRecords($request);

            // Realtime: a new application lights up the approver queue live.
            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $userId, 'apply');

            // Non-blocking advisory: teammates already on leave in this range.
            $teamWarnings = $this->overlapService->teamConflictWarnings($userId, $fromDate, $toDate);

            return response()->json([
                'success' => true,
                'message' => 'Leave application submitted successfully',
                'warnings' => $teamWarnings,
                'leave' => array_merge(
                    (new LeaveResource($newLeave->load('employee')))->toArray($request),
                    [
                        'month' => is_string($newLeave->from_date)
                            ? date('F', strtotime($newLeave->from_date))
                            : $newLeave->from_date->format('F'),
                        'year' => is_string($newLeave->from_date)
                            ? date('Y', strtotime($newLeave->from_date))
                            : $newLeave->from_date->year,
                        'leave_type' => LeaveSetting::find($newLeave->leave_type)?->type,
                    ]
                ),
                'leaves' => new LeaveResourceCollection($leaveData['leaveRecords']),
                'leavesData' => $leaveData['leavesData'],
                'departments' => Department::all('id', 'name'),
            ], 201);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while submitting the leave data.',
                'details' => $this->safeExceptionMessage($e, 'Internal server error'),
            ], 500);
        }
    }

    public function update(Request $request): JsonResponse
    {
        try {
            $leaveId = $request->input('id');
            $existing = Leave::findOrFail($leaveId);

            // Only owner or a user with approve/manage permission may update arbitrary leaves
            if ($existing->user_id !== Auth::id() && ! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
                return response()->json(['error' => 'Unauthorized to update this leave'], 403);
            }

            $safeData = [
                'user_id' => $request->input('user_id', $existing->user_id),
                'leaveType' => $request->input('leaveType'),
                'fromDate' => $request->input('fromDate'),
                'toDate' => $request->input('toDate'),
                'daysCount' => $request->input('daysCount'),
                'leaveReason' => $request->input('leaveReason'),
                'month' => $request->input('month'),
                'isHalfDay' => $request->boolean('isHalfDay'),
                'halfDaySession' => $request->input('halfDaySession'),
            ];

            $this->validateAttachments($request);
            $safeData['hasAttachment'] = $request->hasFile('attachments');

            $fromDate = Carbon::parse($safeData['fromDate'] ?? $existing->from_date);
            $toDate = Carbon::parse($safeData['toDate'] ?? $existing->to_date);
            $overlapError = $this->overlapService->getOverlapErrorMessage(
                $safeData['user_id'], $fromDate, $toDate, $leaveId
            );

            if ($overlapError) {
                return response()->json([
                    'success' => false,
                    'error' => $overlapError,
                    'message' => 'Leave dates overlap with existing leave or holiday',
                ], 422);
            }

            $updatedLeave = $this->crudService->updateLeave($leaveId, $safeData);

            $this->storeAttachments($request, $updatedLeave);

            // Get updated leave records using the same service as paginate method
            $leaveData = $this->queryService->getLeaveRecords($request);

            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $safeData['user_id'], 'update');

            return response()->json([
                'success' => true,
                'message' => 'Leave application updated successfully',
                'leave' => array_merge(
                    (new LeaveResource($updatedLeave->load('employee')))->toArray($request),
                    [
                        'month' => is_string($updatedLeave->from_date)
                            ? date('F', strtotime($updatedLeave->from_date))
                            : $updatedLeave->from_date->format('F'),
                        'year' => is_string($updatedLeave->from_date)
                            ? date('Y', strtotime($updatedLeave->from_date))
                            : $updatedLeave->from_date->year,
                        'leave_type' => LeaveSetting::find($updatedLeave->leave_type)?->type,
                    ]
                ),
                'leaves' => new LeaveResourceCollection($leaveData['leaveRecords']),
                'leavesData' => $leaveData['leavesData'],
                'departments' => Department::all('id', 'name'),
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'An error occurred while updating the leave.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function updateStatus(Request $request)
    {
        // Only approvers may change status
        if (! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
            return response()->json(['error' => 'Unauthorized to change leave status'], 403);
        }

        $result = $this->crudService->updateLeaveStatus(
            $request->input('id'),
            $request->input('status'),
            Auth::id()
        );

        return response()->json(['message' => $result['message']]);
    }

    /**
     * Validate uploaded supporting documents (attachments[]).
     */
    private function validateAttachments(Request $request): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        $request->validate([
            'attachments' => 'array|max:3',
            'attachments.*' => 'file|mimes:pdf,jpg,jpeg,png|max:5120',
        ], [
            'attachments.max' => 'A maximum of 3 attachments are allowed.',
            'attachments.*.mimes' => 'Attachments must be PDF, JPG, or PNG files.',
            'attachments.*.max' => 'Each attachment must not exceed 5 MB.',
        ]);
    }

    /**
     * Persist uploaded supporting documents onto the leave record.
     */
    private function storeAttachments(Request $request, \App\Models\HRM\Leave $leave): void
    {
        if (! $request->hasFile('attachments')) {
            return;
        }

        foreach ($request->file('attachments') as $file) {
            $leave->addMedia($file)->toMediaCollection('attachments');
        }
    }

    /**
     * Stream a leave attachment. Owner, approvers, and leave managers only.
     */
    public function downloadAttachment($id, $mediaId)
    {
        $leave = Leave::findOrFail($id);

        if ($leave->user_id !== Auth::id()
            && ! Auth::user()->can('leaves.view')
            && ! Auth::user()->can('leaves.approve')
            && ! Auth::user()->can('leaves.manage')) {
            abort(403, 'Unauthorized to view this attachment.');
        }

        $media = $leave->getMedia('attachments')->firstWhere('id', (int) $mediaId);
        abort_unless($media, 404);

        return $media;
    }

    /**
     * Remove a leave attachment. Owner (while pending) or leave managers.
     */
    public function deleteAttachment($id, $mediaId): JsonResponse
    {
        $leave = Leave::findOrFail($id);

        $isOwner = $leave->user_id === Auth::id();
        $isManager = Auth::user()->can('leaves.approve') || Auth::user()->can('leaves.manage');

        if (! ($isManager || ($isOwner && strtolower((string) $leave->status) === 'pending'))) {
            return response()->json(['success' => false, 'message' => 'Unauthorized to remove this attachment.'], 403);
        }

        $media = $leave->getMedia('attachments')->firstWhere('id', (int) $mediaId);
        if (! $media) {
            return response()->json(['success' => false, 'message' => 'Attachment not found.'], 404);
        }

        $media->delete();

        return response()->json(['success' => true, 'message' => 'Attachment removed.']);
    }

    /**
     * Cancel (withdraw) a leave request. Owner may cancel pending leave any
     * time and approved leave before it starts; approvers/managers may cancel
     * any active leave. Approved cancellations reverse the ledger consumption.
     */
    public function cancelLeave(Request $request, $id): JsonResponse
    {
        try {
            $leave = $this->crudService->cancelLeave((int) $id, Auth::user(), $request->input('reason'));

            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $leave->user_id, 'cancel');

            return response()->json([
                'success' => true,
                'message' => 'Leave request cancelled successfully.',
                'leave' => new LeaveResource($leave->load('employee')),
            ]);
        } catch (\RuntimeException $e) {
            $code = in_array($e->getCode(), [403, 422], true) ? $e->getCode() : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $code);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while cancelling the leave.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function delete(Request $request): JsonResponse
    {
        try {
            $this->validationService->validateDeleteRequest($request);
            $leaveId = $request->input('id');
            $existing = Leave::findOrFail($leaveId);

            if ($existing->user_id !== Auth::id() && ! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
                return response()->json(['error' => 'Unauthorized to delete this leave'], 403);
            }

            $this->crudService->deleteLeave($leaveId);

            $leaveData = $this->queryService->getLeaveRecords($request);

            return response()->json([
                'success' => true,
                'message' => 'Leave application deleted successfully',
                'leaves' => new LeaveResourceCollection($leaveData['leaveRecords']),
                'leavesData' => $leaveData['leavesData'],
                'departments' => Department::all('id', 'name'),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'An error occurred while deleting the leave.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function leaveSummary(Request $request)
    {
        $filters = [
            'year' => $request->input('year', now()->year),
            'department_id' => $request->input('department_id'),
            'employee_id' => $request->input('employee_id'),
            'status' => $request->input('status'),
            'leave_type' => $request->input('leave_type'),
        ];

        $summaryData = $this->summaryService->generateLeaveSummary($filters);

        return Inertia::render('LeaveSummary', [
            'title' => 'Leave Summary',
            'summaryData' => $summaryData,
        ]);
    }

    public function getSummaryData(?Request $request = null): array
    {
        $filters = [
            'year' => $request?->integer('summary_year', now()->year) ?? now()->year,
            'department_id' => $request?->input('summary_dept') ?: null,
            'employee_id' => null,
            'status' => null,
            'leave_type' => null,
        ];

        return $this->summaryService->generateLeaveSummary($filters);
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'leave_ids' => 'required|array',
                'leave_ids.*' => 'integer|exists:leaves,id',
            ]);

            if (! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
                return response()->json(['error' => 'Unauthorized to bulk approve leaves'], 403);
            }

            $leaveIds = $request->input('leave_ids');
            $updatedCount = 0;

            foreach ($leaveIds as $leaveId) {
                $result = $this->crudService->updateLeaveStatus($leaveId, 'approved', Auth::id());
                if ($result['updated']) {
                    $updatedCount++;
                }
            }

            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', Auth::id(), 'approve');

            return response()->json([
                'message' => "{$updatedCount} leave(s) approved successfully",
                'updated_count' => $updatedCount,
                'total_requested' => count($leaveIds),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'An error occurred while approving leaves.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function bulkReject(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'leave_ids' => 'required|array',
                'leave_ids.*' => 'integer|exists:leaves,id',
            ]);

            if (! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
                return response()->json(['error' => 'Unauthorized to bulk reject leaves'], 403);
            }

            $leaveIds = $request->input('leave_ids');
            $updatedCount = 0;

            foreach ($leaveIds as $leaveId) {
                $result = $this->crudService->updateLeaveStatus($leaveId, 'rejected', Auth::id());
                if ($result['updated']) {
                    $updatedCount++;
                }
            }

            app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', Auth::id(), 'reject');

            return response()->json([
                'message' => "{$updatedCount} leave(s) rejected successfully",
                'updated_count' => $updatedCount,
                'total_requested' => count($leaveIds),
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'An error occurred while rejecting leaves.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Bulk update leave status to any valid target status.
     * Supports: approved, declined, pending, new
     */
    public function bulkStatusUpdate(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'leave_ids' => 'required|array|min:1',
                'leave_ids.*' => 'integer|exists:leaves,id',
                'status' => 'required|string|in:approved,rejected,pending,cancelled',
            ]);

            if (! Auth::user()->can('leaves.approve') && ! Auth::user()->can('leaves.manage')) {
                return response()->json(['error' => 'Unauthorized to bulk update leave status'], 403);
            }

            $leaveIds = $request->input('leave_ids');
            $targetStatus = $request->input('status');
            $updatedCount = 0;
            $skippedCount = 0;

            foreach ($leaveIds as $leaveId) {
                $result = $this->crudService->updateLeaveStatus($leaveId, $targetStatus, Auth::id());
                if ($result['updated']) {
                    $updatedCount++;
                } else {
                    $skippedCount++;
                }
            }

            $statusLabel = ucfirst($targetStatus);

            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} leave(s) {$statusLabel} successfully" .
                    ($skippedCount > 0 ? " ({$skippedCount} skipped — already {$statusLabel})" : ''),
                'updated_count' => $updatedCount,
                'skipped_count' => $skippedCount,
                'total_requested' => count($leaveIds),
                'target_status' => $targetStatus,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'error' => 'An error occurred while updating leave statuses.',
                'details' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function exportExcel(Request $request)
    {
        try {
            $filters = [
                'year' => $request->input('year', now()->year),
                'department_id' => $request->input('department_id'),
                'employee_id' => $request->input('employee_id'),
                'status' => $request->input('status'),
                'leave_type' => $request->input('leave_type'),
            ];

            return Excel::download(
                new LeaveSummaryExport($filters),
                'Leave_Summary_'.($filters['year'] ?? now()->year).'.xlsx'
            );
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Excel export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    public function exportPdf(Request $request)
    {
        try {
            $filters = [
                'year' => $request->input('year', now()->year),
                'department_id' => $request->input('department_id'),
                'employee_id' => $request->input('employee_id'),
                'status' => $request->input('status'),
                'leave_type' => $request->input('leave_type'),
            ];

            $summaryData = $this->summaryService->generateLeaveSummary($filters);

            $pdf = PDF::loadView('leave_summary_pdf', [
                'title' => 'Leave Summary - '.($filters['year'] ?? now()->year),
                'generatedOn' => now()->format('F d, Y h:i A'),
                'summaryData' => $summaryData,
                'filters' => $filters,
            ])->setPaper('a4', 'landscape');

            return $pdf->download('Leave_Summary_'.($filters['year'] ?? now()->year).'.pdf');
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'PDF export failed.',
                'details' => $this->safeExceptionMessage($e, 'Export failed.'),
            ], 500);
        }
    }

    /**
     * Approve leave request at current approval level
     */
    public function approveLeave(Request $request, $id)
    {
        try {
            $leave = Leave::findOrFail($id);
            $approver = Auth::user();
            $comments = $request->input('comments');

            $result = $this->approvalService->approve($leave, $approver, $comments);

            if ($result['success']) {
                app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $approver?->id, 'approve');

                return response()->json($result, 200);
            }

            return response()->json($result, 403);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while approving the leave.',
            ], 500);
        }
    }

    /**
     * Reject leave request
     */
    public function rejectLeave(Request $request, $id)
    {
        try {
            $request->validate([
                'reason' => 'required|string|min:10',
            ]);

            $leave = Leave::findOrFail($id);
            $approver = Auth::user();
            $reason = $request->input('reason');

            $result = $this->approvalService->reject($leave, $approver, $reason);

            if ($result['success']) {
                app(\App\Services\Realtime\RealtimeSignal::class)->touch('leave', 'all', $approver?->id, 'reject');

                return response()->json($result, 200);
            }

            return response()->json($result, 403);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while rejecting the leave.',
            ], 500);
        }
    }

    /**
     * Get pending approvals for current user
     */
    public function pendingApprovals()
    {
        try {
            $user = Auth::user();
            $pendingLeaves = $this->approvalService->getPendingApprovalsForUser($user);
            $stats = $this->approvalService->getApprovalStats($user);

            return response()->json([
                'success' => true,
                'pending_leaves' => $pendingLeaves,
                'stats' => $stats,
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching pending approvals.',
            ], 500);
        }
    }

    /**
     * Get leave analytics data
     */
    public function getAnalytics(Request $request)
    {
        try {
            $year = $request->input('year', now()->year);
            $departmentId = $request->input('department_id');

            $analytics = [
                'monthly_trends' => $this->getMonthlyTrends($year, $departmentId),
                'department_comparison' => $this->getDepartmentComparison($year),
                'leave_type_distribution' => $this->getLeaveTypeDistribution($year, $departmentId),
                'absenteeism_rate' => $this->getAbsenteeismRate($year, $departmentId),
                'peak_periods' => $this->getPeakPeriods($year, $departmentId),
                'top_leave_takers' => $this->getTopLeaveTakers($year, $departmentId),
            ];

            return response()->json([
                'success' => true,
                'analytics' => $analytics,
            ], 200);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'An error occurred while fetching analytics.',
            ], 500);
        }
    }

    /**
     * Get monthly leave trends
     */
    protected function getMonthlyTrends($year, $departmentId = null)
    {
        $query = Leave::whereYear('from_date', $year);

        if ($departmentId) {
            $query->whereHas('user', function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        }

        return collect(range(1, 12))->map(function ($month) use ($query) {
            $monthQuery = clone $query;

            return [
                'month' => Carbon::create(null, $month)->format('M'),
                'leaves_taken' => $monthQuery->whereMonth('from_date', $month)
                    ->where('status', 'approved')
                    ->sum('no_of_days'),
                'leaves_approved' => $monthQuery->whereMonth('from_date', $month)
                    ->where('status', 'approved')
                    ->count(),
            ];
        });
    }

    /**
     * Get department comparison
     */
    protected function getDepartmentComparison($year)
    {
        return Department::withCount(['users as average_days' => function ($query) use ($year) {
            $query->join('leaves', 'users.id', '=', 'leaves.user_id')
                ->whereYear('leaves.from_date', $year)
                ->where('leaves.status', 'approved')
                ->select(DB::raw('AVG(leaves.no_of_days)'));
        }])
            ->having('average_days', '>', 0)
            ->get()
            ->map(function ($dept) {
                return [
                    'department' => $dept->name,
                    'average_days' => round($dept->average_days ?? 0, 2),
                ];
            });
    }

    /**
     * Get leave type distribution
     */
    protected function getLeaveTypeDistribution($year, $departmentId = null)
    {
        $query = Leave::whereYear('from_date', $year)
            ->where('status', 'approved');

        if ($departmentId) {
            $query->whereHas('user', function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        }

        return $query->join('leave_settings', 'leaves.leave_type', '=', 'leave_settings.id')
            ->select('leave_settings.type', DB::raw('count(*) as count'))
            ->groupBy('leave_settings.type')
            ->get()
            ->map(function ($item) {
                return [
                    'type' => $item->type,
                    'count' => $item->count,
                ];
            });
    }

    /**
     * Get absenteeism rate
     */
    protected function getAbsenteeismRate($year, $departmentId = null)
    {
        $workingDays = 260; // Approximate working days in a year

        $query = Leave::whereYear('from_date', $year)
            ->where('status', 'approved');

        if ($departmentId) {
            $query->whereHas('user', function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        }

        $totalLeaveDays = $query->sum('no_of_days');
        $employeeCount = User::when($departmentId, function ($q) use ($departmentId) {
            $q->where('department_id', $departmentId);
        })->count();

        if ($employeeCount === 0) {
            return 0;
        }

        return ($totalLeaveDays / ($employeeCount * $workingDays)) * 100;
    }

    /**
     * Get peak leave periods
     */
    protected function getPeakPeriods($year, $departmentId = null)
    {
        $query = Leave::whereYear('from_date', $year)
            ->where('status', 'approved');

        if ($departmentId) {
            $query->whereHas('user', function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        }

        return $query->selectRaw('MONTH(from_date) as month, COUNT(*) as count')
            ->groupBy('month')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                return [
                    'period' => Carbon::create(null, $item->month)->format('F'),
                    'count' => $item->count,
                    'reason' => null,
                ];
            });
    }

    /**
     * Get top leave takers
     */
    protected function getTopLeaveTakers($year, $departmentId = null)
    {
        $query = Leave::whereYear('from_date', $year)
            ->whereIn('status', ['approved', 'pending']);

        if ($departmentId) {
            $query->whereHas('user', function ($q) use ($departmentId) {
                $q->where('department_id', $departmentId);
            });
        }

        return $query->select(
            'user_id',
            DB::raw('SUM(no_of_days) as total_days'),
            DB::raw("SUM(CASE WHEN status = 'approved' THEN no_of_days ELSE 0 END) as approved_days"),
            DB::raw("SUM(CASE WHEN status = 'pending' THEN no_of_days ELSE 0 END) as pending_days")
        )
            ->groupBy('user_id')
            ->orderByDesc('total_days')
            ->limit(5)
            ->with(['user.department'])
            ->get()
            ->map(function ($item) {
                return [
                    'employee_name' => $item->user->name ?? '—',
                    'department' => $item->user->department->name ?? '—',
                    'total_days' => (int) $item->total_days,
                    'approved_days' => (int) $item->approved_days,
                    'pending_days' => (int) $item->pending_days,
                ];
            });
    }
}
