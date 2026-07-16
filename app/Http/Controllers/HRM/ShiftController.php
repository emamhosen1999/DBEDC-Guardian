<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Designation;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Attendance\ShiftService;
use Carbon\Carbon;
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

        if (! $isGlobal) {
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

    /**
     * Columns whose value determines HOW a day is scored (present/late/half-day/etc).
     * Changing any of these must be versioned so past attendance is never
     * silently re-scored against the new definition.
     */
    private const TIME_BEHAVIOR_FIELDS = [
        'start_time', 'end_time', 'crosses_midnight', 'grace_in_minutes',
        'grace_out_minutes', 'full_day_minutes', 'half_day_minutes',
        'min_present_minutes', 'break_minutes',
    ];

    /** Sentinel "since forever" effective date, mirrors the versions-table migration backfill. */
    private const SENTINEL_EFFECTIVE_FROM = '2000-01-01';

    public function update(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $shift = Shift::findOrFail($id);

        if (! $isGlobal && $shift->created_by !== $user->id) {
            abort(403, 'Unauthorized to update shifts created by other users.');
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:shifts,code,'.$id,
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
            'effective_from' => 'sometimes|date',
        ]);

        $today = Carbon::today();
        $effectiveFrom = isset($data['effective_from'])
            ? Carbon::parse($data['effective_from'])->startOfDay()
            : $today->copy();

        if ($effectiveFrom->gt($today->copy()->addYear())) {
            return response()->json(['message' => 'effective_from cannot be more than one year in the future.'], 422);
        }

        $latestVersion = $shift->versions()->orderByDesc('effective_from')->first();

        if ($latestVersion && $effectiveFrom->lt($latestVersion->effective_from)) {
            return response()->json([
                'message' => 'effective_from cannot be earlier than the shift\'s current version date ('
                    .$latestVersion->effective_from->toDateString().').',
            ], 422);
        }

        unset($data['effective_from']);

        $timeData = array_intersect_key($data, array_flip(self::TIME_BEHAVIOR_FIELDS));
        $otherData = array_diff_key($data, $timeData);

        $normalizeTime = static fn ($value) => Carbon::parse($value)->format('H:i:s');

        $hasTimeBehaviorChange = false;
        foreach ($timeData as $field => $incoming) {
            $current = $shift->{$field};

            if (in_array($field, ['start_time', 'end_time'], true)) {
                $changed = $normalizeTime($incoming) !== $normalizeTime($current);
            } elseif ($field === 'crosses_midnight') {
                $changed = (bool) $incoming !== (bool) $current;
            } else {
                $changed = (int) $incoming !== (int) $current;
            }

            if ($changed) {
                $hasTimeBehaviorChange = true;
                break;
            }
        }

        DB::transaction(function () use ($shift, $otherData, $timeData, $hasTimeBehaviorChange, $effectiveFrom, $today) {
            if (! empty($otherData)) {
                $shift->update($otherData);
            }

            if ($hasTimeBehaviorChange) {
                // If this shift has never been versioned, seed a "since forever" baseline
                // version with the OLD (pre-edit) values first. Without this, any date
                // before the new version's effective_from would have no version to
                // resolve against and would fall back to the shift's live mirror columns
                // — which are about to be overwritten by this very edit.
                if (! $shift->versions()->exists()) {
                    $shift->versions()->create([
                        'effective_from' => self::SENTINEL_EFFECTIVE_FROM,
                        'start_time' => $shift->start_time,
                        'end_time' => $shift->end_time,
                        'crosses_midnight' => (bool) $shift->crosses_midnight,
                        'grace_in_minutes' => $shift->grace_in_minutes,
                        'grace_out_minutes' => $shift->grace_out_minutes,
                        'full_day_minutes' => $shift->full_day_minutes,
                        'half_day_minutes' => $shift->half_day_minutes,
                        'min_present_minutes' => $shift->min_present_minutes,
                        'break_minutes' => $shift->break_minutes,
                    ]);
                }

                $versionValues = [];
                foreach (self::TIME_BEHAVIOR_FIELDS as $field) {
                    $versionValues[$field] = array_key_exists($field, $timeData) ? $timeData[$field] : $shift->{$field};
                }
                $versionValues['crosses_midnight'] = (bool) $versionValues['crosses_midnight'];

                $shift->versions()->updateOrCreate(
                    ['effective_from' => $effectiveFrom->toDateString()],
                    $versionValues
                );

                if ($effectiveFrom->lte($today)) {
                    $shift->update($versionValues);
                }
            }
        });

        $shift->refresh();

        return response()->json([
            'message' => 'Shift updated.',
            'shift' => $shift->load('creator:id,name'),
            'versions_count' => $shift->versions()->count(),
            'historical_days_affected' => RosterDay::where('shift_id', $shift->id)
                ->where('date', '<', $effectiveFrom->toDateString())
                ->count(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $shift = Shift::findOrFail($id);

        if (! $isGlobal && $shift->created_by !== $user->id) {
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

        if (! $isGlobal) {
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

        if (! $isGlobal && $pattern->created_by !== $user->id) {
            abort(403, 'Unauthorized to update rotation patterns created by other users.');
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:shift_rotation_patterns,code,'.$id,
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

        if (! $isGlobal && $pattern->created_by !== $user->id) {
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
                if ((int) $id !== $userDeptId) {
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

        if (! $isGlobal && $userDeptId !== null) {
            $this->validateScopeForManager($data['scope_type'], [$data['scope_id']], $userDeptId);
        }

        $data['assigned_by'] = $user->id;

        try {
            $assignment = DB::transaction(fn () => $this->shifts->createAssignment($data));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        // Working-time compliance is informational only here (never blocks):
        // an assignment can target a whole department/designation/org at
        // once, so hard-blocking on one affected employee's edge case would
        // be too disruptive. Surfaced for HR/manager review.
        $complianceViolations = $this->shifts->complianceForAssignment($assignment);

        return response()->json([
            'message' => 'Assignment created.',
            'assignment' => $assignment,
            'compliance_violations' => $complianceViolations,
        ], 201);
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

        if (! $isGlobal && $userDeptId !== null) {
            $this->validateScopeForManager($scopeType, $scopeIds, $userDeptId);
        }

        $created = [];
        $errors = [];

        try {
            DB::transaction(function () use ($scopeIds, $data, $user, &$created, &$errors) {
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

        // Working-time compliance is informational only here (never blocks),
        // same as storeAssignment. Merge each created assignment's violations
        // (keyed by user_id) into a single payload for the caller.
        $complianceViolations = [];
        foreach ($created as $assignment) {
            $complianceViolations += $this->shifts->complianceForAssignment($assignment);
        }

        return response()->json([
            'message' => "{$total} assignment(s) created.".($failed > 0 ? " {$failed} skipped." : ''),
            'created_count' => $total,
            'skipped' => $errors,
            'compliance_violations' => $complianceViolations,
        ], $total > 0 ? 201 : 422);
    }

    public function assignmentsIndex(): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        $query = ShiftAssignment::with(['shift:id,code,name', 'rotationPattern:id,name', 'assigner:id,name'])
            ->orderByDesc('created_at');

        if (! $isGlobal && $userDeptId !== null) {
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

        if (! $isGlobal && $userDeptId !== null) {
            $isAuthorized = false;
            if ($assignment->assigned_by === $user->id) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'user') {
                $scopedUser = User::find($assignment->scope_id);
                if ($scopedUser && $scopedUser->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            } elseif ($assignment->scope_type === 'department' && (int) $assignment->scope_id === $userDeptId) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'designation') {
                $scopedDesig = Designation::find($assignment->scope_id);
                if ($scopedDesig && $scopedDesig->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            }

            if (! $isAuthorized) {
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

        // Working-time compliance is informational only here (never blocks),
        // same as storeAssignment.
        $complianceViolations = $this->shifts->complianceForAssignment($assignment);

        return response()->json([
            'message' => 'Assignment updated.',
            'assignment' => $assignment,
            'compliance_violations' => $complianceViolations,
        ]);
    }

    public function destroyAssignment(int $id): JsonResponse
    {
        $user = auth()->user();
        $isGlobal = $user->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
        $userDeptId = $user->department_id;

        $assignment = ShiftAssignment::findOrFail($id);

        if (! $isGlobal && $userDeptId !== null) {
            $isAuthorized = false;
            if ($assignment->assigned_by === $user->id) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'user') {
                $scopedUser = User::find($assignment->scope_id);
                if ($scopedUser && $scopedUser->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            } elseif ($assignment->scope_type === 'department' && (int) $assignment->scope_id === $userDeptId) {
                $isAuthorized = true;
            } elseif ($assignment->scope_type === 'designation') {
                $scopedDesig = Designation::find($assignment->scope_id);
                if ($scopedDesig && $scopedDesig->department_id === $userDeptId) {
                    $isAuthorized = true;
                }
            }

            if (! $isAuthorized) {
                abort(403, 'Unauthorized to delete this shift assignment.');
            }
        }

        DB::transaction(fn () => $assignment->delete());

        return response()->json(['message' => 'Assignment deleted.']);
    }
}
