<?php

namespace App\Http\Controllers;

use App\Traits\HandlesApiExceptions;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Http\Resources\UserCollection;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\UserManagementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class UserController extends Controller
{
    use HandlesApiExceptions;

    protected UserManagementService $userService;

    public function __construct(UserManagementService $userService)
    {
        $this->userService = $userService;
    }

    public function index1(): \Inertia\Response
    {
        $pageData = $this->userService->getEmployeeListPageData();
        return Inertia::render('Employees/EmployeeList', array_merge([
            'title' => 'Employee Management'
        ], $pageData));
    }

    public function adminUnified(): \Inertia\Response
    {
        return $this->index2();
    }

    public function index2(): \Inertia\Response
    {
        $this->authorize('viewAny', User::class);
        $pageData = $this->userService->getAdminUnifiedPageData();
        return Inertia::render('AdminUnified', array_merge([
            'title' => 'User/Role Management',
            'can_manage_super_admin' => auth()->user()->can('manage super admin'),
        ], $pageData));
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User creation failed: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User update failed: ' . $e->getMessage());
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
            $this->userService->deleteUser($user);

            Log::info('User deleted', [
                'user_id' => $user->id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'message' => 'User deleted successfully.',
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User deletion failed: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('User restoration failed: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to change user password: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update user role: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update report-to: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update FCM token: ' . $e->getMessage());
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
            'attendance_type_id' => 'required|exists:attendance_types,id',
        ]);

        try {
            $user = User::findOrFail($id);
            $this->authorize('updateAttendanceType', $user);
            $updatedUser = $this->userService->updateAttendanceType($user, $request->input('attendance_type_id'));

            return response()->json([
                'message' => 'Attendance type updated successfully',
                'user' => new UserResource($updatedUser),
            ]);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to update attendance type: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to assign biometric device: ' . $e->getMessage());
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
        $filters = $request->only(['perPage', 'page', 'search', 'department', 'designation', 'attendanceType']);
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get user stats: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get employee stats: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get user roles: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to get user permissions: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to sync user roles: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to sync user permissions: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to grant user permission: ' . $e->getMessage());
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
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            throw $e;
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            throw $e;
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('Failed to revoke user permission: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to revoke permission',
                'message' => $this->safeExceptionMessage($e),
            ], 500);
        }
    }
}
