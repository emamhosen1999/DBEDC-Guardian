# Attendance Accounts Monthly Summary (Phase B1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hand accounts a per-employee monthly attendance Excel (present/absent/leave/OT-hours/late + auditable extras), derived from the same engine as the grid/dashboard, plus the capture guards and termination clamp that keep its numbers honest.

**Architecture:** A new `AttendanceReportService::getPerEmployeeMonthlySummary()` reuses the existing private `buildMonthlyDayResults()` + `classifyDay()` engine pass (so it can't diverge from the on-screen grid/dashboard) to produce one row per employee. A plain-PhpSpreadsheet exporter writes the 12-column FULL/auditable sheet, served synchronously from a new `attendance.view`-gated GET endpoint and a "Summary (Excel)" toolbar button. Three `AttendancePunchService` guards reject bad input, and a `User → Offboarding` relationship adds a leaver clamp mirroring the existing joiner clamp.

**Tech Stack:** Laravel 11, PHP 8.x, PhpSpreadsheet, Inertia v2 + React 18 (Radix), MySQL (prod) / sqlite `:memory:` (tests), PHPUnit class-style.

## Global Constraints

- Single-engine rule: ALL summary numbers derive from one `AttendanceStatusService` pass via the existing private `buildMonthlyDayResults()` + `classifyDay()`. No second counting path.
- Permission gate: reuse `attendance.view`. No new permission.
- Timezone: single-tenant Asia/Dhaka; all Carbon is server-local (existing convention).
- Whole-day leave/present model in this phase (half-days are B3) — document as a sheet caveat, do not attempt 0.5 precision.
- Tests: PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase`. Add NO new failing test. Only allowed pre-existing failures: `MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > any authenticated user can access organization directory`.
- Build rules: `npm run dev` for live testing; NEVER `npm run build`. Frontend tasks commit SOURCE ONLY; one consolidated `npx vite build` + `public/build` commit at the very end (Task 8).
- Reconciliation identity that the tests must enforce: per employee, `Present + Absent + Leave = Working Days + Holidays-worked + Weekly-off-worked` (because `Present` is dashboard-consistent and includes days worked on holidays/off-days, which are not scheduled Working Days), and `Attendance % = Present / (Present + Absent)` (0 when denominator is 0).
- Effective-status buckets (from `classifyDay`): worked = `[PRESENT, LATE, HALF_DAY, SHORT]`; absent = `ABSENT`; leave = `ON_LEAVE`.

---

### Task 1: `User → Offboarding` relationship (termination date source)

**Files:**
- Modify: `app/Models/User.php`
- Test: `tests/Feature/Attendance/TerminationGateTest.php` (create)

**Interfaces:**
- Consumes: `Offboarding` model (`app/Models/HRM/Offboarding.php`), FK `offboardings.employee_id → users.id`, `last_working_date` (date cast), `status` (constants incl. `STATUS_CANCELLED = 'cancelled'`).
- Produces: `User::offboarding(): HasOne` — the most-recent non-cancelled offboarding by `last_working_date` (eager-loadable as `'offboarding'`); used by Task 2/4/6 to resolve a leaver's last working date.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Offboarding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TerminationGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_offboarding_relation_returns_latest_non_cancelled_record(): void
    {
        $user = User::factory()->create();

        Offboarding::create([
            'employee_id' => $user->id,
            'initiation_date' => '2026-05-01',
            'last_working_date' => '2026-05-31',
            'reason' => Offboarding::REASON_RESIGNATION,
            'status' => Offboarding::STATUS_CANCELLED,
        ]);
        $active = Offboarding::create([
            'employee_id' => $user->id,
            'initiation_date' => '2026-06-01',
            'last_working_date' => '2026-06-15',
            'reason' => Offboarding::REASON_TERMINATION,
            'status' => Offboarding::STATUS_IN_PROGRESS,
        ]);

        $resolved = $user->fresh()->offboarding;

        $this->assertNotNull($resolved);
        $this->assertSame($active->id, $resolved->id);
        $this->assertSame('2026-06-15', $resolved->last_working_date->toDateString());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_user_offboarding_relation_returns_latest_non_cancelled_record`
Expected: FAIL — `Call to undefined method App\Models\User::offboarding()` (or relation returns null).

- [ ] **Step 3: Add the relationship**

In `app/Models/User.php`, add (near the other HRM relationships; add `use App\Models\HRM\Offboarding;` and `use Illuminate\Database\Eloquent\Relations\HasOne;` if not present):

```php
public function offboarding(): HasOne
{
    return $this->hasOne(Offboarding::class, 'employee_id')
        ->where('status', '!=', Offboarding::STATUS_CANCELLED)
        ->orderByDesc('last_working_date');
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=test_user_offboarding_relation_returns_latest_non_cancelled_record`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Models/User.php tests/Feature/Attendance/TerminationGateTest.php
git commit -m "feat(attendance): add User->offboarding relation for termination clamp"
```

---

### Task 2: Termination clamp in the engine loader + dashboard

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`getEmployeeUsersWithAttendanceAndLeaves`, `buildMonthlyDayResults`, `calculateMonthlyStats`)
- Test: `tests/Feature/Attendance/TerminationGateTest.php` (extend)

**Interfaces:**
- Consumes: `User::offboarding` (Task 1); existing `buildMonthlyDayResults` day-context array; `DayAttendance::ABSENT`.
- Produces: each day context from `buildMonthlyDayResults` gains key `'after_termination' => bool`; `calculateMonthlyStats` skips those days. Task 4 (summary) relies on the same `'after_termination'` key.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Attendance/TerminationGateTest.php`. (Set up an Employee with a working roster, no punches after the 15th, offboarded `last_working_date = 15th`; assert the dashboard counts zero absent days after the 15th.) Use the project's existing attendance test setup conventions — model it on `tests/Feature/Attendance/MonthlyStatsEngineReconcileTest.php` for roster/permission scaffolding.

```php
public function test_dashboard_does_not_count_absent_after_last_working_date(): void
{
    // Arrange: Employee with a standard working roster for June 2026, terminated on the 15th,
    // and NO punches all month (so every scheduled working day would otherwise be Absent).
    // Mirror MonthlyStatsEngineReconcileTest::setUp for roster + Employee role + AttendanceSetting.
    [$admin, $employee] = $this->seedEmployeeWithWorkingRoster(2026, 6); // helper from the reconcile test pattern

    \App\Models\HRM\Offboarding::create([
        'employee_id' => $employee->id,
        'initiation_date' => '2026-06-01',
        'last_working_date' => '2026-06-15',
        'reason' => \App\Models\HRM\Offboarding::REASON_TERMINATION,
        'status' => \App\Models\HRM\Offboarding::STATUS_COMPLETED,
    ]);

    $stats = app(\App\Services\Attendance\AttendanceReportService::class)
        ->calculateMonthlyStats(6, 2026, true, null);

    // Only scheduled working days on/before the 15th may be counted absent; none after.
    $absentBefore = $this->countScheduledWorkingDays(2026, 6, '2026-06-01', '2026-06-15');
    $this->assertSame($absentBefore, $stats['attendance']['absent']);
}
```

> Implementer note: if a shared `seedEmployeeWithWorkingRoster`/`countScheduledWorkingDays` helper does not already exist, inline the setup the way `MonthlyStatsEngineReconcileTest` does rather than inventing a helper. The assertion that matters: absent count excludes post-termination days.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=test_dashboard_does_not_count_absent_after_last_working_date`
Expected: FAIL — absent count includes days 16–30 (no clamp yet).

- [ ] **Step 3: Implement the clamp**

In `getEmployeeUsersWithAttendanceAndLeaves`, add `'offboarding'` to the `->with([...])` eager loads so the relation is loaded with the user set.

In `buildMonthlyDayResults`, after computing `$joinDate`, add:

```php
$lastWorking = $user->offboarding && $user->offboarding->last_working_date
    ? Carbon::parse($user->offboarding->last_working_date)->endOfDay()
    : null;
```

and in the per-day `$results[$dateString] = [...]` array add:

```php
'after_termination' => $lastWorking !== null && $date->copy()->startOfDay()->greaterThan($lastWorking),
```

In `calculateMonthlyStats`, in the `foreach ($dayResults as $ctx)` loop, change the existing guard:

```php
if ($ctx['before_join']) {
    continue;
}
```
to:
```php
if ($ctx['before_join'] || $ctx['after_termination']) {
    continue;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=TerminationGateTest`
Expected: PASS (both tests).

- [ ] **Step 5: Run the engine reconcile suite to confirm no regression**

Run: `php artisan test --filter="MonthlyStatsEngineReconcileTest|MonthlyStatsShiftAwareTest|MonthlyGridEngineCollapseTest"`
Expected: PASS (no new failures).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/TerminationGateTest.php
git commit -m "feat(attendance): clamp absent counting after last working date"
```

---

### Task 3: Capture guards in `AttendancePunchService`

**Files:**
- Modify: `app/Services/Attendance/AttendancePunchService.php` (`resolvePunchTime`, `punchOut`; add `MAX_CLOCK_DRIFT_HOURS` const)
- Test: `tests/Feature/Attendance/CaptureGuardsTest.php` (create)

**Interfaces:**
- Consumes: existing `processPunch` flow, `Request` inputs `punch_time`, `source` (`biometric|device`), `check_type`.
- Produces: no new public signatures. Behavior: future/over-drifted device timestamps fall back to `Carbon::now()`; out-before-in punch-out returns `['status'=>'error','code'=>422]`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CaptureGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function punch(User $user, array $input): array
    {
        return app(AttendancePunchService::class)->processPunch($user, new Request($input));
    }

    public function test_future_dated_device_punch_falls_back_to_server_time(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');
        $user = User::factory()->create();

        $res = $this->punch($user, [
            'source' => 'biometric',
            'check_type' => 'in',
            'punch_time' => '2026-06-12 09:00:00', // 2 days in the future
        ]);

        $this->assertSame('success', $res['status']);
        $row = Attendance::where('user_id', $user->id)->first();
        // Future timestamp rejected -> server time used, not the bogus future moment.
        $this->assertSame('2026-06-10', Carbon::parse($row->punchin)->toDateString());
        Carbon::setTestNow();
    }

    public function test_legit_old_back_download_is_still_honoured(): void
    {
        Carbon::setTestNow('2026-06-10 20:00:00');
        $user = User::factory()->create();

        $res = $this->punch($user, [
            'source' => 'biometric',
            'check_type' => 'in',
            'punch_time' => '2026-06-10 09:00:00', // real morning punch, downloaded in the evening
        ]);

        $this->assertSame('success', $res['status']);
        $row = Attendance::where('user_id', $user->id)->first();
        $this->assertSame('09:00', Carbon::parse($row->punchin)->format('H:i'));
        Carbon::setTestNow();
    }

    public function test_out_before_in_is_rejected(): void
    {
        Carbon::setTestNow('2026-06-10 09:00:00');
        $user = User::factory()->create();
        $this->punch($user, ['check_type' => 'in']); // server-time punch-in at 09:00

        Carbon::setTestNow('2026-06-10 09:30:00');
        // Device out-punch carrying a timestamp BEFORE the punch-in.
        $res = $this->punch($user, [
            'source' => 'biometric',
            'check_type' => 'out',
            'punch_time' => '2026-06-10 08:00:00',
        ]);

        $this->assertSame('error', $res['status']);
        $this->assertSame(422, $res['code']);
        Carbon::setTestNow();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=CaptureGuardsTest`
Expected: FAIL — future punch recorded as-is; out-before-in writes an inverted interval.

- [ ] **Step 3: Implement the guards**

In `AttendancePunchService`, add the const near the others:

```php
private const MAX_CLOCK_DRIFT_HOURS = 2;
```

Rewrite `resolvePunchTime` to bound drift toward the future (legit *past* back-downloads remain honoured; only implausible/future timestamps fall back):

```php
private function resolvePunchTime(Request $request): Carbon
{
    $raw = $request->input('punch_time');
    $source = $request->input('source');

    if ($raw && in_array($source, ['biometric', 'device'], true)) {
        try {
            $parsed = Carbon::parse($raw);
            $now = Carbon::now();
            // Reject future-dated / over-drifted device clocks: a real punch is never in the
            // future, and a clock running fast beyond tolerance is drift, not a real moment.
            if ($parsed->greaterThan($now->copy()->addHours(self::MAX_CLOCK_DRIFT_HOURS))) {
                Log::warning('Rejected future/over-drifted device punch_time; using server time', [
                    'punch_time' => $raw,
                    'now' => $now->toDateTimeString(),
                ]);

                return $now;
            }

            return $parsed;
        } catch (\Throwable $e) {
            // Unparseable device timestamp — fall back to server time rather than fail capture.
        }
    }

    return Carbon::now();
}
```

In `punchOut`, before `$attendance->update([...])`, add the out-before-in guard:

```php
if ($attendance->punchin && $punchTime->lessThanOrEqualTo(Carbon::parse($attendance->punchin))) {
    return [
        'status' => 'error',
        'message' => 'Punch-out cannot be before punch-in.',
        'code' => 422,
    ];
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=CaptureGuardsTest`
Expected: PASS (all three).

- [ ] **Step 5: Run the existing punch/biometric suite for no regression**

Run: `php artisan test --filter="BiometricPunchTimeTest|AttendancePunch"`
Expected: PASS (no new failures).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendancePunchService.php tests/Feature/Attendance/CaptureGuardsTest.php
git commit -m "feat(attendance): reject future/over-drifted device punches and out-before-in"
```

---

### Task 4: `getPerEmployeeMonthlySummary()` service method

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php`
- Test: `tests/Feature/Attendance/PerEmployeeSummaryTest.php` (create)

**Interfaces:**
- Consumes: private `buildMonthlyDayResults()`, `classifyDay()`, `getEmployeeUsersWithAttendanceAndLeaves()`, `getHolidaysForMonth()`; `DayAttendance` constants; the `'before_join'` / `'after_termination'` day-context keys (Task 2).
- Produces: `getPerEmployeeMonthlySummary(int $year, int $month, ?int $departmentId = null): array` returning `['meta' => [...], 'rows' => [...]]` with row keys: `employee_name, employee_id, department, present, absent, leave, ot_hours, late, holidays_worked, weekly_off_worked, working_days, attendance_percentage`. Tasks 5 & 6 consume this shape.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PerEmployeeSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_reconciles_with_dashboard_and_identity(): void
    {
        // Arrange a fixture month with a mix of present/late/absent/leave and an off-day work day.
        // Reuse the roster + punch + leave seeding pattern from MonthlyStatsEngineReconcileTest.
        $service = app(AttendanceReportService::class);

        $summary = $service->getPerEmployeeMonthlySummary(2026, 6);
        $stats = $service->calculateMonthlyStats(6, 2026, true, null);

        $this->assertArrayHasKey('rows', $summary);
        $this->assertArrayHasKey('meta', $summary);

        // Per-row identity: Present + Absent + Leave = Working Days.
        foreach ($summary['rows'] as $row) {
            $this->assertSame(
                $row['present'] + $row['absent'] + $row['leave'],
                $row['working_days'],
                "Row identity failed for {$row['employee_name']}"
            );
            $denom = $row['present'] + $row['absent'];
            $expectedPct = $denom > 0 ? round($row['present'] / $denom * 100, 1) : 0.0;
            $this->assertSame($expectedPct, $row['attendance_percentage']);
        }

        // Aggregates reconcile with the dashboard's single-engine counts.
        $this->assertSame($stats['attendance']['present'], array_sum(array_column($summary['rows'], 'present')));
        $this->assertSame($stats['attendance']['absent'], array_sum(array_column($summary['rows'], 'absent')));
        $this->assertSame($stats['attendance']['leaves'], array_sum(array_column($summary['rows'], 'leave')));
        $this->assertSame($stats['attendance']['lateArrivals'], array_sum(array_column($summary['rows'], 'late')));
    }

    public function test_department_filter_narrows_rows(): void
    {
        $service = app(AttendanceReportService::class);
        // Seed two employees in different departments (reuse setUp helpers).
        $all = $service->getPerEmployeeMonthlySummary(2026, 6);
        $this->assertNotEmpty($all['rows']);
        // A non-matching department id yields zero rows; this asserts the filter is wired.
        $none = $service->getPerEmployeeMonthlySummary(2026, 6, 999999);
        $this->assertCount(0, $none['rows']);
    }
}
```

> Implementer note: seed the fixture month with the same helpers/conventions used by `MonthlyStatsEngineReconcileTest`. The decisive assertions are the identity and the dashboard reconciliation — those prove single-engine fidelity.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PerEmployeeSummaryTest`
Expected: FAIL — `Call to undefined method ...::getPerEmployeeMonthlySummary()`.

- [ ] **Step 3: Implement the method**

Add to `AttendanceReportService` (after `calculateMonthlyStats`):

```php
/**
 * Per-employee monthly totals for the accounts handoff.
 *
 * Reuses the SAME engine pass as the grid/dashboard (buildMonthlyDayResults + classifyDay)
 * so the sheet cannot diverge from what the admin sees. Leave-days are engine-derived
 * (already exclude weekends/holidays via precedence). Whole-day model until B3.
 *
 * @return array{meta: array, rows: array<int, array>}
 */
public function getPerEmployeeMonthlySummary(int $year, int $month, ?int $departmentId = null): array
{
    $resolver = app(ScheduleResolver::class);
    $policyResolver = app(PolicyResolver::class);
    $statusEngine = app(AttendanceStatusService::class);

    $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
    $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
    $analysisEndDate = $endOfMonth->isFuture() ? Carbon::now()->endOfDay() : $endOfMonth;

    $holidays = $this->getHolidaysForMonth($year, $month);
    $users = $this->getEmployeeUsersWithAttendanceAndLeaves($year, $month, $departmentId);

    $workedStatuses = [
        DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT,
    ];

    $rows = [];
    foreach ($users as $user) {
        $dayResults = $this->buildMonthlyDayResults(
            $user, $year, $month, $holidays, $analysisEndDate,
            $resolver, $policyResolver, $statusEngine
        );

        $present = $absent = $leave = $late = $holidaysWorked = $weeklyOffWorked = $workingDays = 0;
        $otMinutes = 0;

        foreach ($dayResults as $ctx) {
            if ($ctx['before_join'] || $ctx['after_termination']) {
                continue;
            }

            /** @var DayAttendance $result */
            $result = $ctx['result'];
            $effective = $this->classifyDay($ctx);
            $hasPunch = $ctx['attendances']->isNotEmpty();

            if (in_array($effective, $workedStatuses, true)) {
                $present++;
                $otMinutes += $result->ot_minutes;
                if ($result->late_minutes > 0) {
                    $late++;
                }
            } elseif ($effective === DayAttendance::ABSENT) {
                $absent++;
            } elseif ($effective === DayAttendance::ON_LEAVE) {
                $leave++;
            }

            if ($ctx['holiday'] && $hasPunch) {
                $holidaysWorked++;
            } elseif (! $ctx['holiday'] && ! $ctx['schedule']->isWorkingDay && $hasPunch) {
                $weeklyOffWorked++;
            }

            // Scheduled working day (holiday/off-day excluded) -> Present + Absent + Leave.
            if ($ctx['schedule']->isWorkingDay && ! $ctx['holiday']) {
                $workingDays++;
            }
        }

        $denom = $present + $absent;

        $rows[] = [
            'employee_name' => $user->name,
            'employee_id' => $user->employee_id,
            'department' => optional($user->department)->name ?? '—',
            'present' => $present,
            'absent' => $absent,
            'leave' => $leave,
            'ot_hours' => round($otMinutes / 60, 1),
            'late' => $late,
            'holidays_worked' => $holidaysWorked,
            'weekly_off_worked' => $weeklyOffWorked,
            'working_days' => $workingDays,
            'attendance_percentage' => $denom > 0 ? round($present / $denom * 100, 1) : 0.0,
        ];
    }

    $department = $departmentId
        ? \App\Models\HRM\Department::find($departmentId)
        : null;

    return [
        'meta' => [
            'month' => $startOfMonth->format('F Y'),
            'generatedAt' => Carbon::now()->toIso8601String(),
            'departmentId' => $departmentId,
            'departmentName' => $department->name ?? null,
        ],
        'rows' => $rows,
    ];
}
```

> Implementer note: confirm the Department model FQCN and the `User::department` relationship name during implementation (grep `function department(` in `app/Models/User.php`); adjust `optional($user->department)->name` and the `Department::find` import to match. If `user->department` is not eager-loaded, add `'department'` to the `with([...])` in `getEmployeeUsersWithAttendanceAndLeaves` to avoid an N+1.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PerEmployeeSummaryTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/PerEmployeeSummaryTest.php
git commit -m "feat(attendance): per-employee monthly summary from the shared engine pass"
```

---

### Task 5: `AttendancePerEmployeeSummaryExport` (Excel writer)

**Files:**
- Create: `app/Exports/AttendancePerEmployeeSummaryExport.php`
- Test: `tests/Feature/Attendance/PerEmployeeSummaryExportTest.php` (create)

**Interfaces:**
- Consumes: `AttendanceReportService::getPerEmployeeMonthlySummary()` (Task 4).
- Produces: `AttendancePerEmployeeSummaryExport::export(int $year, int $month, ?int $departmentId = null): \Symfony\Component\HttpFoundation\BinaryFileResponse` — a synchronous `.xlsx` download. Task 6 calls this.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Exports\AttendancePerEmployeeSummaryExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

class PerEmployeeSummaryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_returns_xlsx_download(): void
    {
        $response = (new AttendancePerEmployeeSummaryExport)->export(2026, 6);

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $disposition = $response->headers->get('content-disposition');
        $this->assertStringContainsString('.xlsx', $disposition);
        $this->assertStringContainsString('Summary', $disposition);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PerEmployeeSummaryExportTest`
Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement the exporter**

Create `app/Exports/AttendancePerEmployeeSummaryExport.php`:

```php
<?php

namespace App\Exports;

use App\Models\HRM\Department;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendancePerEmployeeSummaryExport
{
    private const HEADERS = [
        'Employee', 'Emp ID', 'Department', 'Present', 'Absent', 'Leave',
        'OT Hours', 'Late', 'Holidays Worked', 'Weekly-off Worked', 'Working Days', 'Attendance %',
    ];

    public function export(int $year, int $month, ?int $departmentId = null)
    {
        $summary = app(AttendanceReportService::class)
            ->getPerEmployeeMonthlySummary($year, $month, $departmentId);

        $monthName = Carbon::create($year, $month, 1)->format('F_Y');
        $deptSuffix = $departmentId && ($summary['meta']['departmentName'] ?? null)
            ? '_'.str_replace(' ', '_', $summary['meta']['departmentName'])
            : '';
        $fileName = "DBEDC_Attendance_Summary_{$monthName}{$deptSuffix}.xlsx";

        $spreadsheet = $this->buildSpreadsheet($summary);
        $writer = new Xlsx($spreadsheet);
        $tempFile = tempnam(sys_get_temp_dir(), 'att_summary');
        $writer->save($tempFile);

        return response()->download($tempFile, $fileName)->deleteFileAfterSend(true);
    }

    private function buildSpreadsheet(array $summary): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $lastColLetter = 'L'; // 12 columns

        // Title + month + caveat.
        $sheet->setCellValue('A1', 'Dhaka Bypass Expressway Development Company Ltd. — Monthly Attendance Summary');
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', $summary['meta']['month']
            .($summary['meta']['departmentName'] ? ' — '.$summary['meta']['departmentName'] : ''));
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->getStyle('A2')->getFont()->setBold(true);
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A3', 'Whole-day leave/present model — half-days are not yet split (a half-day may count as a full day).');
        $sheet->mergeCells("A3:{$lastColLetter}3");
        $sheet->getStyle('A3')->getFont()->setItalic(true)->setSize(9);
        $sheet->getStyle('A3')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

        // Header row (row 5).
        $headerRow = 5;
        $col = 'A';
        foreach (self::HEADERS as $h) {
            $sheet->setCellValue("{$col}{$headerRow}", $h);
            $col++;
        }

        // Data rows.
        $row = $headerRow + 1;
        $totals = ['present' => 0, 'absent' => 0, 'leave' => 0, 'ot_hours' => 0.0,
            'late' => 0, 'holidays_worked' => 0, 'weekly_off_worked' => 0, 'working_days' => 0];

        foreach ($summary['rows'] as $r) {
            $sheet->fromArray([
                $r['employee_name'], $r['employee_id'], $r['department'],
                $r['present'], $r['absent'], $r['leave'], $r['ot_hours'], $r['late'],
                $r['holidays_worked'], $r['weekly_off_worked'], $r['working_days'], $r['attendance_percentage'],
            ], null, "A{$row}");

            foreach (array_keys($totals) as $k) {
                $totals[$k] += $r[$k];
            }
            $row++;
        }

        // Totals footer.
        $sheet->setCellValue("A{$row}", 'TOTAL');
        $sheet->fromArray([
            $totals['present'], $totals['absent'], $totals['leave'], round($totals['ot_hours'], 1),
            $totals['late'], $totals['holidays_worked'], $totals['weekly_off_worked'], $totals['working_days'], '',
        ], null, "D{$row}");
        $sheet->getStyle("A{$row}:{$lastColLetter}{$row}")->getFont()->setBold(true);

        // Borders + header shading.
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$row}")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFE0E0E0');
        $sheet->getStyle("A{$headerRow}:{$lastColLetter}{$headerRow}")->getFont()->setBold(true);

        foreach (range('A', $lastColLetter) as $c) {
            $sheet->getColumnDimension($c)->setAutoSize(true);
        }

        return $spreadsheet;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PerEmployeeSummaryExportTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Exports/AttendancePerEmployeeSummaryExport.php tests/Feature/Attendance/PerEmployeeSummaryExportTest.php
git commit -m "feat(attendance): per-employee monthly summary Excel writer (12-col auditable)"
```

---

### Task 6: Endpoint + route

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php` (add `exportMonthlySummary`)
- Modify: `routes/web.php` (add route in the `permission:attendance.view` group ~line 487, beside `attendance.monthlyCalendar.export`)
- Test: `tests/Feature/Attendance/MonthlySummaryEndpointTest.php` (create)

**Interfaces:**
- Consumes: `AttendancePerEmployeeSummaryExport::export()` (Task 5).
- Produces: `GET /attendance/monthly-summary/export?month=YYYY-MM&department_id=?` (route name `attendance.monthlySummary.export`), gated by `permission:attendance.view`, returns the xlsx download.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MonthlySummaryEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_user_can_download_summary(): void
    {
        Permission::firstOrCreate(['name' => 'attendance.view']);
        $admin = User::factory()->create();
        $admin->givePermissionTo('attendance.view');

        $res = $this->actingAs($admin)->get('/attendance/monthly-summary/export?month=2026-06');

        $res->assertOk();
        $res->assertHeader('content-disposition');
        $this->assertStringContainsString(
            'spreadsheetml',
            $res->headers->get('content-type')
        );
    }

    public function test_unauthorized_user_blocked(): void
    {
        $user = User::factory()->create();
        $res = $this->actingAs($user)->get('/attendance/monthly-summary/export?month=2026-06');
        $res->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MonthlySummaryEndpointTest`
Expected: FAIL — route not defined (404).

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/AttendanceController.php`, near `exportMonthlyCalendar` (~line 814), add (ensure `use App\Exports\AttendancePerEmployeeSummaryExport;` at top):

```php
public function exportMonthlySummary(Request $request)
{
    try {
        $month = $request->query('month', now()->format('Y-m'));
        $from = Carbon::parse($month.'-01');
        $departmentId = $request->query('department_id');
        $departmentId = $departmentId !== null && $departmentId !== '' ? (int) $departmentId : null;

        return (new AttendancePerEmployeeSummaryExport)
            ->export($from->year, $from->month, $departmentId);
    } catch (\Exception $e) {
        return response()->json([
            'error' => 'Summary export failed.',
            'details' => $this->safeExceptionMessage($e, 'Export failed.'),
        ], 500);
    }
}
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the `permission:attendance.view` group (next to `attendance.monthlyCalendar.export`, ~line 487):

```php
Route::get('/attendance/monthly-summary/export', [AttendanceController::class, 'exportMonthlySummary'])->name('attendance.monthlySummary.export');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=MonthlySummaryEndpointTest`
Expected: PASS (both).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php routes/web.php tests/Feature/Attendance/MonthlySummaryEndpointTest.php
git commit -m "feat(attendance): GET /attendance/monthly-summary/export (attendance.view)"
```

---

### Task 7: Frontend "Summary (Excel)" button (SOURCE ONLY)

**Files:**
- Modify: `resources/js/api/queries/useAttendanceQuery.js`
- Modify: `resources/js/Pages/Attendance/MonthlyCalendarTab.jsx`

**Interfaces:**
- Consumes: endpoint `GET /attendance/monthly-summary/export` (Task 6); existing `handleExportResponse` (direct-blob path), `requestJson`, toolbar state `selectedMonth` + `selectedDepartmentId`.
- Produces: `useExportMonthlySummary()` mutation; a toolbar button.

- [ ] **Step 1: Add the mutation**

In `resources/js/api/queries/useAttendanceQuery.js`, after `useExportMonthlyCalendar`:

```js
/**
 * Export per-employee monthly summary (accounts handoff) — direct xlsx download.
 */
export const useExportMonthlySummary = () => {
  return useMutation({
    mutationFn: ({ month, departmentId }) => requestJson('get', '/attendance/monthly-summary/export', {
      params: { month, department_id: departmentId },
      responseType: 'blob'
    }),
  });
};
```

- [ ] **Step 2: Wire the button**

In `resources/js/Pages/Attendance/MonthlyCalendarTab.jsx`:

1. Import the hook (add to the existing `useAttendanceQuery` usage). Near the other export hook (`const exportMonthlyCalendar = useAttendanceQuery.useExportMonthlyCalendar();`, ~line 404), add:

```jsx
const exportSummary = useAttendanceQuery.useExportMonthlySummary();
```

2. Add an export handler near `exportFile` (~line 432):

```jsx
const exportSummaryFile = useCallback(async () => {
    setDownloading('summary');
    try {
        const data = await exportSummary.mutateAsync({
            month: selectedMonth,
            departmentId: selectedDepartmentId !== 'all' ? parseInt(selectedDepartmentId) : null,
        });
        await handleExportResponse(
            data,
            `DBEDC_Attendance_Summary_${selectedMonth}.xlsx`,
            undefined,
            'xlsx'
        );
    } catch (err) {
        console.error('Summary export failed:', err);
        showToast.error('Failed to export summary.');
    } finally { setDownloading(''); }
}, [selectedMonth, selectedDepartmentId, exportSummary]);
```

3. Add the button in the admin export block (after the PDF button, before the closing `</>`, ~line 535):

```jsx
<Button
    size="2" variant="soft" color="blue"
    disabled={isLoading || downloading !== ''}
    onClick={exportSummaryFile}
>
    <DownloadIcon />
    {downloading === 'summary' ? 'Exporting…' : 'Summary (Excel)'}
</Button>
```

- [ ] **Step 3: Build assets locally to verify it compiles (does NOT commit build)**

Run: `npx vite build`
Expected: build completes with no errors referencing `MonthlyCalendarTab` or `useAttendanceQuery`.

- [ ] **Step 4: Commit SOURCE ONLY**

```bash
git add resources/js/api/queries/useAttendanceQuery.js resources/js/Pages/Attendance/MonthlyCalendarTab.jsx
git commit -m "feat(attendance): Summary (Excel) button on admin attendance page"
```

> Do NOT `git add public/build` here — that is the single consolidated commit in Task 8.

---

### Task 8: Live verification + consolidated build commit

**Files:**
- Modify: `public/build/**` (one consolidated rebuild commit)
- Modify: `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md` (mark B1 done)

- [ ] **Step 1: Run the full attendance test suite**

Run: `php artisan test --filter=Attendance`
Expected: PASS — no new failures vs the two allowed pre-existing ones.

- [ ] **Step 2: Live-verify the download (dev server already running via `npm run dev`)**

Use Playwright (login `emam@dhakabypass.com` / `123456789`) at `https://aero-enterprise-suite.test`:
- Open the admin Attendance page (Monthly Calendar tab).
- Click **Summary (Excel)** with a month selected; confirm a `.xlsx` downloads (HTTP 200 on `/attendance/monthly-summary/export`).
- Select a department, click again; confirm the filtered file downloads.
- If a stale-config 500 appears, run `php artisan config:clear`. Clean up any seeded data afterward.

- [ ] **Step 3: Rebuild assets and commit the build once**

Run: `npx vite build`

```bash
git add public/build
git commit -m "build(attendance): rebuild assets for monthly summary export button"
```

- [ ] **Step 4: Mark B1 done in the roadmap**

Edit `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`: mark the B1 bullet (summary export + capture guards + termination gate) ✅ done, leaving B2/B3 planned.

```bash
git add docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md
git commit -m "docs(attendance): mark Phase B1 done (accounts summary + guards + termination gate)"
```

- [ ] **Step 5: Note deploy state**

Record (memory / deploy notes) that B1 adds NO migration; prod deploy = `public/build` only. The pending prod migrations from the prior session (swap `type` / `counterparty_status`) are unchanged by this work.

---

## Self-Review

**Spec coverage:**
- Deliverable 1 (summary service + Excel + endpoint + button): Tasks 4, 5, 6, 7. ✅
- Deliverable 2 (capture guards: future punch, clock-drift bound, out-before-in): Task 3. ✅
- Deliverable 3 (termination gate: relationship + clamp in loader/dashboard/summary): Tasks 1, 2 (+ summary honours it in Task 4). ✅
- FULL 12-column auditable layout + totals footer + caveat: Task 5. ✅
- Reconciliation identity tests: Task 4. ✅
- Reuse `attendance.view`, no new permission: Task 6. ✅
- No migration: confirmed in Task 8 Step 5. ✅

**Placeholder scan:** Test fixtures in Tasks 2 & 4 reference the `MonthlyStatsEngineReconcileTest` seeding pattern rather than inlining ~60 lines of roster setup; flagged with implementer notes pointing at the exact existing test to copy. The decisive assertions are concrete. This is a deliberate DRY pointer to an existing pattern, not a content gap.

**Type consistency:** Row keys (`employee_name, employee_id, department, present, absent, leave, ot_hours, late, holidays_worked, weekly_off_worked, working_days, attendance_percentage`) are identical across Task 4 (produced), Task 5 (consumed in `fromArray`/totals), and the Task 4 test. `'after_termination'` key defined in Task 2, consumed in Tasks 2 & 4. `export(year, month, departmentId)` signature consistent across Tasks 5 & 6. Route name `attendance.monthlySummary.export` and path `/attendance/monthly-summary/export` consistent across Tasks 6 & 7.
