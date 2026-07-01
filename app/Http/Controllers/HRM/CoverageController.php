<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\WorkLocation;
use App\Services\Attendance\CoverageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageController extends Controller
{
    public function __construct(private readonly CoverageService $coverage) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from',
            'location_id' => 'nullable|integer',
        ]);

        $locationIds = isset($data['location_id']) ? [(int) $data['location_id']] : null;

        return response()->json([
            'coverage' => $this->coverage->forRange($data['from'], $data['to'], $locationIds),
        ]);
    }

    public function workLocations(): JsonResponse
    {
        return response()->json([
            'work_locations' => WorkLocation::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code']),
        ]);
    }
}
