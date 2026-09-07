<?php

use App\Http\Controllers\Admin\ClientErrorController;
use App\Http\Controllers\Admin\DeviceSessionController;
use App\Http\Controllers\Admin\FeatureFlagController;
use App\Http\Controllers\Admin\NotificationSettingsController;
use App\Http\Controllers\Aeon\AeonController;
use App\Http\Controllers\Aeon\AeonPageController;
use App\Http\Controllers\ApkDownloadController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\BulkLeaveController;
use App\Http\Controllers\DailyWorkController;
use App\Http\Controllers\DailyWorkSummaryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DesignationController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EducationController;
use App\Http\Controllers\ExperienceController;
use App\Http\Controllers\FirebaseTokenController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\HolidayController;
use App\Http\Controllers\HRM\CompOffController;
use App\Http\Controllers\HRM\CoverageController;
use App\Http\Controllers\HRM\CoverageRequirementController;
use App\Http\Controllers\HRM\OvertimeController;
use App\Http\Controllers\HRM\PolicyController;
use App\Http\Controllers\HRM\PunchExceptionController;
use App\Http\Controllers\HRM\RegularizationController;
use App\Http\Controllers\HRM\RosterController;
use App\Http\Controllers\HRM\ShiftController;
use App\Http\Controllers\HRM\ShiftSwapController;
use App\Http\Controllers\JurisdictionController;
use App\Http\Controllers\LeaveBalanceController;
use App\Http\Controllers\LeaveController;
use App\Http\Controllers\LetterController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\NotificationPreferenceController;
use App\Http\Controllers\ObjectionController;
use App\Http\Controllers\OperationsMaintenanceController;
use App\Http\Controllers\OmRenovationController;
use App\Http\Controllers\OmLookupController;
use App\Http\Controllers\PettyCashController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileImageController;
use App\Http\Controllers\Quality\NcrController;
use App\Http\Controllers\RfiObjectionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\Settings\AttendanceSettingController;
use App\Http\Controllers\Settings\BiometricDeviceController;
use App\Http\Controllers\Settings\CompanySettingController;
use App\Http\Controllers\Settings\LeaveSettingController;
use App\Http\Controllers\Settings\RequestLogController;
use App\Http\Controllers\SystemMonitoringController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkLocationController;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/install-app', function () {
    return Inertia::render('InstallApp');
})->name('install-app');

Route::get('/apk/latest.apk', [ApkDownloadController::class, 'latest'])->name('apk.download');

// Include authentication routes
require __DIR__.'/auth.php';

Route::get('/', function (Request $request) {
    $userAgent = $request->header('User-Agent', '');
    $isAndroid = stripos($userAgent, 'android') !== false;

    return redirect($isAndroid ? '/install-app' : '/dashboard');
});

Route::get('/session-check', function () {
    return response()->json(['authenticated' => auth()->check()]);
});

Route::get('/csrf-token', function () {
    return response()->json(['csrf_token' => csrf_token()]);
});

// Locale switching route (for dynamic translations) - client-side only, no server redirect
Route::post('/locale', function (Request $request) {
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

    // Firebase custom-token endpoint — lets the browser sign in to Firebase (signInWithCustomToken)
    Route::get('/firebase/token', FirebaseTokenController::class)->name('firebase.token');

    // Dashboard routes - require dashboard permission
    Route::middleware(['permission:core.dashboard.view'])->group(function () {
        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('/dashboard/command', [DashboardController::class, 'command'])->name('dashboard.command');
        Route::get('/stats', [DashboardController::class, 'stats'])->name('stats');
    });

    // Employee Dashboard route
    Route::get('/employee-dashboard', [DashboardController::class, 'employeeIndex'])->name('employee-dashboard');
    Route::get('/search', GlobalSearchController::class)->name('search');

    // Quality — NCR register (full CRUD + status workflow)
    Route::prefix('quality')->name('quality.')->group(function () {
        Route::middleware('permission:quality.ncr.view')->group(function () {
            Route::get('/ncr', [NcrController::class, 'index'])->name('ncr.index');
        });
        Route::middleware('permission:quality.ncr.create')->group(function () {
            Route::post('/ncr', [NcrController::class, 'store'])->name('ncr.store');
        });
        Route::middleware('permission:quality.ncr.update')->group(function () {
            Route::put('/ncr/{ncr}', [NcrController::class, 'update'])->name('ncr.update');
            Route::patch('/ncr/{ncr}/transition', [NcrController::class, 'transition'])->name('ncr.transition');
        });
        Route::middleware('permission:quality.ncr.delete')->group(function () {
            Route::delete('/ncr/{ncr}', [NcrController::class, 'destroy'])->name('ncr.destroy');
        });
    });

    // Security Dashboard route - available to authenticated users
    Route::get('/security/dashboard', function () {
        return redirect()->route('user.devices');
    })->name('security.dashboard');

    // Updates route - require updates permission
    Route::middleware(['permission:core.updates.view'])->get('/updates', [DashboardController::class, 'updates'])->name('updates');

    // Employee self-service routes
    Route::middleware(['permission:leave.own.view'])->group(function () {
        Route::get('/leaves-employee', [LeaveController::class, 'index1'])->name('leaves-employee');
        Route::post('/leave-add', [LeaveController::class, 'create'])->name('leave-add');
        Route::post('/leave-update', [LeaveController::class, 'update'])->name('leave-update');
        Route::delete('/leave-delete', [LeaveController::class, 'delete'])->name('leave-delete');
        Route::post('/leaves/{id}/cancel', [LeaveController::class, 'cancelLeave'])->name('leaves.cancel');
        Route::get('/leaves/{id}/attachments/{mediaId}', [LeaveController::class, 'downloadAttachment'])->name('leaves.attachments.download');
        Route::delete('/leaves/{id}/attachments/{mediaId}', [LeaveController::class, 'deleteAttachment'])->name('leaves.attachments.delete');
        Route::get('/leaves-paginate', [LeaveController::class, 'paginate'])->name('leaves.paginate');
        Route::get('/leaves-stats', [LeaveController::class, 'stats'])->name('leaves.stats');
        Route::get('/leave-balances', [LeaveBalanceController::class, 'index'])->name('leave-balances');
        Route::get('/leave-ledger', [LeaveBalanceController::class, 'ledger'])->name('leave-ledger');
    });

    // Attendance self-service routes
    Route::middleware(['permission:attendance.own.view'])->group(function () {
        Route::get('/attendance-employee', [AttendanceController::class, 'index2'])->name('attendance-employee');
        Route::get('/attendance/attendance-today', [AttendanceController::class, 'getCurrentUserPunch'])->name('attendance.current-user-punch');
        Route::get('/get-current-user-attendance-for-date', [AttendanceController::class, 'getCurrentUserAttendanceForDate'])->name('getCurrentUserAttendanceForDate');
        Route::get('/attendance/my-roster', [RosterController::class, 'myRoster'])->name('attendance.myRoster');
        Route::post('/attendance/swaps', [ShiftSwapController::class, 'store'])->name('attendance.swaps.store');
        Route::get('/attendance/swaps/awaiting-me', [ShiftSwapController::class, 'awaitingMe'])->name('attendance.swaps.awaitingMe');
        Route::get('/attendance/swaps/mine', [ShiftSwapController::class, 'mine'])->name('attendance.swaps.mine');
        Route::post('/attendance/swaps/{id}/respond', [ShiftSwapController::class, 'respond'])->name('attendance.swaps.respond');
        Route::get('/attendance/swaps/eligible', [ShiftSwapController::class, 'eligible'])->name('attendance.swaps.eligible');
        Route::get('/attendance/swaps/counterparty-roster', [ShiftSwapController::class, 'counterpartyRoster'])->name('attendance.swaps.counterpartyRoster');
        Route::get('/attendance/swaps/pickup', [ShiftSwapController::class, 'pickup'])->name('attendance.swaps.pickup');
        Route::post('/attendance/regularizations', [RegularizationController::class, 'store'])->name('attendance.regularizations.store');
        Route::get('/attendance/regularizations/mine', [RegularizationController::class, 'mine'])->name('attendance.regularizations.mine');
        Route::post('/attendance/overtime', [OvertimeController::class, 'store'])->name('attendance.overtime.store');
        Route::get('/attendance/overtime/mine', [OvertimeController::class, 'mine'])->name('attendance.overtime.mine');
        Route::get('/attendance/comp-off/mine', [CompOffController::class, 'mine'])->name('attendance.compoff.mine');
    });

    // Punch route - unified validated flow only
    Route::middleware(['permission:attendance.own.punch', 'attendance.rate_limit'])->group(function () {
        Route::post('/attendance/punch', [AttendanceController::class, 'punch'])->name('attendance.punch');
    });

    // General access routes (available to all authenticated users with attendance permissions)
    Route::middleware(['permission:attendance.view'])->group(function () {
        Route::get('/attendance/export/excel', [AttendanceController::class, 'exportExcel'])->name('attendance.exportExcel');
        Route::get('/admin/attendance/export/excel', [AttendanceController::class, 'exportAdminExcel'])->name('attendance.exportAdminExcel');
        Route::get('/admin/attendance/export/pdf', [AttendanceController::class, 'exportAdminPdf'])->name('attendance.exportAdminPdf');
        Route::get('/attendance/export/pdf', [AttendanceController::class, 'exportPdf'])->name('attendance.exportPdf');
        Route::get('/get-present-users-for-date', [AttendanceController::class, 'getPresentUsersForDate'])->name('getPresentUsersForDate');
        Route::get('/get-absent-users-for-date', [AttendanceController::class, 'getAbsentUsersForDate'])->name('getAbsentUsersForDate');
        Route::get('/get-client-ip', [AttendanceController::class, 'getClientIp'])->name('getClientIp');

        // Dedicated route for polling export status without hitting Nginx static file handlers
        Route::get('/attendance/export/status/{filename}', [AttendanceController::class, 'checkExportStatus'])->name('attendance.exportStatus');
    });

    // Daily works routes
    Route::middleware(['permission:daily-works.view'])->group(function () {
        // Unified daily works page (new consolidated page)
        Route::get('/daily-works-unified', [DailyWorkController::class, 'unified'])->name('daily-works-unified');

        // Original routes (hidden - kept for rollback)
        // Route::get('/daily-works', [DailyWorkController::class, 'index'])->name('daily-works');
        // Route::get('/daily-works-summary', [DailyWorkSummaryController::class, 'index'])->name('daily-works-summary');

        Route::get('/daily-works-paginate', [DailyWorkController::class, 'paginate'])->name('dailyWorks.paginate');
        Route::get('/daily-works-all', [DailyWorkController::class, 'all'])->name('dailyWorks.all');
        // Route::get('/daily-works-summary', [DailyWorkSummaryController::class, 'index'])->name('daily-works-summary');
        Route::post('/daily-works-summary/filter', [DailyWorkSummaryController::class, 'filterSummary'])->name('daily-works-summary.filter');
        Route::get('/daily-works/statistics', [DailyWorkSummaryController::class, 'getStatistics'])->name('dailyWorks.statistics');

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
        Route::post('/daily-works/bulk-update-incharge', [DailyWorkController::class, 'bulkUpdateIncharge'])->name('dailyWorks.bulkUpdateIncharge');
        Route::post('/daily-works/bulk-update-status', [DailyWorkController::class, 'bulkUpdateStatus'])->name('dailyWorks.bulkUpdateStatus');
        Route::post('/daily-works/bulk-update-completion-date', [DailyWorkController::class, 'bulkUpdateCompletionDate'])->name('dailyWorks.bulkUpdateCompletionDate');
        Route::post('/daily-works/bulk-delete', [DailyWorkController::class, 'bulkDelete'])->name('dailyWorks.bulkDelete');
        Route::get('/daily-works/export-objected-rfis', [DailyWorkController::class, 'exportObjectedRfis'])->name('dailyWorks.exportObjectedRfis');
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

        // Objections Export
        Route::get('/workspace/objections/export', [ObjectionController::class, 'export'])->name('objections.export');
    });

    Route::middleware(['permission:daily-works.create'])->group(function () {
        Route::post('/add-daily-work', [DailyWorkController::class, 'add'])->name('dailyWorks.add');
    });

    Route::middleware(['permission:daily-works.update'])->group(function () {
        Route::post('/update-daily-work', [DailyWorkController::class, 'update'])->name('dailyWorks.update');
        Route::post('/daily-works-summary/refresh', [DailyWorkSummaryController::class, 'refresh'])->name('daily-works-summary.refresh');
        Route::post('/daily-works/incharge', [DailyWorkController::class, 'updateIncharge'])->name('dailyWorks.updateIncharge');
        Route::post('/daily-works/assign', [DailyWorkController::class, 'assignWork'])->name('dailyWorks.assign');
    });

    Route::middleware(['permission:daily-works.delete'])->group(function () {
        Route::delete('/delete-daily-work', [DailyWorkController::class, 'delete'])->name('dailyWorks.delete');
    });

    Route::middleware(['permission:daily-works.export'])->group(function () {
        Route::post('/daily-works/export', [DailyWorkController::class, 'export'])->name('dailyWorks.export');
        Route::post('/daily-works-summary/export', [DailyWorkSummaryController::class, 'exportDailySummary'])->name('daily-works-summary.export');
        Route::post('/daily-works-summary/export-excel', [DailyWorkSummaryController::class, 'exportExcel'])->name('daily-works-summary.export-excel');
        Route::post('/daily-works-summary/export-pdf', [DailyWorkSummaryController::class, 'exportPdf'])->name('daily-works-summary.export-pdf');
        Route::post('/daily-works-summary/analytics', [DailyWorkSummaryController::class, 'getAnalytics'])->name('daily-works-summary.analytics');
        Route::get('/daily-works-summary/statistics', [DailyWorkSummaryController::class, 'getStatistics'])->name('daily-works-summary.statistics');
    });

    // Holiday routes (Legacy - redirects to Time Off Management)
    Route::middleware(['permission:holidays.view'])->group(function () {
        Route::get('/holidays', [HolidayController::class, 'index'])->name('holidays');
        Route::get('/api/holidays', [HolidayController::class, 'listJson'])->name('api.holidays');
        Route::get('/api/holidays/stats', [HolidayController::class, 'stats'])->name('holidays.stats');
        Route::get('/api/holidays/{holiday}', [HolidayController::class, 'show'])->name('api.holidays.show');

        // Legacy redirect for old holiday routes
        Route::get('/holidays-legacy', [HolidayController::class, 'index'])->name('holidays-legacy');
    });
    Route::post('/holidays-add', [HolidayController::class, 'create'])
        ->middleware('permission:holidays.create')
        ->name('holidays-add');
    Route::delete('/holidays-delete', [HolidayController::class, 'delete'])
        ->middleware('permission:holidays.delete')
        ->name('holidays-delete');

    // Profile reads are owner-scoped unless the actor has user-directory access.
    Route::middleware(['permission:profile.own.view|users.view'])->group(function () {
        Route::get('/profile/{user}', [ProfileController::class, 'index'])->name('profile');
        Route::get('/profile/{user}/stats', [ProfileController::class, 'stats'])->name('profile.stats');
        Route::get('/profile/{user}/export', [ProfileController::class, 'export'])->name('profile.export');
        Route::post('/profile/{user}/track-view', [ProfileController::class, 'trackView'])->name('profile.trackView');
    });

    // Profile writes are independently owner/administrator authorized in each controller.
    Route::middleware(['permission:profile.own.update|users.update'])->group(function () {
        Route::post('/profile/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('/profile/delete', [ProfileController::class, 'destroy'])->name('profile.delete');

        // Profile Image Routes - dedicated endpoints for profile image management
        Route::post('/profile/image/upload', [ProfileImageController::class, 'upload'])->name('profile.image.upload');
        Route::delete('/profile/image/remove', [ProfileImageController::class, 'remove'])->name('profile.image.remove');

        // Education Routes:
        Route::post('/education/update', [EducationController::class, 'update'])->name('education.update');
        Route::delete('/education/delete', [EducationController::class, 'delete'])->name('education.delete');

        // Experience Routes:
        Route::post('/experience/update', [ExperienceController::class, 'update'])->name('experience.update');
        Route::delete('/experience/delete', [ExperienceController::class, 'delete'])->name('experience.delete');
    });

    // Communications routes

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
        Route::get('/leaves', function (Request $request) {
            return Inertia::render('LeavesUnified', [
                'title' => 'Leave Management',
                'allUsers' => User::with('department')->get(),
                'summaryData' => app(LeaveController::class)->getSummaryData($request),
                'leaveTypes' => LeaveSetting::all(),
            ]);
        })->middleware('auth')->name('leaves.index');
        Route::get('/leave-summary', [LeaveController::class, 'leaveSummary'])->name('leave-summary');

        // Leave summary export routes
        Route::get('/leave-summary/export/excel', [LeaveController::class, 'exportExcel'])->name('leave.summary.exportExcel');
        Route::get('/leave-summary/export/pdf', [LeaveController::class, 'exportPdf'])->name('leave.summary.exportPdf');

        // Leave analytics
        Route::get('/leaves/analytics', [LeaveController::class, 'getAnalytics'])->name('leaves.analytics');

        // Approval workflow
        Route::get('/leaves/pending-approvals', [LeaveController::class, 'pendingApprovals'])->name('leaves.pending-approvals');
    });

    // Leave bulk operations (admin only)
    Route::middleware(['permission:leaves.approve'])->group(function () {
        Route::post('/leaves/bulk-approve', [LeaveController::class, 'bulkApprove'])->name('leaves.bulk-approve');
        Route::post('/leaves/bulk-reject', [LeaveController::class, 'bulkReject'])->name('leaves.bulk-reject');
        Route::post('/leaves/bulk-status-update', [LeaveController::class, 'bulkStatusUpdate'])->name('leaves.bulk-status-update');

        // Approval workflow actions
        Route::post('/leaves/{id}/approve', [LeaveController::class, 'approveLeave'])->name('leaves.approve');
        Route::post('/leaves/{id}/reject', [LeaveController::class, 'rejectLeave'])->name('leaves.reject');

        // Update leave status (approve/decline individual leaves)
        Route::post('/leave-update-status', [LeaveController::class, 'updateStatus'])->name('leave-update-status');
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
        Route::get('/employees', [UserController::class, 'index'])->name('employees');
        Route::get('/employees/paginate', [UserController::class, 'employees'])->name('employees.paginate');
        Route::get('/employees/stats', [UserController::class, 'employeeStats'])->name('employees.stats');
        Route::get('/employees/{id}', [UserController::class, 'show'])->name('employees.show');
    });

    // The Master Organization Page (Redirected to Employees Console)
    Route::middleware(['permission:employees.view'])->get('/organization', function () {
        return redirect()->route('employees');
    })->name('organization.index');

    // Department management routes
    Route::middleware(['permission:departments.view'])->get('/departments', [DepartmentController::class, 'index'])->name('departments');
    Route::middleware(['permission:departments.view'])->get('/api/departments', [DepartmentController::class, 'getDepartments'])->name('api.departments');
    Route::middleware(['permission:departments.view'])->get('/departments/stats', [DepartmentController::class, 'getStats'])->name('departments.stats');
    Route::middleware(['permission:departments.create'])->post('/departments', [DepartmentController::class, 'store'])->name('departments.store');
    Route::middleware(['permission:departments.view'])->get('/departments/{id}', [DepartmentController::class, 'show'])->name('departments.show');
    Route::middleware(['permission:departments.update'])->put('/departments/{id}', [DepartmentController::class, 'update'])->name('departments.update');
    Route::middleware(['permission:departments.delete'])->delete('/departments/{id}', [DepartmentController::class, 'destroy'])->name('departments.delete');
    Route::middleware(['permission:departments.update'])->put('/users/{id}/department', [DepartmentController::class, 'updateUserDepartment'])->name('users.update-department');
    Route::middleware(['permission:designations.update'])->post('/users/{id}/designation', [DesignationController::class, 'updateUserDesignation'])->name('users.updateDesignation');
    Route::middleware(['permission:employees.update'])->put('/users/{id}/work-location', [UserController::class, 'updateWorkLocation'])->name('users.updateWorkLocation');

    Route::middleware(['permission:jurisdiction.view'])->get('/jurisdiction', [JurisdictionController::class, 'index'])->name('jurisdiction');

    // Daily works management routes
    Route::middleware(['permission:daily-works.import'])->post('/preview-import-daily-works/', [DailyWorkController::class, 'previewImport'])->name('dailyWorks.previewImport');
    Route::middleware(['permission:daily-works.import', 'throttle:10,1'])->post('/import-daily-works/', [DailyWorkController::class, 'import'])->name('dailyWorks.import');
    Route::middleware(['permission:daily-works.import'])->get('/download-daily-works-template', [DailyWorkController::class, 'downloadTemplate'])->name('dailyWorks.downloadTemplate');
    Route::middleware(['permission:daily-works.delete'])->delete('/delete-daily-work', [DailyWorkController::class, 'delete'])->name('dailyWorks.delete');

    // Holiday management routes
    Route::middleware(['permission:holidays.create'])->post('/holiday-add', [HolidayController::class, 'create'])->name('holiday-add');
    Route::middleware(['permission:holidays.create'])->post('/api/holidays', [HolidayController::class, 'create'])->name('api.holidays.store');
    Route::middleware(['permission:holidays.update'])->put('/holidays/{holiday}', [HolidayController::class, 'update'])->name('holiday-update');
    Route::middleware(['permission:holidays.update'])->put('/api/holidays/{holiday}', [HolidayController::class, 'update'])->name('api.holidays.update');
    Route::middleware(['permission:holidays.create'])->post('/holidays-restore', [HolidayController::class, 'restore'])->name('holidays-restore');
    Route::middleware(['permission:holidays.create'])->post('/holidays-copy-year', [HolidayController::class, 'copyYear'])->name('holidays-copy-year');
    Route::middleware(['permission:holidays.delete'])->delete('/holiday-delete', [HolidayController::class, 'delete'])->name('holiday-delete');
    Route::middleware(['permission:holidays.delete'])->delete('/api/holidays/{holiday}', [HolidayController::class, 'destroy'])->name('api.holidays.destroy');

    // User management routes - CONSOLIDATED & REFACTORED
    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('/admin-unified', function () {
            return redirect()->route('employees');
        })->name('admin.unified');
        Route::get('/users', function () {
            return redirect()->route('employees');
        })->name('users');
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
        Route::post('/users/{id}/roles', [UserController::class, 'updateUserRole'])->name('users.updateRole');
        Route::post('/users/{id}/change-password', [UserController::class, 'changePassword'])->name('users.changePassword');
        Route::post('/users/{id}/restore', [UserController::class, 'restore'])->name('users.restore');
        Route::post('/users/{userId}/attendance-type', [UserController::class, 'updateAttendanceType'])->name('users.updateAttendanceType');
        Route::post('/users/{id}/biometric-device', [UserController::class, 'assignBiometricDevice'])->name('users.updateBiometricDevice');
        Route::post('/users/{id}/report-to', [UserController::class, 'updateReportTo'])->name('users.updateReportTo');

        // Bulk operations
        Route::post('/users/bulk/role', [UserController::class, 'bulkAssignRole'])->name('users.bulk.role');
    });

    Route::middleware(['permission:users.delete'])->group(function () {
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
        Route::post('/users/bulk/delete', [UserController::class, 'bulkDelete'])->name('users.bulk.delete');
        // Legacy route for backward compatibility
        Route::delete('/user/{id}', [UserController::class, 'destroy'])->name('user.delete');
    });

    // SECURE DEVICE MANAGEMENT ROUTES (NEW SYSTEM)
    // User's own devices
    Route::get('/my-devices', [DeviceController::class, 'index'])->name('user.devices');
    Route::delete('/my-devices/{deviceId}', [DeviceController::class, 'deactivateDevice'])->name('user.devices.deactivate');

    // Admin device management
    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('/users/{userId}/devices', [DeviceController::class, 'getUserDevices'])->name('admin.users.devices');

        // Fleet-wide session dashboard (every user's devices in one list)
        Route::get('/admin/device-sessions', [DeviceSessionController::class, 'index'])->name('admin.device-sessions.index');
    });

    Route::middleware(['permission:users.update'])->group(function () {
        Route::post('/users/{userId}/devices/reset', [DeviceController::class, 'resetDevices'])->name('admin.users.devices.reset');
        Route::post('/users/{userId}/devices/toggle', [DeviceController::class, 'toggleSingleDeviceLogin'])->name('admin.users.devices.toggle');
        Route::delete('/users/{userId}/devices/{deviceId}', [DeviceController::class, 'adminDeactivateDevice'])->name('admin.users.devices.deactivate');

        // Revoke a single device session: access tokens + refresh chain + device
        Route::post('/admin/device-sessions/{device}/revoke', [DeviceSessionController::class, 'revoke'])->name('admin.device-sessions.revoke');
    });

    // Feature flags / remote config. Gated at the SAME level as admin device
    // sessions on purpose: a flag flip changes fleet-wide behaviour instantly.
    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('/admin/feature-flags', [FeatureFlagController::class, 'index'])->name('admin.feature-flags.index');
    });

    Route::middleware(['permission:users.update'])->group(function () {
        Route::post('/admin/feature-flags', [FeatureFlagController::class, 'store'])->name('admin.feature-flags.store');
        Route::put('/admin/feature-flags/{flag}', [FeatureFlagController::class, 'update'])->whereNumber('flag')->name('admin.feature-flags.update');
        Route::post('/admin/feature-flags/{flag}/toggle', [FeatureFlagController::class, 'toggle'])->whereNumber('flag')->name('admin.feature-flags.toggle');
        Route::delete('/admin/feature-flags/{flag}', [FeatureFlagController::class, 'destroy'])->whereNumber('flag')->name('admin.feature-flags.destroy');
    });

    // Client Diagnostics: mobile crash telemetry triage. Gated at the SAME
    // level as admin device sessions — the payloads carry device ids, app
    // builds and user attribution for the whole fleet.
    Route::middleware(['permission:users.view'])->group(function () {
        Route::get('/admin/client-errors', [ClientErrorController::class, 'index'])->name('admin.client-errors.index');
        Route::get('/admin/client-errors/{error}', [ClientErrorController::class, 'show'])->whereNumber('error')->name('admin.client-errors.show');
    });

    Route::middleware(['permission:users.update'])->group(function () {
        Route::post('/admin/client-errors/{error}/resolve', [ClientErrorController::class, 'resolve'])->whereNumber('error')->name('admin.client-errors.resolve');
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
        Route::get('/attendance', [AttendanceController::class, 'indexUnified'])->name('attendance.unified'); // Unified attendance page
        Route::get('/attendances-admin-paginate', [AttendanceController::class, 'paginate'])->name('attendancesAdmin.paginate');
        Route::get('/admin/daily-timesheet', [AttendanceController::class, 'getAllUsersAttendanceForDate'])->name('admin.daily-timesheet');
        Route::get('/attendance/log', [AttendanceController::class, 'getAttendanceLog'])->name('attendance.log');
        Route::get('/attendance/locations-today', [AttendanceController::class, 'getUserLocationsForDate'])->name('getUserLocationsForDate');
        Route::get('/admin/get-present-users-for-date', [AttendanceController::class, 'getPresentUsersForDate'])->name('admin.getPresentUsersForDate');
        Route::get('/admin/get-absent-users-for-date', [AttendanceController::class, 'getAbsentUsersForDate'])->name('admin.getAbsentUsersForDate');
        // Single source of truth: the frozen day-partition shape shared with the
        // mobile team-attendance screen so web and mobile show identical numbers.
        Route::get('/attendance/day-partition', [AttendanceController::class, 'dayPartition'])->name('attendance.dayPartition');
        Route::get('/attendance/monthly-stats', [AttendanceController::class, 'getMonthlyAttendanceStats'])->name('attendance.monthlyStats');
        Route::get('/attendance/daily-overview', [AttendanceController::class, 'getDailyOverviewStats'])->name('attendance.dailyOverview');
        Route::get('/attendance/{id}/audit', [AttendanceController::class, 'auditHistory'])->whereNumber('id')->name('attendance.audit.history');
        // Location and timesheet update check routes
        Route::get('check-user-locations-updates/{date}', [AttendanceController::class, 'checkForLocationUpdates'])
            ->name('check-user-locations-updates');
        Route::get('check-timesheet-updates/{date}/{month?}', [AttendanceController::class, 'checkTimesheetUpdates'])
            ->name('check-timesheet-updates');

        // Export routes (dispatchers for frontend export buttons)
        Route::get('/attendance/daily-timesheet/export', [AttendanceController::class, 'exportDailyTimesheet'])->name('attendance.dailyTimesheet.export');
        Route::get('/attendance/monthly-calendar/export', [AttendanceController::class, 'exportMonthlyCalendar'])->name('attendance.monthlyCalendar.export');
        Route::get('/attendance/log/export', [AttendanceController::class, 'exportAttendanceLog'])->name('attendance.log.export');

    });

    // Attendance management routes (admin actions)
    Route::middleware(['permission:attendance.correct|attendance.create|attendance.update'])->group(function () {
        Route::post('/attendance/mark-as-present', [AttendanceController::class, 'markAsPresent'])->name('attendance.mark-as-present');
        Route::post('/attendance/bulk-mark-as-present', [AttendanceController::class, 'bulkMarkAsPresent'])->name('attendance.bulk-mark-as-present');
        Route::get('/attendance/regularizations/pending', [RegularizationController::class, 'pending'])->name('attendance.regularizations.pending');
        Route::post('/attendance/regularizations/{id}/approve', [RegularizationController::class, 'approve'])->name('attendance.regularizations.approve');
        Route::post('/attendance/regularizations/{id}/reject', [RegularizationController::class, 'reject'])->name('attendance.regularizations.reject');
        Route::get('/attendance/punch-exceptions/pending', [PunchExceptionController::class, 'pending'])->name('attendance.punch-exceptions.pending');
        Route::post('/attendance/punch-exceptions/{id}/approve', [PunchExceptionController::class, 'approve'])->name('attendance.punch-exceptions.approve');
        Route::post('/attendance/punch-exceptions/{id}/reject', [PunchExceptionController::class, 'reject'])->name('attendance.punch-exceptions.reject');
        Route::get('/attendance/overtime/pending', [OvertimeController::class, 'pending'])->name('attendance.overtime.pending');
        Route::post('/attendance/overtime/{id}/approve', [OvertimeController::class, 'approve'])->name('attendance.overtime.approve');
        Route::post('/attendance/overtime/{id}/reject', [OvertimeController::class, 'reject'])->name('attendance.overtime.reject');
    });

    // Attendance correction routes
    Route::middleware(['permission:attendance.correct'])->group(function () {
        Route::post('/attendance/{id}/correct', [AttendanceController::class, 'updateAttendanceRecord'])->name('attendance.correct.update');
        Route::post('/attendance/add', [AttendanceController::class, 'addAttendanceRecord'])->name('attendance.correct.add');
        Route::delete('/attendance/{id}', [AttendanceController::class, 'deleteAttendanceRecord'])->name('attendance.correct.delete');
        Route::patch('/attendance/{id}/status', [AttendanceController::class, 'updateAttendanceStatus'])->name('attendance.correct.status');
    });

    // Employee attendance stats route
    Route::middleware(['permission:attendance.own.view'])->group(function () {
        Route::get('/attendance/my-monthly-stats', [AttendanceController::class, 'getMonthlyAttendanceStats'])->name('attendance.myMonthlyStats');
        Route::get('/attendance/my-schedule-today', [AttendanceController::class, 'todaySchedule'])->name('attendance.myScheduleToday');
    });

    Route::middleware(['permission:attendance.settings'])->group(function () {
        // Deprecated standalone Attendance Settings page removed; the canonical UI is the
        // unified /attendance page's in-page Settings tab. The update/type/config routes below
        // remain in use by that tab.
        Route::post('/settings/attendance', [AttendanceSettingController::class, 'updateSettings'])->name('attendance-settings.update');
        Route::post('settings/attendance-type', [AttendanceSettingController::class, 'storeType'])->name('attendance-types.store');
        Route::put('settings/attendance-type/{id}', [AttendanceSettingController::class, 'updateType'])->name('attendance-types.update');
        Route::delete('settings/attendance-type/{id}', [AttendanceSettingController::class, 'destroyType'])->name('attendance-types.destroy');

        // Multi-config management routes
        Route::post('settings/attendance-type/{id}/add-item', [AttendanceSettingController::class, 'addConfigItem'])->name('attendance-types.addItem');
        Route::delete('settings/attendance-type/{id}/remove-item', [AttendanceSettingController::class, 'removeConfigItem'])->name('attendance-types.removeItem');
        Route::post('settings/attendance-type/{id}/generate-qr', [AttendanceSettingController::class, 'generateQrCode'])->name('attendance-types.generateQr');

        // Biometric device management routes
        Route::get('settings/biometric-devices', [BiometricDeviceController::class, 'index'])->name('biometric-devices.index');
        Route::post('settings/biometric-devices', [BiometricDeviceController::class, 'store'])->name('biometric-devices.store');
        Route::put('settings/biometric-devices/{id}', [BiometricDeviceController::class, 'update'])->whereNumber('id')->name('biometric-devices.update');
        Route::delete('settings/biometric-devices/{id}', [BiometricDeviceController::class, 'destroy'])->whereNumber('id')->name('biometric-devices.destroy');
        Route::post('settings/biometric-devices/sync-pool', [BiometricDeviceController::class, 'syncAllToPool'])->name('biometric-devices.sync-pool');
        Route::post('settings/biometric-devices/{id}/regenerate-token', [BiometricDeviceController::class, 'regenerateToken'])->whereNumber('id')->name('biometric-devices.regenerate-token');
        Route::post('settings/biometric-devices/{id}/regenerate-adms-token', [BiometricDeviceController::class, 'regenerateAdmsToken'])->whereNumber('id')->name('biometric-devices.regenerate-adms-token');
        Route::get('settings/biometric-devices/active', [BiometricDeviceController::class, 'getActiveDevices'])->name('biometric-devices.active');
        Route::post('settings/biometric-devices/{id}/ping', [BiometricDeviceController::class, 'pingDevice'])->whereNumber('id')->name('biometric-devices.ping');
        Route::get('settings/biometric-devices/logs', [BiometricDeviceController::class, 'getAdmsLogs'])->name('biometric-devices.logs');
        Route::get('settings/biometric-devices/health', [BiometricDeviceController::class, 'getHealthMetrics'])->name('biometric-devices.health');
        Route::get('settings/biometric-devices/operlogs', [BiometricDeviceController::class, 'getOperLogs'])->name('biometric-devices.operlogs');
        Route::get('settings/biometric-devices/attlogs', [BiometricDeviceController::class, 'getAttLogs'])->name('biometric-devices.attlogs');

        // Unknown-user remediation: link a PIN that resolved to nobody onto a
        // real employee and hand its stranded punches back to the importer.
        // Collection-scoped, not {id}-scoped — an unknown PIN is not a property
        // of one device, the same badge can have punched on several.
        Route::post('settings/biometric-devices/attlogs/link-user', [BiometricDeviceController::class, 'linkAttLogUser'])->name('biometric-devices.attlogs.link-user');

        // Template roaming (matrix §2). The listing is server-side only; the
        // restore is the one action here that writes biometric data to hardware
        // and requires confirm_restore=true.
        Route::get('settings/biometric-devices/templates', [BiometricDeviceController::class, 'templates'])->name('biometric-devices.templates');
        Route::post('settings/biometric-devices/{id}/restore-templates', [BiometricDeviceController::class, 'restoreTemplates'])->whereNumber('id')->name('biometric-devices.restore-templates');
        // The mirror action, and the destructive one: DATA DELETE FINGERTMP wipes
        // fingerprint(s) for one PIN off the TERMINAL. Our stored copy is kept —
        // that asymmetry is what makes this recoverable and a restore possible
        // afterwards. Requires confirm_delete=true; an omitted `fid` means every
        // finger for that PIN.
        Route::post('settings/biometric-devices/{id}/delete-template', [BiometricDeviceController::class, 'deleteTemplate'])->whereNumber('id')->name('biometric-devices.delete-template');

        // Device capabilities + device-internal settings.
        // Every {id} route here MUST carry ->whereNumber('id'): an unconstrained
        // {id} also matches the literal segment "bulk", which previously made
        // POST settings/biometric-devices/bulk/ping resolve to the {id}/ping
        // route with id="bulk" and silently broke every bulk action.
        Route::get('settings/biometric-devices/settings-catalogue', [BiometricDeviceController::class, 'settingsCatalogue'])->name('biometric-devices.settings-catalogue');
        Route::get('settings/biometric-devices/{id}/capabilities', [BiometricDeviceController::class, 'capabilities'])->whereNumber('id')->name('biometric-devices.capabilities');
        Route::post('settings/biometric-devices/{id}/probe', [BiometricDeviceController::class, 'probe'])->whereNumber('id')->name('biometric-devices.probe');
        Route::post('settings/biometric-devices/{id}/settings', [BiometricDeviceController::class, 'updateDeviceSettings'])->whereNumber('id')->name('biometric-devices.settings.update');

        // Reconciliation: this device's punches vs the attendance they became.
        // Read-only and idempotent, hence GET. ->whereNumber('id') is not
        // optional here either — see the note above; an unconstrained {id} on a
        // GET would also swallow settings-catalogue-shaped literals.
        Route::get('settings/biometric-devices/{id}/reconciliation', [BiometricDeviceController::class, 'reconciliation'])->whereNumber('id')->name('biometric-devices.reconciliation');

        // Bulk operations
        Route::post('settings/biometric-devices/bulk/ping', [BiometricDeviceController::class, 'bulkPing'])->name('biometric-devices.bulk.ping');
        Route::post('settings/biometric-devices/bulk/delete', [BiometricDeviceController::class, 'bulkDelete'])->name('biometric-devices.bulk.delete');

        Route::post('settings/biometric-devices/{id}/download-logs', [BiometricDeviceController::class, 'downloadLogs'])->whereNumber('id')->name('biometric-devices.download-logs');
        Route::post('settings/biometric-devices/bulk/download-logs', [BiometricDeviceController::class, 'bulkDownloadLogs'])->name('biometric-devices.bulk.download-logs');
        Route::get('settings/biometric-devices/download-history', [BiometricDeviceController::class, 'getDownloadHistory'])->name('biometric-devices.download-history');
        Route::get('settings/biometric-devices/download-sessions/{id}/logs', [BiometricDeviceController::class, 'getSessionLogs'])->whereNumber('id')->name('biometric-devices.download-sessions.logs');
        Route::post('settings/biometric-devices/download-sessions/{id}/import', [BiometricDeviceController::class, 'importSessionLogs'])->whereNumber('id')->name('biometric-devices.download-sessions.import');

        // Request logs routes
        Route::get('settings/request-logs', [RequestLogController::class, 'index'])->name('request-logs.index');
        Route::get('settings/request-logs/list', [RequestLogController::class, 'list'])->name('request-logs.list');
        Route::get('settings/request-logs/{id}', [RequestLogController::class, 'show'])->name('request-logs.show');
        Route::delete('settings/request-logs/{id}', [RequestLogController::class, 'destroy'])->name('request-logs.destroy');
        Route::post('settings/request-logs/bulk-delete', [RequestLogController::class, 'bulkDelete'])->name('request-logs.bulk-delete');
        Route::post('settings/request-logs/clear-all', [RequestLogController::class, 'clearAll'])->name('request-logs.clear-all');
        Route::get('settings/request-logs/export', [RequestLogController::class, 'export'])->name('request-logs.export');
    });

    Route::middleware(['permission:attendance.view|attendance.settings'])->group(function () {
        // Shift and roster reads
        Route::get('/attendance/shifts', [ShiftController::class, 'index'])->name('attendance.shifts.index');
        Route::get('/attendance/rotation-patterns', [ShiftController::class, 'indexPatterns'])->name('attendance.patterns.index');
        Route::get('/attendance/shift-assignments', [ShiftController::class, 'assignmentsIndex'])->name('attendance.assignments.index');
        Route::get('/attendance/roster', [RosterController::class, 'index'])->name('attendance.roster.index');
    });

    Route::middleware(['permission:attendance.settings'])->group(function () {
        // Shift and roster mutations
        Route::post('/attendance/shifts', [ShiftController::class, 'store'])->name('attendance.shifts.store');
        Route::put('/attendance/shifts/{id}', [ShiftController::class, 'update'])->name('attendance.shifts.update');
        Route::delete('/attendance/shifts/{id}', [ShiftController::class, 'destroy'])->name('attendance.shifts.destroy');
        Route::post('/attendance/rotation-patterns', [ShiftController::class, 'storePattern'])->name('attendance.patterns.store');
        Route::put('/attendance/rotation-patterns/{id}', [ShiftController::class, 'updatePattern'])->name('attendance.patterns.update');
        Route::delete('/attendance/rotation-patterns/{id}', [ShiftController::class, 'destroyPattern'])->name('attendance.patterns.destroy');
        Route::post('/attendance/shift-assignments', [ShiftController::class, 'storeAssignment'])->name('attendance.assignments.store');
        Route::post('/attendance/shift-assignments/bulk', [ShiftController::class, 'storeBulkAssignment'])->name('attendance.assignments.storeBulk');
        Route::put('/attendance/shift-assignments/{id}', [ShiftController::class, 'updateAssignment'])->name('attendance.assignments.update');
        Route::delete('/attendance/shift-assignments/{id}', [ShiftController::class, 'destroyAssignment'])->name('attendance.assignments.destroy');

        Route::post('/attendance/roster/generate', [RosterController::class, 'generate'])->name('attendance.roster.generate');
        Route::put('/attendance/roster/cell', [RosterController::class, 'updateCell'])->name('attendance.roster.cell');
    });

    // Coverage & Locations (Read operations accessible with attendance.view)
    Route::middleware(['permission:attendance.view'])->group(function () {
        Route::get('/attendance/coverage', [CoverageController::class, 'index'])->name('attendance.coverage.index');
        Route::get('/attendance/work-locations', [CoverageController::class, 'workLocations'])->name('attendance.workLocations.index');
    });

    Route::middleware(['permission:attendance.settings'])->group(function () {
        // Coverage requirements (Admin settings)
        Route::get('/attendance/coverage-requirements', [CoverageRequirementController::class, 'index'])->name('attendance.coverageRequirements.index');
        Route::post('/attendance/coverage-requirements', [CoverageRequirementController::class, 'store'])->name('attendance.coverageRequirements.store');
        Route::put('/attendance/coverage-requirements/{id}', [CoverageRequirementController::class, 'update'])->name('attendance.coverageRequirements.update');
        Route::delete('/attendance/coverage-requirements/{id}', [CoverageRequirementController::class, 'destroy'])->name('attendance.coverageRequirements.destroy');

        // Swap management routes (admin)
        Route::get('/attendance/swaps', [ShiftSwapController::class, 'index'])->name('attendance.swaps.index');
        Route::post('/attendance/swaps/{id}/approve', [ShiftSwapController::class, 'approve'])->name('attendance.swaps.approve');
        Route::post('/attendance/swaps/{id}/reject', [ShiftSwapController::class, 'reject'])->name('attendance.swaps.reject');

        // Attendance Policy CRUD + activation + simulation
        Route::get('/attendance/policies', [PolicyController::class, 'index'])->name('attendance.policies.index');
        Route::post('/attendance/policies/simulate', [PolicyController::class, 'simulate'])->name('attendance.policies.simulate');
        Route::post('/attendance/policies', [PolicyController::class, 'store'])->name('attendance.policies.store');
        Route::put('/attendance/policies/{id}', [PolicyController::class, 'update'])->name('attendance.policies.update');
        Route::post('/attendance/policies/{id}/activate', [PolicyController::class, 'activate'])->name('attendance.policies.activate');
    });

    // Task management routes
    Route::middleware(['permission:tasks.view'])->group(function () {
        Route::get('/tasks-all', [TaskController::class, 'allTasks'])->name('allTasks');
    });

    Route::middleware(['permission:tasks.create'])->post('/task/add', [TaskController::class, 'addTask'])->name('addTask');

    // Work location routes (HR/Attendance) — gated by attendance settings, not project jurisdiction.
    Route::middleware(['permission:employees.view'])->group(function () {
        Route::get('/work-location', [WorkLocationController::class, 'showWorkLocations'])->name('showWorkLocations');
        Route::get('/work-location_json', [WorkLocationController::class, 'allWorkLocations'])->name('allWorkLocations');
        Route::get('/work-locations/{workLocation}', [WorkLocationController::class, 'show'])->name('workLocations.show');
    });

    Route::middleware(['permission:attendance.settings'])->post('/work-locations/add', [WorkLocationController::class, 'addWorkLocation'])->name('addWorkLocation');
    Route::middleware(['permission:attendance.settings'])->post('/work-locations/delete', [WorkLocationController::class, 'deleteWorkLocation'])->name('deleteWorkLocation');
    Route::middleware(['permission:attendance.settings'])->post('/work-locations/update', [WorkLocationController::class, 'updateWorkLocation'])->name('updateWorkLocation');

    // Jurisdiction routes (Project chainages)
    Route::middleware(['permission:jurisdiction.view'])->group(function () {
        Route::get('/jurisdictions/json', [JurisdictionController::class, 'allJurisdictions'])->name('allJurisdictions');
    });

    Route::middleware(['permission:jurisdiction.create'])->post('/jurisdictions/add', [JurisdictionController::class, 'addJurisdiction'])->name('addJurisdiction');
    Route::middleware(['permission:jurisdiction.delete'])->post('/jurisdictions/delete', [JurisdictionController::class, 'deleteJurisdiction'])->name('deleteJurisdiction');
    Route::middleware(['permission:jurisdiction.update'])->post('/jurisdictions/update', [JurisdictionController::class, 'updateJurisdiction'])->name('updateJurisdiction');
});

// Redirect old routes to unified admin page
Route::get('/roles', function () {
    return redirect()->route('admin.unified');
})->name('roles');

// Enhanced Role Management Routes (with proper permission-based access control)
Route::middleware(['auth', 'verified', 'permission:roles.view', 'role_permission_sync'])->group(function () {
    // Role Management Interface
    Route::get('/admin/roles-management', [RoleController::class, 'index'])->name('admin.roles-management');
    Route::get('/admin/roles/audit', [RoleController::class, 'getEnhancedRoleAudit'])->name('admin.roles.audit');
    Route::get('/admin/roles/export', [RoleController::class, 'exportRoles'])->name('admin.roles.export');
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

// System Monitoring Routes (Super Administrator only)
Route::middleware(['auth', 'verified', 'role:Super Administrator'])->group(function () {
    Route::get('/admin/system-monitoring', [SystemMonitoringController::class, 'index'])->name('admin.system-monitoring');
    Route::post('/admin/errors/{errorId}/resolve', [SystemMonitoringController::class, 'resolveError'])->name('admin.errors.resolve');
    Route::get('/admin/system-report', [SystemMonitoringController::class, 'exportReport'])->name('admin.system-report');
    Route::get('/admin/optimization-report', [SystemMonitoringController::class, 'getOptimizationReport'])->name('admin.optimization-report');

    // Designation Management
    Route::middleware(['permission:designations.view'])->group(function () {
        // Initial page render (Inertia)
        Route::get('/designations', [DesignationController::class, 'index'])->name('designations.index');
        // API data fetch (JSON)
        Route::get('/designations/json', [DesignationController::class, 'getDesignations'])->name('designations.json');
        // Stats endpoint for frontend analytics
        Route::get('/designations/stats', [DesignationController::class, 'stats'])->name('designations.stats');
        // For dropdowns and API (must be before wildcard {id})
        Route::get('/designations/list', [DesignationController::class, 'list'])->name('designations.list');
        Route::post('/designations', [DesignationController::class, 'store'])->name('designations.store');
        Route::get('/designations/{id}', [DesignationController::class, 'show'])->name('designations.show');
        Route::put('/designations/{id}', [DesignationController::class, 'update'])->name('designations.update');
        Route::delete('/designations/{id}', [DesignationController::class, 'destroy'])->name('designations.destroy');
    });
});

// API routes for dropdown data
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/api/designations/list', function () {
        return response()->json(Designation::select('id', 'title as name')->get());
    })->name('api.designations.list');

    Route::get('/api/departments/list', function () {
        return response()->json(Department::select('id', 'name')->get());
    })->name('departments.list');

    Route::get('/api/users/managers/list', function () {
        return response()->json(User::whereHas('roles', function ($query) {
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

// Petty Cash Routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/petty-cash', [PettyCashController::class, 'index'])->name('petty-cash.index');
    Route::post('/petty-cash/loan', [PettyCashController::class, 'createLoan'])->name('petty-cash.loan');
    Route::post('/petty-cash/loan/approve', [PettyCashController::class, 'approveLoan'])->name('petty-cash.loan.approve');
    Route::post('/petty-cash/loan/reject', [PettyCashController::class, 'rejectLoan'])->name('petty-cash.loan.reject');
    Route::post('/petty-cash/loan/close', [PettyCashController::class, 'closeLoan'])->name('petty-cash.loan.close');
    Route::post('/petty-cash/expense', [PettyCashController::class, 'addExpense'])->name('petty-cash.expense');
    Route::post('/petty-cash/reimbursement', [PettyCashController::class, 'addReimbursement'])->name('petty-cash.reimbursement');
    Route::post('/petty-cash/repayment', [PettyCashController::class, 'addRepayment'])->name('petty-cash.repayment');
    Route::put('/petty-cash/transaction', [PettyCashController::class, 'updateTransaction'])->name('petty-cash.transaction.update');
    Route::delete('/petty-cash/transaction', [PettyCashController::class, 'deleteTransaction'])->name('petty-cash.transaction.delete');
    Route::get('/petty-cash/transactions', [PettyCashController::class, 'getTransactions'])->name('petty-cash.transactions');
    Route::get('/petty-cash/analytics', [PettyCashController::class, 'getAnalytics'])->name('petty-cash.analytics');
    Route::post('/petty-cash/upload-bill', [PettyCashController::class, 'uploadBill'])->name('petty-cash.upload-bill');
    Route::post('/petty-cash/delete-bill', [PettyCashController::class, 'deleteBill'])->name('petty-cash.delete-bill');
    Route::get('/petty-cash/export', [PettyCashController::class, 'exportData'])->name('petty-cash.export');
    Route::get('/petty-cash/export-pdf', [PettyCashController::class, 'exportPdf'])->name('petty-cash.export-pdf');
    Route::get('/petty-cash/export/status/{filename}', [PettyCashController::class, 'checkExportStatus'])->name('petty-cash.export.status');
    Route::get('/petty-cash/history', [PettyCashController::class, 'getHistory'])->name('petty-cash.history');
    Route::get('/petty-cash/admin/overview', [PettyCashController::class, 'getAdminOverview'])->name('petty-cash.admin.overview');
    Route::get('/petty-cash/categories', [PettyCashController::class, 'getCategories'])->name('petty-cash.categories');
    Route::get('/petty-cash/audit-log', [PettyCashController::class, 'getAuditLog'])->name('petty-cash.audit-log');
});

// Operations & Maintenance (O&M) and Traffic Monitoring Center (TMC / ITS) Routes
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/om/dashboard', [OperationsMaintenanceController::class, 'dashboard'])
        ->middleware('permission:om.dashboard.view')->name('om.dashboard');

    Route::get('/om/defects', [OperationsMaintenanceController::class, 'defects'])
        ->middleware('permission:om.maintenance.view')->name('om.defects');
    Route::post('/om/defects', [OperationsMaintenanceController::class, 'storeDefect'])
        ->middleware('permission:om.maintenance.manage')->name('om.defects.store');
    Route::post('/om/defects/{id}/convert-to-wo', [OperationsMaintenanceController::class, 'convertDefectToWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('om.defects.convert-wo');

    Route::get('/om/work-orders', [OperationsMaintenanceController::class, 'workOrders'])
        ->middleware('permission:om.maintenance.view')->name('om.work-orders');
    Route::post('/om/work-orders', [OperationsMaintenanceController::class, 'storeWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('om.work-orders.store');
    Route::post('/om/work-orders/{id}/approve', [OperationsMaintenanceController::class, 'approveWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('om.work-orders.approve');
    Route::post('/om/work-orders/{id}/start', [OperationsMaintenanceController::class, 'startWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('om.work-orders.start');
    Route::post('/om/work-orders/{id}/complete', [OperationsMaintenanceController::class, 'completeWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('om.work-orders.complete');
    Route::post('/om/work-orders/{id}/verify', [OperationsMaintenanceController::class, 'verifyWorkOrder'])
        ->middleware('permission:om.maintenance.manage')->name('om.work-orders.verify');

    Route::get('/om/incidents', [OperationsMaintenanceController::class, 'incidents'])
        ->middleware('permission:om.incidents.view')->name('om.incidents');
    Route::post('/om/incidents', [OperationsMaintenanceController::class, 'storeIncident'])
        ->middleware('permission:om.incidents.manage')->name('om.incidents.store');
    Route::post('/om/incidents/{id}/status', [OperationsMaintenanceController::class, 'updateIncidentStatus'])
        ->middleware('permission:om.incidents.manage')->name('om.incidents.status');
    Route::post('/om/incidents/{id}/create-damage-wo', [OperationsMaintenanceController::class, 'createIncidentDamageWorkOrder'])
        ->middleware('permission:om.incidents.manage')->name('om.incidents.damage-wo');

    Route::get('/om/assets', [OperationsMaintenanceController::class, 'assets'])
        ->middleware('permission:om.equipment.view')->name('om.assets');
    Route::post('/om/assets', [OperationsMaintenanceController::class, 'storeAsset'])
        ->middleware('permission:om.equipment.manage')->name('om.assets.store');

    Route::get('/om/traffic-monitoring', [OperationsMaintenanceController::class, 'trafficMonitoring'])
        ->middleware('permission:om.traffic.view')->name('om.traffic');
    Route::get('/om/daily-report', [OperationsMaintenanceController::class, 'dailyMaintenanceReport'])
        ->middleware('permission:om.dashboard.view')->name('om.daily-report');

    // Dynamic O&M Lookups & Categories Management (Admin)
    Route::get('/om/lookups', [OmLookupController::class, 'index'])
        ->middleware('permission:om.dashboard.view')->name('om.lookups');
    Route::post('/om/lookups', [OmLookupController::class, 'store'])
        ->middleware('permission:om.dashboard.view')->name('om.lookups.store');
    Route::put('/om/lookups/{id}', [OmLookupController::class, 'update'])
        ->middleware('permission:om.dashboard.view')->name('om.lookups.update');
    Route::delete('/om/lookups/{id}', [OmLookupController::class, 'destroy'])
        ->middleware('permission:om.dashboard.view')->name('om.lookups.destroy');

    Route::get('/om/equipment', [OperationsMaintenanceController::class, 'equipment'])
        ->middleware('permission:om.equipment.view')->name('om.equipment');
    Route::get('/om/shift-logs', [OperationsMaintenanceController::class, 'shiftLogs'])
        ->middleware('permission:om.dashboard.view')->name('om.shift-logs');
    Route::post('/om/shift-logs', [OperationsMaintenanceController::class, 'storeShiftLog'])
        ->middleware('permission:om.shift.manage')->name('om.shift-logs.store');
    Route::post('/om/shift-logs/{id}/acknowledge', [OperationsMaintenanceController::class, 'acknowledgeShiftLog'])
        ->middleware('permission:om.shift.manage')->name('om.shift-logs.acknowledge');
});

// Phase 1 O&M Renovation Routes (PM, Inspections, SLA, Analytics, Safety)
Route::middleware(['auth', 'verified'])->group(function () {
    // Preventive Maintenance Scheduler
    Route::get('/om/preventive-maintenance', [OmRenovationController::class, 'preventiveMaintenance'])
        ->middleware('permission:om.maintenance.view')->name('om.pm');
    Route::post('/om/preventive-maintenance', [OmRenovationController::class, 'storePreventiveSchedule'])
        ->middleware('permission:om.maintenance.manage')->name('om.pm.store');
    Route::post('/om/preventive-maintenance/generate', [OmRenovationController::class, 'generatePmWorkOrders'])
        ->middleware('permission:om.maintenance.manage')->name('om.pm.generate');
    Route::post('/om/preventive-maintenance/{id}/toggle', [OmRenovationController::class, 'togglePmSchedule'])
        ->middleware('permission:om.maintenance.manage')->name('om.pm.toggle');

    // Inspections & Checklists
    Route::get('/om/inspections', [OmRenovationController::class, 'inspections'])
        ->middleware('permission:om.maintenance.view')->name('om.inspections');
    Route::post('/om/inspection-templates', [OmRenovationController::class, 'storeInspectionTemplate'])
        ->middleware('permission:om.maintenance.manage')->name('om.inspection-templates.store');
    Route::post('/om/inspections', [OmRenovationController::class, 'submitInspection'])
        ->middleware('permission:om.maintenance.manage')->name('om.inspections.store');
    Route::post('/om/inspections/{id}/review', [OmRenovationController::class, 'reviewInspection'])
        ->middleware('permission:om.maintenance.manage')->name('om.inspections.review');

    // SLA Compliance Dashboard
    Route::get('/om/sla-compliance', [OmRenovationController::class, 'slaCompliance'])
        ->middleware('permission:om.dashboard.view')->name('om.sla');
    Route::post('/om/sla-breaches/{id}/acknowledge', [OmRenovationController::class, 'acknowledgeSlaBreach'])
        ->middleware('permission:om.maintenance.manage')->name('om.sla.acknowledge');

    // O&M Analytics & Reports
    Route::get('/om/analytics', [OmRenovationController::class, 'analytics'])
        ->middleware('permission:om.dashboard.view')->name('om.analytics');

    // Asset Maintenance Timeline
    Route::get('/om/assets/{id}/timeline', [OmRenovationController::class, 'assetTimeline'])
        ->middleware('permission:om.equipment.view')->name('om.assets.timeline');

    // Safety Management
    Route::get('/om/safety', [OmRenovationController::class, 'safety'])
        ->middleware('permission:om.dashboard.view')->name('om.safety');
    Route::post('/om/safety', [OmRenovationController::class, 'storeSafetyIncident'])
        ->middleware('permission:om.maintenance.manage')->name('om.safety.store');
    Route::post('/om/safety/{id}/status', [OmRenovationController::class, 'updateSafetyStatus'])
        ->middleware('permission:om.maintenance.manage')->name('om.safety.status');

    // Phase 2 O&M Renovation Routes (Calendar, Contractors, Inventory, Environmental, Toolbox Talks)
    Route::get('/om/work-orders-calendar', [OmRenovationController::class, 'workOrderCalendar'])
        ->middleware('permission:om.maintenance.view')->name('om.work-orders.calendar');

    Route::get('/om/contractors', [OmRenovationController::class, 'contractors'])
        ->middleware('permission:om.maintenance.view')->name('om.contractors');
    Route::post('/om/contractors', [OmRenovationController::class, 'storeContractor'])
        ->middleware('permission:om.maintenance.manage')->name('om.contractors.store');

    Route::get('/om/inventory', [OmRenovationController::class, 'inventory'])
        ->middleware('permission:om.maintenance.view')->name('om.inventory');
    Route::post('/om/work-orders/{id}/materials', [OmRenovationController::class, 'logWorkOrderMaterial'])
        ->middleware('permission:om.maintenance.manage')->name('om.work-orders.materials.store');

    Route::get('/om/environmental', [OmRenovationController::class, 'environmental'])
        ->middleware('permission:om.dashboard.view')->name('om.environmental');
    Route::post('/om/environmental', [OmRenovationController::class, 'storeEnvironmentalLog'])
        ->middleware('permission:om.maintenance.manage')->name('om.environmental.store');

    Route::get('/om/toolbox-talks', [OmRenovationController::class, 'toolboxTalks'])
        ->middleware('permission:om.dashboard.view')->name('om.toolbox-talks');
    Route::post('/om/toolbox-talks', [OmRenovationController::class, 'storeToolboxTalk'])
        ->middleware('permission:om.maintenance.manage')->name('om.toolbox-talks.store');

    // Phase 3 O&M Advanced Research Routes (TPPD, IRI Roughness, WIM Overload, Markov Pavement, ITS RCM)
    Route::get('/om/tppd-claims', [OmRenovationController::class, 'tppdClaims'])
        ->middleware('permission:om.incidents.view')->name('om.tppd');
    Route::post('/om/tppd-claims', [OmRenovationController::class, 'storeTppdClaim'])
        ->middleware('permission:om.incidents.manage')->name('om.tppd.store');
    Route::post('/om/tppd-claims/{id}/status', [OmRenovationController::class, 'updateTppdStatus'])
        ->middleware('permission:om.incidents.manage')->name('om.tppd.status');

    Route::get('/om/iri-roughness', [OmRenovationController::class, 'iriHeatmap'])
        ->middleware('permission:om.dashboard.view')->name('om.iri');

    Route::get('/om/wim-overload', [OmRenovationController::class, 'wimOverloadAnalytics'])
        ->middleware('permission:om.dashboard.view')->name('om.wim');

    Route::get('/om/pavement-deterioration', [OmRenovationController::class, 'pavementDeterioration'])
        ->middleware('permission:om.dashboard.view')->name('om.pavement.deterioration');

    Route::get('/om/its-rcm', [OmRenovationController::class, 'rcmReliability'])
        ->middleware('permission:om.equipment.view')->name('om.its.rcm');
});

// Notification Settings Routes (admin)
Route::middleware(['auth', 'verified', 'permission:notifications.settings'])->group(function () {
    Route::get('/admin/settings/notifications', [NotificationSettingsController::class, 'index'])->name('admin.settings.notifications');
    Route::get('/admin/settings/notifications/list', [NotificationSettingsController::class, 'list']);
    Route::put('/admin/settings/notifications/{type}', [NotificationSettingsController::class, 'update']);
});

// User notification preferences (any authenticated user manages their own prefs)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/settings/notifications', [NotificationPreferenceController::class, 'index'])->name('settings.notifications');
    Route::get('/settings/notifications/list', [NotificationPreferenceController::class, 'list']);
    Route::put('/settings/notifications', [NotificationPreferenceController::class, 'update']);
});

// In-app notification center — Inertia page + JSON endpoints for the SPA (session auth,
// matching the convention used by every other React Query hook in this app). The
// /api/notifications* routes remain for token-authenticated (mobile) clients.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/notifications', fn () => Inertia::render('Notifications/Index'))->name('notifications.index');
    Route::get('/notifications/list', [NotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
});

// Aeon AI Copilot Routes
Route::middleware(['auth', 'verified'])->prefix('aeon')->name('aeon.')->group(function () {
    Route::get('/', [AeonPageController::class, 'index'])->name('index');
    Route::post('/message', [AeonController::class, 'message'])->name('message');
    Route::post('/message/stream', [AeonController::class, 'stream'])->name('message.stream');
    Route::get('/conversations', [AeonController::class, 'conversations'])->name('conversations.index');
    Route::get('/conversations/{id}', [AeonController::class, 'show'])->name('conversations.show');
    Route::delete('/conversations/{id}', [AeonController::class, 'destroy'])->name('conversations.destroy');
    Route::post('/messages/{id}/feedback', [AeonController::class, 'feedback'])->name('messages.feedback');
});

require __DIR__.'/auth.php';
