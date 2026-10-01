<?php

namespace App\Http\Controllers;

use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\User;
use App\Services\Access\DepartmentScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DesignationController extends Controller
{
    public function __construct()
    {
        // Apply authorization middleware or policies
        $this->middleware('permission:designations.view')->only(['index', 'getDesignations', 'stats', 'show', 'list']);
        $this->middleware('permission:designations.create')->only(['store']);
        $this->middleware('permission:designations.update')->only(['update']);
        // Assigning a designation to an employee is a placement action, gated on the route by
        // employees.placement.update (see routes/web.php) and by UserPolicy::updatePlacement below.

        $this->middleware('permission:designations.delete')->only(['destroy']);
    }

    private function scope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    /**
     * A designation query confined to what the actor may SEE: everything for a company-wide actor,
     * otherwise the designations of the departments they administer plus their own (fail closed — no
     * department grants nothing, where the old check read a null home department as "everyone").
     */
    private function visibleDesignations(?Builder $query = null): Builder
    {
        $query ??= Designation::query();
        $actor = Auth::user();

        if (! $this->scope()->isGlobal($actor)) {
            $query->whereIn('designations.department_id', $this->scope()->visibleDepartmentIds($actor));
        }

        return $query;
    }

    /**
     * May the actor create / change / delete designations of this department? A company-wide actor
     * any; everyone else only the departments they ADMINISTER (a department admin delegates his own).
     */
    private function assertMayManageDepartment(int|string|null $departmentId): void
    {
        $actor = Auth::user();

        if ($this->scope()->isGlobal($actor)) {
            return;
        }

        abort_unless(
            $departmentId !== null && in_array((int) $departmentId, $this->scope()->managedDepartmentIds($actor), true),
            403,
            'You can only manage designations of departments you administer.'
        );
    }

    /**
     * A parent designation must belong to the SAME department as its child, must not be the
     * designation itself and must not be one of its own descendants (no cycles).
     */
    private function assertValidParent(?int $parentId, int $departmentId, ?Designation $self = null): void
    {
        if ($parentId === null) {
            return;
        }

        $parent = Designation::find($parentId);
        if ($parent === null || (int) $parent->department_id !== $departmentId) {
            throw ValidationException::withMessages(['parent_id' => ['The parent designation must belong to the same department.']]);
        }

        if ($self !== null) {
            $seen = [];
            for ($ancestor = $parent; $ancestor !== null && ! isset($seen[$ancestor->id]); $ancestor = $ancestor->parent_id ? Designation::find($ancestor->parent_id) : null) {
                if ((int) $ancestor->id === (int) $self->id) {
                    throw ValidationException::withMessages(['parent_id' => ['A designation cannot report to itself or to one of its own sub-designations.']]);
                }
                $seen[$ancestor->id] = true;
            }
        }
    }

    /**
     * Render the Designations page with dropdown data and stats.
     */
    public function index(Request $request): Response
    {
        $actor = Auth::user();
        $managers = $this->scope()->applyToUsers(User::query(), $actor)->whereHas('roles', function ($q) {
            $q->where('name', 'like', '%Manager%')
                ->orWhere('name', 'like', '%Director%')
                ->orWhere('name', 'like', '%Head%');
        })->get(['employee_id as id', 'employee_id', 'name']);

        $parentDesignations = $this->visibleDesignations()->where(function ($q) {
            $q->whereNull('parent_id')->orWhere('parent_id', 0);
        })->get(['id', 'title']);

        $stats = $this->statsPayload();
        $departments = $this->scope()->applyToDepartments(Department::query(), $actor)->get(['id', 'name']);
        $allDesignations = $this->visibleDesignations(Designation::with('department'))->orderBy('hierarchy_level', 'asc')->get();

        return Inertia::render('Designations', [
            'title' => 'Designation Management',
            'designations' => [], // Loaded via frontend API
            'allDesignations' => $allDesignations,
            'departments' => $departments,
            'managers' => $managers,
            'parentDesignations' => $parentDesignations,
            'stats' => $stats,
            'filters' => $request->only(['search', 'status', 'department', 'parent_designation']),
        ]);
    }

    /**
     * Fetch paginated designations with filters applied.
     */
    public function getDesignations(Request $request)
    {
        $query = $this->visibleDesignations(Designation::with(['department'])->withCount('users'));

        // Apply search
        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where('title', 'like', "%{$search}%");
        }

        // Filter by department
        if ($request->filled('department') && $request->department !== 'all') {
            $query->where('department_id', $request->department);
        }

        // Filter by active/inactive
        if ($request->status && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        // Filter by parent designation
        if ($request->parent_designation && $request->parent_designation !== 'all') {
            $query->where(function ($q) use ($request) {
                if ($request->parent_designation === 'none') {
                    $q->whereNull('parent_id')->orWhere('parent_id', 0);
                } else {
                    $q->where('parent_id', $request->parent_designation);
                }
            });
        }

        $designations = $query->paginate(min(max((int) $request->input('per_page', 10), 5), 100));

        $designations->getCollection()->transform(function ($designation) {
            return [
                'id' => $designation->id,
                'title' => $designation->title,
                'department_id' => $designation->department_id,
                'department_name' => optional($designation->department)->name,
                'parent_id' => $designation->parent_id,
                'hierarchy_level' => $designation->hierarchy_level,
                'employee_count' => $designation->employee_count,
                'is_active' => $designation->is_active,
            ];
        });

        Log::info('Fetched designations:', $designations->toArray());

        return response()->json(['designations' => $designations]);
    }

    /**
     * Update a user's designation.
     */
    public function updateUserDesignation(Request $request, $id)
    {
        $request->validate([
            'designation_id' => 'required|exists:designations,id',
        ]);

        $user = User::findOrFail($id);

        // employees.placement.update AND scope over the employee AND (non-global) outranking him;
        // never one's own designation.
        $this->authorize('updatePlacement', $user);

        // Non-global: only to a designation of the employee's own department, which he administers
        // (fail closed — no department grants nothing).
        $scope = app(DepartmentScope::class);
        $authUser = $request->user();
        if (! $scope->isGlobal($authUser)) {
            $targetDesig = Designation::findOrFail($request->input('designation_id'));
            if (! in_array((int) $targetDesig->department_id, $scope->managedDepartmentIds($authUser), true)) {
                abort(403, 'Unauthorized to modify designations outside your department scope.');
            }
            if ((int) $targetDesig->department_id !== (int) $user->department_id) {
                return response()->json(['errors' => ['designation_id' => ['The designation must belong to the employee\'s department.']]], 422);
            }
        }

        $user->designation_id = $request->input('designation_id');
        $user->save();

        return response()->json(['messages' => ['Designation updated successfully']], 200);
    }

    /**
     * Create a new designation. A department admin creates them for the departments he administers
     * only, with a parent inside the same department.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
            'hierarchy_level' => 'required|integer|min:1|max:10',
            'parent_id' => 'nullable|exists:designations,id',
            'is_active' => 'boolean',
        ]);

        $this->assertMayManageDepartment($validated['department_id']);
        $this->assertValidParent(isset($validated['parent_id']) ? (int) $validated['parent_id'] : null, (int) $validated['department_id']);

        $designation = Designation::create($validated);

        return response()->json(['designation' => $designation->load('department:id,name'), 'message' => 'Designation created successfully'], 201);
    }

    /**
     * Show a single designation (only one the actor may see — anything else reads as not found).
     */
    public function show($id)
    {
        $designation = $this->visibleDesignations(Designation::with('department'))->findOrFail($id);

        return response()->json(['designation' => $designation]);
    }

    /**
     * Update an existing designation. For a non-global actor both the current and the new department
     * must be ones he administers; a designation that employees hold cannot change department.
     */
    public function update(Request $request, $id)
    {
        $designation = Designation::findOrFail($id);
        $this->assertMayManageDepartment($designation->department_id);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'department_id' => 'required|exists:departments,id',
            'hierarchy_level' => 'required|integer|min:1|max:10',
            'parent_id' => 'nullable|exists:designations,id',
            'is_active' => 'boolean',
        ]);

        $this->assertMayManageDepartment($validated['department_id']);

        if ((int) $validated['department_id'] !== (int) $designation->department_id
            && ($designation->users()->exists() || Designation::where('parent_id', $designation->id)->exists())) {
            throw ValidationException::withMessages([
                'department_id' => ['Employees or sub-designations still use this designation: reassign them before moving it to another department.'],
            ]);
        }

        $this->assertValidParent(isset($validated['parent_id']) ? (int) $validated['parent_id'] : null, (int) $validated['department_id'], $designation);

        $designation->update($validated);

        return response()->json(['designation' => $designation->load('department:id,name'), 'message' => 'Designation updated successfully']);
    }

    /**
     * Delete a designation. One that employees hold cannot be deleted — reassign them or
     * deactivate the designation instead — and neither can one that others report to.
     */
    public function destroy($id)
    {
        $designation = Designation::findOrFail($id);
        $this->assertMayManageDepartment($designation->department_id);

        if ($designation->users()->exists()) {
            return response()->json([
                'error' => 'Cannot delete a designation that employees hold. Reassign them or deactivate the designation instead.',
                'message' => 'Cannot delete a designation that employees hold. Reassign them or deactivate the designation instead.',
                'employee_count' => $designation->users()->count(),
            ], 422);
        }

        if (Designation::where('parent_id', $designation->id)->exists()) {
            return response()->json([
                'error' => 'Other designations report to this one. Reassign or delete them first.',
                'message' => 'Other designations report to this one. Reassign or delete them first.',
            ], 422);
        }

        $designation->delete(); // soft delete

        return response()->json(['message' => 'Designation deleted successfully.']);
    }

    /**
     * Get list of active designations for dropdowns.
     */
    public function list()
    {
        if (! $this->scope()->isGlobal(Auth::user())) {
            return response()->json($this->visibleDesignations(Designation::select('id', 'title'))->where('is_active', true)->get());
        }

        $designations = Cache::remember('active_designations_list', now()->addHour(), function () {
            return Designation::select('id', 'title')
                ->where('is_active', true)
                ->get();
        });

        return response()->json($designations);
    }

    /**
     * Get designation statistics for frontend analytics (scoped like the list they summarise).
     */
    public function stats()
    {
        return response()->json(['stats' => $this->statsPayload()]);
    }

    /** @return array<string, int> */
    private function statsPayload(): array
    {
        return [
            'total' => $this->visibleDesignations()->count(),
            'active' => $this->visibleDesignations()->where('is_active', true)->count(),
            'inactive' => $this->visibleDesignations()->where('is_active', false)->count(),
            'parent_designations' => $this->visibleDesignations()->where(function ($q) {
                $q->whereNull('parent_id')->orWhere('parent_id', 0);
            })->count(),
        ];
    }
}
