<?php

use App\Http\Controllers\Api\BiometricWebhookController;
use App\Http\Controllers\Api\LocaleController;
use App\Http\Controllers\Api\V1\AccountSecurityController;
use App\Http\Controllers\Api\V1\AttendanceController as MobileAttendanceController;
use App\Http\Controllers\Api\V1\AttendanceRequestController as MobileAttendanceRequestController;
use App\Http\Controllers\Api\V1\AuthController as MobileAuthController;
use App\Http\Controllers\Api\V1\ClientErrorLogController;
use App\Http\Controllers\Api\V1\ConfigController;
use App\Http\Controllers\Api\V1\DailyWorkController as MobileDailyWorkController;
use App\Http\Controllers\Api\V1\HeartbeatController;
use App\Http\Controllers\Api\V1\LeaveController as MobileLeaveController;
use App\Http\Controllers\Api\V1\ManagerDashboardController as MobileManagerDashboardController;
use App\Http\Controllers\Api\V1\OmMobileApiController;
use App\Http\Controllers\Api\V1\ProfileController as MobileProfileController;
use App\Http\Controllers\Api\V1\RosterController;
use App\Http\Controllers\Api\V1\SyncController as MobileSyncController;
use App\Http\Controllers\Api\VersionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OperationsMaintenanceController;
use App\Http\Controllers\OmLookupController;
use App\Http\Controllers\OmRenovationController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SystemMonitoringController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\ApiDeviceAuthMiddleware;
use App\Http\Middleware\SlideTokenExpiration;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Biometric device webhook (public — device-token auth handled in controller)
Route::post('/biometric/webhook', [BiometricWebhookController::class, 'handle'])
    ->middleware('throttle:60,1')
    ->name('api.biometric.webhook');
Route::post('/biometric/heartbeat', [BiometricWebhookController::class, 'heartbeat'])
    ->middleware('throttle:60,1')
    ->name('api.biometric.heartbeat');

// Active O&M Lookups (Public / Mobile bootstrap)
Route::get('/om/lookups', [OmLookupController::class, 'activeLookups'])
    ->middleware('throttle:60,1')
    ->name('api.om.lookups');

// ZKTeco ADMS routes are in routes/iclock.php (registered without /api prefix
// via bootstrap/app.php → then callback). The MB460 hardcodes /iclock/cdata.

// Device command management (requires authentication + device-management permission)
Route::middleware(['web', 'auth', 'permission:attendance.settings', 'throttle:api'])->prefix('biometric-devices')->group(function () {
    Route::post('/{deviceId}/commands', [BiometricWebhookController::class, 'queueCommand'])
        ->name('api.biometric-devices.commands.queue');
    Route::get('/{deviceId}/commands', [BiometricWebhookController::class, 'getCommands'])
        ->name('api.biometric-devices.commands.index');
    Route::get('/{deviceId}/logs', [BiometricWebhookController::class, 'getLogs'])
        ->name('api.biometric-devices.logs.index');
    Route::get('/logs', [BiometricWebhookController::class, 'getLogs'])
        ->name('api.biometric-devices.logs.all');
});

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

        try {
            \App\Models\ClientErrorLog::record([
                'source' => 'web',
                'platform' => 'web',
                'error_type' => 'FrontendCrash',
                'message' => $validated['message'],
                'stack' => $validated['stack'] ?? null,
                'screen' => parse_url($validated['url'], PHP_URL_PATH) ?: $validated['url'],
                'severity' => 'fatal',
                'context' => [
                    'error_id' => $validated['error_id'],
                    'component_stack' => $validated['component_stack'] ?? null,
                    'user_agent' => $validated['user_agent'] ?? null,
                ],
            ], $request->user()?->employee_id ?? $request->user()?->id);
        } catch (\Throwable) {}

        if (Schema::hasTable('error_logs')) {
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
        }

        return response()->json(['success' => true]);
    } catch (Exception $e) {
        Log::error('Failed to log frontend error: '.$e->getMessage());

        return response()->json(['success' => false], 500);
    }
})->middleware(['web', 'auth', 'throttle:60,1']);

// Performance logging endpoint

// Notifications (in-app center + device tokens)
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notification-token', [NotificationController::class, 'storeToken']);
});
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
    } catch (Exception $e) {
        Log::error('Failed to log performance metric: '.$e->getMessage());

        return response()->json(['success' => false], 500);
    }
})->middleware(['web', 'auth', 'throttle:60,1']);

// System monitoring API routes
Route::middleware(['web', 'auth', 'role:Super Administrator', 'throttle:api'])->group(function () {
    Route::get('/system-monitoring/metrics', [SystemMonitoringController::class, 'getMetrics'])->name('api.system-monitoring.metrics');
    Route::get('/system-monitoring/overview', [SystemMonitoringController::class, 'getSystemOverview'])->name('api.system-monitoring.overview');
});

// Locale API routes
Route::prefix('locale')->middleware('throttle:api')->group(function () {
    Route::get('/', [LocaleController::class, 'index'])->name('api.locale.index');
    Route::post('/', [LocaleController::class, 'update'])->name('api.locale.update');
    Route::get('/translations/{namespace?}', [LocaleController::class, 'translations'])->name('api.locale.translations');
});

// ============================================================================
// RBAC API Routes - Role and Permission Management
// ============================================================================
Route::middleware(['web', 'auth', 'throttle:api'])->prefix('roles')->group(function () {
    // Role CRUD operations
    Route::get('/', [RoleController::class, 'apiIndex'])->name('api.roles.index');
    Route::post('/', [RoleController::class, 'storeRole'])->name('api.roles.store');
    Route::get('/{id}', [RoleController::class, 'apiShow'])->name('api.roles.show');
    Route::put('/{id}', [RoleController::class, 'updateRole'])->name('api.roles.update');
    Route::delete('/{id}', [RoleController::class, 'deleteRole'])->name('api.roles.destroy');

    // Role-Permission assignment
    Route::patch('/{id}/permissions', [RoleController::class, 'batchUpdatePermissions'])->name('api.roles.permissions.batch');
    Route::post('/{id}/permissions/sync', [RoleController::class, 'syncRolePermissions'])->name('api.roles.permissions.sync');
});

Route::middleware(['web', 'auth', 'throttle:api'])->prefix('permissions')->group(function () {
    // Permission CRUD operations
    Route::get('/', [PermissionController::class, 'index'])->name('api.permissions.index');
    Route::post('/', [PermissionController::class, 'store'])->name('api.permissions.store');
    Route::get('/{id}', [PermissionController::class, 'show'])->name('api.permissions.show');
    Route::put('/{id}', [PermissionController::class, 'update'])->name('api.permissions.update');
    Route::delete('/{id}', [PermissionController::class, 'destroy'])->name('api.permissions.destroy');

    // Permission grouping
    Route::get('/grouped/modules', [PermissionController::class, 'groupedByModule'])->name('api.permissions.grouped');
});

Route::middleware(['web', 'auth', 'throttle:api'])->prefix('users')->group(function () {
    // User-Role assignment
    Route::get('/{id}/roles', [UserController::class, 'getUserRoles'])->name('api.users.roles.index');
    Route::post('/{id}/roles', [UserController::class, 'updateUserRole'])->name('api.users.roles.sync');

    // User-Permission direct assignment
    Route::get('/{id}/permissions', [UserController::class, 'getUserPermissions'])->name('api.users.permissions.index');
    Route::post('/{id}/permissions', [UserController::class, 'syncUserPermissions'])->name('api.users.permissions.sync');
    Route::post('/{id}/permissions/give', [UserController::class, 'giveUserPermission'])->name('api.users.permissions.give');
    Route::post('/{id}/permissions/revoke', [UserController::class, 'revokeUserPermission'])->name('api.users.permissions.revoke');
});

// ============================================================================
// Mobile API v1 Routes
// ============================================================================
Route::prefix('v1')->middleware('throttle:api')->group(function () {
    Route::post('/auth/login', [MobileAuthController::class, 'login'])->name('api.v1.auth.login');
    // Public + throttled: the refresh token is the credential, so this must work
    // once the access token has already expired.
    Route::post('/auth/refresh', [MobileAuthController::class, 'refresh'])->name('api.v1.auth.refresh');

    // Client Diagnostics ingest. Public + tightly throttled ON PURPOSE: the
    // authenticated v1 group also runs ApiDeviceAuthMiddleware, and a crash
    // reporter that only works while auth is healthy cannot report the crashes
    // that matter most (login, token refresh, cold start). A bearer token is
    // still honoured when present, attributing the group to a user.
    Route::post('/client-errors', [ClientErrorLogController::class, 'store'])
        ->middleware('throttle:30,1')
        ->name('api.v1.client-errors.store');
});

Route::prefix('v1')->middleware(['auth:sanctum', SlideTokenExpiration::class, ApiDeviceAuthMiddleware::class, 'throttle:api'])->group(function () {
    Route::get('/auth/me', [MobileAuthController::class, 'me'])->name('api.v1.auth.me');
    Route::post('/heartbeat', HeartbeatController::class)->middleware('throttle:30,1')->name('api.v1.heartbeat');
    // Runtime remote config: server-controlled flags the app reads after login
    // and on foreground, so behaviour changes WITHOUT a store release.
    Route::get('/config', [ConfigController::class, 'show'])->name('api.v1.config.show');
    Route::post('/auth/logout', [MobileAuthController::class, 'logout'])->name('api.v1.auth.logout');
    Route::get('/profile', [MobileProfileController::class, 'show'])->name('api.v1.profile.show');
    Route::put('/profile', [MobileProfileController::class, 'update'])->name('api.v1.profile.update');
    Route::post('/profile/image', [MobileProfileController::class, 'uploadImage'])->name('api.v1.profile.image.upload');
    Route::delete('/profile/image', [MobileProfileController::class, 'removeImage'])->name('api.v1.profile.image.remove');
    Route::get('/attendance/today', [MobileAttendanceController::class, 'today'])->name('api.v1.attendance.today');
    Route::get('/attendance/present-users', [MobileAttendanceController::class, 'presentUsersForDate'])->name('api.v1.attendance.present-users');
    Route::get('/attendance/absent-users', [MobileAttendanceController::class, 'absentUsersForDate'])->name('api.v1.attendance.absent-users');
    // Single source of truth: the frozen day-partition shape shared with the web
    // daily timesheet so mobile and web show identical, correct numbers.
    Route::get('/attendance/team-day', [MobileAttendanceController::class, 'teamDay'])->name('api.v1.attendance.team-day');
    Route::post('/attendance/mark-present', [MobileAttendanceController::class, 'markPresent'])->middleware('throttle:30,1')->name('api.v1.attendance.mark-present');
    Route::get('/attendance/locations-today', [MobileAttendanceController::class, 'userLocationsForDate'])->name('api.v1.attendance.locations-today');
    Route::get('/attendance/check-user-locations-updates/{date}', [MobileAttendanceController::class, 'checkUserLocationUpdates'])->name('api.v1.attendance.check-user-locations-updates');
    Route::get('/attendance/check-timesheet-updates/{date}/{month?}', [MobileAttendanceController::class, 'checkTimesheetUpdates'])->name('api.v1.attendance.check-timesheet-updates');
    Route::get('/attendance/daily-timesheet', [MobileAttendanceController::class, 'dailyTimesheet'])->name('api.v1.attendance.daily-timesheet');
    Route::get('/attendance/team-locations', [MobileAttendanceController::class, 'teamLocations'])->name('api.v1.attendance.team-locations');
    Route::get('/attendance/monthly-summary', [MobileAttendanceController::class, 'monthlySummary'])->name('api.v1.attendance.monthly-summary');
    Route::get('/attendance/history', [MobileAttendanceController::class, 'history'])->name('api.v1.attendance.history');
    Route::post('/attendance/punch', [MobileAttendanceController::class, 'punch'])->middleware('throttle:20,1')->name('api.v1.attendance.punch');
    Route::get('/attendance/my-roster', [MobileAttendanceRequestController::class, 'myRoster'])->name('api.v1.attendance.my-roster');
    Route::get('/attendance/roster', [RosterController::class, 'index'])->name('api.v1.attendance.roster');
    Route::get('/attendance/shifts', [RosterController::class, 'shifts'])->name('api.v1.attendance.shifts');
    Route::post('/attendance/regularizations', [MobileAttendanceRequestController::class, 'storeRegularization'])->name('api.v1.attendance.regularizations.store');
    Route::get('/attendance/regularizations/mine', [MobileAttendanceRequestController::class, 'myRegularizations'])->name('api.v1.attendance.regularizations.mine');
    Route::get('/attendance/regularizations/pending', [MobileAttendanceRequestController::class, 'pendingRegularizations'])->name('api.v1.attendance.regularizations.pending');
    Route::get('/attendance/regularizations/decided', [MobileAttendanceRequestController::class, 'decidedRegularizations'])->name('api.v1.attendance.regularizations.decided');
    Route::post('/attendance/regularizations/{id}/approve', [MobileAttendanceRequestController::class, 'approveRegularization'])->name('api.v1.attendance.regularizations.approve');
    Route::post('/attendance/regularizations/{id}/reject', [MobileAttendanceRequestController::class, 'rejectRegularization'])->name('api.v1.attendance.regularizations.reject');

    Route::post('/attendance/overtime', [MobileAttendanceRequestController::class, 'storeOvertime'])->name('api.v1.attendance.overtime.store');
    Route::get('/attendance/overtime/mine', [MobileAttendanceRequestController::class, 'myOvertime'])->name('api.v1.attendance.overtime.mine');
    Route::get('/attendance/overtime/pending', [MobileAttendanceRequestController::class, 'pendingOvertime'])->name('api.v1.attendance.overtime.pending');
    Route::get('/attendance/overtime/decided', [MobileAttendanceRequestController::class, 'decidedOvertime'])->name('api.v1.attendance.overtime.decided');
    Route::post('/attendance/overtime/{id}/approve', [MobileAttendanceRequestController::class, 'approveOvertime'])->name('api.v1.attendance.overtime.approve');
    Route::post('/attendance/overtime/{id}/reject', [MobileAttendanceRequestController::class, 'rejectOvertime'])->name('api.v1.attendance.overtime.reject');

    Route::get('/attendance/swaps/pending', [MobileAttendanceRequestController::class, 'pendingSwaps'])->name('api.v1.attendance.swaps.pending');
    Route::get('/attendance/swaps/team-decided', [MobileAttendanceRequestController::class, 'teamDecidedSwaps'])->name('api.v1.attendance.swaps.team-decided');
    Route::post('/attendance/swaps/{id}/approve', [MobileAttendanceRequestController::class, 'approveSwap'])->name('api.v1.attendance.swaps.approve');
    Route::post('/attendance/swaps/{id}/reject', [MobileAttendanceRequestController::class, 'rejectSwap'])->name('api.v1.attendance.swaps.reject');

    Route::get('/attendance/comp-off/mine', [MobileAttendanceRequestController::class, 'myCompOff'])->name('api.v1.attendance.compoff.mine');
    Route::post('/attendance/swaps', [MobileAttendanceRequestController::class, 'storeSwap'])->name('api.v1.attendance.swaps.store');
    Route::get('/attendance/swaps/mine', [MobileAttendanceRequestController::class, 'mySwaps'])->name('api.v1.attendance.swaps.mine');
    Route::get('/attendance/swaps/awaiting-me', [MobileAttendanceRequestController::class, 'swapsAwaitingMe'])->name('api.v1.attendance.swaps.awaiting-me');
    Route::post('/attendance/swaps/{id}/respond', [MobileAttendanceRequestController::class, 'respondSwap'])->whereNumber('id')->name('api.v1.attendance.swaps.respond');
    Route::get('/attendance/swaps/eligible', [MobileAttendanceRequestController::class, 'swapEligible'])->name('api.v1.attendance.swaps.eligible');
    Route::get('/attendance/swaps/counterparty-roster', [MobileAttendanceRequestController::class, 'counterpartyRoster'])->name('api.v1.attendance.swaps.counterparty-roster');
    Route::get('/attendance/swaps/pickup', [MobileAttendanceRequestController::class, 'pickup'])->name('api.v1.attendance.swaps.pickup');
    Route::get('/leave-types', [MobileLeaveController::class, 'types'])->name('api.v1.leave-types.index');
    Route::get('/leaves', [MobileLeaveController::class, 'index'])->name('api.v1.leaves.index');
    Route::get('/leaves/summary', [MobileLeaveController::class, 'summary'])->name('api.v1.leaves.summary');
    Route::get('/leaves/analytics', [MobileLeaveController::class, 'analytics'])->name('api.v1.leaves.analytics');
    Route::get('/leaves/calendar', [MobileLeaveController::class, 'calendar'])->name('api.v1.leaves.calendar');
    Route::get('/leaves/pending-approvals', [MobileLeaveController::class, 'pendingApprovals'])->name('api.v1.leaves.pending-approvals');
    Route::get('/leaves/decided-approvals', [MobileLeaveController::class, 'decidedApprovals'])->name('api.v1.leaves.decided-approvals');
    Route::get('/leaves/{leaveId}', [MobileLeaveController::class, 'show'])->whereNumber('leaveId')->name('api.v1.leaves.show');
    Route::get('/manager/dashboard-summary', [MobileManagerDashboardController::class, 'summary'])->name('api.v1.manager.dashboard.summary');
    Route::get('/manager/team-members', [MobileManagerDashboardController::class, 'teamMembers'])->name('api.v1.manager.team-members');
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
    Route::get('/daily-works/objections/my', [MobileDailyWorkController::class, 'myObjections'])->name('api.v1.daily-works.objections.my');
    Route::get('/daily-works/objections/queue', [MobileDailyWorkController::class, 'objectionQueue'])->name('api.v1.daily-works.objections.queue');
    Route::get('/daily-works/{dailyWorkId}', [MobileDailyWorkController::class, 'show'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.show');
    Route::patch('/daily-works/{dailyWorkId}/status', [MobileDailyWorkController::class, 'updateStatus'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.status.update');
    Route::patch('/daily-works/{dailyWorkId}/incharge', [MobileDailyWorkController::class, 'updateIncharge'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.incharge.update');
    Route::patch('/daily-works/{dailyWorkId}/assigned', [MobileDailyWorkController::class, 'updateAssigned'])->whereNumber('dailyWorkId')->name('api.v1.daily-works.assigned.update');
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

    // Operations & Maintenance (O&M) and Traffic Monitoring Center (TMC) Mobile API Endpoints
    Route::get('/om/dashboard', [OperationsMaintenanceController::class, 'dashboard'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.dashboard');
    Route::get('/om/traffic-monitoring', [OperationsMaintenanceController::class, 'trafficMonitoring'])
        ->middleware('permission:om.traffic.view')->name('api.v1.om.traffic');
    Route::get('/om/incidents', [OperationsMaintenanceController::class, 'incidents'])
        ->middleware('permission:om.incidents.view')->name('api.v1.om.incidents');
    Route::post('/om/incidents', [OperationsMaintenanceController::class, 'storeIncident'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.incidents.store');
    Route::get('/om/work-orders', [OperationsMaintenanceController::class, 'workOrders'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.work-orders');
    Route::post('/om/work-orders', [OperationsMaintenanceController::class, 'storeWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.work-orders.store');
    Route::get('/om/defects', [OperationsMaintenanceController::class, 'defects'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.defects');
    Route::post('/om/defects', [OperationsMaintenanceController::class, 'storeDefect'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.defects.store');
    Route::get('/om/assets', [OperationsMaintenanceController::class, 'assets'])
        ->middleware('permission:om.equipment.view')->name('api.v1.om.assets');
    Route::get('/om/equipment', [OperationsMaintenanceController::class, 'equipment'])
        ->middleware('permission:om.equipment.view')->name('api.v1.om.equipment');
    Route::get('/om/shift-logs', [OperationsMaintenanceController::class, 'shiftLogs'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.shift-logs');

    Route::get('/om/lookups', [OmLookupController::class, 'activeLookups'])
        ->name('api.v1.om.lookups');

    // Dedicated Field Worker API
    Route::get('/om/field/overview', [OmMobileApiController::class, 'fieldOverview'])
        ->middleware('permission:om.dashboard.view|om.incidents.view|om.incidents.manage|om.maintenance.view|om.maintenance.manage')
        ->name('api.v1.om.field.overview');
    Route::post('/om/field/defects', [OmMobileApiController::class, 'logDefect'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.defect.log');
    Route::post('/om/field/incidents/{id}', [OmMobileApiController::class, 'updateIncident'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.incident.update');
    Route::post('/om/field/work-orders/{id}', [OmMobileApiController::class, 'updateWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.work-order.update');

    // Phase 1 Mobile API: Inspections, Safety, Full Incident Creation, PM Overview
    Route::get('/om/preventive-maintenance', [OmRenovationController::class, 'preventiveMaintenance'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.pm');
    Route::get('/om/inspections', [OmRenovationController::class, 'inspections'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.inspections');
    Route::post('/om/field/inspections', [OmRenovationController::class, 'submitInspection'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.inspection.submit');
    Route::get('/om/sla-compliance', [OmRenovationController::class, 'slaCompliance'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.sla');
    Route::get('/om/analytics', [OmRenovationController::class, 'analytics'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.analytics');
    Route::get('/om/safety', [OmRenovationController::class, 'safety'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.safety');
    Route::post('/om/field/safety', [OmRenovationController::class, 'storeSafetyIncident'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.safety.report');
    Route::get('/om/assets/{id}/timeline', [OmRenovationController::class, 'assetTimeline'])
        ->middleware('permission:om.equipment.view')->name('api.v1.om.assets.timeline');
    Route::post('/om/field/incidents/create', [OmMobileApiController::class, 'createIncident'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.incident.create');
    Route::post('/om/field/patrol/start', [OmMobileApiController::class, 'startPatrol'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.patrol.start');
    Route::post('/om/field/patrol/end/{id}', [OmMobileApiController::class, 'endPatrol'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.patrol.end');

    // Phase 2 Mobile API: Asset Lookup, Materials Logging, Toolbox Talks, Calendar, Contractors, Environmental
    Route::get('/om/assets/lookup/{identifier}', [OmRenovationController::class, 'lookupAsset'])
        ->middleware('permission:om.equipment.view')->name('api.v1.om.assets.lookup');
    Route::get('/om/work-orders/calendar', [OmRenovationController::class, 'workOrderCalendar'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.work-orders.calendar');
    Route::post('/om/field/work-orders/{id}/materials', [OmRenovationController::class, 'logWorkOrderMaterial'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.work-orders.materials');
    Route::get('/om/contractors', [OmRenovationController::class, 'contractors'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.contractors');
    Route::post('/om/field/contractors', [OmRenovationController::class, 'storeContractor'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.contractors.store');
    Route::get('/om/inventory', [OmRenovationController::class, 'inventory'])
        ->middleware('permission:om.maintenance.view')->name('api.v1.om.inventory');
    Route::get('/om/environmental', [OmRenovationController::class, 'environmental'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.environmental');
    Route::post('/om/field/environmental', [OmRenovationController::class, 'storeEnvironmentalLog'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.environmental.store');
    Route::get('/om/toolbox-talks', [OmRenovationController::class, 'toolboxTalks'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.toolbox-talks');
    Route::post('/om/field/toolbox-talks', [OmRenovationController::class, 'storeToolboxTalk'])
        ->middleware('permission:om.maintenance.manage')->name('api.v1.om.field.toolbox-talks.store');

    // Phase 3 Mobile API: TPPD Claims, Patrol IRI Telemetry, WIM Overload, Deterioration & RCM
    Route::get('/om/tppd-claims', [OmRenovationController::class, 'tppdClaims'])
        ->middleware('permission:om.incidents.view')->name('api.v1.om.tppd');
    Route::post('/om/field/tppd-claims', [OmRenovationController::class, 'storeTppdClaim'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.tppd.store');
    Route::post('/om/field/tppd-claims/{id}/status', [OmRenovationController::class, 'updateTppdStatus'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.tppd.status');
    Route::get('/om/iri-roughness', [OmRenovationController::class, 'iriHeatmap'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.iri');
    Route::post('/om/field/iri-telemetry', [OmRenovationController::class, 'storeIriTelemetry'])
        ->middleware('permission:om.incidents.manage')->name('api.v1.om.field.iri.telemetry');
    Route::get('/om/wim-overload', [OmRenovationController::class, 'wimOverloadAnalytics'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.wim');
    Route::get('/om/pavement-deterioration', [OmRenovationController::class, 'pavementDeterioration'])
        ->middleware('permission:om.dashboard.view')->name('api.v1.om.pavement.deterioration');
    Route::get('/om/its-rcm', [OmRenovationController::class, 'rcmReliability'])
        ->middleware('permission:om.equipment.view')->name('api.v1.om.its.rcm');

    // Self-service account security (scoped to the authenticated user).
    Route::post('/account/change-password', [AccountSecurityController::class, 'changePassword'])->name('api.v1.account.change-password');
    Route::get('/account/devices', [AccountSecurityController::class, 'devices'])->name('api.v1.account.devices.index');
    Route::post('/account/devices/{device}/revoke', [AccountSecurityController::class, 'revokeDevice'])->whereNumber('device')->name('api.v1.account.devices.revoke');
    Route::post('/account/sign-out-all', [AccountSecurityController::class, 'signOutAll'])->name('api.v1.account.sign-out-all');
});
