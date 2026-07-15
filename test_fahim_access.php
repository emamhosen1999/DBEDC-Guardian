<?php

use App\Models\User;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\Attendance;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\HRM\RosterController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// 1. Authenticate as Fahim Hossain
$user = User::where('email', 'fahim@dhakabypass.com')->firstOrFail();

// Sync roles locally to match production: Department Manager + Employee
$user->syncRoles(['Department Manager', 'Employee']);

Auth::login($user);

echo "==================================================\n";
echo "TESTING ACCESS FOR USER: " . $user->name . " (" . $user->email . ")\n";
echo "Role(s): " . implode(', ', $user->getRoleNames()->toArray()) . "\n";
echo "Department: " . ($user->department ? $user->department->name : 'None') . " (ID: " . $user->department_id . ")\n";
echo "==================================================\n\n";

// 2. Test Employees Page / Paginated Employees
echo "1. TESTING EMPLOYEES CONSOLE ACCESS:\n";
$userController = app(UserController::class);
$request = new Request([
    'perPage' => 100,
    'page' => 1,
]);
$request->setUserResolver(fn() => $user);

$response = $userController->employees($request);
$data = $response->getData(true);
$employees = $data['data'] ?? [];
echo "Total employees returned: " . count($employees) . "\n";

$otherDepts = [];
foreach ($employees as $emp) {
    $deptName = $emp['department']['name'] ?? 'No Department';
    if (($emp['department_id'] ?? null) !== $user->department_id) {
        $otherDepts[] = $emp['name'] . " (" . $deptName . ")";
    }
}

if (empty($otherDepts)) {
    echo "✅ SUCCESS: All returned employees belong to QC department!\n";
} else {
    echo "❌ FAILURE: Found employees from other departments:\n" . implode("\n", $otherDepts) . "\n";
}
echo "\n";

// 3. Test Daily Timesheet / Attendance Present & Absent
echo "2. TESTING DAILY TIMESHEET ACCESS:\n";
$attendanceController = app(AttendanceController::class);

$dateRequest = new Request(['date' => date('Y-m-d')]);
$dateRequest->setUserResolver(fn() => $user);
$allAttendanceRes = $attendanceController->getAllUsersAttendanceForDate($dateRequest);
$dailyData = $allAttendanceRes->getData(true);
$dailyRecords = $dailyData['data'] ?? [];
echo "Total daily timesheet records: " . count($dailyRecords) . "\n";

$otherDailyDepts = [];
foreach ($dailyRecords as $record) {
    if (($record['department_id'] ?? null) !== $user->department_id) {
        $otherDailyDepts[] = $record['name'] ?? 'Unknown';
    }
}
if (empty($otherDailyDepts)) {
    echo "✅ SUCCESS: All daily timesheet records belong to QC department!\n";
} else {
    echo "❌ FAILURE: Found daily timesheet records from other departments:\n" . implode("\n", $otherDailyDepts) . "\n";
}
echo "\n";

// 4. Test Monthly Calendar stats & pagination
echo "3. TESTING MONTHLY CALENDAR ACCESS:\n";
$monthRequest = new Request([
    'currentMonth' => (int)date('m'),
    'currentYear' => (int)date('Y'),
    'perPage' => 100,
]);
$monthRequest->setUserResolver(fn() => $user);
$monthlyRes = $attendanceController->paginate($monthRequest);
$monthlyData = $monthlyRes->getData(true);
$monthlyRecords = $monthlyData['data'] ?? [];
echo "Total monthly calendar records: " . count($monthlyRecords) . "\n";

$otherMonthlyDepts = [];
foreach ($monthlyRecords as $record) {
    $userModel = User::find($record['user_id'] ?? null);
    if ($userModel && $userModel->department_id !== $user->department_id) {
        $otherMonthlyDepts[] = $userModel->name . " (" . ($userModel->department ? $userModel->department->name : 'None') . ")";
    }
}
if (empty($otherMonthlyDepts)) {
    echo "✅ SUCCESS: All monthly calendar records belong to QC department!\n";
} else {
    echo "❌ FAILURE: Found monthly calendar records from other departments:\n" . implode("\n", $otherMonthlyDepts) . "\n";
}
echo "\n";

// 5. Test Roster Page Access
echo "4. TESTING ROSTER ACCESS:\n";
$rosterController = app(RosterController::class);
$rosterRequest = new Request([
    'from' => date('Y-m-01'),
    'to' => date('Y-m-t'),
]);
$rosterRequest->setUserResolver(fn() => $user);
$rosterRes = $rosterController->index($rosterRequest);
$rosterData = $rosterRes->getData(true);
$rosterRecords = $rosterData['roster'] ?? [];
echo "Total roster records returned: " . count($rosterRecords) . "\n";

$otherRosterDepts = [];
foreach ($rosterRecords as $userId => $record) {
    $userModel = User::find($userId);
    if ($userModel && $userModel->department_id !== $user->department_id) {
        $otherRosterDepts[] = $userModel->name . " (" . ($userModel->department ? $userModel->department->name : 'None') . ")";
    }
}
if (empty($otherRosterDepts)) {
    echo "✅ SUCCESS: All roster records belong to QC department!\n";
} else {
    echo "❌ FAILURE: Found roster records from other departments:\n" . implode("\n", $otherRosterDepts) . "\n";
}
echo "\n";
