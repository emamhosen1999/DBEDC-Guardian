<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\OvertimeRequest;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeService $service,
        private readonly AttendanceApprovalService $approvals,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date',
            'requested_minutes' => 'required|integer|min:1|max:1440',
            'reason' => 'required|string|max:500',
        ]);

        $ot = $this->service->request($request->user()->id, $data);

        return response()->json(['message' => 'Overtime request submitted.', 'request' => $ot], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'requests' => OvertimeRequest::where('user_id', $request->user()->id)
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $pending = $this->approvals->pendingFor($request->user(), OvertimeRequest::class)
            ->load('user:id,name');

        return response()->json(['requests' => $pending->values()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $ot = OvertimeRequest::findOrFail($id);
        $res = $this->service->approve($ot, $request->user(), $request->input('comments'), $request->boolean('grant_comp_off'));

        return response()->json($res, $res['success'] ? 200 : 422);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $ot = OvertimeRequest::findOrFail($id);
        $res = $this->approvals->reject($ot, $request->user(), $data['reason']);

        return response()->json($res, $res['success'] ? 200 : 422);
    }
}
