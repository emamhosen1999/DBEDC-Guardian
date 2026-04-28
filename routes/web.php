
<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BulkLeaveController;
use App\Http\Controllers\DailyWorkController;
use App\Http\Controllers\DailyWorkRealtimeController;
use App\Http\Controllers\DailyWorkSummaryController;
use App\Http\Controllers\AdminDashboardController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DailyWorksAnalyticsController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EducationController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\FMSController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\IMSController;
use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\LMSController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ObjectionController;
use App\Http\Controllers\POSController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileImageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RfiObjectionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Settings\AttendanceSettingController;
use App\Http\Controllers\Settings\CompanySettingController;
use App\Http\Controllers\Settings\LeaveSettingController;
use App\Http\Controllers\SystemMonitoringController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;
// APK Install Gate (public, always accessible)
use Inertia\Inertia;

Route::get('/install-app', function () {
    return Inertia::render('InstallApp');
})->name('install-app');

// Include authentication routes
require __DIR__.'/auth.php';

Route::get('/', function (Request $request) {
    $userAgent = $request->header('User-Agent', '');
    $isAndroid = stripos($userAgent, 'android') !== false;

    return redirect($isAndroid ? '/install-app' : '/dashboard-redirect');
});

Route::get('/session-check', function () {
    return response()->json(['authenticated' => auth()->check()]);
});

Route::get('/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
});

// Locale switching route (for dynamic translations) - client-side only, no server redirect
Route::post('/locale', function (\Illuminate\Http\Request $request) {
    $locale = $request->input('locale', 'en');
    $supportedLocales = ['en', 'bn', 'ar', 'es', 'fr', 'de', 'hi', 'zh-CN', 'zh-TW'];

    if (in_array($locale, $supportedLocales)) {
        session(['locale' => $locale]);
        app()->setLocale($locale);

        if (auth()->check()) {
            auth()->user()->update(['locale' => $locale]);
        }
    }

    // Return empty response - locale is handled client-side
    return response()->noContent();
})->name('locale.update');

// Device authentication is now handled globally via DeviceAuthMiddleware
// No need to apply it here - it runs on all requests automatically
$middlewareStack = ['auth', 'verified'];

Route::middleware($middlewareStack)->group(function () {

    // Dashboard redirect - redirects to appropriate dashboard based on role
    Route::get('/dashboard-redirect', function () {
        $user = auth()->user();
        $roles = $user->roles->pluck('name')->toArray();
        
        if (in_array('Super Administrator', $roles)) {
            return redirect('/admin/dashboard');
        }
        
        if (in_array('Member', $roles)) {
            return redirect('/employee/dashboard');
        }
        
        // Fallback to member dashboard if no specific role
        return redirect('/employee/dashboard');
    })->name('dashboard.redirect');

    // Admin Dashboard route
    Route::get('/admin/dashboard', function () {
        return inertia('AdminDashboard');
    })->name('admin.dashboard');

    // Admin Dashboard API routes
    Route::prefix('admin/dashboard')->group(function () {
        Route::get('/stats', [AdminDashboardController::class, 'stats'])->name('admin.dashboard.stats');
        Route::get('/recent-activity', [AdminDashboardController::class, 'recentActivity'])->name('admin.dashboard.recent-activity');
        Route::get('/attendance-trends', [AdminDashboardController::class, 'attendanceTrends'])->name('admin.dashboard.attendance-trends');
        Route::get('/pending-approvals', [AdminDashboardController::class, 'pendingApprovals'])->name('admin.dashboard.pending-approvals');
        Route::get('/system-health', [AdminDashboardController::class, 'systemHealth'])->name('admin.dashboard.system-health');
        Route::get('/recent-members', [AdminDashboardController::class, 'recentMembers'])->name('admin.dashboard.recent-members');
        Route::post('/approve-leave/{id}', [AdminDashboardController::class, 'approveLeave'])->name('admin.dashboard.approve-leave');
        Route::post('/reject-leave/{id}', [AdminDashboardController::class, 'rejectLeave'])->name('admin.dashboard.reject-leave');
    });

    // Member Dashboard route
    Route::get('/employee/dashboard', function () {
        return inertia('MemberDashboard');
    })->name('employee.dashboard');

    // Security Dashboard route - available to authenticated users
    Route::get('/security/dashboard', function () {
        return inertia('Security/Dashboard');
    })->name('security.dashboard');

    // Updates route - require updates permission
    Route::middleware(['permission:core.updates.view'])->get('/updates', [DashboardController::class, 'updates'])->name('updates');

    // Member self-service routes
    Route::middleware(['permission:leave.own.view'])->group(function () {
        Route::get('/leaves-employee', [LeaveController::class, 'index1'])->name('leaves-employee');
        Route::post('/leave-add', [LeaveController::class, 'create'])->name('leave-add');
        Route::post('/leave-update', [LeaveController::class, 'update'])->name('leave-update');
        Route::delete('/leave-delete', [LeaveController::class, 'delete'])->name('leave-delete');
        Route::get('/leaves-paginate', [LeaveController::class, 'paginate'])->name('leaves.paginate');
        Route::get('/leaves-stats', [LeaveController::class, 'stats'])->name('leaves.stats');
    });

    // Attendance self-service routes
    Route::middleware(['permission:attendance.own.view'])->group(function () {
        Route::get('/attendance-employee', [AttendanceController::class, 'index2'])->name('attendance-employee');
        Route::get('/attendance/attendance-today', [AttendanceController::class, 'getCurrentUserPunch'])->name('attendance.current-user-punch');
        Route::get('/get-current-user-attendance-for-date', [AttendanceController::class, 'getCurrentUserAttendanceForDate'])->name('getCurrentUserAttendanceForDate');
    });

    // Punch route - unified validated flow only
    Route::middleware(['permission:attendance.own.punch', 'attendance.rate_limit'])->group(function () {
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])->name('attendance.punch');
    });

    // Attendance export routes - secured with permissions and rate limiting
    Route::middleware(['permission:attendance.export', 'throttle:10,1'])->group(function () {
        Route::get('/attendance/export/excel', [AttendanceController::class, 'exportExcel'])->name('attendance.exportExcel');
        Route::get('/attendance/export/pdf', [AttendanceController::class, 'exportPdf'])->name('attendance.exportPdf');
    });

    Route::middleware(['permission:attendance.manage', 'throttle:10,1'])->group(function () {
        Route::get('/admin/attendance/export/excel', [AttendanceController::class, 'exportAdminExcel'])->name('attendance.exportAdminExcel');
        Route::get('/admin/attendance/export/pdf', [AttendanceController::class, 'exportAdminPdf'])->name('attendance.exportAdminPdf');
    });
    Route::get('/get-all-users-attendance-for-date', [AttendanceController::class, 'getAllUsersAttendanceForDate'])->name('getAllUsersAttendanceForDate');
    Route::get('/get-present-users-for-date', [AttendanceController::class, 'getPresentUsersForDate'])->name('getPresentUsersForDate');
    Route::get('/get-absent-users-for-date', [AttendanceController::class, 'getAbsentUsersForDate'])->name('getAbsentUsersForDate');
    Route::get('/get-client-ip', [AttendanceController::class, 'getClientIp'])->name('getClientIp');

    // Daily works routes
    Route::middleware(['permission:daily-works.view'])->group(function () {
        Route::get('/daily-works', [DailyWorkController::class, 'index'])->name('daily-works');
        Route::get('/daily-works-paginate', [DailyWorkController::class, 'paginate'])->name('dailyWorks.paginate');
        Route::get('/daily-works-all', [DailyWorkController::class, 'all'])->name('dailyWorks.all');
        
        // Daily works analytics routes (consolidated)
        Route::get('/daily-works-analytics', [DailyWorksAnalyticsController::class, 'index'])->name('daily-works-analytics');
        Route::get('/daily-works-analytics/dashboard', [DailyWorksAnalyticsController::class, 'getDashboard'])->name('daily-works-analytics.dashboard');
        Route::get('/daily-works-analytics/summary', [DailyWorksAnalyticsController::class, 'getSummary'])->name('daily-works-analytics.summary');
        Route::get('/daily-works-analytics/analytics', [DailyWorksAnalyticsController::class, 'getAnalytics'])->name('daily-works-analytics.analytics');

        // Routes that incharge/assigned users can access (authorization checked in controller)
        Route::post('/daily-works/status', [DailyWorkController::class, 'updateStatus'])->name('dailyWorks.updateStatus');
        Route::post('/daily-works/completion-time', [DailyWorkController::class, 'updateCompletionTime'])->name('dailyWorks.updateCompletionTime');
        Route::post('/daily-works/submission-time', [DailyWorkController::class, 'updateSubmissionTime'])->name('dailyWorks.updateSubmissionTime');
        Route::post('/daily-works/bulk-submit', [DailyWorkController::class, 'bulkSubmit'])->name('dailyWorks.bulkSubmit');
        Route::post('/daily-works/bulk-import-submit', [DailyWorkController::class, 'bulkImportSubmit'])->name('dailyWorks.bulkImportSubmit');
        Route::get('/daily-works/bulk-import-template', [DailyWorkController::class, 'downloadBulkImportTemplate'])->name('dailyWorks.bulkImportTemplate');
        Route::post('/daily-works/bulk-response-status', [DailyWorkController::class, 'bulkResponseStatusUpdate'])->name('dailyWorks.bulkResponseStatusUpdate');
        Route::post('/daily-works/bulk-import-response-status', [DailyWorkController::class, 'bulkImportResponseStatus'])->name('dailyWorks.bulkImportResponseStatus');
        Route::get('/daily-works/response-status-template', [DailyWorkController::class, 'downloadResponseStatusTemplate'])->name('dailyWorks.downloadResponseStatusTemplate');

        // Mobile-optimized routes
        Route::get('/mobile/daily-works', [DailyWorkController::class, 'mobileDailyWorks'])->name('dailyWorks.mobile.dailyWorks');
        Route::get('/mobile/daily-works/recent', [DailyWorkController::class, 'mobileRecentWorks'])->name('dailyWorks.mobile.recentWorks');
        Route::get('/mobile/daily-works/statistics', [DailyWorkController::class, 'mobileStatistics'])->name('dailyWorks.mobile.statistics');
        Route::get('/mobile/daily-works/{id}', [DailyWorkController::class, 'mobileWorkDetails'])->name('dailyWorks.mobile.workDetails');
        Route::get('/mobile/daily-works/realtime-stats', [DailyWorkController::class, 'mobileRealtimeStats'])->name('dailyWorks.mobile.realtimeStats');

        // Real-time updates using Server-Sent Events (SSE)
        Route::get('/daily-works/realtime/stream', [DailyWorkRealtimeController::class, 'stream'])->name('dailyWorks.realtime.stream');
        Route::get('/daily-works/realtime/updates', [DailyWorkRealtimeController::class, 'getUpdates'])->name('dailyWorks.realtime.updates');
        Route::get('/daily-works/realtime/test', [DailyWorkRealtimeController::class, 'test'])->name('dailyWorks.realtime.test');

        Route::post('/daily-works/assigned', [DailyWorkController::class, 'updateAssigned'])->name('dailyWorks.updateAssigned');
        Route::post('/update-rfi-file', [DailyWorkController::class, 'uploadRFIFile'])->name('dailyWorks.uploadRFI');
        Route::post('/daily-works/inspection-details', [DailyWorkController::class, 'updateInspectionDetails'])->name('dailyWorks.updateInspectionDetails');

        // RFI File Management routes
        Route::post('/daily-works/{dailyWork}/rfi-files', [DailyWorkController::class, 'uploadRfiFiles'])->name('dailyWorks.rfiFiles.upload');
        Route::get('/daily-works/{dailyWork}/rfi-files', [DailyWorkController::class, 'getRfiFiles'])->name('dailyWorks.rfiFiles.index');
        Route::delete('/daily-works/{dailyWork}/rfi-files/{mediaId}', [DailyWorkController::class, 'deleteRfiFile'])->name('dailyWorks.rfiFiles.delete');
        Route::get('/daily-works/{dailyWork}/rfi-files/{mediaId}/download', [DailyWorkController::class, 'downloadRfiFile'])->name('dailyWorks.rfiFiles.download');

        // RFI Objection Routes - View and metadata
        Route::get('/daily-works/{dailyWork}/objections', [RfiObjectionController::class, 'index'])->name('dailyWorks.objections.index');
        Route::get('/daily-works/{dailyWork}/objections/available', [RfiObjectionController::class, 'available'])->name('dailyWorks.objections.available');
        Route::get('/daily-works/{dailyWork}/objections/{objection}', [RfiObjectionController::class, 'show'])->name('dailyWorks.objections.show');
        Route::get('/daily-works/objections/metadata', [RfiObjectionController::class, 'getMetadata'])->name('dailyWorks.objections.metadata');
        Route::get('/daily-works/{dailyWork}/objections/{objection}/files', [RfiObjectionController::class, 'getFiles'])->name('dailyWorks.objections.files');
        Route::get('/daily-works/{dailyWork}/objections/{objection}/files/{mediaId}/download', [RfiObjectionController::class, 'downloadFile'])->name('dailyWorks.objections.files.download');

        // RFI Objection Routes - Create and manage (authorization handled by policy)
        Route::post('/daily-works/{dailyWork}/objections', [RfiObjectionController::class, 'store'])->name('dailyWorks.objections.store');
        Route::post('/daily-works/{dailyWork}/objections/attach', [RfiObjectionController::class, 'attach'])->name('dailyWorks.objections.attach');
        Route::post('/daily-works/{dailyWork}/objections/detach', [RfiObjectionController::class, 'detach'])->name('dailyWorks.objections.detach');
        Route::put('/daily-works/{dailyWork}/objections/{objection}', [RfiObjectionController::class, 'update'])->name('dailyWorks.objections.update');
        Route::delete('/daily-works/{dailyWork}/objections/{objection}', [RfiObjectionController::class, 'destroy'])->name('dailyWorks.objections.destroy');
        Route::post('/daily-works/{dailyWork}/objections/{objection}/submit', [RfiObjectionController::class, 'submit'])->name('dailyWorks.objections.submit');
        Route::post('/daily-works/{dailyWork}/objections/{objection}/files', [RfiObjectionController::class, 'uploadFiles'])->name('dailyWorks.objections.files.upload');
        Route::delete('/daily-works/{dailyWork}/objections/{objection}/files/{mediaId}', [RfiObjectionController::class, 'deleteFile'])->name('dailyWorks.objections.files.delete');

        // RFI Objection Routes - Review actions (authorization handled by policy)
        Route::post('/daily-works/{dailyWork}/objections/{objection}/review', [RfiObjectionController::class, 'startReview'])->name('dailyWorks.objections.review');
        Route::post('/daily-works/{dailyWork}/objections/{objection}/resolve', [RfiObjectionController::class, 'resolve'])->name('dailyWorks.objections.resolve');
        Route::post('/daily-works/{dailyWork}/objections/{objection}/reject', [RfiObjectionController::class, 'reject'])->name('dailyWorks.objections.reject');

        // Dedicated Objections Management Routes (many-to-many architecture)
        Route::get('/workspace/objections', [ObjectionController::class, 'index'])->name('objections.index');
        Route::post('/workspace/objections', [ObjectionController::class, 'store'])->name('objections.store');
        Route::put('/workspace/objections/{objection}', [ObjectionController::class, 'update'])->name('objections.update');
        Route::delete('/workspace/objections/{objection}', [ObjectionController::class, 'destroy'])->name('objections.destroy');
        Route::post('/workspace/objections/{objection}/attach-rfis', [ObjectionController::class, 'attachToRfis'])->name('objections.attachRfis');
        Route::post('/workspace/objections/{objection}/detach-rfis', [ObjectionController::class, 'detachFromRfis'])->name('objections.detachRfis');
        Route::get('/workspace/objections/suggest-rfis', [ObjectionController::class, 'suggestRfis'])->name('objections.suggestRfis');
        Route::get('/workspace/objections/export-suggested-rfis', [ObjectionController::class, 'exportSuggestedRfis'])->name('objections.exportSuggestedRfis');

        // Status flow routes for Objections
        Route::post('/workspace/objections/{objection}/submit', [ObjectionController::class, 'submit'])->name('objections.submit');
        Route::post('/workspace/objections/{objection}/review', [ObjectionController::class, 'review'])->name('objections.review');
        Route::post('/workspace/objections/{objection}/resolve', [ObjectionController::class, 'resolve'])->name('objections.resolve');
        Route::post('/workspace/objections/{objection}/reject', [ObjectionController::class, 'reject'])->name('objections.reject');

        // Objections Export - secured with permissions and rate limiting
        Route::middleware(['permission:daily-works.export', 'throttle:10,1'])->group(function () {
            Route::get('/workspace/objections/export', [ObjectionController::class, 'export'])->name('objections.export');
        });
    });

    Route::middleware(['permission:daily-works.create'])->group(function () {
        Route::post('/add-daily-work', [DailyWorkController::class, 'add'])->name('dailyWorks.add');
    });

    Route::middleware(['permission:daily-works.update'])->group(function () {
        Route::post('/update-daily-work', [DailyWorkController::class, 'update'])->name('dailyWorks.update');
        Route::post('/daily-works/incharge', [DailyWorkController::class, 'updateIncharge'])->name('dailyWorks.updateIncharge');
        Route::post('/daily-works/assign', [DailyWorkController::class, 'assignWork'])->name('dailyWorks.assign');
    });

    Route::middleware(['permission:daily-works.delete'])->group(function () {
        Route::delete('/delete-daily-work', [DailyWorkController::class, 'delete'])->name('dailyWorks.delete');
    });

    Route::middleware(['permission:daily-works.export', 'throttle:10,1'])->group(function () {
        Route::post('/daily-works/export', [DailyWorkController::class, 'export'])->name('dailyWorks.export');
        Route::get('/daily-works-analytics/export', [DailyWorksAnalyticsController::class, 'exportFiltered'])->name('daily-works-analytics.export');
        Route::get('/daily-works/export-objected-rfis', [DailyWorkController::class, 'exportObjectedRfis'])->name('dailyWorks.exportObjectedRfis');
    });

    // Holiday routes (Legacy - redirects to Time Off Management)
    Route::middleware(['permission:holidays.view'])->group(function () {
        Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays');
        Route::post('/holidays-add', [HolidayController::class, 'create'])->name('holidays-add');
        Route::delete('/holidays-delete', [HolidayController::class, 'delete'])->name('holidays-delete');

        // Legacy redirect for old holiday routes
        Route::get('/holidays-legacy', [HolidayController::class, 'index'])->name('holidays-legacy');
    });

    // Profile Routes - own profile access
    Route::middleware(['permission:profile.own.view'])->group(function () {
        Route::get('/profile/{user}', [ProfileController::class, 'index'])->name('profile');
        Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile/delete', [ProfileController::class, 'delete'])->name('profile.delete');

        // Profile Image Routes - dedicated endpoints for profile image management
        Route::post('/profile/image/upload', [ProfileImageController::class, 'upload'])->name('profile.image.upload');
        Route::delete('/profile/image/remove', [ProfileImageController::class, 'remove'])->name('profile.image.remove');

        // New API endpoints for enhanced profile functionality (consistent with other modules)
        Route::get('/profile/{user}/stats', [ProfileController::class, 'stats'])->name('profile.stats');
        
        // Profile export - secured with permissions and rate limiting
        Route::middleware(['permission:users.view', 'throttle:10,1'])->group(function () {
            Route::get('/profile/{user}/export', [ProfileController::class, 'export'])->name('profile.export');
        });
        
        Route::post('/profile/{user}/track-view', [ProfileController::class, 'trackView'])->name('profile.trackView');

        // Education Routes:
        Route::post('/education/update', [EducationController::class, 'update'])->name('education.update');
        Route::delete('/education/delete', [EducationController::class, 'delete'])->name('education.delete');

        // Experience Routes:
        Route::post('/experience/update', [ExperienceController::class, 'update'])->name('experience.update');
        Route::delete('/experience/delete', [ExperienceController::class, 'delete'])->name('experience.delete');
    });

    // Communications routes
    Route::middleware(['permission:communications.own.view'])->get('/emails', [EmailController::class, 'index'])->name('emails');

    // Leave summary route
    Route::middleware(['permission:leaves.view,leaves.own.view'])->get('/leave-summary', [LeaveController::class, 'summary'])->name('leave.summary');
});

// Administrative routes - require specific permissions
Route::middleware(['auth', 'verified'])->group(function () {

    // Document management routes
    Route::middleware(['permission:letters.view'])->group(function () {
        Route::get('/letters', [LetterController::class, 'index'])->name('letters');
        Route::get('/letters-paginate', [LetterController::class, 'paginate'])->name('letters.paginate');
    });

    Route::middleware(['permission:letters.update'])->put('/letters-update', [LetterController::class, 'update'])->name('letters.update');    // Leave management routes
    Route::middleware(['permission:leaves.view'])->group(function () {
        Route::get('/leaves', [LeaveController::class, 'index2'])->name('leaves');
        Route::get('/leave-summary', [LeaveController::class, 'leaveSummary'])->name('leave-summary');
        Route::post('/leave-update-status', [LeaveController::class, 'updateStatus'])->name('leave-update-status');

        // Leave summary export routes - secured with permissions and rate limiting
        Route::middleware(['permission:leaves.export', 'throttle:10,1'])->group(function () {
            Route::get('/leave-summary/export/excel', [LeaveController::class, 'exportExcel'])->name('leave.summary.exportExcel');
            Route::get('/leave-summary/export/pdf', [LeaveController::class, 'exportPdf'])->name('leave.summary.exportPdf');
        });

        // Leave analytics
        Route::get('/leaves/analytics', [LeaveController::class, 'getAnalytics'])->name('leaves.analytics');

        // Approval workflow
        Route::get('/leaves/pending-approvals', [LeaveController::class, 'pendingApprovals'])->name('leaves.pending-approvals');
    });

    // Leave bulk operations (admin only)
    Route::middleware(['permission:leaves.approve'])->group(function () {
        Route::post('/leaves/bulk-approve', [LeaveController::class, 'bulkApprove'])->name('leaves.bulk-approve');
        Route::post('/leaves/bulk-reject', [LeaveController::class, 'bulkReject'])->name('leaves.bulk-reject');

        // Approval workflow actions
        Route::post('/leaves/{id}/approve', [LeaveController::class, 'approveLeave'])->name('leaves.approve');
        Route::post('/leaves/{id}/reject', [LeaveController::class, 'rejectLeave'])->name('leaves.reject');
    });

    // Bulk leave creation routes
    Route::middleware(['permission:leaves.create'])->group(function () {
        Route::post('/leaves/bulk/validate', [BulkLeaveController::class, 'validateDates'])->name('leaves.bulk.validate');
        Route::post('/leaves/bulk', [BulkLeaveController::class, 'store'])->name('leaves.bulk.store');
        Route::get('/leaves/bulk/leave-types', [BulkLeaveController::class, 'getLeaveTypes'])->name('leaves.bulk.leave-types');
        Route::get('/leaves/bulk/calendar-data', [BulkLeaveController::class, 'getCalendarData'])->name('leaves.bulk.calendar-data');
    });

    // Bulk leave deletion route
    Route::middleware(['permission:leaves.delete'])->group(function () {
        Route::delete('/leaves/bulk', [BulkLeaveController::class, 'bulkDelete'])->name('leaves.bulk.delete');
    });

    // Leave settings routes
    Route::middleware(['permission:leave-settings.update'])->group(function () {
        Route::get('/leave-settings', [LeaveSettingController::class, 'index'])->name('leave-settings');
        Route::post('/add-leave-type', [LeaveSettingController::class, 'store'])->name('add-leave-type');
        Route::put('/update-leave-type/{id}', [LeaveSettingController::class, 'update'])->name('update-leave-type');
        Route::delete('/delete-leave-type/{id}', [LeaveSettingController::class, 'destroy'])->name('delete-leave-type');
    });

    // HR Management routes
    Route::middleware(['permission:employees.view'])->group(function () {
        Route::get('/employees', [\App\Http\Controllers\MemberController::class, 'index'])->name('employees');
        Route::get('/employees/paginate', [\App\Http\Controllers\MemberController::class, 'paginate'])->name('employees.paginate');
        Route::get('/employees/stats', [\App\Http\Controllers\MemberController::class, 'stats'])->name('employees.stats');
    });

    // Department management routes
    Route::middleware(['permission:departments.view'])->get('/departments', [DepartmentController::class, 'index'])->name('departments');
    Route::middleware(['permission:departments.view'])->get('/api/departments', [DepartmentController::class, 'getDepartments'])->name('api.departments');
    Route::middleware(['permission:departments.view'])->get('/departments/stats', [DepartmentController::class, 'getStats'])->name('departments.stats');
    Route::middleware(['permission:departments.create'])->post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::middleware(['permission:departments.view'])->get('/departments/{id}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::middleware(['permission:departments.update'])->put('/departments/{id}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::middleware(['permission:departments.delete'])->delete('/departments/{id}', [DepartmentController::class, 'destroy'])->name('departments.delete');
    Route::middleware(['permission:departments.update'])->put('/users/{id}/department', [DepartmentController::class, 'updateUserDepartment'])->name('users.update-department');

    Route::middleware(['permission:jurisdiction.view'])->get('/jurisdiction', [JurisdictionController::class, 'index'])->name('jurisdiction');

    // Daily works management routes
    Route::middleware(['permission:daily-works.import'])->post('/preview-daily-works-import', [DailyWorkController::class, 'previewImport'])->name('dailyWorks.previewImport');
    Route::middleware(['permission:daily-works.import', 'throttle:10,1'])->post('/import-daily-works/', [DailyWorkController::class, 'import'])->name('dailyWorks.import');
    Route::middleware(['permission:daily-works.import'])->get('/download-daily-works-template', [DailyWorkController::class, 'downloadTemplate'])->name('dailyWorks.downloadTemplate');
    Route::middleware(['permission:daily-works.delete'])->delete('/delete-daily-work', [DailyWorkController::class, 'delete'])->name('dailyWorks.delete');

    // Holiday management routes
    Route::middleware(['permission:holidays.create'])->post('/holiday-add', [HolidayController::class, 'create'])->name('holiday-add');
    Route::middleware(['permission:holidays.delete'])->delete('/holiday-delete', [HolidayController::class, 'delete'])->name('holiday-delete');

    // User management routes - CONSOLIDATED & REFACTORED
    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('/users', [UserController::class, 'index2'])->name('users');
        Route::get('/users/paginate', [UserController::class, 'paginate'])->name('users.paginate');
        Route::get('/users/stats', [UserController::class, 'stats'])->name('users.stats');

        // Profile search for admin usage (consistent with other modules)
        Route::get('/profiles/search', [ProfileController::class, 'search'])->name('profiles.search');
    });

    Route::middleware(['permission:users.create'])->group(function () {
        Route::post('/users', [UserController::class, 'store'])
            ->middleware(['precognitive'])
            ->name('users.store');
        // Legacy route for backward compatibility
        Route::post('/users/legacy', [ProfileController::class, 'store'])->name('addUser');
    });

    Route::middleware(['permission:users.update'])->group(function () {
        Route::put('/users/{id}', [UserController::class, 'update'])
            ->middleware(['precognitive'])
            ->name('users.update');
        Route::put('/users/{id}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggleStatus');
        Route::post('/users/{id}/roles', [UserController::class, 'updateUserRole'])->name('users.updateRole');
        Route::post('/users/{id}/attendance-type', [UserController::class, 'updateUserAttendanceType'])->name('users.updateAttendanceType');
        Route::post('/users/{id}/report-to', [UserController::class, 'updateReportTo'])->name('users.updateReportTo');

        // Legacy routes for backward compatibility
        Route::post('/user/{id}/update-department', [DepartmentController::class, 'updateUserDepartment'])->name('user.updateDepartment');
        Route::post('/user/{id}/update-designation', [DesignationController::class, 'updateUserDesignation'])->name('user.updateDesignation');
        Route::post('/user/{id}/update-role', [UserController::class, 'updateUserRole'])->name('user.updateRole');
        Route::put('/user/toggle-status/{id}', [UserController::class, 'toggleStatus'])->name('user.toggleStatus');
        Route::post('/user/{id}/update-attendance-type', [UserController::class, 'updateUserAttendanceType'])->name('user.updateAttendanceType');
        Route::post('/user/{id}/update-report-to', [UserController::class, 'updateReportTo'])->name('user.updateReportTo');
    });

    Route::middleware(['permission:users.delete'])->group(function () {
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
        // Legacy route for backward compatibility
        Route::delete('/user/{id}', [MemberController::class, 'destroy'])->name('user.delete');
    });

    // SECURE DEVICE MANAGEMENT ROUTES (NEW SYSTEM)
    // User's own devices
    Route::get('/my-devices', [DeviceController::class, 'index'])->name('user.devices');
    Route::delete('/my-devices/{deviceId}', [DeviceController::class, 'deactivateDevice'])->name('user.devices.deactivate');

    // Admin device management
    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('/users/{userId}/devices', [DeviceController::class, 'getUserDevices'])->name('admin.users.devices');
    });

    Route::middleware(['permission:users.update'])->group(function () {
        Route::post('/users/{userId}/devices/reset', [DeviceController::class, 'resetDevices'])->name('admin.users.devices.reset');
        Route::post('/users/{userId}/devices/toggle', [DeviceController::class, 'toggleSingleDeviceLogin'])->name('admin.users.devices.toggle');
        Route::delete('/users/{userId}/devices/{deviceId}', [DeviceController::class, 'adminDeactivateDevice'])->name('admin.users.devices.deactivate');
    });

    // Company settings routes
    Route::middleware(['permission:company.settings'])->group(function () {
        Route::put('/update-company-settings', [CompanySettingController::class, 'update'])->name('update-company-settings');
        Route::get('/company-settings', [CompanySettingController::class, 'index'])->name('admin.settings.company');
    });    // Legacy role routes (maintained for backward compatibility)
    Route::middleware(['permission:roles.view'])->get('/roles-permissions', [RoleController::class, 'getRolesAndPermissions'])->name('roles-settings');

    // Document management routes
    Route::middleware(['permission:letters.view'])->get('/letters', [LetterController::class, 'index'])->name('letters');    // Attendance management routes
    Route::middleware(['permission:attendance.view'])->group(function () {
        Route::get('/attendances', [AttendanceController::class, 'index1'])->name('attendances');
        Route::get('/timesheet', [AttendanceController::class, 'index3'])->name('timesheet'); // New TimeSheet page route
        Route::get('/attendances-admin-paginate', [AttendanceController::class, 'paginate'])->name('attendancesAdmin.paginate');
        Route::get('/attendance/locations-today', [AttendanceController::class, 'getUserLocationsForDate'])->name('getUserLocationsForDate');
        Route::get('/admin/get-present-users-for-date', [AttendanceController::class, 'getPresentUsersForDate'])->name('admin.getPresentUsersForDate');
        Route::get('/admin/get-absent-users-for-date', [AttendanceController::class, 'getAbsentUsersForDate'])->name('admin.getAbsentUsersForDate');
        Route::get('/attendance/monthly-stats', [AttendanceController::class, 'getMonthlyAttendanceStats'])->name('attendance.monthlyStats');
        // Location and timesheet update check routes
        Route::get('check-user-locations-updates/{date}', [AttendanceController::class, 'checkForLocationUpdates'])
            ->name('check-user-locations-updates');
        Route::get('check-timesheet-updates/{date}/{month?}', [AttendanceController::class, 'checkTimesheetUpdates'])
            ->name('check-timesheet-updates');
    });

    // Attendance management routes (admin actions)
    Route::middleware(['permission:attendance.manage'])->group(function () {
        Route::post('/attendance/mark-as-present', [AttendanceController::class, 'markAsPresent'])->name('attendance.mark-as-present');
        Route::post('/attendance/bulk-mark-as-present', [AttendanceController::class, 'bulkMarkAsPresent'])->name('attendance.bulk-mark-as-present');
    });

    // Member attendance stats route
    Route::middleware(['permission:attendance.own.view'])->group(function () {
        Route::get('/attendance/my-monthly-stats', [AttendanceController::class, 'getMonthlyAttendanceStats'])->name('attendance.myMonthlyStats');
    });

    Route::middleware(['permission:attendance.settings'])->group(function () {
        Route::get('/settings/attendance', [AttendanceSettingController::class, 'index'])->name('attendance-settings.index');
        Route::post('/settings/attendance', [AttendanceSettingController::class, 'updateSettings'])->name('attendance-settings.update');
        Route::post('settings/attendance-type', [AttendanceSettingController::class, 'storeType'])->name('attendance-types.store');
        Route::put('settings/attendance-type/{id}', [AttendanceSettingController::class, 'updateType'])->name('attendance-types.update');
        Route::delete('settings/attendance-type/{id}', [AttendanceSettingController::class, 'destroyType'])->name('attendance-types.destroy');

        // Multi-config management routes
        Route::post('settings/attendance-type/{id}/add-item', [AttendanceSettingController::class, 'addConfigItem'])->name('attendance-types.addItem');
        Route::delete('settings/attendance-type/{id}/remove-item', [AttendanceSettingController::class, 'removeConfigItem'])->name('attendance-types.removeItem');
        Route::post('settings/attendance-type/{id}/generate-qr', [AttendanceSettingController::class, 'generateQrCode'])->name('attendance-types.generateQr');
    });

    // HR Module Settings
    Route::prefix('settings/hr')->middleware(['auth', 'verified'])->group(function () {
        Route::middleware(['permission:hr.onboarding.view'])->get('/onboarding', [\App\Http\Controllers\Settings\HrmSettingController::class, 'index'])->name('settings.hr.onboarding');
        Route::middleware(['permission:hr.skills.view'])->get('/skills', [\App\Http\Controllers\Settings\HrmSettingController::class, 'index'])->name('settings.hr.skills');
        Route::middleware(['permission:hr.benefits.view'])->get('/benefits', [\App\Http\Controllers\Settings\HrmSettingController::class, 'index'])->name('settings.hr.benefits');
        Route::middleware(['permission:hr.safety.view'])->get('/safety', [\App\Http\Controllers\Settings\HrmSettingController::class, 'index'])->name('settings.hr.safety');
        Route::middleware(['permission:hr.documents.view'])->get('/documents', [\App\Http\Controllers\Settings\HrmSettingController::class, 'index'])->name('settings.hr.documents');

        // Update routes
        Route::middleware(['permission:hr.onboarding.update'])->post('/onboarding', [\App\Http\Controllers\Settings\HrmSettingController::class, 'updateOnboardingSettings'])->name('settings.hr.onboarding.update');
        Route::middleware(['permission:hr.skills.update'])->post('/skills', [\App\Http\Controllers\Settings\HrmSettingController::class, 'updateSkillsSettings'])->name('settings.hr.skills.update');
        Route::middleware(['permission:hr.benefits.update'])->post('/benefits', [\App\Http\Controllers\Settings\HrmSettingController::class, 'updateBenefitsSettings'])->name('settings.hr.benefits.update');
        Route::middleware(['permission:hr.safety.update'])->post('/safety', [\App\Http\Controllers\Settings\HrmSettingController::class, 'updateSafetySettings'])->name('settings.hr.safety.update');
        Route::middleware(['permission:hr.documents.update'])->post('/documents', [\App\Http\Controllers\Settings\HrmSettingController::class, 'updateDocumentSettings'])->name('settings.hr.documents.update');
    });

    // Task management routes
    Route::middleware(['permission:tasks.view'])->group(function () {
        Route::get('/tasks-all', [TaskController::class, 'allTasks'])->name('allTasks');
        Route::post('/tasks-filtered', [TaskController::class, 'filterTasks'])->name('filterTasks');
    });

    Route::middleware(['permission:tasks.create'])->post('/task/add', [TaskController::class, 'addTask'])->name('addTask');

    // Jurisdiction/Work location routes
    Route::middleware(['permission:jurisdiction.view'])->group(function () {
        Route::get('/work-location', [JurisdictionController::class, 'showWorkLocations'])->name('showWorkLocations');
        Route::get('/work-location_json', [JurisdictionController::class, 'allWorkLocations'])->name('allWorkLocations');
    });

    Route::middleware(['permission:jurisdiction.create'])->post('/work-locations/add', [JurisdictionController::class, 'addWorkLocation'])->name('addWorkLocation');
    Route::middleware(['permission:jurisdiction.delete'])->post('/work-locations/delete', [JurisdictionController::class, 'deleteWorkLocation'])->name('deleteWorkLocation');
    Route::middleware(['permission:jurisdiction.update'])->post('/work-locations/update', [JurisdictionController::class, 'updateWorkLocation'])->name('updateWorkLocation');
});

Route::middleware(['auth', 'verified'])->group(function () {

    Route::get('/tasks-all-se', [TaskController::class, 'allTasks'])->name('allTasksSE');
    Route::post('/tasks-filtered-se', [TaskController::class, 'filterTasks'])->name('filterTasksSE');
    Route::get('/tasks/se', [TaskController::class, 'showTasks'])->name('showTasksSE');
    Route::post('/task/add-se', [TaskController::class, 'addTask'])->name('addTaskSE');
    Route::post('/task/update-inspection-details', [TaskController::class, 'updateInspectionDetails'])->name('updateInspectionDetails');
    Route::post('/task/update-status', [TaskController::class, 'updateTaskStatus'])->name('updateTaskStatus');
    Route::post('/task/assign', [TaskController::class, 'assignTask'])->name('assignTask');
    Route::post('/task/update-completion-date-time-se', [TaskController::class, 'updateCompletionDateTime'])->name('updateCompletionDateTimeSE');
    Route::get('/tasks/daily-summary-se', [DailyWorkSummaryController::class, 'showDailySummary', 'title' => 'Daily Summary'])->name('showDailySummarySE');
    Route::post('/tasks/daily-summary-filtered-se', [DailyWorkSummaryController::class, 'filterSummary'])->name('filterSummarySE');
    Route::get('/get-latest-timestamp', [TaskController::class, 'getLatestTimestamp'])->name('getLatestTimestamp');
    Route::get('/tasks/daily-summary-json', [DailyWorkSummaryController::class, 'dailySummary'])->name('dailySummaryJSON');

    Route::get('/reports', [ReportController::class, 'showReports'])->name('showReports');
    Route::get('/reports-json', [ReportController::class, 'allReports'])->name('allReports');
    Route::post('/reports/add', [ReportController::class, 'addReport'])->name('addReport');
    Route::post('/reports/delete', [ReportController::class, 'deleteReport'])->name('deleteReport');
    Route::post('/reports/update', [ReportController::class, 'updateReport'])->name('updateReport');
    Route::post('/tasks/attach-report', [TaskController::class, 'attachReport'])->name('attachReport');
    Route::post('/tasks/detach-report', [TaskController::class, 'detachReport'])->name('detachReport');
});

// Enhanced Role Management Routes (with proper permission-based access control)
Route::middleware(['auth', 'verified', 'permission:roles.view', 'role_permission_sync'])->group(function () {
    // Role Management Interface
    Route::get('/admin/roles-management', [RoleController::class, 'index'])->name('admin.roles-management');
    Route::get('/admin/roles/audit', [RoleController::class, 'getEnhancedRoleAudit'])->name('admin.roles.audit');
    
    // Role export - secured with permissions and rate limiting
    Route::middleware(['permission:roles.manage', 'throttle:10,1'])->group(function () {
        Route::get('/admin/roles/export', [RoleController::class, 'exportRoles'])->name('admin.roles.export');
    });
    
    Route::get('/admin/roles/metrics', [RoleController::class, 'getRoleMetrics'])->name('admin.roles.metrics');
    Route::get('/admin/roles/snapshot', [RoleController::class, 'snapshot'])->name('admin.roles.snapshot');
});

Route::middleware(['auth', 'verified', 'permission:roles.create'])->group(function () {
    Route::post('/admin/roles', [RoleController::class, 'storeRole'])->name('admin.roles.store');
    Route::post('/admin/roles/clone', [RoleController::class, 'cloneRole'])->name('admin.roles.clone');
});

Route::middleware(['auth', 'verified', 'permission:roles.update'])->group(function () {
    Route::put('/admin/roles/{id}', [RoleController::class, 'updateRole'])->name('admin.roles.update');
    Route::post('/admin/roles/update-permission', [RoleController::class, 'updateRolePermission'])->name('admin.roles.update-permission');
    Route::post('/admin/roles/toggle-permission', [RoleController::class, 'togglePermission'])->name('admin.roles.toggle-permission');
    Route::post('/admin/roles/update-module', [RoleController::class, 'updateRoleModule'])->name('admin.roles.update-module');
    Route::post('/admin/roles/bulk-operation', [RoleController::class, 'bulkOperation'])->name('admin.roles.bulk-operation');
    Route::patch('/admin/roles/{role}/permissions', [RoleController::class, 'batchUpdatePermissions'])->name('admin.roles.batch-permissions');
});

Route::middleware(['auth', 'verified', 'permission:roles.delete'])->group(function () {
    Route::delete('/admin/roles/{id}', [RoleController::class, 'deleteRole'])->name('admin.roles.delete');
});

// Super Administrator only routes
Route::middleware(['auth', 'verified', 'role:Super Administrator'])->group(function () {
    Route::post('/admin/roles/initialize-enterprise', [RoleController::class, 'initializeEnterpriseSystem'])->name('admin.roles.initialize-enterprise');
});

// Test route for role controller
Route::middleware(['auth', 'verified'])->get('/admin/roles-test', [RoleController::class, 'test'])->name('admin.roles.test');

// Module Permission Registry Management Routes
Route::middleware(['auth', 'verified', 'permission:modules.view'])->group(function () {
    Route::get('/admin/modules', [ModuleController::class, 'index'])->name('modules.index');
    Route::get('/admin/modules/api', [ModuleController::class, 'apiIndex'])->name('modules.api.index');
    Route::get('/admin/modules/statistics', [ModuleController::class, 'statistics'])->name('modules.statistics');
    Route::get('/admin/modules/check-access', [ModuleController::class, 'checkAccess'])->name('modules.check-access');
});

Route::middleware(['auth', 'verified', 'permission:modules.create'])->group(function () {
    Route::post('/admin/modules', [ModuleController::class, 'storeModule'])->name('modules.store');
    Route::post('/admin/modules/{module}/sub-modules', [ModuleController::class, 'storeSubModule'])->name('modules.sub-modules.store');
    Route::post('/admin/modules/sub-modules/{subModule}/components', [ModuleController::class, 'storeComponent'])->name('modules.components.store');
});

Route::middleware(['auth', 'verified', 'permission:modules.update'])->group(function () {
    Route::put('/admin/modules/{module}', [ModuleController::class, 'updateModule'])->name('modules.update');
    Route::put('/admin/modules/sub-modules/{subModule}', [ModuleController::class, 'updateSubModule'])->name('modules.sub-modules.update');
    Route::put('/admin/modules/components/{component}', [ModuleController::class, 'updateComponent'])->name('modules.components.update');
    Route::post('/admin/modules/{module}/sync-permissions', [ModuleController::class, 'syncModulePermissions'])->name('modules.sync-permissions');
    Route::post('/admin/modules/sub-modules/{subModule}/sync-permissions', [ModuleController::class, 'syncSubModulePermissions'])->name('modules.sub-modules.sync-permissions');
    Route::post('/admin/modules/components/{component}/sync-permissions', [ModuleController::class, 'syncComponentPermissions'])->name('modules.components.sync-permissions');
});

Route::middleware(['auth', 'verified', 'permission:modules.delete'])->group(function () {
    Route::delete('/admin/modules/{module}', [ModuleController::class, 'destroyModule'])->name('modules.destroy');
    Route::delete('/admin/modules/sub-modules/{subModule}', [ModuleController::class, 'destroySubModule'])->name('modules.sub-modules.destroy');
    Route::delete('/admin/modules/components/{component}', [ModuleController::class, 'destroyComponent'])->name('modules.components.destroy');
});

// Role Debug Routes (for troubleshooting live server issues)
Route::middleware(['auth', 'verified', 'role:Super Administrator'])->group(function () {
    Route::get('/admin/roles/debug', [App\Http\Controllers\RoleDebugController::class, 'debug'])->name('admin.roles.debug');
    Route::post('/admin/roles/debug/refresh-cache', [App\Http\Controllers\RoleDebugController::class, 'refreshCache'])->name('admin.roles.debug.refresh-cache');
    Route::get('/admin/roles/debug/test-role', [App\Http\Controllers\RoleDebugController::class, 'testRole'])->name('admin.roles.debug.test-role');
    Route::post('/admin/roles/debug/test-permission', [App\Http\Controllers\RoleDebugController::class, 'testPermissionAssignment'])->name('admin.roles.debug.test-permission');
});

// System Monitoring Routes (Super Administrator only)
Route::middleware(['auth', 'verified', 'role:Super Administrator'])->group(function () {
    Route::get('/admin/system-monitoring', [SystemMonitoringController::class, 'index'])->name('admin.system-monitoring');
    Route::post('/admin/errors/{errorId}/resolve', [SystemMonitoringController::class, 'resolveError'])->name('admin.errors.resolve');
    
    // System report export - secured with permissions and rate limiting
    Route::middleware(['permission:system.monitoring', 'throttle:10,1'])->group(function () {
        Route::get('/admin/system-report', [SystemMonitoringController::class, 'exportReport'])->name('admin.system-report');
    });
    
    Route::get('/admin/optimization-report', [SystemMonitoringController::class, 'getOptimizationReport'])->name('admin.optimization-report');



    // Designation Management
    Route::middleware(['permission:hr.designations.view'])->group(function () {
        // Initial page render (Inertia)
        Route::get('/designations', [\App\Http\Controllers\DesignationController::class, 'index'])->name('designations.index');
        // API data fetch (JSON)
        Route::get('/designations/json', [\App\Http\Controllers\DesignationController::class, 'getDesignations'])->name('designations.json');
        // Stats endpoint for frontend analytics
        Route::get('/designations/stats', [\App\Http\Controllers\DesignationController::class, 'stats'])->name('designations.stats');
        Route::post('/designations', [\App\Http\Controllers\DesignationController::class, 'store'])->name('designations.store');
        Route::get('/designations/{id}', [\App\Http\Controllers\DesignationController::class, 'show'])->name('designations.show');
        Route::put('/designations/{id}', [\App\Http\Controllers\DesignationController::class, 'update'])->name('designations.update');
        Route::delete('/designations/{id}', [\App\Http\Controllers\DesignationController::class, 'destroy'])->name('designations.destroy');
        // For dropdowns and API
        Route::get('/designations/list', [\App\Http\Controllers\DesignationController::class, 'list'])->name('designations.list');
    });
});

// API routes for dropdown data
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/api/designations/list', function () {
        return response()->json(\App\Models\HRM\Designation::select('id', 'title as name')->get());
    })->name('api.designations.list');

    Route::get('/api/departments/list', function () {
        return response()->json(\App\Models\HRM\Department::select('id', 'name')->get());
    })->name('departments.list');

    Route::get('/api/users/managers/list', function () {
        return response()->json(\App\Models\User::whereHas('roles', function ($query) {
            $query->whereIn('name', [
                'Super Administrator',
                'Administrator',
                'HR Manager',
                'Project Manager',
                'Department Manager',
                'Team Lead',
            ]);
        })
            ->select('id', 'name')
            ->get());
    })->name('users.managers.list');
});

Route::post('/update-fcm-token', [UserController::class, 'updateFcmToken'])->name('updateFcmToken');

// Service worker route for development
Route::get('/service-worker.js', function () {
    $filePath = public_path('service-worker.js');
    if (file_exists($filePath)) {
        return response()->file($filePath, [
            'Content-Type' => 'application/javascript',
            'Service-Worker-Allowed' => '/',
        ]);
    }
    abort(404);
})->name('service-worker');




require __DIR__.'/hr.php';
require __DIR__.'/auth.php';
