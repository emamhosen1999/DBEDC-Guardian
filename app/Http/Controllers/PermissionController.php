<?php

namespace App\Http\Controllers;

use App\Http\Responses\ApiResponse;
use App\Services\Role\RolePermissionService;
use App\Traits\HandlesApiExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission Controller
 *
 * Handles CRUD operations for permissions via API
 * Implements proper authorization checks using Spatie Permission
 */
class PermissionController extends Controller
{
    use ApiResponse;
    use HandlesApiExceptions;

    private RolePermissionService $rolePermissionService;

    public function __construct(RolePermissionService $rolePermissionService)
    {
        $this->rolePermissionService = $rolePermissionService;
        $this->middleware('auth');
    }

    /**
     * List all permissions
     */
    public function index(Request $request)
    {
        try {
            if (! Auth::user()->can('permissions.view')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            $query = Permission::query();

            if ($request->has('search')) {
                $search = $request->input('search');
                $query->where('name', 'like', "%{$search}%");
            }

            if ($request->has('module')) {
                $module = $request->input('module');
                $query->where('name', 'like', "{$module}.%");
            }

            $permissions = $query->orderBy('name')->get();

            return $this->successResponse([
                'permissions' => $permissions,
                'total' => $permissions->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to list permissions: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to retrieve permissions.'),
                'PERMISSIONS_LIST_FAILED',
                500
            );
        }
    }

    /**
     * Get permissions grouped by module
     */
    public function groupedByModule()
    {
        try {
            if (! Auth::user()->can('permissions.view')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            return $this->successResponse([
                'permissionsGrouped' => $this->rolePermissionService->getPermissionsGroupedByModule(),
                'enterprise_modules' => $this->rolePermissionService->getEnterpriseModules(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to get grouped permissions: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to retrieve grouped permissions.'),
                'PERMISSIONS_GROUPED_FAILED',
                500
            );
        }
    }

    /**
     * Store a new permission
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:permissions,name',
            'guard_name' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            if (! Auth::user()->can('permissions.create')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            $permission = Permission::create([
                'name' => $request->name,
                'guard_name' => $request->guard_name ?? 'web',
            ]);

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission created', [
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'created_by' => Auth::id(),
            ]);

            return $this->successResponse(
                ['permission' => $permission],
                'Permission created successfully',
                201
            );
        } catch (\Exception $e) {
            Log::error('Failed to create permission: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to create permission.'),
                'PERMISSION_CREATE_FAILED',
                500
            );
        }
    }

    /**
     * Show a specific permission
     */
    public function show($id)
    {
        try {
            if (! Auth::user()->can('permissions.view')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            $permission = Permission::with('roles')->findById($id);

            if (! $permission) {
                return $this->notFoundResponse('Permission not found');
            }

            return $this->successResponse([
                'permission' => $permission,
                'roles' => $permission->roles->pluck('name'),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to show permission: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to retrieve permission.'),
                'PERMISSION_SHOW_FAILED',
                500
            );
        }
    }

    /**
     * Update a permission
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:permissions,name,'.$id,
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            if (! Auth::user()->can('permissions.update')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            $permission = Permission::findById($id);

            if (! $permission) {
                return $this->notFoundResponse('Permission not found');
            }

            $oldName = $permission->name;
            $permission->name = $request->name;
            $permission->save();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission updated', [
                'permission_id' => $permission->id,
                'old_name' => $oldName,
                'new_name' => $permission->name,
                'updated_by' => Auth::id(),
            ]);

            return $this->successResponse(
                ['permission' => $permission],
                'Permission updated successfully'
            );
        } catch (\Exception $e) {
            Log::error('Failed to update permission: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to update permission.'),
                'PERMISSION_UPDATE_FAILED',
                500
            );
        }
    }

    /**
     * Delete a permission
     */
    public function destroy($id)
    {
        try {
            if (! Auth::user()->can('permissions.delete')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            $permission = Permission::findById($id);

            if (! $permission) {
                return $this->notFoundResponse('Permission not found');
            }

            $rolesCount = $permission->roles()->count();
            if ($rolesCount > 0) {
                return $this->errorResponse(
                    "Cannot delete permission. It is assigned to {$rolesCount} role(s).",
                    'PERMISSION_IN_USE',
                    409
                );
            }

            $permissionName = $permission->name;
            $permission->delete();

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            Log::warning('Permission deleted', [
                'permission_name' => $permissionName,
                'deleted_by' => Auth::id(),
            ]);

            return $this->successResponse(null, 'Permission deleted successfully');
        } catch (\Exception $e) {
            Log::error('Failed to delete permission: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to delete permission.'),
                'PERMISSION_DELETE_FAILED',
                500
            );
        }
    }

    /**
     * Sync roles for a permission
     */
    public function syncRoles(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'roles' => 'required|array',
            'roles.*' => 'string|exists:roles,name',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors()->toArray());
        }

        try {
            if (! Auth::user()->can('permissions.update')) {
                return $this->forbiddenResponse('Unauthorized');
            }

            $permission = Permission::findById($id);

            if (! $permission) {
                return $this->notFoundResponse('Permission not found');
            }

            $permission->syncRoles($request->roles);

            app()[PermissionRegistrar::class]->forgetCachedPermissions();

            Log::info('Permission roles synced', [
                'permission_id' => $permission->id,
                'permission_name' => $permission->name,
                'roles' => $request->roles,
                'synced_by' => Auth::id(),
            ]);

            return $this->successResponse(
                ['permission' => $permission->fresh('roles')],
                'Permission roles synced successfully'
            );
        } catch (\Exception $e) {
            Log::error('Failed to sync permission roles: '.$e->getMessage());

            return $this->errorResponse(
                $this->safeExceptionMessage($e, 'Failed to sync permission roles.'),
                'PERMISSION_ROLES_SYNC_FAILED',
                500
            );
        }
    }
}
