<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\UserManagementService;
use App\Traits\HandlesApiExceptions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class UserController extends Controller
{
    use HandlesApiExceptions;

    protected UserManagementService $userService;

    public function __construct(UserManagementService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);

        // 1. Workforce & Organization base data
        $departments = Department::select('id', 'name', 'code', 'parent_id', 'is_active')->get();
        $designations = Designation::select('id', 'title', 'department_id', 'hierarchy_level', 'parent_id', 'is_active')
            ->with('department:id,name') // department_name is appended in Designation::toArray(); avoid lazy-load violation
            ->orderBy('hierarchy_level', 'asc')
            ->get();
        
        $roles = Role::with('permissions')->get();

        $attendanceTypes = AttendanceType::select('id', 'name', 'slug', 'config', 'is_active')
            ->with(['biometricDevices:id,name,serial_number,location'])
            ->get();

        $activeUsers = User::select('id', 'name', 'email', 'department_id', 'designation_id')
            ->whereNull('deleted_at')
            ->get();

        $workLocations = \App\Models\WorkLocation::with('attendanceType')->get();

        // 2. Department Tab Stats & Pagination
        $parentDepartments = $departments->whereNull('parent_id')->values();
        $departmentStats = [
            'total' => $departments->count(),
            'active' => $departments->where('is_active', true)->count(),
            'inactive' => $departments->where('is_active', false)->count(),
            'parent_departments' => $parentDepartments->count(),
        ];
        $initialDepartments = Department::with(['manager:id,name,email', 'parent:id,name'])
            ->withCount('employees')
            ->paginate(10);

        // 3. Designation Tab Stats & Pagination
        $designationStats = [
            'total' => $designations->count(),
            'active' => $designations->where('is_active', true)->count(),
            'inactive' => $designations->where('is_active', false)->count(),
            'parent_designations' => $designations->whereNull('parent_id')->count(),
        ];
        $initialDesignations = Designation::with('department:id,name')
            ->withCount(['users as employee_count'])
            ->paginate(10);

        $overviewStats = [
            'total_employees' => $activeUsers->count(),
            'total_departments' => $departments->count(),
            'total_designations' => $designations->count(),
            'total_locations' => $workLocations->count(),
        ];

        // 4. Admin - Roles & Permissions data
        $permissions = Permission::all();
        $roleHasPermissions = DB::table('role_has_permissions')->get();
        $permissionsGrouped = Permission::all()->groupBy('module')
            ->map(fn ($perms, $module) => [
                'label' => $module,
                'permissions' => $perms->values(),
            ]);

        // 5. Admin - Biometric Devices data
        $devices = BiometricDevice::all();

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
            $this->authorize('update', $user);
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

    /**
     * Update user report to manager.
     */
    public function updateReportTo(Request $request, $id)
    {
        $request->validate([
            'report_to' => 'nullable|exists:users,id',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('update', $user);
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
    public function updateFcmToken(Request $request, $id)
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        try {
            $user = User::findOrFail($id);
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
        $result = $this->userService->paginateUsers($filters);

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
        $result = $this->userService->paginateEmployees($filters);

        return response()->json([
            'employees' => $result['employees'],
            'stats' => $result['stats'],
            'allManagers' => $result['allManagers'],
        ]);
    }

    /**
     * Get statistics for user management dashboard.
     */
    public function stats()
    {
        $this->authorize('viewAny', User::class);
        try {
            $stats = $this->userService->getUserStats();

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
    public function employeeStats()
    {
        $this->authorize('viewAny', User::class);
        try {
            $stats = $this->userService->getEmployeeStats();

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
            $user->update([
                'work_location_id' => $request->work_location_id,
            ]);

            return response()->json([
                'message' => 'Work location updated successfully',
                'user' => $user->fresh(['workLocation']),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
