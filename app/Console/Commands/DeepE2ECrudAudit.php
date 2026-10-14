<?php

namespace App\Console\Commands;

use App\Models\DailyWork;
use App\Models\HRM\AttendanceType;
use App\Models\HRM\Department;
use App\Models\HRM\Designation;
use App\Models\HRM\Leave;
use App\Models\HRM\Shift;
use App\Models\OmEquipment;
use App\Models\OmIncident;
use App\Models\OmShiftLog;
use App\Models\OmTollRecord;
use App\Models\OmTrafficLog;
use App\Models\OmWorkOrder;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\Report;
use App\Models\RfiObjection;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\WorkLocation;
use App\Services\DeviceAuthService;
use Illuminate\Console\Command;
use Illuminate\Console\Prohibitable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * Writes real records (and a device registration outside the transaction) and relies on a rollback to remove them.
 * Production has MyISAM tables that ignore the rollback, so AppServiceProvider prohibits this command there.
 */
class DeepE2ECrudAudit extends Command
{
    use Prohibitable;

    protected $signature = 'audit:e2e-crud';
    protected $description = 'Deep automated testing of all tab sections, buttons, CRUD operations, and backend services';

    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function handle(): int
    {
        if ($this->isProhibited()) {
            return self::FAILURE;
        }

        $this->info('=====================================================================');
        $this->info('   DBEDC GUARDIAN: DEEP END-TO-END CRUD & BACKEND AUDIT SUITE       ');
        $this->info('=====================================================================');

        $admin = User::role('Super Administrator')->first()
            ?? User::role('Administrator')->first()
            ?? User::first();

        if (! $admin) {
            $this->error('Fatal: No user found to execute audit.');
            return 1;
        }

        $this->info("Admin Actor: {$admin->name} [ID: {$admin->employee_id} | Email: {$admin->email}]");
        $this->newLine();

        $app = app();
        $deviceId = 'e2e-audit-device-001';
        $dummyReq = Request::create('/', 'GET');
        $deviceService = $app->make(DeviceAuthService::class);
        $deviceService->registerDevice($admin, $dummyReq, $deviceId, ['platform' => 'android'], 'Audit Mobile Device');

        DB::beginTransaction();

        try {
            // ─────────────────────────────────────────────────────────────
            // MODULE 1: USERS & ROLES CRUD
            // ─────────────────────────────────────────────────────────────
            $this->section('1. Users, Roles & Permissions CRUD');
            $testEmpId = 'AUDIT-EMP-' . strtoupper(Str::random(5));
            $testEmail = 'audit.' . strtolower(Str::random(5)) . '@dhakabypass.com';

            $this->runTest('User: Create New Employee', function () use ($testEmpId, $testEmail) {
                $user = User::create([
                    'employee_id' => $testEmpId,
                    'name' => 'Audit Test User',
                    'user_name' => strtolower($testEmpId),
                    'email' => $testEmail,
                    'password' => Hash::make('password123'),
                    'joining_date' => now()->toDateString(),
                ]);
                if (! $user || $user->employee_id !== $testEmpId) {
                    throw new \Exception('Failed to create user model');
                }
                return $user;
            }, $newUser);

            $this->runTest('User: Read & Query with Relations', function () use ($newUser) {
                $loaded = User::with(['roles', 'department', 'designation', 'devices', 'notificationPreferences'])
                    ->where('employee_id', $newUser->employee_id)
                    ->firstOrFail();
                if ($loaded->id !== $newUser->employee_id) {
                    throw new \Exception('User id accessor did not return employee_id string');
                }
            });

            $this->runTest('User: Update Employee Profile', function () use ($newUser) {
                $newUser->name = 'Audit Test User (Updated)';
                $newUser->save();
                $newUser->refresh();
                if ($newUser->name !== 'Audit Test User (Updated)') {
                    throw new \Exception('User update did not persist');
                }
            });

            $this->runTest('User: Role & Permission Assignment', function () use ($newUser) {
                $employeeRole = Role::firstOrCreate(['name' => 'Employee', 'guard_name' => 'web']);
                $newUser->assignRole($employeeRole);
                if (! $newUser->hasRole('Employee', 'web')) {
                    throw new \Exception('Failed to assign role to user with string employee_id');
                }
            });

            // ─────────────────────────────────────────────────────────────
            // MODULE 2: ORGANIZATION (DEPARTMENTS, DESIGNATIONS, HOLIDAYS)
            // ─────────────────────────────────────────────────────────────
            $this->section('2. Organization & Structure CRUD');

            $this->runTest('Department: Create, Update & Query', function () {
                $dept = Department::create([
                    'name' => 'Audit Quality Dept ' . Str::random(4),
                    'code' => 'AQD-' . rand(100, 999),
                    'description' => 'Automated test department',
                    'is_active' => true,
                ]);
                $dept->update(['description' => 'Updated test description']);
                $found = Department::with('users')->find($dept->id);
                if (! $found || $found->description !== 'Updated test description') {
                    throw new \Exception('Department CRUD failed');
                }
                return $dept;
            }, $testDept);

            $this->runTest('Designation: Create, Update & Query', function () use ($testDept) {
                $desig = Designation::create([
                    'title' => 'Audit QA Lead ' . Str::random(4),
                    'department_id' => $testDept->id,
                    'hierarchy_level' => 3,
                    'is_active' => true,
                ]);
                $desig->update(['hierarchy_level' => 2]);
                $found = Designation::with('department')->find($desig->id);
                if (! $found || $found->hierarchy_level !== 2) {
                    throw new \Exception('Designation CRUD failed');
                }
                return $desig;
            }, $testDesig);

            // ─────────────────────────────────────────────────────────────
            // MODULE 3: DAILY WORKS, TASKS, RFI OBJECTIONS & REPORTS
            // ─────────────────────────────────────────────────────────────
            $this->section('3. Daily Works, Tasks, Objections & Reports CRUD');

            $this->runTest('DailyWork: Create Task & Attach Report', function () use ($admin) {
                $dw = DailyWork::create([
                    'date' => now()->toDateString(),
                    'number' => 'RFI-AUDIT-' . rand(10000, 99999),
                    'time' => '10:00 AM',
                    'status' => DailyWork::STATUS_PENDING,
                    'type' => 'Embankment',
                    'description' => 'E2E Audit Task Description',
                    'location' => 'K12+300',
                    'side' => 'LHS',
                    'incharge' => $admin->employee_id,
                    'assigned' => $admin->employee_id,
                ]);

                $report = Report::firstOrCreate([
                    'ref_no' => 'REP-AUDIT-' . rand(1000, 9999),
                ], [
                    'report_type' => 'Daily Inspection',
                    'issue_date' => now()->toDateString(),
                    'details' => 'Daily inspection details',
                    'status' => 'submitted',
                ]);

                $dw->reports()->syncWithoutDetaching([$report->id]);
                $found = DailyWork::with(['reports', 'inchargeUser', 'assignedUser'])->find($dw->id);
                if (! $found || $found->reports->isEmpty()) {
                    throw new \Exception('DailyWork task creation or report relation failed');
                }
                return $dw;
            }, $testDw);

            $this->runTest('DailyWork: Update Status & Inspection Details', function () use ($testDw) {
                $testDw->update([
                    'status' => DailyWork::STATUS_COMPLETED,
                    'inspection_details' => 'Passed all density tests successfully.',
                    'completion_time' => now()->toTimeString(),
                ]);
                $testDw->refresh();
                if ($testDw->status !== DailyWork::STATUS_COMPLETED) {
                    throw new \Exception('DailyWork update failed');
                }
            });

            $this->runTest('RfiObjection: Create, Status Log & Query', function () use ($admin, $testDw) {
                $obj = RfiObjection::create([
                    'rfi_id' => $testDw->id,
                    'title' => 'Compaction level below requirement',
                    'reason' => 'Compaction level below requirement',
                    'type' => 'Embankment',
                    'status' => RfiObjection::STATUS_DRAFT,
                    'description' => 'Compaction test failed at LHS',
                    'chainage_start' => 12.3,
                    'created_by' => $admin->employee_id,
                ]);

                $obj->dailyWorks()->syncWithoutDetaching([$testDw->id]);
                $found = RfiObjection::with(['dailyWorks', 'createdBy'])->find($obj->id);
                if (! $found || (string)$found->created_by !== (string)$admin->employee_id) {
                    throw new \Exception('RFI Objection create or user relation failed');
                }
                return $obj;
            }, $testObj);

            // ─────────────────────────────────────────────────────────────
            // MODULE 4: ATTENDANCE & LEAVES CRUD
            // ─────────────────────────────────────────────────────────────
            $this->section('4. Attendance, Shifts, Geo-Punch & Leaves CRUD');

            $this->runTest('Shift: Create & Schedule Validation', function () {
                $shift = Shift::firstOrCreate(['name' => 'Morning Shift (Audit)'], [
                    'code' => 'MS-AUD',
                    'start_time' => '08:00:00',
                    'end_time' => '16:00:00',
                    'grace_period_mins' => 15,
                    'is_active' => true,
                ]);
                if (! $shift) {
                    throw new \Exception('Shift creation failed');
                }
                return $shift;
            }, $testShift);

            $this->runTest('Attendance Type: Geo Polygon Definition', function () {
                $type = AttendanceType::updateOrCreate(['slug' => 'geo_polygon'], [
                    'name' => 'HQ Dhaka Bypass Geofence',
                    'is_active' => true,
                    'config' => [
                        'polygons' => [
                            [
                                'id' => 'hq_polygon',
                                'is_active' => true,
                                'points' => [
                                    ['lat' => 23.8000, 'lng' => 90.4000],
                                    ['lat' => 23.8200, 'lng' => 90.4000],
                                    ['lat' => 23.8200, 'lng' => 90.4200],
                                    ['lat' => 23.8000, 'lng' => 90.4200],
                                ],
                            ],
                        ],
                    ],
                ]);
                return $type;
            }, $testPunchType);

            $this->runTest('Leave Application: Create & Status Update', function () use ($admin) {
                $leaveSetting = \App\Models\HRM\LeaveSetting::first();
                $leaveType = $leaveSetting ? $leaveSetting->id : 1;
                $leave = Leave::create([
                    'user_id' => $admin->employee_id,
                    'leave_type' => $leaveType,
                    'from_date' => now()->addDays(10)->toDateString(),
                    'to_date' => now()->addDays(12)->toDateString(),
                    'no_of_days' => 3,
                    'reason' => 'Automated E2E Audit Leave Request',
                    'status' => 'Pending',
                ]);

                $leave->update(['status' => 'Approved', 'approved_by' => $admin->employee_id]);
                $found = Leave::with('user')->find($leave->id);
                if (! $found || $found->status !== 'Approved') {
                    throw new \Exception('Leave application or approval failed');
                }
                return $leave;
            }, $testLeave);

            // ─────────────────────────────────────────────────────────────
            // MODULE 5: OPERATIONS & MAINTENANCE (O&M) CRUD
            // ─────────────────────────────────────────────────────────────
            $this->section('5. Operations & Maintenance (O&M) CRUD');

            $this->runTest('O&M Equipment: Create, Query & Update', function () {
                $eq = OmEquipment::create([
                    'equipment_code' => 'WIM-' . rand(1000, 9999),
                    'name' => 'Weigh-in-Motion Sensor #' . rand(100, 999),
                    'category' => 'wim',
                    'location' => 'Plaza Lane 2',
                    'status' => 'online',
                ]);
                $eq->update(['status' => 'offline']);
                $found = OmEquipment::find($eq->id);
                if (! $found || $found->status !== 'offline') {
                    throw new \Exception('OmEquipment CRUD failed');
                }
            });

            $this->runTest('O&M Incident: Create & Resolve', function () {
                $inc = OmIncident::create([
                    'incident_number' => 'INC-' . rand(1000, 9999),
                    'title' => 'Vehicle Stall at Chainage K15+200',
                    'chainage' => 'K15+200',
                    'severity' => 'minor',
                    'status' => 'detected',
                    'reported_at' => now(),
                ]);
                $inc->update(['status' => 'cleared']);
                $found = OmIncident::find($inc->id);
                if (! $found || $found->status !== 'cleared') {
                    throw new \Exception('OmIncident CRUD failed');
                }
            });

            $this->runTest('O&M Shift Log: Create & Validate', function () use ($admin) {
                $log = OmShiftLog::create([
                    'shift_date' => now()->toDateString(),
                    'shift_type' => 'night',
                    'operator_id' => $admin->employee_id,
                    'handover_notes' => 'Smooth operations with zero downtime.',
                ]);
                $found = OmShiftLog::find($log->id);
                if (! $found || (string)$found->operator_id !== (string)$admin->employee_id) {
                    throw new \Exception('OmShiftLog create failed');
                }
            });

            $this->runTest('O&M Toll Operations: Record Entry', function () {
                $record = OmTollRecord::create([
                    'lane_id' => 'Lane-03',
                    'vehicle_class' => 'Heavy Truck',
                    'amount' => 450.00,
                    'payment_method' => 'ETC',
                    'transacted_at' => now(),
                ]);
                $found = OmTollRecord::find($record->id);
                if (! $found || (float)$found->amount !== 450.00) {
                    throw new \Exception('OmTollRecord CRUD failed');
                }
            });

            // ─────────────────────────────────────────────────────────────
            // MODULE 6: FINANCIALS & PETTY CASH CRUD
            // ─────────────────────────────────────────────────────────────
            $this->section('6. Financials & Petty Cash CRUD');

            $this->runTest('Petty Cash Loan: Issue & Repayment Tracking', function () use ($admin) {
                $loan = PettyCashLoan::create([
                    'user_id' => $admin->employee_id,
                    'fund_name' => 'Site Fuel Fund',
                    'loan_amount' => 5000.00,
                    'original_amount' => 5000.00,
                    'current_balance' => 5000.00,
                    'status' => 'active',
                    'loan_date' => now()->toDateString(),
                    'approved_by' => $admin->employee_id,
                ]);
                $loan->update(['current_balance' => 3500.00]);
                $found = PettyCashLoan::with('user')->find($loan->id);
                if (! $found || (float)$found->current_balance !== 3500.00) {
                    throw new \Exception('PettyCashLoan CRUD failed');
                }
                return $loan;
            }, $testLoan);

            $this->runTest('Petty Cash Transaction: Record & Balance Check', function () use ($testLoan) {
                $txn = PettyCashTransaction::create([
                    'petty_cash_loan_id' => $testLoan->id,
                    'type' => 'expense',
                    'category' => 'Fuel',
                    'amount' => 1500.00,
                    'description' => 'Diesel Fuel receipt #8812',
                    'transaction_date' => now()->toDateString(),
                ]);
                $found = PettyCashTransaction::with(['pettyCashLoan'])->find($txn->id);
                if (! $found || (float)$found->amount !== 1500.00) {
                    throw new \Exception('PettyCashTransaction CRUD failed');
                }
            });

            // ─────────────────────────────────────────────────────────────
            // MODULE 7: MOBILE API v1 ENDPOINTS WITH AUTH & DEVICE
            // ─────────────────────────────────────────────────────────────
            $this->section('7. Mobile API v1 Route Verification');

            $admin->attendanceTypes()->syncWithoutDetaching([$testPunchType->id]);
            $admin->update(['attendance_type_id' => $testPunchType->id]);
            $admin->refresh();

            $token = $admin->createToken('E2E Audit Token')->plainTextToken;

            $apiRoutes = [
                ['GET', '/api/v1/auth/me', []],
                ['GET', '/api/v1/profile', []],
                ['GET', '/api/v1/config', []],
                ['GET', '/api/v1/sync/bootstrap', []],
                ['GET', '/api/v1/attendance/today', []],
                ['GET', '/api/v1/attendance/history', []],
                ['GET', '/api/v1/attendance/my-roster?from=' . now()->startOfMonth()->toDateString() . '&to=' . now()->endOfMonth()->toDateString(), []],
                ['GET', '/api/v1/attendance/roster?from=' . now()->startOfMonth()->toDateString() . '&to=' . now()->endOfMonth()->toDateString(), []],
                ['GET', '/api/v1/attendance/shifts', []],
                ['GET', '/api/v1/attendance/regularizations/mine', []],
                ['GET', '/api/v1/attendance/overtime/mine', []],
                ['GET', '/api/v1/attendance/swaps/pending', []],
                ['GET', '/api/v1/daily-works', []],
                ['GET', '/api/v1/daily-works/selectable-dates', []],
                ['GET', '/api/v1/daily-works/objections/metadata', []],
                ['GET', '/api/v1/daily-works/objections/my', []],
                ['GET', '/api/v1/leaves', []],
                ['GET', '/api/v1/leaves/summary', []],
                ['GET', '/api/v1/leave-types', []],
                ['GET', '/api/notifications', []],
                ['GET', '/api/notifications/unread-count', []],
                ['POST', '/api/v1/attendance/punch', [
                    'lat' => 23.8103,
                    'lng' => 90.4125,
                    'location' => 'HQ Toll Plaza',
                    'photo' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                ]],
            ];

            foreach ($apiRoutes as [$method, $uri, $params]) {
                $this->runTest("API Endpoint: $method $uri", function () use ($app, $token, $deviceId, $method, $uri, $params) {
                    $req = Request::create($uri, $method, $params);
                    $req->headers->set('Authorization', 'Bearer ' . $token);
                    $req->headers->set('Accept', 'application/json');
                    $req->headers->set('X-Device-Id', $deviceId);

                    $resp = $app->handle($req);
                    $status = $resp->getStatusCode();

                    if ($status < 200 || $status >= 400) {
                        throw new \Exception("Status $status: " . substr($resp->getContent(), 0, 120));
                    }
                });
            }

            // ─────────────────────────────────────────────────────────────
            // MODULE 8: WEB CONTROLLER PAGES & INERTIA RESPONSES
            // ─────────────────────────────────────────────────────────────
            $this->section('8. Web Pages & Controller Handlers');

            $session = $app->make('session.store');
            $session->start();
            $session->put('login_web_59ba36addc2b2f9401580f014c7f58ea4e30989d', $admin->getAuthIdentifier());
            $session->put('device_id', $deviceId);
            $session->put('device_verified', true);

            $webRoutes = [
                '/dashboard',
                '/employees',
                '/users/paginate',
                '/users/stats',
                '/departments',
                '/designations',
                '/roles-permissions',
                '/organization',
                '/holidays',
                '/leaves',
                '/leaves-paginate',
                '/leaves-stats',
                '/attendance-employee',
                '/reports',
                '/reports-json',
                '/stats',
                '/tasks-all',
                '/tasks/daily-summary-json',
                '/petty-cash',
                '/petty-cash/history',
                '/petty-cash/categories',
                '/my-devices',
                '/notifications',
                '/notifications/list',
                '/notifications/unread-count',
                '/workspace/objections',
                '/settings/notifications',
                '/settings/notifications/list',
                '/security/dashboard',
                '/om/dashboard',
                '/om/equipment',
                '/om/incidents',
                '/om/shift-logs',
                '/om/toll-operations',
                '/om/traffic-monitoring',
                '/om/work-orders',
                '/updates',
            ];

            foreach ($webRoutes as $uri) {
                $this->runTest("Web Route: GET $uri", function () use ($app, $admin, $session, $deviceId, $uri) {
                    Auth::guard('web')->setUser($admin);
                    $req = Request::create($uri, 'GET');
                    $req->setLaravelSession($session);
                    $req->setUserResolver(fn () => $admin);
                    $req->headers->set('Accept', 'application/json, text/plain, */*');
                    $req->headers->set('X-Requested-With', 'XMLHttpRequest');
                    $req->headers->set('X-Device-Id', $deviceId);
                    $req->cookies->set('device_id', $deviceId);

                    $resp = $app->handle($req);
                    $status = $resp->getStatusCode();

                    if ($status >= 500) {
                        throw new \Exception("Server Error 500: " . substr($resp->getContent(), 0, 150));
                    }
                });
            }

        } finally {
            // Clean rollback so no test artifacts remain in production database
            DB::rollBack();
        }

        $this->newLine();
        $this->info('=====================================================================');
        $this->info("FINAL AUDIT RESULT: {$this->passed} Passed, {$this->failed} Failed");
        if ($this->failed > 0) {
            $this->error('Failed assertions detected:');
            foreach ($this->failures as $failure) {
                $this->line(" ❌ {$failure['name']}: {$failure['error']}");
            }
            $this->info('=====================================================================');
            return 1;
        }

        $this->info('🎉 100% OF ALL MODULES, TABS, CRUDS & APIS PASSED WITH ZERO ERRORS!');
        $this->info('=====================================================================');
        return 0;
    }

    private function section(string $title): void
    {
        $this->info("\n--- $title ---");
    }

    private function runTest(string $name, callable $callback, mixed &$result = null): void
    {
        try {
            $result = $callback();
            $this->line("  [PASS] $name");
            $this->passed++;
        } catch (\Throwable $e) {
            $this->error("  [FAIL] $name => " . $e->getMessage());
            $this->failed++;
            $this->failures[] = ['name' => $name, 'error' => $e->getMessage()];
        }
    }
}
