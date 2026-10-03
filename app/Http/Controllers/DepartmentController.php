<?php

namespace App\Http\Controllers;

use App\Models\HRM\Department;
use App\Models\User;
use App\Services\Access\DepartmentDefaultRoles;
use App\Traits\HandlesApiExceptions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DepartmentController extends Controller
{
    use HandlesApiExceptions;

    /**
     * Display a listing of departments
     */
    public function index(Request $request): Response
    {
        $query = Department::with(['parent', 'manager', 'children']);

        // Apply search filter if provided
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Apply status filter if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        // Get departments with pagination
        $departments = $query->paginate(min(max((int) $request->get('per_page', 20), 5), 100));

        // Get all employees for manager dropdown
        $managers = User::orderBy('name')->get(['employee_id as id', 'employee_id', 'name']);

        // Get parent departments for dropdown
        $parentDepartments = Department::whereNull('parent_id')
            ->orWhere('parent_id', 0)
            ->get(['id', 'name']);

        // Department statistics
        $stats = [
            'total' => Department::count(),
            'active' => Department::where('is_active', true)->count(),
            'inactive' => Department::where('is_active', false)->count(),
            'parent_departments' => Department::whereNull('parent_id')->orWhere('parent_id', 0)->count(),
        ];

        return Inertia::render('Departments', [
            'title' => 'Department Management',
            'departments' => $departments,
            'managers' => $managers,
            'parentDepartments' => $parentDepartments,
            'stats' => $stats,
            'filters' => $request->only(['search', 'status']),
        ]);
    }

    /**
     * Store a newly created department
     *
     * @return JsonResponse
     */
    public function store(Request $request)
    {
        try {
            // Validate request data
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'code' => 'nullable|string|max:50|unique:departments',
                'description' => 'nullable|string',
                'parent_id' => 'nullable|exists:departments,id',
                'manager_id' => 'nullable|exists:users,employee_id',
                'location' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'established_date' => 'nullable|date',
                'default_roles' => 'sometimes|nullable|array',
                'default_roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web'), Rule::notIn(DepartmentDefaultRoles::FORBIDDEN)],
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $data = $this->withDefaultRolesForGlobalAdmin($request, $validator->validated(), null);

            // Create new department
            $department = Department::create($data);

            return response()->json([
                'message' => 'Department created successfully',
                'department' => $department,
            ], 201);
        } catch (HttpException $e) {
            throw $e; // a deliberate 403/404 stays one, never a generic 500
        } catch (\Exception $e) {
            Log::error('Failed to create department: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to create department',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Display the specified department
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function show($id)
    {
        try {
            $department = Department::with(['parent', 'manager', 'children'])
                ->findOrFail($id);

            return response()->json($department);
        } catch (\Exception $e) {
            Log::error('Failed to get department: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to get department',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Update the specified department
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function update(Request $request, $id)
    {
        try {
            $authUser = auth()->user();
            $isGlobal = $authUser->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
            $userDeptId = $authUser->department_id;

            if (! $isGlobal && $userDeptId !== null) {
                if ((int) $id !== (int) $userDeptId) {
                    abort(403, 'Unauthorized to modify other departments.');
                }
            }

            $department = Department::findOrFail($id);

            // Validate request data
            $validator = Validator::make($request->all(), [
                'name' => 'required|string|max:255',
                'code' => [
                    'nullable',
                    'string',
                    'max:50',
                    Rule::unique('departments')->ignore($id),
                ],
                'description' => 'nullable|string',
                'parent_id' => 'nullable|exists:departments,id',
                'manager_id' => 'nullable|exists:users,employee_id',
                'location' => 'nullable|string|max:255',
                'is_active' => 'boolean',
                'established_date' => 'nullable|date',
                'default_roles' => 'sometimes|nullable|array',
                'default_roles.*' => ['string', Rule::exists('roles', 'name')->where('guard_name', 'web'), Rule::notIn(DepartmentDefaultRoles::FORBIDDEN)],
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $data = $this->withDefaultRolesForGlobalAdmin($request, $validator->validated(), $department);

            // Update department
            $department->update($data);

            return response()->json([
                'message' => 'Department updated successfully',
                'department' => $department,
            ]);
        } catch (HttpException $e) {
            throw $e; // a deliberate 403/404 stays one, never a generic 500
        } catch (\Exception $e) {
            Log::error('Failed to update department: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update department',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Remove the specified department (soft delete)
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function destroy($id)
    {
        try {
            $authUser = auth()->user();
            $isGlobal = $authUser->hasRole(['Super Administrator', 'Administrator', 'HR Manager']);
            $userDeptId = $authUser->department_id;

            if (! $isGlobal && $userDeptId !== null) {
                if ((int) $id !== (int) $userDeptId) {
                    abort(403, 'Unauthorized to delete other departments.');
                }
            }

            $department = Department::findOrFail($id);

            // Check if department has employees
            if ($department->employees()->count() > 0) {
                return response()->json([
                    'message' => 'Cannot delete department with active employees',
                    'errors' => ['department' => 'Department has active employees. Reassign them before deleting.'],
                ], 422);
            }

            // Soft delete the department
            $department->delete();

            return response()->json([
                'message' => 'Department deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to delete department: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to delete department',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Update a user's department
     *
     * @param  int  $id
     * @return JsonResponse
     */
    public function updateUserDepartment(Request $request, $id)
    {
        try {
            $request->validate([
                'department' => 'required|integer|exists:departments,id',
            ]);

            $user = User::findOrFail($id);

            // UserPolicy::transfer — employees.update AND scope over the employee AND, for a non-global
            // actor, only employees in a department he administers, only into another department he
            // administers (fail closed otherwise); never one's own department.
            abort_unless(
                $request->user()->can('transfer', [$user, (int) $request->input('department')]),
                403,
                'You can only move employees between departments you manage.'
            );

            // Get the new department ID and verify it exists
            $newDepartmentId = $request->input('department');
            $department = Department::find($newDepartmentId);

            if (! $department) {
                return response()->json([
                    'errors' => ['department' => 'The selected department does not exist.'],
                ], 422);
            }

            // Check if department changed
            $departmentChanged = (int) $user->department_id !== (int) $newDepartmentId;

            // Update department
            $user->department_id = $newDepartmentId;

            // Optionally reset designation if department changed
            if ($departmentChanged) {
                $user->designation_id = null; // Reset designation when department changes
            }

            $previousDepartmentId = $user->getOriginal('department_id');
            $user->save();

            if ($departmentChanged) {
                app(DepartmentDefaultRoles::class)->syncOnTransfer(
                    $user,
                    $previousDepartmentId ? (int) $previousDepartmentId : null,
                    (int) $newDepartmentId
                );
            }

            return response()->json([
                'messages' => ['Department updated successfully'],
                'user' => $user, // Optional: return updated user info
            ], 200);
        } catch (ValidationException $e) {
            Log::error('Validation error on updateUserDepartment', [
                'errors' => $e->errors(),
                'request' => $request->all(),
            ]);

            return response()->json(['errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            Log::error('User not found during updateUserDepartment', [
                'user_id' => $id,
            ]);

            return response()->json(['errors' => ['User not found']], 404);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Unexpected error during updateUserDepartment', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
                'user_id' => $id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred.',
                'error_code' => 'DEPARTMENT_UPDATE_FAILED',
            ], 500);
        }
    }

    /**
     * Get department statistics
     *
     * @return JsonResponse
     */
    public function getStats()
    {
        $stats = [
            'total' => Department::count(),
            'active' => Department::where('is_active', true)->count(),
            'inactive' => Department::where('is_active', false)->count(),
            'parent_departments' => Department::whereNull('parent_id')->orWhere('parent_id', 0)->count(),
        ];

        return response()->json([
            'stats' => $stats,
        ]);
    }

    /**
     * Get departments data for API requests
     *
     * @return JsonResponse
     */
    public function getDepartments(Request $request)
    {
        $query = Department::with(['parent', 'manager', 'children']);

        // Apply search filter if provided
        if ($request->has('search') && ! empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('location', 'like', "%{$search}%");
            });
        }

        // Apply status filter if provided
        if ($request->has('status') && $request->status !== 'all') {
            $query->where('is_active', $request->status === 'active');
        }

        // Apply parent department filter if provided
        if ($request->has('parent_department') && $request->parent_department !== 'all') {
            if ($request->parent_department === 'none') {
                $query->whereNull('parent_id')->orWhere('parent_id', 0);
            } else {
                $query->where('parent_id', $request->parent_department);
            }
        }

        // Get departments with pagination
        $departments = $query->paginate(min(max((int) $request->input('per_page', 10), 5), 100));

        return response()->json([
            'departments' => $departments,
        ]);
    }

    /** Roles a Super Administrator may list as a department default. */
    public function defaultRoleOptions(Request $request): JsonResponse
    {
        abort_unless($request->user()->hasRole('Super Administrator'), 403);

        return response()->json([
            'roles' => Role::query()->where('guard_name', 'web')
                ->whereNotIn('name', DepartmentDefaultRoles::FORBIDDEN)->orderBy('name')->pluck('name'),
        ]);
    }

    /**
     * default_roles decides which functional roles every employee of the department receives, so
     * only a Super Administrator may set it (owner decision O-15); anyone else's value is refused when
     * it would change it and otherwise dropped.
     */
    private function withDefaultRolesForGlobalAdmin(Request $request, array $data, ?Department $existing): array
    {
        if (! array_key_exists('default_roles', $data)) {
            return $data;
        }
        $requested = $data['default_roles'] === null ? null : array_values(array_unique($data['default_roles']));
        if ($request->user()->hasRole('Super Administrator')) {
            $data['default_roles'] = $requested ?: null;

            return $data;
        }

        // The edit form echoes the current value back: unchanged is a no-op, a change is refused.
        $current = collect($existing?->default_roles ?? [])->sort()->values()->all();
        if (collect($requested ?? [])->sort()->values()->all() !== $current) {
            abort(403, 'Only a Super Administrator may change a department\'s default roles.');
        }
        unset($data['default_roles']);

        return $data;
    }
}
