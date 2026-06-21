<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\AttendanceRegularization;
use App\Services\Attendance\AttendanceApprovalService;
use App\Services\Attendance\RegularizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegularizationController extends Controller
{
    public function __construct(
        private readonly RegularizationService $service,
        private readonly AttendanceApprovalService $approvals,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => 'required|date',
            'type' => 'required|in:missing_punchin,missing_punchout,wrong_time,missed_day,other',
            'requested_punchin' => 'nullable|date',
            'requested_punchout' => 'nullable|date',
            'reason' => 'required|string|max:500',
        ]);

        $req = $this->service->request($request->user()->id, $data);

        return response()->json(['message' => 'Regularization submitted.', 'request' => $req], 201);
    }

    public function mine(Request $request): JsonResponse
    {
        return response()->json([
            'requests' => AttendanceRegularization::where('user_id', $request->user()->id)
                ->orderByDesc('created_at')->get(),
        ]);
    }

    public function pending(Request $request): JsonResponse
    {
        $status = $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            $status = 'pending';
        }

        $requests = $this->approvals->forApprover($request->user(), AttendanceRegularization::class, $status)
            ->load('user:id,name');

        return response()->json(['requests' => $requests->values()]);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $r = AttendanceRegularization::findOrFail($id);
        $res = $this->service->approve($r, $request->user(), $request->input('comments'));

        return response()->json($res, $res['success'] ? 200 : 422);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $r = AttendanceRegularization::findOrFail($id);
        $res = $this->approvals->reject($r, $request->user(), $data['reason']);

        return response()->json($res, $res['success'] ? 200 : 422);
    }
}
