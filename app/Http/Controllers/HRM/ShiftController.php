<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftRotationPattern;
use App\Services\Attendance\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts) {}

    public function index(): JsonResponse
    {
        return response()->json(['shifts' => Shift::orderBy('name')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:shifts,code',
            'type' => 'required|in:fixed,flexible,open',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i',
            'crosses_midnight' => 'boolean',
            'break_minutes' => 'integer|min:0',
            'grace_in_minutes' => 'integer|min:0',
            'grace_out_minutes' => 'integer|min:0',
            'full_day_minutes' => 'integer|min:0',
            'half_day_minutes' => 'integer|min:0',
            'min_present_minutes' => 'integer|min:0',
            'core_start_time' => 'nullable|date_format:H:i',
            'core_end_time' => 'nullable|date_format:H:i',
            'color' => 'nullable|string|max:20',
            'is_active' => 'boolean',
        ]);

        $shift = DB::transaction(fn () => Shift::create($data));

        return response()->json(['message' => 'Shift created.', 'shift' => $shift], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:shifts,code,' . $id,
            'type' => 'sometimes|in:fixed,flexible,open',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i',
            'crosses_midnight' => 'sometimes|boolean',
            'break_minutes' => 'sometimes|integer|min:0',
            'grace_in_minutes' => 'sometimes|integer|min:0',
            'grace_out_minutes' => 'sometimes|integer|min:0',
            'full_day_minutes' => 'sometimes|integer|min:0',
            'half_day_minutes' => 'sometimes|integer|min:0',
            'min_present_minutes' => 'sometimes|integer|min:0',
            'core_start_time' => 'sometimes|nullable|date_format:H:i',
            'core_end_time' => 'sometimes|nullable|date_format:H:i',
            'color' => 'sometimes|nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::transaction(fn () => $shift->update($data));

        return response()->json(['message' => 'Shift updated.', 'shift' => $shift->fresh()]);
    }

    public function destroy(int $id): JsonResponse
    {
        $shift = Shift::findOrFail($id);
        DB::transaction(fn () => $shift->delete());

        return response()->json(['message' => 'Shift deleted.']);
    }

    public function indexPatterns(): JsonResponse
    {
        return response()->json(['patterns' => ShiftRotationPattern::orderBy('name')->get()]);
    }

    public function storePattern(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:shift_rotation_patterns,code',
            'cycle_length_days' => 'required|integer|min:1',
            'definition' => 'required|array',
            'is_active' => 'boolean',
        ]);

        $pattern = DB::transaction(fn () => ShiftRotationPattern::create($data));

        return response()->json(['message' => 'Pattern created.', 'pattern' => $pattern], 201);
    }

    public function updatePattern(Request $request, int $id): JsonResponse
    {
        $pattern = ShiftRotationPattern::findOrFail($id);

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:shift_rotation_patterns,code,' . $id,
            'cycle_length_days' => 'sometimes|integer|min:1',
            'definition' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::transaction(fn () => $pattern->update($data));

        return response()->json(['message' => 'Pattern updated.', 'pattern' => $pattern->fresh()]);
    }

    public function destroyPattern(int $id): JsonResponse
    {
        $pattern = ShiftRotationPattern::findOrFail($id);
        DB::transaction(fn () => $pattern->delete());

        return response()->json(['message' => 'Pattern deleted.']);
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => 'required|in:user,designation,department,org',
            'scope_id' => 'nullable|integer',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'required|date',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'priority' => 'integer|min:0',
            'assigned_by' => 'nullable|integer|exists:users,id',
        ]);

        try {
            $assignment = DB::transaction(fn () => $this->shifts->createAssignment($data));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Assignment created.', 'assignment' => $assignment], 201);
    }

    /**
     * Bulk-create shift assignments for multiple scope IDs at once.
     * Accepts scope_ids[] (array) instead of scope_id (single).
     */
    public function storeBulkAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => 'required|in:user,designation,department,org',
            'scope_ids' => 'required_unless:scope_type,org|array',
            'scope_ids.*' => 'integer',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'required|date',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'priority' => 'integer|min:0',
            'assigned_by' => 'nullable|integer|exists:users,id',
        ]);

        $scopeType = $data['scope_type'];
        $scopeIds = $scopeType === 'org' ? [null] : ($data['scope_ids'] ?? []);

        if (empty($scopeIds)) {
            return response()->json(['message' => 'No scope items selected.'], 422);
        }

        $created = [];
        $errors = [];

        try {
            DB::transaction(function () use ($scopeIds, $data, $scopeType, &$created, &$errors) {
                foreach ($scopeIds as $scopeId) {
                    $row = $data;
                    $row['scope_id'] = $scopeId;
                    unset($row['scope_ids']);

                    try {
                        $created[] = $this->shifts->createAssignment($row);
                    } catch (InvalidArgumentException $e) {
                        $errors[] = ['scope_id' => $scopeId, 'message' => $e->getMessage()];
                    }
                }
            });
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $total = count($created);
        $failed = count($errors);

        return response()->json([
            'message' => "{$total} assignment(s) created." . ($failed > 0 ? " {$failed} skipped." : ''),
            'created_count' => $total,
            'skipped' => $errors,
        ], $total > 0 ? 201 : 422);
    }

    public function assignmentsIndex(): JsonResponse
    {
        $assignments = \App\Models\HRM\ShiftAssignment::with(['shift:id,code,name', 'rotationPattern:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'scope_type' => $a->scope_type,
                'scope_id' => $a->scope_id,
                'shift' => $a->shift ? ['id' => $a->shift->id, 'code' => $a->shift->code, 'name' => $a->shift->name] : null,
                'rotation_pattern' => $a->rotationPattern ? ['id' => $a->rotationPattern->id, 'name' => $a->rotationPattern->name] : null,
                'anchor_date' => $a->anchor_date?->toDateString(),
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'priority' => $a->priority,
            ]);

        return response()->json(['assignments' => $assignments]);
    }

    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        $assignment = \App\Models\HRM\ShiftAssignment::findOrFail($id);

        $data = $request->validate([
            'scope_type' => 'sometimes|in:user,designation,department,org',
            'scope_id' => 'sometimes|nullable|integer',
            'shift_id' => 'sometimes|nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'sometimes|nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'sometimes|date',
            'effective_from' => 'sometimes|date',
            'effective_to' => 'sometimes|nullable|date',
            'priority' => 'sometimes|integer|min:0',
            'assigned_by' => 'sometimes|nullable|integer|exists:users,id',
        ]);

        try {
            $assignment = DB::transaction(fn () => $this->shifts->updateAssignment($assignment, $data));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Assignment updated.', 'assignment' => $assignment]);
    }

    public function destroyAssignment(int $id): JsonResponse
    {
        $assignment = \App\Models\HRM\ShiftAssignment::findOrFail($id);
        DB::transaction(fn () => $assignment->delete());

        return response()->json(['message' => 'Assignment deleted.']);
    }
}
