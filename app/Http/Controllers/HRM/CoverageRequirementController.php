<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\CoverageRequirement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CoverageRequirementController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'requirements' => CoverageRequirement::with([
                'workLocation:id,name',
                'shift:id,code,name',
                'designation:id,title',
            ])->orderBy('work_location_id')->orderBy('shift_id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $requirement = CoverageRequirement::create($data + ['is_active' => true]);

        return response()->json(['requirement' => $requirement]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $requirement = CoverageRequirement::findOrFail($id);
        $requirement->update($this->validated($request));

        return response()->json(['requirement' => $requirement]);
    }

    public function destroy(int $id): JsonResponse
    {
        CoverageRequirement::findOrFail($id)->delete();

        return response()->json(['message' => 'Deleted.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'work_location_id' => 'required|integer|exists:work_locations,id',
            'shift_id' => 'required|integer|exists:shifts,id',
            'designation_id' => 'nullable|integer|exists:designations,id',
            'required_headcount' => 'required|integer|min:0',
            'weekday' => 'nullable|integer|between:0,6',
            'date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);
    }
}
