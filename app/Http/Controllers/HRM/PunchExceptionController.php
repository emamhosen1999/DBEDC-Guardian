<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Services\Attendance\PunchExceptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PunchExceptionController extends Controller
{
    public function __construct(private readonly PunchExceptionService $service) {}

    public function pending(): JsonResponse
    {
        return response()->json($this->service->pending());
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        $res = $this->service->approve($id, $request->user());

        return response()->json($res);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $res = $this->service->reject($id, $request->user(), $data['reason']);

        return response()->json($res);
    }
}
