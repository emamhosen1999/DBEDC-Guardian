<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StoreOvertimeClaimRequest;
use App\Models\HRM\OvertimeRequest;
use App\Services\Attachments\AttachmentAccess;
use App\Services\Attendance\OvertimeEligibilityService;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeService $service,
        private readonly AttendanceApprovalService $approvals,
        private readonly AttachmentAccess $access,
        private readonly OvertimeEligibilityService $eligibility,
    ) {}

    /** What the employee sees for each claim: the document list, the "returned for re-upload" state, the legacy flag. */
    private function present(OvertimeRequest $ot, Request $request): array
    {
        return $ot->toArray() + ['legacy' => $ot->isLegacy(), 'attachments' => $this->access->present($ot, $request->user(), false)];
    }

    /** Can this day be claimed, and for how much? (drives the claim form) */
    public function check(Request $request): JsonResponse
    {
        $data = $request->validate(['date' => 'required|date', 'declared_end_time' => 'nullable|date_format:H:i']);
        $date = \Carbon\Carbon::parse($data['date']);
        $end = ! empty($data['declared_end_time']) ? \Carbon\Carbon::parse($date->toDateString().' '.$data['declared_end_time']) : null;

        return response()->json(['success' => true, 'data' => $this->eligibility->evaluate((string) $request->user()->employee_id, $date, $end)]);
    }

    /** "Overtime worked, not claimed": rest-day and holiday work with the credit already worked out. */
    public function unclaimed(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $this->eligibility->unclaimed((string) $request->user()->employee_id)->values()]);
    }

    public function store(StoreOvertimeClaimRequest $request): JsonResponse
    {
        $ot = $this->service->request((string) ($request->user()->employee_id ?? $request->user()->getKey()), $request->validated());

        return response()->json(['message' => 'Overtime claim submitted.', 'request' => $this->present($ot, $request)], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'requests' => OvertimeRequest::with('media')->where('user_id', $request->user()->employee_id ?? $request->user()->getKey())
                ->orderByDesc('created_at')->get()->map(fn ($ot) => $this->present($ot, $request))->values(),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $requests = $this->approvals->forApprover($request->user(), OvertimeRequest::class, $status)
            ->load(['user:employee_id,name', 'media']);

        return response()->json(['requests' => $requests->map(fn ($ot) => $this->present($ot, $request))->values()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $ot = OvertimeRequest::findOrFail($id);
        $res = $this->service->approve(
            $ot, $request->user(), $request->input('comments'), $request->boolean('grant_comp_off'),
            $request->input('compensation'), $request->filled('adjusted_credit') ? (float) $request->input('adjusted_credit') : null, $request->input('adjust_reason'),
        );

        return response()->json($res, $res['success'] ? 200 : 422);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $ot = OvertimeRequest::findOrFail($id);

        // OvertimeService owns the rejection transition, the requester
        // notification and the attendance/all realtime signal.
        $res = $this->service->reject($ot, $request->user(), $data['reason']);

        return response()->json($res, $res['success'] ? 200 : 422);
    }
}
