<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\CompOffLedger;
use App\Services\Attendance\CompOffService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompOffController extends Controller
{
    public function __construct(private readonly CompOffService $service) {}

    public function mine(Request $request): JsonResponse
    {
        $uid = $request->user()->id;

        return response()->json([
            'balance_minutes' => $this->service->balance($uid),
            'entries' => CompOffLedger::where('user_id', $uid)->orderByDesc('id')->limit(50)->get(),
        ]);
    }
}
