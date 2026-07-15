<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\Designation;
use App\Models\User;
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
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);

        $query = Shift::with('creator:id,name')->orderBy('start_time')->orderBy('name');

        if (!$isGlobal) {
            $query->where('created_by', $user->id);
        }

        return response()->json([
            'shifts' => $query->get(),
        ]);
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

        $data['created_by'] = auth()->id();

        $shift = DB::transaction(fn () => Shift::create($data));

        return response()->json(['message' => 'Shift created.', 'shift' => $shift->load('creator:id,name')], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $shift = Shift::findOrFail($id);

        if (!$isGlobal && $shift->created_by !== $user->id) {
            abort(403, 'Unauthorized to update shifts created by other users.');
        }

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

        return response()->json(['message' => 'Shift updated.', 'shift' => $shift->fresh()->load('creator:id,name')]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $shift = Shift::findOrFail($id);

        if (!$isGlobal && $shift->created_by !== $user->id) {
            abort(403, 'Unauthorized to delete shifts created by other users.');
        }

        DB::transaction(fn () => $shift->delete());

        return response()->json(['message' => 'Shift deleted.']);
    }

    public function indexPatterns(): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);

        $query = ShiftRotationPattern::with('creator:id,name')->orderBy('name');

        if (!$isGlobal) {
            $query->where('created_by', $user->id);
        }

        return response()->json([
            'patterns' => $query->get(),
        ]);
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

        $data['created_by'] = auth()->id();

        $pattern = DB::transaction(fn () => ShiftRotationPattern::create($data));

        return response()->json(['message' => 'Pattern created.', 'pattern' => $pattern->load('creator:id,name')], 201);
    }

    public function updatePattern(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $pattern = ShiftRotationPattern::findOrFail($id);

        if (!$isGlobal && $pattern->created_by !== $user->id) {
            abort(403, 'Unauthorized to update rotation patterns created by other users.');
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:shift_rotation_patterns,code,' . $id,
            'cycle_length_days' => 'sometimes|integer|min:1',
            'definition' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
        ]);

        DB::transaction(fn () => $pattern->update($data));

        return response()->json(['message' => 'Pattern updated.', 'pattern' => $pattern->fresh()->load('creator:id,name')]);
    }

    public function destroyPattern(int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $pattern = ShiftRotationPattern::findOrFail($id);

        if (!$isGlobal && $pattern->created_by !== $user->id) {
            abort(403, 'Unauthorized to delete rotation patterns created by other users.');
        }

        DB::transaction(fn () => $pattern->delete());

        return response()->json(['message' => 'Pattern deleted.']);
    }

    private function validateScopeForManager(string $scopeType, array $scopeIds, int $userDeptId)
    {
        if ($scopeType === 'org') {
            abort(403, 'Unauthorized to assign shifts at the organization level.');
        }

        if ($scopeType === 'department') {
            foreach ($scopeIds as $id) {
                if ((int)$id !== $userDeptId) {
                    abort(403, 'Unauthorized to assign shifts for other departments.');
                }
            }
        }

        if ($scopeType === 'designation') {
            $invalidCount = Designation::whereIn('id', $scopeIds)
                ->where('department_id', '!=', $userDeptId)
                ->count();
            if ($invalidCount > 0) {
                abort(403, 'Unauthorized to assign shifts for designations outside your department.');
            }
        }

        if ($scopeType === 'user') {
            $invalidCount = User::whereIn('id', $scopeIds)
                ->where('department_id', '!=', $userDeptId)
                ->count();
            if ($invalidCount > 0) {
                abort(403, 'Unauthorized to assign shifts to employees outside your department.');
            }
        }
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
        ]);

        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        if (!$isGlobal && $userDeptId !== null) {
            $this->validateScopeForManager($data['scope_type'], [$data['scope_id']], $userDeptId);
        }

        $data['assigned_by'] = $user->id;

        try {
            $assignment = DB::transaction(fn () => $this->shifts->createAssignment($data));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['message' => 'Assignment created.', 'assignment' => $assignment], 201);
    }

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
        ]);

        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        $scopeType = $data['scope_type'];
        $scopeIds = $scopeType === 'org' ? [null] : ($data['scope_ids'] ?? []);

        if (empty($scopeIds)) {
            return response()->json(['message' => 'No scope items selected.'], 422);
        }

        if (!$isGlobal && $userDeptId !== null) {
            $this->validateScopeForManager($scopeType, $scopeIds, $userDeptId);
        }

        $created = [];
        $errors = [];

        try {
            DB::transaction(function () use ($scopeIds, $data, $scopeType, $user, &$created, &$errors) {
                foreach ($scopeIds as $scopeId) {
                    $row = $data;
                    $row['scope_id'] = $scopeId;
                    $row['assigned_by'] = $user->id;
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
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        $query = ShiftAssignment::with(['shift:id,code,name', 'rotationPattern:id,name', 'assigner:id,name'])
            ->orderByDesc('created_at');

        if (!$isGlobal && $userDeptId !== null) {
            $query->where(function ($q) use ($userDeptId, $user) {
                $q->where(function ($sub) use ($userDeptId) {
                    $sub->where('scope_type', 'user')
                        ->whereIn('scope_id', User::where('department_id', $userDeptId)->pluck('id'));
                })->orWhere(function ($sub) use ($userDeptId) {
                    $sub->where('scope_type', 'department')
                        ->where('scope_id', $userDeptId);
                })->orWhere(function ($sub) use ($userDeptId) {
                    $sub->where('scope_type', 'designation')
                        ->whereIn('scope_id', Designation::where('department_id', $userDeptId)->pluck('id'));
                })->orWhere('assigned_by', $user->id);
            });
        }

        $assignments = $query->get()->map(fn ($a) => [
            'id' => $a->id,
            'scope_type' => $a->scope_type,
            'scope_id' => $a->scope_id,
            'shift' => $a->shift ? ['id' => $a->shift->id, 'code' => $a->shift->code, 'name' => $a->shift->name] : null,
            'rotation_pattern' => $a->rotationPattern ? ['id' => $a->rotationPattern->id, 'name' => $a->rotationPattern->name] : null,
            'anchor_date' => $a->anchor_date?->toDateString(),
            'effective_from' => $a->effective_from?->toDateString(),
            'effective_to' => $a->effective_to?->toDateString(),
            'priority' => $a->priority,
            'assigner' => $a->assigner ? ['id' => $a->assigner->id, 'name' => $a->assigner->name] : null,
        ]);

        return response()->json(['assignments' => $assignments]);
    }

    public function updateAssignment(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        $assignment = ShiftAssignment::findOrFail($id);

        if (!$isGlobal && $userDeptId !== null) {
            $isAuthorized = false;
            if ($assignment->assigned_by === $user->id) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'user') {
                $scopedUser = User::find($assignment->scope_id);
                if ($scopedUser && $scopedUser->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            } elseif ($assignment->scope_type === 'department' && (int)$assignment->scope_id === $userDeptId) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'designation') {
                $scopedDesig = Designation::find($assignment->scope_id);
                if ($scopedDesig && $scopedDesig->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
                abort(403, 'Unauthorized to update this shift assignment.');
            }

            if ($request->has('scope_type') && $request->has('scope_id')) {
                $this->validateScopeForManager($request->input('scope_type'), [$request->input('scope_id')], $userDeptId);
            }
        }

        $data = $request->validate([
            'scope_type' => 'sometimes|in:user,designation,department,org',
            'scope_id' => 'sometimes|nullable|integer',
            'shift_id' => 'sometimes|nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'sometimes|nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'sometimes|date',
            'effective_from' => 'sometimes|date',
            'effective_to' => 'sometimes|nullable|date',
            'priority' => 'sometimes|integer|min:0',
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
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        $assignment = ShiftAssignment::findOrFail($id);

        if (!$isGlobal && $userDeptId !== null) {
            $isAuthorized = false;
            if ($assignment->assigned_by === $user->id) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'user') {
                $scopedUser = User::find($assignment->scope_id);
                if ($scopedUser && $scopedUser->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            } elseif ($assignment->scope_type === 'department' && (int)$assignment->scope_id === $userDeptId) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'designation') {
                $scopedDesig = Designation::find($assignment->scope_id);
                if ($scopedDesig && $scopedDesig->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            }

            if (!$isAuthorized) {
                abort(403, 'Unauthorized to delete this shift assignment.');
            }
        }

        DB::transaction(fn () => $assignment->delete());

        return response()->json(['message' => 'Assignment deleted.']);
    }
}
