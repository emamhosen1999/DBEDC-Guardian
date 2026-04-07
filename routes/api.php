<?php

use App\Http\Controllers\Api\V1\AttendanceController as MobileAttendanceController;
use App\Http\Controllers\Api\V1\AuthController as MobileAuthController;
use App\Http\Controllers\Api\V1\DailyWorkController as MobileDailyWorkController;
use App\Http\Controllers\Api\V1\LeaveController as MobileLeaveController;
use App\Http\Controllers\Api\V1\ManagerDashboardController as MobileManagerDashboardController;
use App\Http\Controllers\Api\V1\ProfileController as MobileProfileController;
use App\Http\Controllers\Api\V1\SyncController as MobileSyncController;
use App\Http\Controllers\Api\VersionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\SystemMonitoringController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Version check endpoints (no auth required for PWA functionality)
Route::get('/version', [VersionController::class, 'current'])->name('api.version.current');
Route::post('/version/check', [VersionController::class, 'check'])->name('api.version.check');

// Error logging endpoint
Route::post('/log-error', function (Request $request) {
    try {
        $validated = $request->validate([
            'error_id' => 'required|string',
            'message' => 'required|string',
            'stack' => 'nullable|string',
            'component_stack' => 'nullable|string',
            'url' => 'required|string',
            'user_agent' => 'nullable|string',
            'timestamp' => 'required|string',
        ]);

        DB::table('error_logs')->insert([
            'error_id' => $validated['error_id'],
            'message' => $validated['message'],
            'stack_trace' => $validated['stack'] ?? null,
            'component_stack' => $validated['component_stack'] ?? null,
            'url' => $validated['url'],
            'user_agent' => $validated['user_agent'] ?? null,
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'metadata' => json_encode([
                'timestamp' => $validated['timestamp'],
                'session_id' => session()->getId(),
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        Log::error('Failed to log frontend error: '.$e->getMessage());

        return response()->json(['success' => false], 500);
    }
})->middleware(['web']);

// Performance logging endpoint

// Notification token endpoint
Route::post('/notification-token', [NotificationController::class, 'storeToken'])->middleware(['auth:sanctum']);
Route::post('/log-performance', function (Request $request) {
    try {
        $validated = $request->validate([
            'metric_type' => 'required|string|in:page_load,api_response,query_execution,render_time',
            'identifier' => 'required|string',
            'execution_time_ms' => 'required|numeric',
            'metadata' => 'nullable|array',
        ]);

        DB::table('performance_metrics')->insert([
            'metric_type' => $validated['metric_type'],
            'identifier' => $validated['identifier'],
            'execution_time_ms' => $validated['execution_time_ms'],
            'metadata' => json_encode($validated['metadata'] ?? []),
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    } catch (\Exception $e) {
        Log::error('Failed to log performance metric: '.$e->getMessage());

        return response()->json(['success' => false], 500);
    }
})->middleware(['web']);

// System monitoring API routes
Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/system-monitoring/metrics', [SystemMonitoringController::class, 'getMetrics'])->name('api.system-monitoring.metrics');
    Route::get('/system-monitoring/overview', [SystemMonitoringController::class, 'getSystemOverview'])->name('api.system-monitoring.overview');
});

// Locale API routes
Route::prefix('locale')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\LocaleController::class, 'index'])->name('api.locale.index');
    Route::post('/', [\App\Http\Controllers\Api\LocaleController::class, 'update'])->name('api.locale.update');
    Route::get('/translations/{namespace?}', [\App\Http\Controllers\Api\LocaleController::class, 'translations'])->name('api.locale.translations');
});

// ============================================================================
// RBAC API Routes - Role and Permission Management
// ============================================================================
Route::middleware(['web', 'auth'])->prefix('roles')->group(function () {
    // Role CRUD operations
    Route::get('/', [\App\Http\Controllers\RoleController::class, 'apiIndex'])->name('api.roles.index');
    Route::post('/', [\App\Http\Controllers\RoleController::class, 'storeRole'])->name('api.roles.store');
    Route::get('/{id}', [\App\Http\Controllers\RoleController::class, 'apiShow'])->name('api.roles.show');
    Route::put('/{id}', [\App\Http\Controllers\RoleController::class, 'updateRole'])->name('api.roles.update');
    Route::delete('/{id}', [\App\Http\Controllers\RoleController::class, 'deleteRole'])->name('api.roles.destroy');

    // Role-Permission assignment
    Route::patch('/{id}/permissions', [\App\Http\Controllers\RoleController::class, 'batchUpdatePermissions'])->name('api.roles.permissions.batch');
    Route::post('/{id}/permissions/sync', [\App\Http\Controllers\RoleController::class, 'syncRolePermissions'])->name('api.roles.permissions.sync');
});

Route::middleware(['web', 'auth'])->prefix('permissions')->group(function () {
    // Permission CRUD operations
    Route::get('/', [\App\Http\Controllers\PermissionController::class, 'index'])->name('api.permissions.index');
    Route::post('/', [\App\Http\Controllers\PermissionController::class, 'store'])->name('api.permissions.store');
    Route::get('/{id}', [\App\Http\Controllers\PermissionController::class, 'show'])->name('api.permissions.show');
    Route::put('/{id}', [\App\Http\Controllers\PermissionController::class, 'update'])->name('api.permissions.update');
    Route::delete('/{id}', [\App\Http\Controllers\PermissionController::class, 'destroy'])->name('api.permissions.destroy');

    // Permission grouping
    Route::get('/grouped/modules', [\App\Http\Controllers\PermissionController::class, 'groupedByModule'])->name('api.permissions.grouped');
});

Route::middleware(['web', 'auth'])->prefix('users')->group(function () {
    // User-Role assignment
    Route::get('/{id}/roles', [\App\Http\Controllers\UserController::class, 'getUserRoles'])->name('api.users.roles.index');
    Route::post('/{id}/roles', [\App\Http\Controllers\UserController::class, 'updateUserRole'])->name('api.users.roles.sync');

    // User-Permission direct assignment
    Route::get('/{id}/permissions', [\App\Http\Controllers\UserController::class, 'getUserPermissions'])->name('api.users.permissions.index');
    Route::post('/{id}/permissions', [\App\Http\Controllers\UserController::class, 'syncUserPermissions'])->name('api.users.permissions.sync');
    Route::post('/{id}/permissions/give', [\App\Http\Controllers\UserController::class, 'giveUserPermission'])->name('api.users.permissions.give');
    Route::post('/{id}/permissions/revoke', [\App\Http\Controllers\UserController::class, 'revokeUserPermission'])->name('api.users.permissions.revoke');
});

// ============================================================================
// Mobile API v1 Routes
// ============================================================================
Route::prefix('v1')->group(function () {
    Route::post('/auth/login', [MobileAuthController::class, 'login'])->name('api.v1.auth.login');
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [MobileAuthController::class, 'me'])->name('api.v1.auth.me');
    Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::get('/profile', [MobileProfileController::class, 'show'])->name('api.v1.profile.show');
    Route::put('/profile', [MobileProfileController::class, 'update'])->name('api.v1.profile.update');
    Route::post('/profile/image', [MobileProfileController::class, 'uploadImage'])->name('api.v1.profile.image.upload');
    Route::delete('/profile/image', [MobileProfileController::class, 'removeImage'])->name('api.v1.profile.image.remove');
    Route::get('/attendance/today', [MobileAttendanceController::class, 'today'])->name('api.v1.attendance.today');
    Route::get('/attendance/present-users', [MobileAttendanceController::class, 'presentUsersForDate'])->name('api.v1.attendance.present-users');
    Route::get('/attendance/absent-users', [MobileAttendanceController::class, 'absentUsersForDate'])->name('api.v1.attendance.absent-users');
    Route::get('/attendance/locations-today', [MobileAttendanceController::class, 'userLocationsForDate'])->name('api.v1.attendance.locations-today');
    Route::get('/attendance/check-user-locations-updates/{date}', [MobileAttendanceController::class, 'checkUserLocationUpdates'])->name('api.v1.attendance.check-user-locations-updates');
    Route::get('/attendance/check-timesheet-updates/{date}/{month?}', [MobileAttendanceController::class, 'checkTimesheetUpdates'])->name('api.v1.attendance.check-timesheet-updates');
    Route::get('/attendance/daily-timesheet', [MobileAttendanceController::class, 'dailyTimesheet'])->name('api.v1.attendance.daily-timesheet');
    Route::get('/attendance/team-locations', [MobileAttendanceController::class, 'teamLocations'])->name('api.v1.attendance.team-locations');
    Route::get('/attendance/monthly-summary', [MobileAttendanceController::class, 'monthlySummary'])->name('api.v1.attendance.monthly-summary');
    Route::get('/attendance/history', [MobileAttendanceController::class, 'history'])->name('api.v1.attendance.history');
    Route::post('/attendance/punch', [MobileAttendanceController::class, 'punch'])->middleware('throttle:20,1')->name('api.v1.attendance.punch');
    Route::get('/leave-types', [MobileLeaveController::class, 'types'])->name('api.v1.leave-types.index');
    Route::get('/leaves', [MobileLeaveController::class, 'index'])->name('api.v1.leaves.index');
    Route::get('/leaves/summary', [MobileLeaveController::class, 'summary'])->name('api.v1.leaves.summary');
    Route::get('/leaves/analytics', [MobileLeaveController::class, 'analytics'])->name('api.v1.leaves.analytics');
    Route::get('/leaves/calendar', [MobileLeaveController::class, 'calendar'])->name('api.v1.leaves.calendar');
    Route::get('/leaves/{leaveId}', [MobileLeaveController::class, 'show'])->whereNumber('leaveId')->name('api.v1.leaves.show');
    Route::get('/leaves/pending-approvals', [MobileLeaveController::class, 'pendingApprovals'])->name('api.v1.leaves.pending-approvals');
    Route::get('/manager/dashboard-summary', [MobileManagerDashboardController::class, 'summary'])->name('api.v1.manager.dashboard.summary');
    Route::post('/leaves', [MobileLeaveController::class, 'store'])->name('api.v1.leaves.store');
    Route::put('/leaves/{leaveId}', [MobileLeaveController::class, 'update'])->name('api.v1.leaves.update');
    Route::post('/leaves/{leaveId}/approve', [MobileLeaveController::class, 'approve'])->name('api.v1.leaves.approve');
    Route::post('/leaves/{leaveId}/reject', [MobileLeaveController::class, 'reject'])->name('api.v1.leaves.reject');
    Route::post('/leaves/bulk-approve', [MobileLeaveController::class, 'bulkApprove'])->name('api.v1.leaves.bulk-approve');
    Route::post('/leaves/bulk-reject', [MobileLeaveController::class, 'bulkReject'])->name('api.v1.leaves.bulk-reject');
    Route::delete('/leaves/{leaveId}', [MobileLeaveController::class, 'destroy'])->name('api.v1.leaves.destroy');
    Route::get('/sync/bootstrap', [MobileSyncController::class, 'bootstrap'])->name('api.v1.sync.bootstrap');
    Route::get('/sync/pull', [MobileSyncController::class, 'pull'])->name('api.v1.sync.pull');
    Route::post('/sync/push', [MobileSyncController::class, 'push'])->name('api.v1.sync.push');
    Route::get('/daily-works', [MobileDailyWorkController::class, 'index'])->name('api.v1.daily-works.index');
    Route::get('/daily-works/selectable-dates', [MobileDailyWorkController::class, 'selectableDates'])->name('api.v1.daily-works.selectable-dates');
    Route::get('/daily-works/objections/metadata', [MobileDailyWorkController::class, 'objectionMetadata'])->name('api.v1.daily-works.objections.metadata');
    Route::get('/daily-works/{dailyWorkId}', [MobileDailyWorkController::class, 'show'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.show');
    Route::patch('/daily-works/{dailyWorkId}/status', [MobileDailyWorkController::class, 'updateStatus'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.status.update');
    Route::get('/daily-works/{dailyWorkId}/objections', [MobileDailyWorkController::class, 'objections'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.objections.index');
    Route::post('/daily-works/{dailyWorkId}/objections', [MobileDailyWorkController::class, 'storeObjection'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.objections.store');
    Route::post('/daily-works/{dailyWorkId}/objections/{objectionId}/submit', [MobileDailyWorkController::class, 'submitObjection'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->name('api.v1.daily-works.objections.submit');
    Route::post('/daily-works/{dailyWorkId}/objections/{objectionId}/review', [MobileDailyWorkController::class, 'startReviewObjection'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->name('api.v1.daily-works.objections.review');
    Route::post('/daily-works/{dailyWorkId}/objections/{objectionId}/resolve', [MobileDailyWorkController::class, 'resolveObjection'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->name('api.v1.daily-works.objections.resolve');
    Route::post('/daily-works/{dailyWorkId}/objections/{objectionId}/reject', [MobileDailyWorkController::class, 'rejectObjection'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->name('api.v1.daily-works.objections.reject');
    Route::get('/daily-works/{dailyWorkId}/objections/{objectionId}/files', [MobileDailyWorkController::class, 'objectionFiles'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->name('api.v1.daily-works.objections.files.index');
    Route::post('/daily-works/{dailyWorkId}/objections/{objectionId}/files', [MobileDailyWorkController::class, 'uploadObjectionFiles'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->name('api.v1.daily-works.objections.files.upload');
    Route::delete('/daily-works/{dailyWorkId}/objections/{objectionId}/files/{mediaId}', [MobileDailyWorkController::class, 'deleteObjectionFile'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->whereNumber('mediaId')->name('api.v1.daily-works.objections.files.delete');
    Route::get('/daily-works/{dailyWorkId}/objections/{objectionId}/files/{mediaId}/download', [MobileDailyWorkController::class, 'downloadObjectionFile'])->whereNumber('dailyWorkId')->whereNumber('objectionId')->whereNumber('mediaId')->name('api.v1.daily-works.objections.files.download');
    Route::post('/notifications/token', [NotificationController::class, 'storeToken'])->name('api.v1.notifications.token.store');
});
