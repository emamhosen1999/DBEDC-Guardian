<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Services\Attendance\CompOffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompOffController extends Controller
{
    public function __construct(private readonly CompOffService $service) {}

    public function mine(Request $request): JsonResponse
    {
        return response()->json($this->service->summary((string) $request->user()->employee_id));
    }
}
