<?php

namespace App\Http\Controllers;

use App\Http\Requests\Attendance\StoreReplacementLeaveRequest;
use App\Models\HRM\ReplacementLeaveRequest;
use App\Services\Attachments\AttachmentAccess;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\ReplacementLeaveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Replacement-leave requests, shared by the web and the mobile API (both answer {success, data}). */
class ReplacementLeaveController extends Controller
{
    public function __construct(
        private readonly ReplacementLeaveService $service,
        private readonly AttendanceApprovalService $approvals,
        private readonly AttachmentAccess $access,
    ) {}

    private function present(ReplacementLeaveRequest $r, Request $request): array
    {
        return $r->toArray() + ['attachments' => $this->access->present($r, $request->user(), $request->is('api/*'))];
    }

    public function store(StoreReplacementLeaveRequest $request): JsonResponse
    {
        $r = $this->service->request((string) $request->user()->employee_id, $request->validated() + ['days' => $request->input('days')]);

        return response()->json(['success' => true, 'message' => 'Replacement leave requested.', 'data' => $this->present($r, $request)], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $rows = ReplacementLeaveRequest::with('media')->where('user_id', (string) $request->user()->employee_id)->orderByDesc('id')->get();

        return response()->json(['success' => true, 'data' => $rows->map(fn ($r) => $this->present($r, $request))->values()]);
    }

    public function pending(Request $request): JsonResponse
    {
        $status = in_array($request->query('status'), ['approved', 'rejected', 'all'], true) ? $request->query('status') : 'pending';
        $rows = $this->approvals->forApprover($request->user(), ReplacementLeaveRequest::class, $status)->load(['user:employee_id,name', 'media']);

        return response()->json(['success' => true, 'data' => $rows->map(fn ($r) => $this->present($r, $request))->values()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $res = $this->service->approve(ReplacementLeaveRequest::findOrFail($id), $request->user(), $request->input('comments'));

        return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = $this->service->reject(ReplacementLeaveRequest::findOrFail($id), $request->user(), $data['reason']);

        return response()->json($res, ($res['success'] ?? false) ? 200 : 422);
    }
}
