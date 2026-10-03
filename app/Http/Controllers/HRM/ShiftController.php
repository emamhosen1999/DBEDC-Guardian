<?php

namespace App\Http\Controllers\HRM;

use App\Http\Controllers\Controller;
use App\Models\HRM\Designation;
use App\Models\HRM\RosterDay;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use App\Services\Access\ShiftTemplateScope;
use App\Services\Attendance\ShiftService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ShiftController extends Controller
{
    public function __construct(
        private readonly ShiftService $shifts,
        private readonly DepartmentScope $scope,
        private readonly ShiftTemplateScope $templates,
    ) {}

    /**
     * Templates (shift definitions / rotation patterns) as the actor may see them: the department
     * that owns each, whether the actor may edit it, and the creator's name only for company-wide
     * actors (a department admin has no business with other people's author names).
     *
     * @param  Collection<int, Model>  $templates
     */
    private function presentTemplates($templates, User $actor)
    {
        $exposeCreator = $this->templates->exposesCreator($actor);

        return $templates->each(function ($template) use ($actor, $exposeCreator) {
            $template->setAttribute('can_manage', $this->templates->canManage($actor, $template));
            if (! $exposeCreator) {
                $template->unsetRelation('creator');
                $template->makeHidden(['created_by', 'creator']);
            }
        });
    }

    /**
     * Rotation-pattern definitions list shift ids: every one must be assignable by the actor and
     * belong to the pattern's own department or be company-wide (a department's pattern never reaches
     * into another department's shifts, a company-wide one only uses company-wide shifts).
     *
     * @param  array<int, mixed>  $definition
     */
    private function assertPatternShiftsAllowed(array $definition, ?int $departmentId, User $actor): void
    {
        $ids = collect($definition)
            ->filter(fn ($entry) => $entry !== null && $entry !== 'off' && $entry !== '')
            ->map(fn ($entry) => (int) $entry)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $shifts = Shift::whereIn('id', $ids)->get(['id', 'department_id', 'created_by']);
        if ($shifts->count() !== $ids->count()) {
            throw ValidationException::withMessages(['definition' => ['The pattern uses a shift that does not exist.']]);
        }

        foreach ($shifts as $shift) {
            $compatible = $shift->department_id === null || (int) $shift->department_id === (int) $departmentId;
            if (! $compatible || ! $this->templates->canAssign($actor, $shift)) {
                throw ValidationException::withMessages(['definition' => ['The pattern uses a shift that belongs to another department.']]);
            }
        }
    }

    /**
     * A non-company-wide actor assigns only the templates they can see: company-wide ones and those
     * of the departments they administer.
     */
    private function assertMayAssignTemplates(User $actor, mixed $shiftId, mixed $patternId): void
    {
        if ($this->scope->isAttendanceAdmin($actor)) {
            return;
        }

        if ($shiftId !== null && $shiftId !== '' && ! $this->templates->canAssign($actor, Shift::findOrFail($shiftId))) {
            abort(403, 'Unauthorized to assign a shift that belongs to another department.');
        }

        if ($patternId !== null && $patternId !== '' && ! $this->templates->canAssign($actor, ShiftRotationPattern::findOrFail($patternId))) {
            abort(403, 'Unauthorized to assign a rotation pattern that belongs to another department.');
        }
    }

    public function index(): JsonResponse
    {
        $user = auth()->user();

        $query = Shift::with('department:id,name')->orderBy('start_time')->orderBy('name');
        if ($this->templates->exposesCreator($user)) {
            $query->with('creator:employee_id,name');
        }

        // Company-wide shifts plus those of the departments the actor administers (roster managers
        // must see the catalogue to assign from it); attendance administrators see everything.
        $this->templates->applyTo($query, $user, 'shifts');

        return response()->json([
            'shifts' => $this->presentTemplates($query->get(), $user),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = auth()->user();

        // Authorize BEFORE validating: who may create what is decided by the owning department. A
        // company-wide template needs attendance.settings; a department's own is delegated to the
        // admin of that department.
        $requestedDepartment = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        abort_unless($this->templates->canCreate($user, $requestedDepartment), 403, 'You can only create shifts for a department you administer.');

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
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        $data['department_id'] = $requestedDepartment;
        $data['created_by'] = auth()->id();

        $shift = DB::transaction(fn () => Shift::create($data));
        $shift->load('department:id,name');
        if ($this->templates->exposesCreator($user)) {
            $shift->load('creator:employee_id,name');
        }

        return response()->json(['message' => 'Shift created.', 'shift' => $this->presentTemplates(collect([$shift]), $user)->first()], 201);
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
        $shift = Shift::findOrFail($id);

        if (! $this->templates->canManage($user, $shift)) {
            abort(403, 'Unauthorized to update this shift.');
        }

        $data = $request->validate([
            'department_id' => 'sometimes|nullable|integer|exists:departments,id',
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

        if (array_key_exists('department_id', $data) && ! $this->templates->canReassignDepartment($user, $shift, $data['department_id'] === null ? null : (int) $data['department_id'])) {
            abort(403, 'Unauthorized to move this shift to another department.');
        }

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

        $shift->load('department:id,name');
        if ($this->templates->exposesCreator($user)) {
            $shift->load('creator:employee_id,name');
        }

        return response()->json([
            'message' => 'Shift updated.',
            'shift' => $this->presentTemplates(collect([$shift]), $user)->first(),
            'versions_count' => $shift->versions()->count(),
            'historical_days_affected' => RosterDay::where('shift_id', $shift->id)
                ->where('date', '<', $effectiveFrom->toDateString())
                ->count(),
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $user = auth()->user();
        $shift = Shift::findOrFail($id);

        if (! $this->templates->canManage($user, $shift)) {
            abort(403, 'Unauthorized to delete this shift.');
        }

        // Deleting a shift in use would silently turn its roster days into OFF days and strip it from
        // every assignment (the foreign keys null out). A delegated admin deactivates it instead.
        if (! $this->scope->isGlobal($user)
            && (RosterDay::where('shift_id', $shift->id)->exists() || ShiftAssignment::where('shift_id', $shift->id)->exists())) {
            return response()->json(['message' => 'This shift is used by roster days or assignments. Deactivate it instead of deleting it.'], 422);
        }

        DB::transaction(fn () => $shift->delete());

        return response()->json(['message' => 'Shift deleted.']);
    }

    public function indexPatterns(): JsonResponse
    {
        $user = auth()->user();

        $query = ShiftRotationPattern::with('department:id,name')->orderBy('name');
        if ($this->templates->exposesCreator($user)) {
            $query->with('creator:employee_id,name');
        }

        // Same visibility rule as shift definitions: company-wide plus the actor's own departments.
        $this->templates->applyTo($query, $user, 'shift_rotation_patterns');

        return response()->json([
            'patterns' => $this->presentTemplates($query->get(), $user),
        ]);
    }

    public function storePattern(Request $request): JsonResponse
    {
        $user = auth()->user();

        $requestedDepartment = $request->filled('department_id') ? (int) $request->input('department_id') : null;
        abort_unless($this->templates->canCreate($user, $requestedDepartment), 403, 'You can only create rotation patterns for a department you administer.');

        $data = $request->validate([
            'name' => 'required|string|max:100',
            'code' => 'required|string|max:20|unique:shift_rotation_patterns,code',
            'cycle_length_days' => 'required|integer|min:1',
            'definition' => 'required|array',
            'is_active' => 'boolean',
            'department_id' => 'nullable|integer|exists:departments,id',
        ]);

        $this->assertPatternShiftsAllowed($data['definition'], $requestedDepartment, $user);

        $data['department_id'] = $requestedDepartment;
        $data['created_by'] = auth()->id();

        $pattern = DB::transaction(fn () => ShiftRotationPattern::create($data));
        $pattern->load('department:id,name');
        if ($this->templates->exposesCreator($user)) {
            $pattern->load('creator:employee_id,name');
        }

        return response()->json(['message' => 'Pattern created.', 'pattern' => $this->presentTemplates(collect([$pattern]), $user)->first()], 201);
    }

    public function updatePattern(Request $request, int $id): JsonResponse
    {
        $user = auth()->user();
        $pattern = ShiftRotationPattern::findOrFail($id);

        if (! $this->templates->canManage($user, $pattern)) {
            abort(403, 'Unauthorized to update this rotation pattern.');
        }

        $data = $request->validate([
            'name' => 'sometimes|string|max:100',
            'code' => 'sometimes|string|max:20|unique:shift_rotation_patterns,code,'.$id,
            'cycle_length_days' => 'sometimes|integer|min:1',
            'definition' => 'sometimes|array',
            'is_active' => 'sometimes|boolean',
            'department_id' => 'sometimes|nullable|integer|exists:departments,id',
        ]);

        $newDepartment = array_key_exists('department_id', $data)
            ? ($data['department_id'] === null ? null : (int) $data['department_id'])
            : ($pattern->department_id === null ? null : (int) $pattern->department_id);

        if (array_key_exists('department_id', $data) && ! $this->templates->canReassignDepartment($user, $pattern, $newDepartment)) {
            abort(403, 'Unauthorized to move this rotation pattern to another department.');
        }

        $this->assertPatternShiftsAllowed($data['definition'] ?? ($pattern->definition ?? []), $newDepartment, $user);

        DB::transaction(fn () => $pattern->update($data));

        $fresh = $pattern->fresh()->load('department:id,name');
        if ($this->templates->exposesCreator($user)) {
            $fresh->load('creator:employee_id,name');
        }

        return response()->json(['message' => 'Pattern updated.', 'pattern' => $this->presentTemplates(collect([$fresh]), $user)->first()]);
    }

    public function destroyPattern(int $id): JsonResponse
    {
        $user = auth()->user();
        $pattern = ShiftRotationPattern::findOrFail($id);

        if (! $this->templates->canManage($user, $pattern)) {
            abort(403, 'Unauthorized to delete this rotation pattern.');
        }

        // Same protection as shifts: a delegated admin cannot delete a pattern assignments still use.
        if (! $this->scope->isGlobal($user) && ShiftAssignment::where('rotation_pattern_id', $pattern->id)->exists()) {
            return response()->json(['message' => 'This rotation pattern is used by shift assignments. Deactivate it instead of deleting it.'], 422);
        }

        DB::transaction(fn () => $pattern->delete());

        return response()->json(['message' => 'Pattern deleted.']);
    }

    /**
     * A non-global actor may only assign shifts inside the departments they manage
     * and to employees DepartmentScope lets them act on. Fails closed: an actor with
     * no managed department can target only themselves (user scope).
     */
    private function validateScopeForManager(string $scopeType, array $scopeIds, User $actor): void
    {
        if ($scopeType === 'org') {
            abort(403, 'Unauthorized to assign shifts at the organization level.');
        }

        $managed = $this->scope->managedDepartmentIds($actor);

        if ($scopeType === 'department') {
            foreach ($scopeIds as $id) {
                if (! in_array((int) $id, $managed, true)) {
                    abort(403, 'Unauthorized to assign shifts for other departments.');
                }
            }
        }

        if ($scopeType === 'designation') {
            $invalidCount = Designation::whereIn('id', $scopeIds)
                ->where(fn ($q) => $q->whereNull('department_id')->orWhereNotIn('department_id', $managed))
                ->count();
            if ($invalidCount > 0) {
                abort(403, 'Unauthorized to assign shifts for designations outside your department.');
            }
        }

        if ($scopeType === 'user') {
            foreach ($scopeIds as $id) {
                if (! $this->scope->canActOn($actor, (string) $id)) {
                    abort(403, 'Unauthorized to assign shifts to employees outside your department.');
                }
            }
        }
    }

    /** May the (non-global) actor act on an existing assignment's target? */
    private function assignmentInScope(User $actor, ShiftAssignment $assignment): bool
    {
        $managed = $this->scope->managedDepartmentIds($actor);

        return match ($assignment->scope_type) {
            'user' => $this->scope->canActOn($actor, (string) $assignment->scope_id),
            'department' => in_array((int) $assignment->scope_id, $managed, true),
            'designation' => Designation::whereKey($assignment->scope_id)
                ->whereIn('department_id', $managed)
                ->exists(),
            default => false,
        };
    }

    /**
     * Normalize polymorphic scope identifiers without coercing employee codes to zero.
     *
     * @return array<int, string|null>
     */
    private function normalizeScopeIds(string $scopeType, array $scopeIds): array
    {
        if ($scopeType === 'org') {
            return [null];
        }

        $ids = collect($scopeIds)
            ->reject(fn ($id) => $id === null || $id === '')
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['scope_id' => 'Select at least one scope target.']);
        }

        $existingCount = match ($scopeType) {
            'user' => User::whereIn('employee_id', $ids)->count(),
            'department' => DB::table('departments')->whereIn('id', $ids)->count(),
            'designation' => Designation::whereIn('id', $ids)->count(),
            default => 0,
        };

        if ($existingCount !== $ids->count()) {
            throw ValidationException::withMessages(['scope_id' => 'One or more selected scope targets are invalid.']);
        }

        return $ids->all();
    }

    public function storeAssignment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'scope_type' => 'required|in:user,designation,department,org',
            'scope_id' => 'nullable',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'required|date',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'priority' => 'integer|min:0',
        ]);

        $data['scope_id'] = $this->normalizeScopeIds($data['scope_type'], [$data['scope_id'] ?? null])[0];

        $user = auth()->user();

        if (! $this->scope->isAttendanceAdmin($user)) {
            $this->validateScopeForManager($data['scope_type'], [$data['scope_id']], $user);
        }
        $this->assertMayAssignTemplates($user, $data['shift_id'] ?? null, $data['rotation_pattern_id'] ?? null);

        $data['assigned_by'] = $user->getKey();

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
            'scope_ids.*' => 'required',
            'shift_id' => 'nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'required|date',
            'effective_from' => 'required|date',
            'effective_to' => 'nullable|date|after_or_equal:effective_from',
            'priority' => 'integer|min:0',
        ]);

        $user = auth()->user();

        $scopeType = $data['scope_type'];
        $scopeIds = $this->normalizeScopeIds($scopeType, $data['scope_ids'] ?? []);

        if (! $this->scope->isAttendanceAdmin($user)) {
            $this->validateScopeForManager($scopeType, $scopeIds, $user);
        }
        $this->assertMayAssignTemplates($user, $data['shift_id'] ?? null, $data['rotation_pattern_id'] ?? null);

        $created = [];
        $errors = [];

        try {
            DB::transaction(function () use ($scopeIds, $data, $user, &$created, &$errors) {
                foreach ($scopeIds as $scopeId) {
                    $row = $data;
                    $row['scope_id'] = $scopeId;
                    $row['assigned_by'] = $user->getKey();
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

        $query = ShiftAssignment::with(['shift:id,code,name', 'rotationPattern:id,name', 'assigner:employee_id,name'])
            ->orderByDesc('created_at');

        if (! $this->scope->isAttendanceAdmin($user)) {
            $managed = $this->scope->managedDepartmentIds($user);
            $visibleIds = $this->scope->visibleEmployeeIds($user) ?? [];

            $query->where(function ($q) use ($managed, $visibleIds) {
                $q->where(fn ($sub) => $sub->where('scope_type', 'user')->whereIn('scope_id', $visibleIds))
                    ->orWhere(fn ($sub) => $sub->where('scope_type', 'department')->whereIn('scope_id', $managed))
                    ->orWhere(fn ($sub) => $sub->where('scope_type', 'designation')
                        ->whereIn('scope_id', Designation::whereIn('department_id', $managed)->pluck('id')));
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
        $assignment = ShiftAssignment::findOrFail($id);

        if (! $this->scope->isAttendanceAdmin($user)) {
            if (! $this->assignmentInScope($user, $assignment)) {
                abort(403, 'Unauthorized to update this shift assignment.');
            }

            if ($request->has('scope_type') && $request->has('scope_id')) {
                $this->validateScopeForManager($request->input('scope_type'), [$request->input('scope_id')], $user);
            }
        }

        $data = $request->validate([
            'scope_type' => 'sometimes|in:user,designation,department,org',
            'scope_id' => 'sometimes|nullable',
            'shift_id' => 'sometimes|nullable|integer|exists:shifts,id',
            'rotation_pattern_id' => 'sometimes|nullable|integer|exists:shift_rotation_patterns,id',
            'anchor_date' => 'sometimes|date',
            'effective_from' => 'sometimes|date',
            'effective_to' => 'sometimes|nullable|date',
            'priority' => 'sometimes|integer|min:0',
        ]);

        $this->assertMayAssignTemplates($user, $data['shift_id'] ?? null, $data['rotation_pattern_id'] ?? null);

        if (array_key_exists('scope_type', $data) || array_key_exists('scope_id', $data)) {
            $effectiveScopeType = $data['scope_type'] ?? $assignment->scope_type;
            $effectiveScopeId = array_key_exists('scope_id', $data) ? $data['scope_id'] : $assignment->scope_id;
            $data['scope_id'] = $this->normalizeScopeIds($effectiveScopeType, [$effectiveScopeId])[0];
        }

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
        $assignment = ShiftAssignment::findOrFail($id);

        if (! $this->scope->isAttendanceAdmin($user) && ! $this->assignmentInScope($user, $assignment)) {
            abort(403, 'Unauthorized to delete this shift assignment.');
        }

        DB::transaction(fn () => $assignment->delete());

        return response()->json(['message' => 'Assignment deleted.']);
    }
}
