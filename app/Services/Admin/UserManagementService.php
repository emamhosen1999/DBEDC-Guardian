<?php

namespace App\Services\Admin;

use App\Models\HRM\AttendanceType;
use App\Models\HRM\BiometricDevice;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\EmployeeAttendanceType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class UserManagementService
{
    // ──────────────────────────────────────────────
    //  Page data helpers (Inertia props)
    // ──────────────────────────────────────────────

    /**
     * Build props for the employee list page.
     */
    public function getEmployeeListPageData(): array
    {
        return [
            'departments'     => Department::all(),
            'designations'    => Designation::all(),
            'attendanceTypes' => AttendanceType::where('is_active', true)->get(),
        ];
    }

    /**
     * Build props for the admin unified page.
     */
    public function getAdminUnifiedPageData(): array
    {
        return [
            // Users panel
            'roles'                => Role::with('permissions')->get(),
            'departments'          => Department::all(),
            'designations'         => Designation::with('department')->orderBy('hierarchy_level', 'asc')->get(),

            // Roles & Permissions panel
            'permissions'          => Permission::all(),
            'role_has_permissions' => DB::table('role_has_permissions')->get(),
            'permissionsGrouped'   => Permission::all()->groupBy('module')
                ->map(fn ($perms, $module) => [
                    'label'       => $module,
                    'permissions' => $perms->values(),
                ]),

            // Biometric panel
            'devices'         => BiometricDevice::all(),
            'employees'       => User::select('id', 'name', 'employee_id', 'department_id', 'designation_id')->get(),
            'attendanceTypes' => AttendanceType::where('is_active', true)->select('id', 'name', 'slug')->get(),
        ];
    }

    // ──────────────────────────────────────────────
    //  User CRUD
    // ──────────────────────────────────────────────

    /**
     * Create a new user from validated data.
     *
     * @return User  The freshly created (and eagerly-loaded) user.
     */
    public function createUser(array $validated, ?array $roles, $profileImage = null): User
    {
        return DB::transaction(function () use ($validated, $roles, $profileImage) {
            unset($validated['profile_image']);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            if (isset($validated['single_device_login_enabled'])) {
                $validated['single_device_login_enabled'] = filter_var(
                    $validated['single_device_login_enabled'],
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            $user = User::create($validated);

            if ($roles) {
                $user->syncRoles($roles);
            } else {
                $user->assignRole('Employee');
            }

            if ($profileImage) {
                $user->addMedia($profileImage)->toMediaCollection('profile_images');
            }

            return $user->fresh(['department', 'designation', 'roles', 'currentDevice']);
        });
    }

    /**
     * Update an existing user from validated data.
     *
     * @return User  The freshly loaded user.
     */
    public function updateUser(int $id, array $validated, ?array $roles, bool $hasRoles, $profileImage = null): User
    {
        return DB::transaction(function () use ($id, $validated, $roles, $hasRoles, $profileImage) {
            $user = User::findOrFail($id);

            unset($validated['profile_image']);

            if (isset($validated['password'])) {
                $validated['password'] = Hash::make($validated['password']);
            }

            if (isset($validated['single_device_login_enabled'])) {
                $validated['single_device_login_enabled'] = filter_var(
                    $validated['single_device_login_enabled'],
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            $user->update($validated);

            if ($hasRoles) {
                $user->syncRoles($roles);
            }

            if ($profileImage) {
                $user->clearMediaCollection('profile_images');
                $user->addMedia($profileImage)->toMediaCollection('profile_images');
            }

            return $user->fresh(['department', 'designation', 'roles', 'currentDevice']);
        });
    }

    /**
     * Soft-delete a user.
     */
    public function deleteUser(User $user): void
    {
        $user->delete();
    }

    /**
     * Restore a soft-deleted user.
     */
    public function restoreUser(User $user): User
    {
        $user->restore();

        return $user->fresh(['department', 'designation', 'roles', 'currentDevice']);
    }

    // ──────────────────────────────────────────────
    //  Role management
    // ──────────────────────────────────────────────

    /**
     * Sync roles on a user and return the refreshed model.
     */
    public function syncRoles(User $user, array $roles): User
    {
        $user->syncRoles($roles);

        return $user->fresh(['department', 'designation', 'roles', 'currentDevice']);
    }

    /**
     * Bulk-assign a role to multiple users.
     *
     * @return int Number of users processed.
     */
    public function bulkAssignRole(array $userIds, string $role): int
    {
        $users = User::whereIn('id', $userIds)->get();
        $count = 0;

        foreach ($users as $user) {
            if (! $user->hasRole($role)) {
                $user->assignRole($role);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Bulk soft-delete users.
     *
     * @return int Number of deleted users.
     */
    public function bulkDelete(array $userIds): int
    {
        return DB::transaction(function () use ($userIds) {
            return User::whereIn('id', $userIds)->delete();
        });
    }

    // ──────────────────────────────────────────────
    //  Report-to / FCM / Attendance-type updates
    // ──────────────────────────────────────────────

    /**
     * Update the report_to field for a user.
     */
    public function updateReportTo(User $user, ?int $reportTo): User
    {
        $user->report_to = $reportTo;
        $user->save();

        return $user->fresh(['department', 'designation', 'roles', 'currentDevice', 'reportsTo']);
    }

    /**
     * Update a user's FCM token.
     */
    public function updateFcmToken(User $user, string $fcmToken): User
    {
        $user->fcm_token = $fcmToken;
        $user->save();

        return $user;
    }

    /**
     * Update the attendance type of a user.
     */
    public function updateAttendanceType(User $user, int $attendanceTypeId): User
    {
        $attendanceType = AttendanceType::findOrFail($attendanceTypeId);

        $user->attendanceType()->associate($attendanceType);
        $user->save();

        EmployeeAttendanceType::updateOrCreate(
            ['user_id' => $user->id],
            ['attendance_type_id' => $attendanceType->id, 'biometric_device_id' => null]
        );

        return $user->fresh(['department', 'designation', 'roles', 'currentDevice', 'attendanceType']);
    }

    /**
     * Assign (or clear) a biometric device for an employee.
     *
     * @return array{success: bool, message: string, biometric_device_id: int|null, biometric_device_name: string|null}
     *
     * @throws \InvalidArgumentException when the device doesn't belong to the employee's attendance type.
     */
    public function assignBiometricDevice(User $user, ?int $deviceId): array
    {
        if ($deviceId && $user->attendance_type_id) {
            $inPool = AttendanceType::find($user->attendance_type_id)
                ?->biometricDevices()
                ->where('biometric_devices.id', $deviceId)
                ->exists();

            if (! $inPool) {
                throw new \InvalidArgumentException("Device does not belong to this employee's attendance type.");
            }
        }

        $eat = EmployeeAttendanceType::firstOrCreate(
            ['user_id' => $user->id],
            ['attendance_type_id' => $user->attendance_type_id]
        );
        $eat->biometric_device_id = $deviceId;
        $eat->save();

        $device = $deviceId ? BiometricDevice::find($deviceId) : null;

        return [
            'success'               => true,
            'message'               => $device ? "Assigned to {$device->name}." : 'Device assignment cleared.',
            'biometric_device_id'   => $deviceId,
            'biometric_device_name' => $device?->name,
        ];
    }

    // ──────────────────────────────────────────────
    //  Pagination / listing queries
    // ──────────────────────────────────────────────

    /**
     * Paginate users with filters and return data + stats.
     */
    public function paginateUsers(array $filters): array
    {
        $perPage    = $filters['perPage'] ?? 20;
        $page       = $filters['page'] ?? 1;
        $search     = $filters['search'] ?? null;
        $role       = $filters['role'] ?? null;
        $status     = $filters['status'] ?? null;
        $department = $filters['department'] ?? null;

        $query = User::withTrashed()
            ->with(['department', 'designation', 'roles', 'currentDevice', 'reportsTo']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($role && $role !== 'all') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $role));
        }

        if ($status && $status !== 'all') {
            if ($status === 'active') {
                $query->whereNull('deleted_at');
            } elseif ($status === 'inactive') {
                $query->whereNotNull('deleted_at');
            }
        }

        if ($department && $department !== 'all') {
            $query->where('department_id', $department);
        }

        $statsQuery = clone $query;

        if (DB::getDriverName() === 'sqlite') {
            $query->orderByRaw('CASE WHEN deleted_at IS NULL THEN 0 ELSE 1 END ASC')->orderBy('name');
        } else {
            $query->orderByRaw('ISNULL(deleted_at) DESC')->orderBy('name');
        }

        $users = $query->paginate($perPage, ['*'], 'page', $page);

        return [
            'users' => $users,
            'stats' => [
                'overview' => [
                    'total_users'    => (clone $statsQuery)->count(),
                    'active_users'   => (clone $statsQuery)->whereNull('deleted_at')->count(),
                    'inactive_users' => (clone $statsQuery)->whereNotNull('deleted_at')->count(),
                ],
            ],
        ];
    }

    /**
     * Paginate employees with filters and return data + stats + managers list.
     */
    public function paginateEmployees(array $filters): array
    {
        $perPage        = $filters['perPage'] ?? 20;
        $page           = $filters['page'] ?? 1;
        $search         = $filters['search'] ?? '';
        $department     = $filters['department'] ?? null;
        $designation    = $filters['designation'] ?? null;
        $attendanceType = $filters['attendanceType'] ?? null;

        $query = User::with(['department', 'designation', 'attendanceType']);

        if (! empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%");
            });
        }

        if (! empty($department) && $department !== 'all') {
            $query->where('department_id', $department);
        }

        if (! empty($designation) && $designation !== 'all') {
            $query->where('designation_id', $designation);
        }

        if (! empty($attendanceType) && $attendanceType !== 'all') {
            $query->where('attendance_type_id', $attendanceType);
        }

        $query->orderBy('created_at', 'desc');

        $employees = $query->with('reportsTo')->paginate($perPage, ['*'], 'page', $page);

        $transformedEmployees = $employees->map(function ($employee) {
            return [
                'id'                          => $employee->id,
                'name'                        => $employee->name,
                'email'                       => $employee->email,
                'phone'                       => $employee->phone,
                'employee_id'                 => $employee->employee_id,
                'profile_image_url'           => $employee->profile_image_url,
                'deleted_at'                  => $employee->deleted_at,
                'department_id'               => $employee->department_id,
                'department_name'             => $employee->department?->name,
                'designation_id'              => $employee->designation_id,
                'designation_name'            => $employee->designation?->title,
                'designation_hierarchy_level' => $employee->designation?->hierarchy_level,
                'attendance_type_id'          => $employee->attendance_type_id,
                'attendance_type_name'        => $employee->attendanceType?->name,
                'report_to'                   => $employee->report_to,
                'reports_to'                  => $employee->reportsTo ? [
                    'id'               => $employee->reportsTo->id,
                    'name'             => $employee->reportsTo->name,
                    'profile_image_url' => $employee->reportsTo->profile_image_url,
                    'designation_name' => $employee->reportsTo->designation?->title,
                ] : null,
                'created_at' => $employee->created_at,
                'updated_at' => $employee->updated_at,
            ];
        });

        $employees->setCollection($transformedEmployees);

        $stats = [
            'total'        => User::withTrashed()->count(),
            'active'       => User::whereNull('deleted_at')->count(),
            'inactive'     => User::onlyTrashed()->count(),
            'departments'  => Department::count(),
            'designations' => Designation::count(),
        ];

        $allManagers = User::with(['designation', 'department'])
            ->get()
            ->map(function ($user) {
                return [
                    'id'                          => $user->id,
                    'name'                        => $user->name,
                    'profile_image_url'           => $user->profile_image_url,
                    'department_id'               => $user->department_id,
                    'department_name'             => $user->department?->name,
                    'designation_id'              => $user->designation_id,
                    'designation_name'            => $user->designation?->title,
                    'designation_hierarchy_level' => $user->designation?->hierarchy_level ?? 999,
                ];
            });

        return [
            'employees'   => $employees,
            'stats'       => $stats,
            'allManagers' => $allManagers,
        ];
    }

    // ──────────────────────────────────────────────
    //  Statistics
    // ──────────────────────────────────────────────

    /**
     * Compile comprehensive user management statistics.
     */
    public function getUserStats(): array
    {
        $totalUsers   = User::withTrashed()->count();
        $activeUsers  = User::whereNull('deleted_at')->count();
        $inactiveUsers = User::onlyTrashed()->count();

        $roleCount     = Role::count();
        $rolesWithUsers = Role::withCount('users')->get()->map(function ($role) use ($totalUsers) {
            return [
                'name'       => $role->name,
                'count'      => $role->users_count,
                'percentage' => $totalUsers > 0 ? round(($role->users_count / $totalUsers) * 100, 1) : 0,
            ];
        });

        $departmentStats = Department::withCount('users')->get()->map(function ($dept) use ($totalUsers) {
            return [
                'name'       => $dept->name,
                'count'      => $dept->users_count,
                'percentage' => $totalUsers > 0 ? round(($dept->users_count / $totalUsers) * 100, 1) : 0,
            ];
        });

        $now = now();
        $recentActivity = [
            'new_users_30_days' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'new_users_90_days' => User::where('created_at', '>=', $now->copy()->subDays(90))->count(),
            'new_users_year'    => User::where('created_at', '>=', $now->copy()->subYear())->count(),
            'recently_active'   => User::where('updated_at', '>=', $now->copy()->subDays(7))->count(),
        ];

        $statusRatio = [
            'active_percentage'   => $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0,
            'inactive_percentage' => $totalUsers > 0 ? round(($inactiveUsers / $totalUsers) * 100, 1) : 0,
        ];

        $previousMonthUsers = User::withTrashed()->where('created_at', '<', $now->copy()->startOfMonth())->count();
        $currentMonthUsers  = User::withTrashed()->where('created_at', '>=', $now->copy()->startOfMonth())->count();
        $userGrowthRate     = $previousMonthUsers > 0 ? round((($currentMonthUsers / $previousMonthUsers) * 100), 1) : 0;

        $securityMetrics = [
            'users_with_roles'    => User::whereHas('roles')->count(),
            'users_without_roles' => User::whereDoesntHave('roles')->count(),
            'admin_users'         => User::whereHas('roles', fn ($q) => $q->where('name', 'like', '%admin%'))->count(),
            'regular_users'       => User::whereHas('roles', fn ($q) => $q->where('name', 'not like', '%admin%'))->count(),
        ];

        $systemHealth = [
            'user_activation_rate' => $totalUsers > 0 ? round(($activeUsers / $totalUsers) * 100, 1) : 0,
            'role_coverage'        => $totalUsers > 0 ? round(($securityMetrics['users_with_roles'] / $totalUsers) * 100, 1) : 0,
            'department_coverage'  => $totalUsers > 0 ? round((User::whereNotNull('department_id')->count() / $totalUsers) * 100, 1) : 0,
        ];

        return [
            'overview' => [
                'total_users'       => $totalUsers,
                'active_users'      => $activeUsers,
                'inactive_users'    => $inactiveUsers,
                'total_roles'       => $roleCount,
                'total_departments' => Department::count(),
            ],
            'distribution' => [
                'by_role'       => $rolesWithUsers,
                'by_department' => $departmentStats,
                'by_status'     => [
                    ['name' => 'Active', 'count' => $activeUsers, 'percentage' => $statusRatio['active_percentage']],
                    ['name' => 'Inactive', 'count' => $inactiveUsers, 'percentage' => $statusRatio['inactive_percentage']],
                ],
            ],
            'activity' => [
                'recent_registrations'        => $recentActivity,
                'user_growth_rate'            => $userGrowthRate,
                'current_month_registrations' => $currentMonthUsers,
            ],
            'security' => [
                'access_metrics'    => $securityMetrics,
                'role_distribution' => $rolesWithUsers,
            ],
            'health' => [
                'status_ratio'   => $statusRatio,
                'system_metrics' => $systemHealth,
            ],
            'quick_metrics' => [
                'total_users'          => $totalUsers,
                'active_ratio'         => $statusRatio['active_percentage'],
                'role_diversity'       => $roleCount,
                'department_diversity' => Department::count(),
                'recent_activity'      => $recentActivity['new_users_30_days'],
                'system_health_score'  => round(($systemHealth['user_activation_rate'] + $systemHealth['role_coverage'] + $systemHealth['department_coverage']) / 3, 1),
            ],
        ];
    }

    /**
     * Compile comprehensive employee / HR statistics.
     */
    public function getEmployeeStats(): array
    {
        $totalEmployees    = User::count();
        $activeEmployees   = User::whereNull('deleted_at')->count();
        $inactiveEmployees = User::whereNotNull('deleted_at')->count();

        $departmentCount  = Department::count();
        $designationCount = Designation::count();

        $attendanceTypeStats = AttendanceType::withCount('users')
            ->where('is_active', true)
            ->get()
            ->map(function ($type) use ($totalEmployees) {
                return [
                    'name'       => $type->name,
                    'count'      => $type->users_count,
                    'percentage' => $type->users_count > 0 ? round(($type->users_count / ($totalEmployees ?: 1)) * 100, 1) : 0,
                ];
            });

        $departmentStats = Department::withCount('users')->get()->map(function ($dept) use ($totalEmployees) {
            return [
                'name'       => $dept->name,
                'count'      => $dept->users_count,
                'percentage' => $dept->users_count > 0 ? round(($dept->users_count / ($totalEmployees ?: 1)) * 100, 1) : 0,
            ];
        });

        $designationStats = Designation::withCount('users')->get()->map(function ($desig) use ($totalEmployees) {
            return [
                'name'       => $desig->title,
                'count'      => $desig->users_count,
                'percentage' => $desig->users_count > 0 ? round(($desig->users_count / ($totalEmployees ?: 1)) * 100, 1) : 0,
            ];
        });

        $now = now();
        $recentHires = [
            'last_30_days' => User::where('created_at', '>=', $now->copy()->subDays(30))->count(),
            'last_90_days' => User::where('created_at', '>=', $now->copy()->subDays(90))->count(),
            'last_year'    => User::where('created_at', '>=', $now->copy()->subYear())->count(),
        ];

        $statusRatio = [
            'active_percentage'   => $totalEmployees > 0 ? round(($activeEmployees / $totalEmployees) * 100, 1) : 0,
            'inactive_percentage' => $totalEmployees > 0 ? round(($inactiveEmployees / $totalEmployees) * 100, 1) : 0,
            'retention_rate'      => $totalEmployees > 0 ? round(($activeEmployees / $totalEmployees) * 100, 1) : 0,
        ];

        $previousMonthCount = User::where('created_at', '<', $now->copy()->startOfMonth())->count();
        $currentMonthHires  = User::where('created_at', '>=', $now->copy()->startOfMonth())->count();
        $growthRate         = $previousMonthCount > 0 ? round((($currentMonthHires / $previousMonthCount) * 100), 1) : 0;

        return [
            'overview' => [
                'total_employees'        => $totalEmployees,
                'active_employees'       => $activeEmployees,
                'inactive_employees'     => $inactiveEmployees,
                'total_departments'      => $departmentCount,
                'total_designations'     => $designationCount,
                'total_attendance_types' => AttendanceType::where('is_active', true)->count(),
            ],
            'distribution' => [
                'by_department'      => $departmentStats,
                'by_designation'     => $designationStats,
                'by_attendance_type' => $attendanceTypeStats,
            ],
            'hiring_trends' => [
                'recent_hires'        => $recentHires,
                'monthly_growth_rate' => $growthRate,
                'current_month_hires' => $currentMonthHires,
            ],
            'workforce_health' => [
                'status_ratio'  => $statusRatio,
                'retention_rate' => $statusRatio['retention_rate'],
                'turnover_rate' => 100 - $statusRatio['retention_rate'],
            ],
            'quick_metrics' => [
                'headcount'            => $totalEmployees,
                'active_ratio'         => $statusRatio['active_percentage'],
                'department_diversity' => $departmentCount,
                'role_diversity'       => $designationCount,
                'recent_activity'      => $recentHires['last_30_days'],
            ],
        ];
    }

    // ──────────────────────────────────────────────
    //  Permissions management
    // ──────────────────────────────────────────────

    /**
     * Get all roles for a user, formatted for API response.
     */
    public function getUserRoles(User $user): array
    {
        return [
            'user_id'   => $user->id,
            'user_name' => $user->name,
            'roles'     => $user->roles->map(fn ($role) => [
                'id'         => $role->id,
                'name'       => $role->name,
                'guard_name' => $role->guard_name,
            ]),
        ];
    }

    /**
     * Get all permissions for a user (direct + via roles).
     */
    public function getUserPermissions(User $user): array
    {
        $directPermissions = $user->getDirectPermissions()->map(fn ($p) => [
            'id'     => $p->id,
            'name'   => $p->name,
            'source' => 'direct',
        ]);

        $rolePermissions = $user->getPermissionsViaRoles()->map(fn ($p) => [
            'id'     => $p->id,
            'name'   => $p->name,
            'source' => 'role',
        ]);

        return [
            'user_id'              => $user->id,
            'user_name'            => $user->name,
            'direct_permissions'   => $directPermissions,
            'role_permissions'     => $rolePermissions,
            'all_permissions'      => $user->getAllPermissions()->pluck('name'),
        ];
    }

    /**
     * Sync roles and return the refreshed user (with audit logging + cache clear).
     */
    public function syncUserRoles(User $user, array $roles): User
    {
        $originalRoles = $user->roles->pluck('name')->toArray();

        $user->syncRoles($roles);

        Log::info('User roles synced', [
            'user_id'        => $user->id,
            'user_name'      => $user->name,
            'original_roles' => $originalRoles,
            'new_roles'      => $roles,
            'synced_by'      => auth()->id(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return $user->fresh(['department', 'designation', 'roles', 'currentDevice']);
    }

    /**
     * Sync direct permissions for a user.
     */
    public function syncUserPermissions(User $user, array $permissions): array
    {
        $originalPermissions = $user->getDirectPermissions()->pluck('name')->toArray();

        $user->syncPermissions($permissions);

        Log::info('User permissions synced', [
            'user_id'              => $user->id,
            'user_name'            => $user->name,
            'original_permissions' => $originalPermissions,
            'new_permissions'      => $permissions,
            'synced_by'            => auth()->id(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return [
            'direct_permissions' => $user->getDirectPermissions()->pluck('name'),
            'all_permissions'    => $user->getAllPermissions()->pluck('name'),
        ];
    }

    /**
     * Give a single permission to a user. Returns null if already held.
     */
    public function giveUserPermission(User $user, string $permissionName): ?array
    {
        if ($user->hasDirectPermission($permissionName)) {
            return null; // already has it
        }

        $user->givePermissionTo($permissionName);

        Log::info('Permission granted to user', [
            'user_id'    => $user->id,
            'user_name'  => $user->name,
            'permission' => $permissionName,
            'granted_by' => auth()->id(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return [
            'direct_permissions' => $user->getDirectPermissions()->pluck('name'),
        ];
    }

    /**
     * Revoke a single permission from a user. Returns null if not held.
     */
    public function revokeUserPermission(User $user, string $permissionName): ?array
    {
        if (! $user->hasDirectPermission($permissionName)) {
            return null; // doesn't have it
        }

        $user->revokePermissionTo($permissionName);

        Log::info('Permission revoked from user', [
            'user_id'    => $user->id,
            'user_name'  => $user->name,
            'permission' => $permissionName,
            'revoked_by' => auth()->id(),
        ]);

        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        return [
            'direct_permissions' => $user->getDirectPermissions()->pluck('name'),
        ];
    }
}
