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
use App\Services\Access\ReportingManagerCandidates;
use App\Services\Access\SelfAdministration;
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

    /**
     * Fields on the employee edit/create form, grouped by the permission that governs them. A
     * group the actor may not change is dropped when unchanged (the form echoes every field back)
     * and refused (403) when it would change something.
     */
    private const PLACEMENT_FIELDS = ['designation_id', 'report_to', 'work_location_id'];

    private const ATTENDANCE_CONFIG_FIELDS = ['attendance_type_id', 'attendance_type_ids', 'biometric_device_ids'];

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
        $visibleDeptIds = $this->scope()->visibleDepartmentIds($authUser);

        $departmentsQuery = Department::select('id', 'name', 'code', 'parent_id', 'is_active', 'default_roles');
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

        // Work locations are company reference data (every actor needs them to place staff),
        // with the terminals linked to each one so the device picker can offer them by location.
        $workLocations = WorkLocation::with(['attendanceType', 'attendanceTypes:id,name,slug', 'biometricDevices:id,name,serial_number,location,is_active'])->get();

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

        // 5. Biometric terminals are infrastructure reference data, not employee data: every actor
        // who can place staff needs the active ones to pick from (the picker prefers the ones
        // linked to the chosen work location and falls back to all of these). Scoping them to the
        // locations of EXISTING in-scope staff left a department with no staff yet — or none at a
        // given site — with an empty list. Only picker fields are sent, never connection secrets.
        $biometricDevices = BiometricDevice::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'serial_number', 'location']);

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

            // Biometric terminals (picker reference data) + the in-scope people
            'biometricDevices' => $biometricDevices,
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
                // A department admin registers people only into a department they administer
                // (defaulting when they administer one) and under a manager inside his scope.
                $validated['department_id'] = $this->resolveCreatableDepartment($authUser, $validated['department_id'] ?? null);
                $this->assertReportToInScope($authUser, $validated['report_to'] ?? null, isset($validated['employee_id']) ? (string) $validated['employee_id'] : null);
            }

            if (! $authUser->can('employees.access.manage')) {
                // No role-management right: every employee he creates is a plain base-role Employee,
                // whatever the client sent. Nothing else can be minted from here. Null roles makes the
                // service assign Employee + the department's default functional roles (server-enforced).
                $roles = null;
            } elseif ($roles) {
                // Role managers may create staff, but never a role equal to or more powerful
                // than their own (an HR Manager must not mint an Administrator) — the same
                // hierarchy rule the role endpoints enforce.
                $this->userService->assertCanGrantRoles($authUser, (array) $roles);
            }

            $validated = $this->stripUnpermittedCreateFields($authUser, $validated);

            // No employee without a way to check in: an explicit method or the work location's default.
            $this->userService->assertAttendanceMethodResolvable($validated);

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
            $targetUser = User::findOrFail($id);
            $isSelf = (string) $authUser->employee_id === (string) $targetUser->employee_id;

            $selfAdmin = $isSelf && app(SelfAdministration::class)->allows($authUser, $targetUser);
            $selfAdminBefore = [];

            if ($isSelf && ! $selfAdmin) {
                // Self-service edits must never touch role, pay, org placement or identity
                // fields. A Super Administrator is exempt for non-role fields only.
                $roles = null;
                $hasRoles = false;
                if (! $authUser->hasRole('Super Administrator')) {
                    $validated = Arr::except($validated, self::SELF_PROTECTED_FIELDS);
                }
            } else {
                if ($selfAdmin) {
                    // Governed exception: job fields follow the same per-group gates as for his staff; identity
                    // and escalation fields stay closed (roles are nulled below, employee_id is Administrator-only).
                    $validated = Arr::except($validated, ['date_of_joining']);
                    foreach (Arr::except($validated, ['roles']) as $field => $value) {
                        if ($this->fieldChanged($targetUser, $field, $value)) {
                            $selfAdminBefore[$field] = [$field === 'password' ? '***' : $targetUser->{$field} ?? null, $field === 'password' ? '***' : $value];
                        }
                    }
                }
                // UpdateUserRequest already proved employees.update AND scope over the target AND AND
                // (for a non-global actor) that he outranks it. Each field group below needs its
                // own permission on top: a group he may not change is dropped when unchanged (the
                // form echoes every field back) and refused when it would change something.
                if (array_key_exists('department_id', $validated)
                    && (int) $validated['department_id'] !== (int) $targetUser->department_id
                    && ! $authUser->can('transfer', [$targetUser, (int) $validated['department_id']])) {
                    abort(403, 'You can only move employees between departments you manage.');
                }

                $validated = $this->gateFieldGroup($authUser, $targetUser, $validated, self::PLACEMENT_FIELDS, 'updatePlacement', 'designation, reporting line and work location');
                if (array_key_exists('report_to', $validated)
                    && (string) $validated['report_to'] !== (string) $targetUser->report_to
                    && ! $this->scope()->isGlobal($authUser)) {
                    $this->assertReportToInScope($authUser, $validated['report_to'], (string) $targetUser->employee_id);
                }
                $validated = $this->gateFieldGroup($authUser, $targetUser, $validated, self::ATTENDANCE_CONFIG_FIELDS, 'updateAttendanceConfig', 'attendance method and devices');
                $validated = $this->gateFieldGroup($authUser, $targetUser, $validated, ['salary_amount'], 'updateCompensation', 'salary');
                $validated = $this->gateFieldGroup($authUser, $targetUser, $validated, ['single_device_login_enabled'], 'manageDevices', 'device lock');
                $validated = $this->gateFieldGroup($authUser, $targetUser, $validated, ['password'], 'resetPassword', 'password');

                if (array_key_exists('employee_id', $validated)
                    && (string) $validated['employee_id'] !== (string) $targetUser->employee_id
                    && ! $authUser->hasRole(['Super Administrator', 'Administrator'])) {
                    abort(403, 'Only an Administrator may change an employee ID.');
                }

                if (! $authUser->can('employees.access.manage') || ! $this->scope()->isGlobal($authUser)) {
                    // No role-management right (a department admin), or not a company-wide actor: the
                    // server ignores roles on a profile edit — it must never demote a Team Lead on a
                    // phone-number change. (Role changes go through the dedicated role endpoints.)
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
            }

            $user = $this->userService->updateUser($id, $validated, $roles, $hasRoles, $profileImage);

            if ($selfAdmin) {
                $changed = array_intersect_key($selfAdminBefore, $validated);
                if ($changed !== []) {
                    app(SelfAdministration::class)->record($authUser, 'employee.update', 'updated his own '.implode(', ', array_keys($changed)), 'user', $targetUser->employee_id, $changed);
                }
            }

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

            // employees.password.reset AND scope over the target AND (non-global) outranking it —
            // never one's own: change that through the profile.
            $this->authorize('resetPassword', $user);

            // Privilege-escalation guard: only someone who can manage super admins
            // (i.e. a Super Administrator) may reset a Super Administrator's password.
            if ($user->hasRole('Super Administrator') && ! auth()->user()->can('manage super admin')) {
                abort(403, 'You are not allowed to reset a Super Administrator password.');
            }

            // An admin knows this password: the employee must replace it at next sign-in.
            $user->update([
                'password' => bcrypt($request->input('password')),
            ]);
            $user->forceFill(['must_change_password' => true])->save();

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
            // employees.placement.update + scope + outranking; never one's own reporting line.
            $this->authorize('updatePlacement', $user);
            if (! $this->scope()->isGlobal($request->user())) {
                $this->assertReportToInScope($request->user(), $request->input('report_to'), (string) $user->employee_id);
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
            $this->authorize('updateAttendanceConfig', $user);
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
            $this->authorize('updateAttendanceConfig', $user);
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
            // Nobody can delegate a permission they do not hold themselves.
            $this->userService->assertCanDelegatePermissions(
                $request->user(),
                array_diff($request->input('permissions'), $user->getDirectPermissions()->pluck('name')->all())
            );
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
            // Nobody can delegate a permission they do not hold themselves.
            $this->userService->assertCanDelegatePermissions($request->user(), [$request->input('permission')]);
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
     * Enforce one permission group of the edit form (see PLACEMENT_FIELDS). An actor holding the
     * ability passes untouched; one without it may resubmit the unchanged values (the form echoes
     * every field) but never change them.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    private function gateFieldGroup(User $actor, User $target, array $validated, array $fields, string $ability, string $label): array
    {
        $present = array_values(array_intersect($fields, array_keys($validated)));

        if ($present === [] || $actor->can($ability, $target)) {
            return $validated;
        }

        foreach ($present as $field) {
            if ($this->fieldChanged($target, $field, $validated[$field])) {
                abort(403, "You are not allowed to change this employee's {$label}.");
            }
        }

        return Arr::except($validated, $present);
    }

    /** Would writing $value to $field change the employee's stored value? */
    private function fieldChanged(User $target, string $field, mixed $value): bool
    {
        $ids = fn (iterable $list): array => collect($list)->filter(fn ($id) => $id !== null && $id !== '')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();

        return match ($field) {
            'attendance_type_ids' => $ids((array) $value) !== $ids($target->attendanceTypes()->pluck('attendance_types.id')->all()),
            'biometric_device_ids' => $ids((array) $value) !== $ids($target->biometricDevices()->pluck('biometric_devices.id')->all()),
            'attendance_type_id' => (string) ($value ?? '') !== (string) ($target->getRawOriginal('attendance_type_id') ?? ''),
            'single_device_login_enabled' => filter_var($value, FILTER_VALIDATE_BOOLEAN) !== (bool) $target->single_device_login_enabled,
            'salary_amount' => (float) ($value ?? 0) !== (float) ($target->salary_amount ?? 0),
            'password' => $value !== null && $value !== '',
            default => is_array($value)
                ? json_encode($value) !== json_encode($target->{$field} ?? null)
                : (string) ($value ?? '') !== (string) ($target->{$field} ?? ''),
        };
    }

    /**
     * On create a field group the actor may not set is simply left out (the create form always
     * sends every field): placement, attendance method/devices, device lock and salary each need
     * their own permission, exactly as on update.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function stripUnpermittedCreateFields(User $actor, array $validated): array
    {
        $groups = [
            'employees.placement.update' => self::PLACEMENT_FIELDS,
            'employees.attendance-config.update' => self::ATTENDANCE_CONFIG_FIELDS,
            'employees.devices.manage' => ['single_device_login_enabled'],
            'employees.compensation.update' => ['salary_amount'],
        ];

        foreach ($groups as $permission => $fields) {
            if (! $actor->can($permission)) {
                $validated = Arr::except($validated, $fields);
            }
        }

        return $validated;
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
     * The reporting manager must be in the set this actor may choose from (ReportingManagerCandidates):
     * their scope plus every department head. Anyone else is refused - never grafting an employee under
     * a manager outside what the actor may see.
     */
    private function assertReportToInScope(User $actor, mixed $reportTo, ?string $employeeId = null): void
    {
        if ($reportTo === null || $reportTo === '') {
            return;
        }

        if (! app(ReportingManagerCandidates::class)->allows($actor, $employeeId, (string) $reportTo)) {
            throw ValidationException::withMessages([
                'report_to' => 'The selected reporting manager is not available to you.',
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
            // employees.placement.update + scope + outranking; never one's own work location.
            $this->authorize('updatePlacement', $user);
            $user->update([
                'work_location_id' => $request->work_location_id,
            ]);

            return response()->json([
                'message' => 'Work location updated successfully',
                'user' => $user->fresh(['workLocation']),
            ]);
        } catch (HttpException|ValidationException|HttpResponseException|AuthorizationException $e) {
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
