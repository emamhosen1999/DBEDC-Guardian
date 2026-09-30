<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\User;
use App\Models\WorkLocation;
use App\Services\Access\DepartmentScope;
use App\Services\Admin\UserManagementService;
use App\Traits\HandlesApiExceptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

class UserController extends Controller
{
    use HandlesApiExceptions;

    protected UserManagementService $userService;

    /**
     * Fields a user may never change on their own record.
     */
    private const SELF_PROTECTED_FIELDS = [
        'roles', 'salary_amount', 'department_id', 'designation_id', 'employee_id', 'report_to',
        'attendance_type_id', 'attendance_type_ids', 'biometric_device_ids', 'work_location_id', 'date_of_joining',
    ];

    public function __construct(UserManagementService $userService)
    {
        $this->userService = $userService;
    }

    /**
     * Resolved per call, not injected: route-cached controller instances outlive a
     * request, while the scope memo must not.
     */
    private function scope(): DepartmentScope
    {
        return app(DepartmentScope::class);
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        $authUser = Auth::user();
        $isGlobal = $this->scope()->isGlobal($authUser);
        // Departments a non-global actor may see: the ones they administer, plus
        // their own (so their own record still renders with its department).
        $visibleDeptIds = array_values(array_unique(array_filter(array_merge(
            $this->scope()->managedDepartmentIds($authUser),
            [$authUser->department_id !== null ? (int) $authUser->department_id : null],
        ), fn ($id) => $id !== null)));

        $departmentsQuery = Department::select('id', 'name', 'code', 'parent_id', 'is_active');
        $designationsQuery = Designation::select('id', 'title', 'department_id', 'hierarchy_level', 'parent_id', 'is_active')
            ->with('department:id,name') // department_name is appended in Designation::toArray(); avoid lazy-load violation
            ->orderBy('hierarchy_level', 'asc');
        $usersQuery = $this->scope()->applyToUsers(User::query(), $authUser)
            ->select('employee_id as id', 'employee_id', 'name', 'email', 'department_id', 'designation_id');

        if (! $isGlobal) {
            $departmentsQuery->whereIn('id', $visibleDeptIds);
            $designationsQuery->whereIn('department_id', $visibleDeptIds);
        }

        $departments = $departmentsQuery->get();
        $designations = $designationsQuery->get();
        // Scoped actors only get the roles they may actually grant (never Administrator etc.).
        $roles = $isGlobal
            ? Role::with('permissions')->get()
            : $this->userService->grantableRoles($authUser)->with('permissions')->get();

        $attendanceTypes = AttendanceType::select('id', 'name', 'slug', 'config', 'is_active')
            ->with(['biometricDevices:id,name,serial_number,location'])
            ->get();

        $activeUsers = $usersQuery->get();

        $workLocations = WorkLocation::with(['attendanceType', 'attendanceTypes:id,name,slug', 'biometricDevices:id,name,serial_number'])->get();

        // 2. Department Tab Stats & Pagination
        $parentDepartments = $departments->whereNull('parent_id')->values();
        $departmentStats = [
            'total' => $departments->count(),
            'active' => $departments->where('is_active', true)->count(),
            'inactive' => $departments->where('is_active', false)->count(),
            'parent_departments' => $parentDepartments->count(),
        ];
        $departmentsPaginateQuery = Department::with(['manager:employee_id,name,email', 'parent:id,name'])
            ->withCount('employees');
        $designationsPaginateQuery = Designation::with('department:id,name')
            ->withCount(['users as employee_count']);

        if (! $isGlobal) {
            $departmentsPaginateQuery->whereIn('id', $visibleDeptIds);
            $designationsPaginateQuery->whereIn('department_id', $visibleDeptIds);
        }

        $initialDepartments = $departmentsPaginateQuery->paginate(10);

        // 3. Designation Tab Stats & Pagination
        $designationStats = [
            'total' => $designations->count(),
            'active' => $designations->where('is_active', true)->count(),
            'inactive' => $designations->where('is_active', false)->count(),
            'parent_designations' => $designations->whereNull('parent_id')->count(),
        ];
        $initialDesignations = $designationsPaginateQuery->paginate(10);

        $overviewStats = [
            'total_employees' => $activeUsers->count(),
            'total_departments' => $departments->count(),
            'total_designations' => $designations->count(),
            'total_locations' => $workLocations->count(),
        ];

        // 4. Admin - Roles & Permissions data. Only for actors who can open the Roles tab
        // (roles.view) — a department admin must not receive the whole role/permission matrix.
        if ($authUser->can('roles.view')) {
            $permissions = Permission::all();
            $roleHasPermissions = DB::table('role_has_permissions')->get();
            $permissionsGrouped = Permission::all()->groupBy('module')
                ->map(fn ($perms, $module) => [
                    'label' => $module,
                    'permissions' => $perms->values(),
                ]);
        } else {
            $permissions = collect();
            $roleHasPermissions = collect();
            $permissionsGrouped = collect();
        }

        // 5. Admin - Biometric Devices data
        // Scoped actors: only terminals mapped to the work locations of people in their scope.
        if ($isGlobal) {
            $devices = BiometricDevice::all();
        } else {
            $locationIds = $this->scope()->applyToUsers(User::query(), $authUser)
                ->whereNotNull('work_location_id')->distinct()->pluck('work_location_id');
            $devices = BiometricDevice::whereIn('id', DB::table('work_location_biometric_device')
                ->whereIn('work_location_id', $locationIds)->select('biometric_device_id'))->get();
        }

        return Inertia::render('Employees/EmployeesPage', [
            'title' => 'Employees Console',

            // Shared lists
            'departments' => $departments,
            'designations' => $designations,
            'attendanceTypes' => $attendanceTypes,
            'roles' => $roles,
            'allManagers' => $activeUsers,

            // Department Tab
            'managers' => $activeUsers,
            'parentDepartments' => $parentDepartments,
            'departmentsData' => $initialDepartments,
            'stats' => $departmentStats,

            // Designation Tab
            'allDesignations' => $designations,
            'initialDesignations' => $initialDesignations,
            'designationStats' => $designationStats,

            // Work Locations Tab
            'workLocations' => $workLocations,
            'users' => $activeUsers,

            // Roles & Permissions Tab
            'permissions' => $permissions,
            'role_has_permissions' => $roleHasPermissions,
            'permissionsGrouped' => $permissionsGrouped,
            'can_manage_super_admin' => auth()->user()->can('manage super admin'),

            // Biometric Tab
            'devices' => $devices,
            'employees' => $activeUsers,

            // Page Overview Stats
            'overviewStats' => $overviewStats,
        ]);
    }

    /**
     * Store a new user.
     */
    public function store(StoreUserRequest $request)
    {
        try {
            $validated = $request->validated();
            $roles = $request->input('roles');
            $profileImage = $request->file('profile_image');

            $authUser = Auth::user();

            if (! $this->scope()->isGlobal($authUser)) {
                // A department admin creates plain employees, and only into a
                // department they administer (defaulting when they administer one).
                $validated['department_id'] = $this->resolveCreatableDepartment($authUser, $validated['department_id'] ?? null);
                $this->assertReportToInScope($authUser, $validated['report_to'] ?? null);
                $roles = ['Employee'];
            } elseif ($roles) {
                // Global actors may create staff, but never a role equal to or more powerful
                // than their own (an HR Manager must not mint an Administrator) — the same
                // hierarchy rule the role endpoints enforce.
                $this->userService->assertCanGrantRoles($authUser, (array) $roles);
            }

            $user = $this->userService->createUser($validated, $roles, $profileImage);

            Log::info('User created', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'created_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'User created successfully',
                'user' => new UserResource($user),
            ], 201);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User creation failed: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to create user',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Update the specified user in storage.
     */
    public function update(UpdateUserRequest $request, $id)
    {
        try {
            $validated = $request->validated();
            $roles = $request->input('roles');
            $hasRoles = $request->has('roles');
            $profileImage = $request->file('profile_image');

            $authUser = Auth::user();
            $isGlobal = $this->scope()->isGlobal($authUser);
            $targetUser = User::findOrFail($id);
            $isSelf = (string) $authUser->employee_id === (string) $targetUser->employee_id;

            if ($isSelf) {
                // Self-service edits must never touch role, pay, org placement or identity
                // fields. A Super Administrator is exempt for non-role fields only.
                $roles = null;
                $hasRoles = false;
                if (! $authUser->hasRole('Super Administrator')) {
                    $validated = Arr::except($validated, self::SELF_PROTECTED_FIELDS);
                }
            } elseif (! $isGlobal) {
                // Non-global actors never change roles here (that would demote e.g. a
                // Team Lead on every phone-number edit), only touch employees in their
                // scope whom they outrank, and only move them between departments
                // they administer.
                if (! $this->scope()->canManage($authUser, $targetUser)) {
                    abort(403, 'Unauthorized to update users outside your department scope.');
                }
                if (array_key_exists('department_id', $validated)
                    && (int) $validated['department_id'] !== (int) $targetUser->department_id
                    && ! in_array((int) $validated['department_id'], $this->scope()->managedDepartmentIds($authUser), true)) {
                    abort(403, 'You can only assign employees to departments you manage.');
                }
                if (array_key_exists('report_to', $validated)
                    && (string) $validated['report_to'] !== (string) $targetUser->report_to) {
                    $this->assertReportToInScope($authUser, $validated['report_to']);
                }
                $roles = null;
                $hasRoles = false;
            } elseif ($hasRoles) {
                $requestedRoles = array_values(array_filter((array) $roles, 'is_string'));
                $currentRoles = $targetUser->roles->pluck('name')->all();

                if (array_diff($requestedRoles, $currentRoles) === [] && array_diff($currentRoles, $requestedRoles) === []) {
                    // The edit form echoes the current roles back; an unchanged set is a no-op.
                    $roles = null;
                    $hasRoles = false;
                } else {
                    if (! $authUser->can('updateRoles', $targetUser)) {
                        abort(403, 'You are not allowed to change this user\'s roles.');
                    }
                    $this->userService->assertCanModifyTarget($authUser, $targetUser);
                    $this->userService->assertCanGrantRoles($authUser, $requestedRoles);
                }
            }

            if (! $isSelf) {
                if (array_key_exists('salary_amount', $validated)
                    && (float) $validated['salary_amount'] !== (float) $targetUser->salary_amount) {
                    if (! $authUser->hasRole(['Super Administrator', 'Administrator', 'HR Manager'])) {
                        abort(403, 'Only HR may change salary.');
                    }
                }
                if (array_key_exists('employee_id', $validated)
                    && (string) $validated['employee_id'] !== (string) $targetUser->employee_id
                    && ! $authUser->hasRole(['Super Administrator', 'Administrator'])) {
                    abort(403, 'Only an Administrator may change an employee ID.');
                }
            }

            $user = $this->userService->updateUser($id, $validated, $roles, $hasRoles, $profileImage);

            Log::info('User updated', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'User updated successfully',
                'user' => new UserResource($user),
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User update failed: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update user',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy($id)
    {
        try {
            $user = User::withTrashed()->findOrFail($id);
            $this->authorize('delete', $user);

            // Check for dependencies before deletion
            $dependencies = $this->checkUserDependencies($user);
            if (! empty($dependencies)) {
                return response()->json([
                    'error' => 'Cannot delete user with active dependencies',
                    'dependencies' => $dependencies,
                ], 422);
            }

            $this->userService->deleteUser($user);

            Log::info('User deleted', [
                'user_id' => $user->id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'User deleted successfully.',
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User deletion failed: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to delete user.',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Restore the specified soft-deleted user.
     */
    public function restore($id)
    {
        try {
            $user = User::withTrashed()->findOrFail($id);
            // Restore is the inverse of delete: it needs `users.delete` AND scope over the
            // target (UserPolicy::restore). `users.update` alone — which a department admin
            // holds — must not reinstate an offboarded employee.
            $this->authorize('restore', $user);
            $restoredUser = $this->userService->restoreUser($user);

            Log::info('User restored', [
                'user_id' => $restoredUser->id,
                'restored_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'User restored successfully.',
                'user' => new UserResource($restoredUser),
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User restoration failed: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to restore user',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Reset a user's password.
     */
    public function changePassword(Request $request, $id)
    {
        $request->validate([
            'password' => 'required|string|min:8|confirmed',
        ]);

        try {
            $user = User::findOrFail($id);

            if (! $this->scope()->canManage($request->user(), $user, allowSelf: true)) {
                abort(403, 'You cannot reset the password of a user outside your department scope.');
            }

            // Privilege-escalation guard: only someone who can manage super admins
            // (i.e. a Super Administrator) may reset a Super Administrator's password.
            if ($user->hasRole('Super Administrator') && ! auth()->user()->can('manage super admin')) {
                abort(403, 'You are not allowed to reset a Super Administrator password.');
            }

            $user->update([
                'password' => bcrypt($request->input('password')),
            ]);

            Log::info('Password changed for user', [
                'user_id' => $user->id,
                'user_name' => $user->name,
                'changed_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Password changed successfully',
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to change user password: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to change password',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Update user role via dedicated endpoint.
     */
    public function updateUserRole(UpdateUserRoleRequest $request, $id)
    {
        try {
            $user = User::findOrFail($id);
            $this->authorize('updateRoles', $user);
            $this->userService->assertCanModifyTarget($request->user(), $user);
            $this->userService->assertCanGrantRoles($request->user(), (array) $request->input('roles', []));
            $updatedUser = $this->userService->syncRoles($user, $request->input('roles'));

            Log::info('User roles updated via updateUserRole', [
                'user_id' => $user->id,
                'updated_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'Role updated successfully',
                'user' => new UserResource($updatedUser),
            ], 200);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update user role: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update user role.',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    public function bulkAssignRole(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|string|distinct|exists:users,employee_id',
            'role' => 'required|string|exists:roles,name',
        ]);

        $this->userService->assertCanGrantRoles($request->user(), [$validated['role']]);

        $users = User::query()
            ->whereIn('employee_id', $validated['user_ids'])
            ->orderBy('employee_id')
            ->get();

        foreach ($users as $user) {
            $this->authorize('updateRoles', $user);
            $this->userService->assertCanModifyTarget($request->user(), $user);
        }

        $count = DB::transaction(fn (): int => $this->userService->bulkAssignRole(
            $validated['user_ids'],
            $validated['role'],
        ));

        return response()->json([
            'message' => $validated['role'].' assigned to '.$count.' user(s).',
            'updated_count' => $count,
        ]);
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'user_ids' => 'required|array|min:1',
            'user_ids.*' => 'required|string|distinct|exists:users,employee_id',
        ]);

        $users = User::query()
            ->whereIn('employee_id', $validated['user_ids'])
            ->orderBy('employee_id')
            ->get();

        $blocked = [];
        foreach ($users as $user) {
            $this->authorize('delete', $user);
            $dependencies = $this->checkUserDependencies($user);
            if ($dependencies !== []) {
                $blocked[$user->employee_id] = $dependencies;
            }
        }

        if ($blocked !== []) {
            return response()->json([
                'message' => 'No users were deleted because one or more selected users have active dependencies.',
                'dependencies' => $blocked,
            ], 422);
        }

        DB::transaction(function () use ($users): void {
            foreach ($users as $user) {
                $this->userService->deleteUser($user);
            }
        });

        return response()->json([
            'message' => $users->count().' user(s) deleted.',
            'deleted_count' => $users->count(),
        ]);
    }

    /**
     * Update user report to manager.
     */
    public function updateReportTo(Request $request, $id)
    {
        $request->validate([
            'report_to' => 'nullable|exists:users,employee_id',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('update', $user);
            if ((string) $user->getKey() === (string) $request->user()->getKey() && ! $request->user()->hasRole('Super Administrator')) {
                abort(403, 'You cannot change your own reporting line.');
            }
            if (! $this->scope()->isGlobal($request->user())) {
                $this->assertReportToInScope($request->user(), $request->input('report_to'));
            }
            $updatedUser = $this->userService->updateReportTo($user, $request->input('report_to'));

            return response()->json([
                'message' => 'Report-to updated successfully',
                'user' => new UserResource($updatedUser),
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update report-to: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update report-to',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Update FCM registration token.
     */
    public function updateFcmToken(Request $request)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        try {
            $user = $request->user();
            $this->userService->updateFcmToken($user, $request->input('fcm_token'));

            return response()->json([
                'message' => 'FCM token updated successfully',
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update FCM token: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update FCM token',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Update user attendance type.
     */
    public function updateAttendanceType(Request $request, $id)
    {
        $request->validate([
            'attendance_type_id' => 'nullable|exists:attendance_types,id',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('updateAttendanceType', $user);
            $typeId = $request->input('attendance_type_id');
            $updatedUser = $this->userService->updateAttendanceType($user, $typeId !== null ? (int) $typeId : null);

            return response()->json([
                'message' => 'Attendance type updated successfully',
                'user' => new UserResource($updatedUser),
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update attendance type: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update attendance type',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Assign biometric device to employee.
     */
    public function assignBiometricDevice(Request $request, $id)
    {
        $request->validate([
            'biometric_device_id' => 'nullable|exists:biometric_devices,id',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('update', $user);
            $result = $this->userService->assignBiometricDevice($user, $request->input('biometric_device_id'));

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json([
                'error' => 'Invalid device assignment',
                'message' => $e->getMessage(),
            ], 422);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to assign biometric device: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to assign device',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Paginated list of users for admin panel.
     */
    public function paginate(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->only(['perPage', 'page', 'search', 'role', 'status', 'department']);
        $result = $this->userService->paginateUsers($filters, $request->user());

        return response()->json([
            'users' => new UserCollection($result['users']),
            'stats' => $result['stats'],
        ]);
    }

    /**
     * Paginated list of employees for employee list view.
     */
    public function employees(Request $request)
    {
        $this->authorize('viewAny', User::class);
        $filters = $request->only(['perPage', 'page', 'search', 'department', 'designation', 'attendanceType', 'role', 'status', 'showDeleted']);
        $result = $this->userService->paginateEmployees($filters, $request->user());

        return response()->json([
            'employees' => $result['employees'],
            'stats' => $result['stats'],
            'allManagers' => $result['allManagers'],
        ]);
    }

    /**
     * Return one employee for edit/detail experiences that load records lazily.
     */
    public function show(Request $request, string $id)
    {
        $query = User::with([
            'department:id,name',
            'designation:id,title',
            'roles:id,name',
            'reportsTo:employee_id,name',
            'attendanceTypes:id,name,slug',
        ]);
        $actor = $request->user();
        $user = $query->find($id);
        // Out-of-scope reads as not-found so the directory cannot probe for existence.
        if ($user === null || ! $this->scope()->canActOn($actor, $user, allowSelf: true)) {
            abort(404);
        }

        return response()->json([
            // Directory access is not access to private profile/device details.
            'employee' => [
                'id' => (string) $user->getKey(),
                'employee_id' => $user->employee_id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'profile_image_url' => $user->profile_image_url,
                'department' => $user->department,
                'designation' => $user->designation,
                'roles' => $user->roles->pluck('name'),
                'report_to' => $user->report_to,
                'reports_to' => $user->reportsTo,
                'attendance_types' => $user->attendanceTypes->map(fn ($type) => $type->only(['id', 'name', 'slug'])),
                'work_location_id' => $user->work_location_id,
            ],
        ]);
    }

    /**
     * Get statistics for user management dashboard.
     */
    public function stats(Request $request)
    {
        $this->authorize('viewAny', User::class);
        try {
            $stats = $this->userService->getUserStats($request->user());

            return response()->json(['stats' => $stats]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get user stats: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to get stats',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Get employee demographics and retention statistics.
     */
    public function employeeStats(Request $request)
    {
        $this->authorize('viewAny', User::class);
        try {
            $stats = $this->userService->getEmployeeStats($request->user());

            return response()->json(['stats' => $stats]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get employee stats: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to get stats',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Get user roles.
     */
    public function getUserRoles($id)
    {
        try {
            $user = User::findOrFail($id);
            $this->authorize('view', $user);
            $rolesData = $this->userService->getUserRoles($user);

            return response()->json($rolesData);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get user roles: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to retrieve roles',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Get user direct and role permissions.
     */
    public function getUserPermissions($id)
    {
        try {
            $user = User::findOrFail($id);
            $this->authorize('view', $user);
            $permsData = $this->userService->getUserPermissions($user);

            return response()->json($permsData);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get user permissions: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to retrieve permissions',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Sync user roles.
     */
    public function syncUserRoles(Request $request, $id)
    {
        $request->validate([
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('updateRoles', $user);
            $this->userService->assertCanModifyTarget($request->user(), $user);
            $this->userService->assertCanGrantRoles($request->user(), $request->input('roles'));
            $updatedUser = $this->userService->syncUserRoles($user, $request->input('roles'));

            return response()->json([
                'message' => 'User roles updated successfully',
                'user' => new UserResource($updatedUser),
            ]);
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to sync user roles: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to sync roles',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Sync direct user permissions.
     */
    public function syncUserPermissions(Request $request, $id)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'string|exists:permissions,name',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('updateRoles', $user);
            $this->userService->assertCanModifyTarget($request->user(), $user);
            $result = $this->userService->syncUserPermissions($user, $request->input('permissions'));

            return response()->json(array_merge([
                'message' => 'User permissions updated successfully',
            ], $result));
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to sync user permissions: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to sync permissions',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Grant a single direct permission to user.
     */
    public function giveUserPermission(Request $request, $id)
    {
        $request->validate([
            'permission' => 'required|string|exists:permissions,name',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('updateRoles', $user);
            $this->userService->assertCanModifyTarget($request->user(), $user);
            $result = $this->userService->giveUserPermission($user, $request->input('permission'));

            if ($result === null) {
                return response()->json([
                    'message' => 'User already has this permission directly',
                ], 200);
            }

            return response()->json(array_merge([
                'message' => 'Permission granted successfully',
            ], $result));
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to grant user permission: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to grant permission',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Revoke direct permission from user.
     */
    public function revokeUserPermission(Request $request, $id)
    {
        $request->validate([
            'permission' => 'required|string|exists:permissions,name',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('updateRoles', $user);
            $this->userService->assertCanModifyTarget($request->user(), $user);
            $result = $this->userService->revokeUserPermission($user, $request->input('permission'));

            if ($result === null) {
                return response()->json([
                    'message' => 'User does not have this permission directly',
                ], 200);
            }

            return response()->json(array_merge([
                'message' => 'Permission revoked successfully',
            ], $result));
        } catch (HttpException $e) {
            throw $e;
        } catch (AuthorizationException $e) {
            throw $e;
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to revoke user permission: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to revoke permission',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Admin-side write on another employee: in scope and outranked. Only a Super
     * Administrator may use these admin endpoints on their own record (mirrors
     * SELF_PROTECTED_FIELDS in update()).
     */
    private function canManageOther(User $actor, User $target): bool
    {
        return $this->scope()->canManage($actor, $target, allowSelf: $actor->hasRole('Super Administrator'));
    }

    /**
     * The department a non-global actor may create an employee in: the requested one
     * when they administer it, else their single administered department. Throws a
     * 422 when the request is ambiguous or out of scope, 403 when they administer none.
     */
    private function resolveCreatableDepartment(User $actor, mixed $requested): int
    {
        $managed = $this->scope()->managedDepartmentIds($actor);

        if ($managed === []) {
            abort(403, 'You do not administer any department.');
        }

        if ($requested === null || $requested === '') {
            if (count($managed) === 1) {
                return $managed[0];
            }

            throw ValidationException::withMessages([
                'department_id' => 'Select one of the departments you manage.',
            ]);
        }

        if (! in_array((int) $requested, $managed, true)) {
            throw ValidationException::withMessages([
                'department_id' => 'You can only add employees to a department you manage.',
            ]);
        }

        return (int) $requested;
    }

    /**
     * A non-global actor may only point a reporting line at someone in their own
     * scope (or themselves) — never graft an employee under an outside manager.
     */
    private function assertReportToInScope(User $actor, mixed $reportTo): void
    {
        if ($reportTo === null || $reportTo === '') {
            return;
        }

        if (! $this->scope()->canActOn($actor, (string) $reportTo, allowSelf: true)) {
            throw ValidationException::withMessages([
                'report_to' => 'The reporting manager must be within your department scope.',
            ]);
        }
    }

    /**
     * Check for user dependencies before deletion
     */
    private function checkUserDependencies(User $user): array
    {
        $dependencies = [];

        // Check for active projects (if the table exists)
        try {
            if (Schema::hasTable('project_members')) {
                $activeProjects = DB::table('project_members')
                    ->join('projects', 'project_members.project_id', '=', 'projects.id')
                    ->where('project_members.user_id', $user->id)
                    ->where('projects.status', 'active')
                    ->count();

                if ($activeProjects > 0) {
                    $dependencies['active_projects'] = $activeProjects;
                }
            }
        } catch (\Exception $e) {
            // Table doesn't exist, skip this check
        }

        // Check for pending leaves
        try {
            if (Schema::hasTable('leaves')) {
                $pendingLeaves = DB::table('leaves')
                    ->where('user_id', $user->id)
                    ->where('status', 'pending')
                    ->count();

                if ($pendingLeaves > 0) {
                    $dependencies['pending_leaves'] = $pendingLeaves;
                }
            }
        } catch (\Exception $e) {
            // Table doesn't exist, skip this check
        }

        // Check for active trainings
        try {
            if (Schema::hasTable('training_enrollments')) {
                $activeTrainings = DB::table('training_enrollments')
                    ->join('trainings', 'training_enrollments.training_id', '=', 'trainings.id')
                    ->where('training_enrollments.user_id', $user->id)
                    ->where('trainings.status', 'active')
                    ->count();

                if ($activeTrainings > 0) {
                    $dependencies['active_trainings'] = $activeTrainings;
                }
            }
        } catch (\Exception $e) {
            // Table doesn't exist, skip this check
        }

        return $dependencies;
    }

    /**
     * Update work location of a user.
     */
    public function updateWorkLocation(Request $request, $id)
    {
        try {
            $request->validate([
                'work_location_id' => 'nullable|exists:work_locations,id',
            ]);

            $user = User::findOrFail($id);
            if (! $this->canManageOther($request->user(), $user)) {
                abort(403, 'You cannot update users outside your department scope.');
            }
            $user->update([
                'work_location_id' => $request->work_location_id,
            ]);

            return response()->json([
                'message' => 'Work location updated successfully',
                'user' => $user->fresh(['workLocation']),
            ]);
        } catch (HttpException|ValidationException|HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update work location: '.$e->getMessage());

            return response()->json([
                'error' => 'Failed to update work location',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }

    /**
     * Confirm an employee upon successful probation completion (BLA s.4(4)).
     */
    public function confirmEmployee(Request $request, $id): JsonResponse
    {
        $user = User::findOrFail($id);
        if (! $this->canManageOther($request->user(), $user)) {
            abort(403, 'You cannot confirm employees outside your department scope.');
        }
        $user->update([
            'employment_status' => 'confirmed',
            'confirmation_date' => now()->toDateString(),
            'probation_notes' => $request->input('notes', 'Probation successfully completed and confirmed as permanent staff.'),
        ]);

        return response()->json([
            'message' => "Employee {$user->name} has been confirmed as permanent staff.",
            'user' => $user->fresh(['department', 'designation']),
        ]);
    }
}
