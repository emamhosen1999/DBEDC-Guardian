# Attendance Single-Engine Collapse Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `AttendanceReportService` derive every displayed grid and dashboard number from a single `AttendanceStatusService` pass per employee-day, eliminating the legacy `calculateTotalMinutes()` hours-math and the `daysPassed*2/7` absent estimate.

**Architecture:** Introduce one private helper `buildMonthlyDayResults()` that resolves schedule + holiday + leave + policy and calls the engine once per employee-day, returning the `DayAttendance` plus context. The monthly-grid method (`getUserAttendanceData`) becomes a pure display mapper over those results; the dashboard method (`calculateMonthlyStats`) becomes a status aggregator over the same results. Both load employees through the existing `getEmployeeUsersWithAttendanceAndLeaves` loader so they share identical holiday/leave/policy inputs.

**Tech Stack:** Laravel 11, PHP 8.x, Carbon, PHPUnit class-style on sqlite `:memory:` + `RefreshDatabase`. No new migrations, routes, dependencies, or frontend changes — the JSON output shape is unchanged.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-06-22-attendance-single-engine-collapse-design.md`.
- The `paginate` per-day cell JSON shape is a public contract — keep ALL keys: `status` (a symbol string), `punch_in`, `punch_out`, `total_work_hours` ("HH:MM"), `remarks`, `ot_minutes`, `worked_minutes`, `double_time_minutes`, `regular_minutes`, `break_deducted_minutes`, `policy_events`.
- `calculateMonthlyStats` response shape unchanged: `meta{month,scope,totalEmployees,workingDays,holidays,weekends}`, `attendance{present,absent,leaves,lateArrivals,percentage,perfectCount}`, `hours{totalWork,averageDaily,overtime}`.
- Engine status constants live on `App\Services\Attendance\DTO\DayAttendance` (`PRESENT, LATE, HALF_DAY, SHORT, ABSENT, ON_LEAVE, HOLIDAY, WEEKEND, DAY_OFF`).
- The shared loader already filters attendances `policy_status != 'rejected'` and leaves `status = 'approved'`; do not change those filters.
- These four tests MUST stay green: `tests/Feature/Attendance/MonthlyCalendarOtBucketsTest.php`, `MonthlyGridOffDayTest.php`, `MonthlyStatsShiftAwareTest.php`, `PunchExceptionApiTest.php`.
- The only allowed pre-existing failures in the whole suite are `MobileSyncApiTest > sync push applies leave apply mutation` and `NavigationRoutesTest > any authenticated user can access organization directory`. Add no new failure.
- Tests run on real machine clock; any stats test that depends on "today" MUST freeze time with `Carbon::setTestNow(...)` and reset it at the end.
- Run the suite with the project's PHP: `php artisan test --filter=<Class>`.

---

### Task 1: Shared engine helper + grid display mapper

Replace the grid's legacy hours-math and ad-hoc status branching with a single engine pass. Introduce `buildMonthlyDayResults()` and `mapStatusToDisplay()`; rewrite `getUserAttendanceData()` to consume them; delete `calculateTotalMinutes()`.

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php`
- Test: `tests/Feature/Attendance/MonthlyGridEngineCollapseTest.php` (create)

**Interfaces:**
- Consumes (existing): `AttendanceStatusService::resolve(Collection $punches, ShiftSchedule $shift, bool $isHoliday, bool $isOnLeave, ?CarbonInterface $now, ?PolicyProfile $policy): DayAttendance`; `ScheduleResolver::resolve(int $userId, CarbonInterface $date): ShiftSchedule`; `PolicyResolver::resolve(int $userId, CarbonInterface $date): PolicyProfile`.
- Produces (for Task 2):
  - `private buildMonthlyDayResults($user, int $year, int $month, $holidays, ?CarbonInterface $until, ScheduleResolver $resolver, PolicyResolver $policyResolver, AttendanceStatusService $statusEngine): array` — returns `array<string YYYY-MM-DD, array{result: DayAttendance, holiday: ?object, leave: ?object, schedule: ShiftSchedule, attendances: Collection, before_join: bool}>`. Days after `$until` (when non-null) are omitted.
  - `private classifyDay(array $ctx): string` — maps one day-result context entry to an effective `DayAttendance::*` status, applying holiday/off > leave precedence. Both the grid mapper and the stats aggregator consume it so they cannot diverge.
  - `private mapStatusToDisplay(string $effective, $holiday, $leave, $leaveTypes, Carbon $date, int $worked): array` — `[symbol, remarks]` for a given effective status.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/MonthlyGridEngineCollapseTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendancePolicy;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Holiday;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlyGridEngineCollapseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);

        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);
    }

    private function loadUser(int $id): User
    {
        return app(AttendanceReportService::class)
            ->getEmployeeUsersWithAttendanceAndLeaves(2026, 6)
            ->firstWhere('id', $id);
    }

    public function test_grid_total_work_hours_reflect_break_policy_and_match_buckets(): void
    {
        // Active org-wide breaks policy: 30-min unpaid meal once worked >= 360 min.
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 7001,
            'effective_from' => '2026-01-01',
            'rule_overrides' => ['breaks' => ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]],
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');

        // 2026-06-17 Wed, continuous 7h punch -> 420 raw, 390 after the 30-min auto-deduction.
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-17',
            'punchin' => '2026-06-17 09:00:00',
            'punchout' => '2026-06-17 16:00:00',
        ]);

        $data = app(AttendanceReportService::class)
            ->getUserAttendanceData($this->loadUser($user->id), 2026, 6, collect(), collect());

        $cell = $data['2026-06-17'];
        $this->assertSame('√', $cell['status']);
        $this->assertSame(390, $cell['worked_minutes']);
        $this->assertSame(30, $cell['break_deducted_minutes']);
        // The headline hours now come from the engine, not the raw span: 390 min = 06:30.
        $this->assertSame('06:30', $cell['total_work_hours']);
    }

    public function test_grid_holiday_beats_leave_on_no_punch_day(): void
    {
        $user = User::factory()->create();
        $user->assignRole('Employee');

        Holiday::create(['name' => 'Test Holiday', 'from_date' => '2026-06-15', 'to_date' => '2026-06-15']);
        $setting = LeaveSetting::factory()->create(['type' => 'Casual', 'symbol' => 'C']);
        Leave::factory()->for($user)->create([
            'leave_type' => $setting->id, 'status' => 'approved',
            'from_date' => '2026-06-15', 'to_date' => '2026-06-15',
        ]);

        $svc = app(AttendanceReportService::class);
        $data = $svc->getUserAttendanceData(
            $this->loadUser($user->id), 2026, 6, $svc->getHolidaysForMonth(2026, 6), LeaveSetting::all()
        );

        // No punch on a day that is both holiday and approved-leave -> Holiday wins.
        $this->assertSame('#', $data['2026-06-15']['status']);
        $this->assertSame('Holiday', $data['2026-06-15']['remarks']);
    }

    public function test_grid_leave_day_with_real_punch_stays_on_leave(): void
    {
        // A punch on an approved-leave day is a conflict for the (Phase B) exceptions
        // workflow to reconcile — NOT silently relabeled "Present" here. Capture is never
        // blocked; the day keeps the approved intent ("On Leave") and hides hours.
        $user = User::factory()->create();
        $user->assignRole('Employee');

        $setting = LeaveSetting::factory()->create(['type' => 'Casual', 'symbol' => 'C']);
        Leave::factory()->for($user)->create([
            'leave_type' => $setting->id, 'status' => 'approved',
            'from_date' => '2026-06-16', 'to_date' => '2026-06-16',
        ]);
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-16',
            'punchin' => '2026-06-16 09:00:00',
            'punchout' => '2026-06-16 17:00:00',
        ]);

        $svc = app(AttendanceReportService::class);
        $data = $svc->getUserAttendanceData(
            $this->loadUser($user->id), 2026, 6, $svc->getHolidaysForMonth(2026, 6), LeaveSetting::all()
        );

        // The loader aliases leaves.leave_type to the leave-type NAME, so firstWhere('id', ...)
        // misses and the symbol falls back to '/'. Remarks must be "On Leave"; hours hidden.
        $this->assertSame('/', $data['2026-06-16']['status']);
        $this->assertSame('On Leave', $data['2026-06-16']['remarks']);
        $this->assertSame('00:00', $data['2026-06-16']['total_work_hours']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MonthlyGridEngineCollapseTest`
Expected: 2 of 3 FAIL — `test_grid_total_work_hours_reflect_break_policy_and_match_buckets` asserts `06:30` but the legacy path returns the raw span `07:00`; `test_grid_holiday_beats_leave_on_no_punch_day` asserts `#`/"Holiday" but the legacy `elseif ($leave)` makes leave win ("On Leave"). The third, `test_grid_leave_day_with_real_punch_stays_on_leave`, is a **regression guard** for preserved behavior and already passes on the legacy code — keep it to lock the behavior through the refactor.

- [ ] **Step 3: Add the `DayAttendance` import**

In `app/Services/Attendance/AttendanceReportService.php`, add to the `use` block (after the existing `use App\Models\User;` line):

```php
use App\Services\Attendance\Contracts\PolicyResolver;
use App\Services\Attendance\Contracts\ScheduleResolver;
use App\Services\Attendance\DTO\DayAttendance;
use Carbon\CarbonInterface;
```

- [ ] **Step 4: Add the shared helper and display mapper**

In `app/Services/Attendance/AttendanceReportService.php`, add these two private methods (place them just above the existing `private function calculateTotalMinutes(...)`):

```php
/**
 * Resolve the engine result for every day of the month for one user.
 *
 * One AttendanceStatusService pass per day, fed the same schedule / holiday /
 * leave / policy the grid uses, so every surface derives from one source.
 * Pass $until (e.g. "today") to stop at a date for current-month stats; pass
 * null for the whole month (grid).
 *
 * @return array<string, array{result: DayAttendance, holiday: ?object, leave: ?object, schedule: \App\Services\Attendance\DTO\ShiftSchedule, attendances: \Illuminate\Support\Collection, before_join: bool}>
 */
private function buildMonthlyDayResults(
    $user,
    int $year,
    int $month,
    $holidays,
    ?CarbonInterface $until,
    ScheduleResolver $resolver,
    PolicyResolver $policyResolver,
    AttendanceStatusService $statusEngine
): array {
    $daysInMonth = Carbon::create($year, $month)->daysInMonth;
    $joinDate = $user->date_of_joining ? Carbon::parse($user->date_of_joining)->startOfDay() : null;

    $results = [];
    for ($day = 1; $day <= $daysInMonth; $day++) {
        $date = Carbon::create($year, $month, $day);
        if ($until !== null && $date->copy()->startOfDay()->greaterThan($until)) {
            continue;
        }
        $dateString = $date->toDateString();

        $schedule = $resolver->resolve($user->id, $date);

        $attendancesForDate = $user->attendances
            ->filter(fn ($a) => Carbon::parse($a->date)->isSameDay($date))
            ->sortBy('punchin')
            ->values();

        $holiday = $holidays->first(fn ($h) => $date->between(
            Carbon::parse($h->from_date)->startOfDay(),
            Carbon::parse($h->to_date)->endOfDay()
        ));
        $leave = $user->leaves->first(fn ($l) => $date->between(
            Carbon::parse($l->from_date)->startOfDay(),
            Carbon::parse($l->to_date)->endOfDay()
        ));

        $policy = $attendancesForDate->isNotEmpty()
            ? $policyResolver->resolve($user->id, $date)
            : null;

        $result = $statusEngine->resolve(
            $attendancesForDate,
            $schedule,
            isHoliday: (bool) $holiday,
            isOnLeave: (bool) $leave,
            policy: $policy,
        );

        $results[$dateString] = [
            'result' => $result,
            'holiday' => $holiday,
            'leave' => $leave,
            'schedule' => $schedule,
            'attendances' => $attendancesForDate,
            'before_join' => $joinDate !== null && $date->copy()->startOfDay()->lessThan($joinDate),
        ];
    }

    return $results;
}

/**
 * Decide what a day MEANS (effective status) so the grid and the dashboard
 * can't diverge — both consume this one classifier. Standard precedence:
 * holiday and weekly-off outrank leave (leave isn't consumed on a non-working
 * day); leave only paints a working day. A punch on an approved-leave day is a
 * conflict left "On Leave" for the Phase B exceptions workflow to reconcile —
 * it is NOT silently relabeled present here.
 *
 * @return string a DayAttendance::* constant
 */
private function classifyDay(array $ctx): string
{
    /** @var DayAttendance $result */
    $result = $ctx['result'];
    $hasPunch = $ctx['attendances']->isNotEmpty();

    if ($ctx['holiday']) {
        // Worked on a holiday -> present-day ("Present on Holiday"); otherwise Holiday.
        return $hasPunch ? DayAttendance::PRESENT : DayAttendance::HOLIDAY;
    }

    if (! $ctx['schedule']->isWorkingDay) {
        // Off/weekend: engine already returns PRESENT if punched (off-day work) else WEEKEND.
        return $result->status;
    }

    if ($ctx['leave']) {
        return DayAttendance::ON_LEAVE;
    }

    return $result->status; // PRESENT / LATE / HALF_DAY / SHORT / ABSENT
}

/**
 * Map an effective status (+ display context) to the grid's [symbol, remarks].
 *
 * @return array{0: string, 1: string}
 */
private function mapStatusToDisplay(string $effective, $holiday, $leave, $leaveTypes, Carbon $date, int $worked): array
{
    $isToday = now()->toDateString() === $date->toDateString();

    switch ($effective) {
        case DayAttendance::HOLIDAY:
            return ['#', 'Holiday'];

        case DayAttendance::ON_LEAVE:
            $symbol = $leave
                ? ($leaveTypes->firstWhere('id', $leave->leave_type)->symbol ?? '/')
                : '/';

            return [$symbol, 'On Leave'];

        case DayAttendance::WEEKEND:
        case DayAttendance::DAY_OFF:
            return ['▽', 'Day Off'];

        case DayAttendance::ABSENT:
            return ['▼', 'Absent'];

        default: // worked-day statuses: present / late / half_day / short
            if ($worked > 0) {
                $remarks = $holiday ? 'Present on Holiday' : 'Present';
            } else {
                $remarks = $isToday ? 'Currently Working' : 'Not Punched Out';
            }

            return ['√', $remarks];
    }
}
```

- [ ] **Step 5: Rewrite `getUserAttendanceData` to consume the helper**

Replace the entire body of `getUserAttendanceData` (the method from `public function getUserAttendanceData($user, int $year, int $month, $holidays, $leaveTypes): array` through its closing brace) with:

```php
public function getUserAttendanceData($user, int $year, int $month, $holidays, $leaveTypes): array
{
    $attendanceData = [
        'user_id' => $user->id,
        'employee_id' => $user->employee_id,
        'name' => $user->name,
        'profile_image_url' => $user->profile_image_url,
    ];

    $resolver = app(ScheduleResolver::class);
    $policyResolver = app(PolicyResolver::class);
    $statusEngine = app(AttendanceStatusService::class);

    $dayResults = $this->buildMonthlyDayResults(
        $user, $year, $month, $holidays, null, $resolver, $policyResolver, $statusEngine
    );

    foreach ($dayResults as $dateString => $ctx) {
        /** @var DayAttendance $result */
        $result = $ctx['result'];
        $attendancesForDate = $ctx['attendances'];
        $date = Carbon::parse($dateString);
        $worked = $result->worked_minutes;

        $effective = $this->classifyDay($ctx);

        $isWorkedDay = in_array($effective, [
            DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT,
        ], true);

        $punchIn = null;
        $punchOut = null;
        $totalWorkHours = '00:00';

        if ($isWorkedDay && $attendancesForDate->isNotEmpty()) {
            $first = $attendancesForDate->first();
            $last = $attendancesForDate->count() === 1
                ? $first
                : $attendancesForDate->reverse()->first(fn ($item) => $item->punchout !== null);
            $punchIn = $first->punchin;
            $punchOut = $last ? $last->punchout : null;
            $totalWorkHours = sprintf('%02d:%02d', intdiv($worked, 60), $worked % 60);
        }

        [$symbol, $remarks] = $this->mapStatusToDisplay(
            $effective, $ctx['holiday'], $ctx['leave'], $leaveTypes, $date, $worked
        );

        $attendanceData[$dateString] = [
            'status' => $symbol,
            'punch_in' => $punchIn,
            'punch_out' => $punchOut,
            'total_work_hours' => $totalWorkHours,
            'remarks' => $remarks,
            'ot_minutes' => $result->ot_minutes,
            'worked_minutes' => $result->worked_minutes,
            'double_time_minutes' => $result->double_time_minutes,
            'regular_minutes' => $result->regular_minutes,
            'break_deducted_minutes' => $result->break_deducted_minutes,
            'policy_events' => $result->policy_events,
        ];
    }

    return $attendanceData;
}
```

- [ ] **Step 6: Delete the obsolete `calculateTotalMinutes` method**

In `app/Services/Attendance/AttendanceReportService.php`, delete the entire `private function calculateTotalMinutes(Collection $attendancesForDate): int { ... }` method (it is no longer referenced).

- [ ] **Step 7: Run the new test to verify it passes**

Run: `php artisan test --filter=MonthlyGridEngineCollapseTest`
Expected: PASS (3 tests).

- [ ] **Step 8: Run the existing grid tests to verify no regression**

Run: `php artisan test --filter="MonthlyGridOffDayTest|MonthlyCalendarOtBucketsTest"`
Expected: PASS. (Off-day still `▽`/"Day Off"; OT-day still `√` with `worked_minutes=630, regular=480, ot=150`; break-day still `break_deducted=30, worked=390`.)

- [ ] **Step 9: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/MonthlyGridEngineCollapseTest.php
git commit -m "refactor(attendance): grid derives hours/status/remarks from the engine (#3)

getUserAttendanceData now maps a single AttendanceStatusService pass per
employee-day (via buildMonthlyDayResults) instead of legacy calculateTotalMinutes()
+ ad-hoc branching. total_work_hours now reflects policy break/rounding and matches
the OT/break buckets; holiday beats leave on no-punch days; a leave day with real
punches shows Present. JSON cell shape unchanged.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: Dashboard stats aggregator on the engine

Rewrite `calculateMonthlyStats` to count engine statuses per employee-day via the shared helper (replacing the `daysPassed*2/7` absent estimate and the any-punch present count), respecting `date_of_joining` and the analysis window. Extend the loader with an optional single-user filter.

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php`
- Test: `tests/Feature/Attendance/MonthlyStatsEngineReconcileTest.php` (create)

**Interfaces:**
- Consumes (from Task 1): `buildMonthlyDayResults($user, int $year, int $month, $holidays, ?CarbonInterface $until, ScheduleResolver $resolver, PolicyResolver $policyResolver, AttendanceStatusService $statusEngine): array`.
- Produces: `getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null, ?int $userId = null): Collection` — when `$userId` is set, returns just that user (role filter bypassed) with the same eager-loaded relations.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Attendance/MonthlyStatsEngineReconcileTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlyStatsEngineReconcileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_absent_man_days_are_exact_per_roster_not_estimated(): void
    {
        // Freeze "today" at 2026-06-03 so only 06-01..06-03 are in the analysis window.
        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00'));

        // Monday is the weekly off. 2026-06-01 is a Monday => off; 06-02 Tue, 06-03 Wed work.
        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['monday'], 'auto_punch_out' => false,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');

        // Present on 06-02 only; 06-03 is a working day with no punch -> exactly 1 absent.
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-02', 'punchin' => '2026-06-02 09:00:00', 'punchout' => '2026-06-02 17:00:00',
        ]);

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $user->id);

        $this->assertSame(1, $stats['attendance']['present']);
        // Old daysPassed*2/7 estimate would report 2 here; the engine reports the exact 1.
        $this->assertSame(1, $stats['attendance']['absent']);
        $this->assertSame(0, $stats['attendance']['leaves']);
        $this->assertSame(50.0, $stats['attendance']['percentage']);
    }

    public function test_absent_excludes_days_before_joining(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-03 12:00:00'));

        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 15,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => [], 'auto_punch_out' => false,
        ]);

        // Joined 06-03: 06-01 and 06-02 must not accrue absents; only 06-03 (no punch) does.
        $user = User::factory()->create(['date_of_joining' => '2026-06-03']);
        $user->assignRole('Employee');

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $user->id);

        $this->assertSame(0, $stats['attendance']['present']);
        $this->assertSame(1, $stats['attendance']['absent']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=MonthlyStatsEngineReconcileTest`
Expected: FAIL — the legacy `daysPassed*2/7` path reports `absent=2` for test 1, and ignores `date_of_joining` so test 2 reports `absent=3`.

- [ ] **Step 3: Add the optional single-user filter to the loader**

In `app/Services/Attendance/AttendanceReportService.php`, replace the `getEmployeeUsersWithAttendanceAndLeaves` method signature and its query-building head. Change the signature line:

```php
    public function getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null): Collection
    {
        $query = User::role('Employee')
            ->select('users.*')
            ->leftJoin('designations', 'users.designation_id', '=', 'designations.id');

        if ($departmentId) {
            $query->where('users.department_id', $departmentId);
        }
```

to:

```php
    public function getEmployeeUsersWithAttendanceAndLeaves(int $year, int $month, ?int $departmentId = null, ?int $userId = null): Collection
    {
        $query = User::query()
            ->select('users.*')
            ->leftJoin('designations', 'users.designation_id', '=', 'designations.id');

        if ($userId !== null) {
            $query->where('users.id', $userId);
        } else {
            $query->role('Employee');

            if ($departmentId) {
                $query->where('users.department_id', $departmentId);
            }
        }
```

(Leave the rest of the method — the `orderByRaw(...)->orderBy(...)->with([...])->get()` chain — unchanged.)

- [ ] **Step 4: Rewrite `calculateMonthlyStats`**

Replace the entire body of `calculateMonthlyStats` (from `public function calculateMonthlyStats(` through its closing brace) with:

```php
public function calculateMonthlyStats(
    int $currentMonth,
    int $currentYear,
    bool $isGlobalScope,
    ?int $userId
): array {
    $resolver = app(ScheduleResolver::class);
    $policyResolver = app(PolicyResolver::class);
    $statusEngine = app(AttendanceStatusService::class);

    $settings = AttendanceSetting::first();
    $weekendDays = $settings->weekend_days ?? ['saturday', 'sunday'];

    $startOfMonth = Carbon::create($currentYear, $currentMonth, 1)->startOfDay();
    $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
    $analysisEndDate = $endOfMonth->isFuture() ? Carbon::now()->endOfDay() : $endOfMonth;

    // Calendar-level meta (describes the month, not per-employee man-days).
    $totalDaysInMonth = $startOfMonth->daysInMonth;
    $holidaysCount = $this->getTotalHolidayDays($currentYear, $currentMonth);
    $weekendCount = $this->getWeekendDaysCount($currentYear, $currentMonth, $weekendDays);
    $calendarWorkingDays = max(0, $totalDaysInMonth - $holidaysCount - $weekendCount);

    $holidays = $this->getHolidaysForMonth($currentYear, $currentMonth);

    $users = $this->getEmployeeUsersWithAttendanceAndLeaves(
        $currentYear, $currentMonth, null, $isGlobalScope ? null : $userId
    );

    $totalEmployees = $users->count();

    $present = 0;
    $absent = 0;
    $leaveDays = 0;
    $lateArrivals = 0;
    $workMinutes = 0;
    $otMinutes = 0;
    $potentialManDays = 0;
    $perfectCount = 0;

    $workedStatuses = [
        DayAttendance::PRESENT, DayAttendance::LATE, DayAttendance::HALF_DAY, DayAttendance::SHORT,
    ];

    foreach ($users as $user) {
        $dayResults = $this->buildMonthlyDayResults(
            $user, $currentYear, $currentMonth, $holidays, $analysisEndDate,
            $resolver, $policyResolver, $statusEngine
        );

        $userPresent = 0;
        $userWorkingDays = 0;

        foreach ($dayResults as $ctx) {
            if ($ctx['before_join']) {
                continue;
            }

            /** @var DayAttendance $result */
            $result = $ctx['result'];
            $effective = $this->classifyDay($ctx);

            if (in_array($effective, $workedStatuses, true)) {
                $present++;
                $userPresent++;
                // Hours/late only accrue on worked-effective days (a punch on a leave
                // day is effective ON_LEAVE and contributes nothing, matching the grid).
                $workMinutes += $result->worked_minutes;
                $otMinutes += $result->ot_minutes;
                if ($result->late_minutes > 0) {
                    $lateArrivals++;
                }
            } elseif ($effective === DayAttendance::ABSENT) {
                $absent++;
            } elseif ($effective === DayAttendance::ON_LEAVE) {
                $leaveDays++;
            }

            // A day this employee was actually scheduled to work (excludes off-days & holidays).
            if ($ctx['schedule']->isWorkingDay && ! $ctx['holiday']) {
                $userWorkingDays++;
                $potentialManDays++;
            }
        }

        if ($userWorkingDays > 0 && $userPresent >= $userWorkingDays) {
            $perfectCount++;
        }
    }

    $attendancePercentage = $potentialManDays > 0
        ? round(($present / $potentialManDays) * 100, 1)
        : 0;

    $averageWorkHours = $present > 0
        ? round(($workMinutes / 60) / $present, 1)
        : 0;

    return [
        'meta' => [
            'month' => $startOfMonth->format('F Y'),
            'scope' => $isGlobalScope ? 'Global' : 'Single',
            'totalEmployees' => (int) $totalEmployees,
            'workingDays' => (int) $calendarWorkingDays,
            'holidays' => (int) $holidaysCount,
            'weekends' => (int) $weekendCount,
        ],
        'attendance' => [
            'present' => (int) $present,
            'absent' => (int) $absent,
            'leaves' => (int) $leaveDays,
            'lateArrivals' => (int) $lateArrivals,
            'percentage' => $attendancePercentage,
            'perfectCount' => (int) $perfectCount,
        ],
        'hours' => [
            'totalWork' => round($workMinutes / 60, 1),
            'averageDaily' => $averageWorkHours,
            'overtime' => round($otMinutes / 60, 1),
        ],
    ];
}
```

- [ ] **Step 5: Run the new test to verify it passes**

Run: `php artisan test --filter=MonthlyStatsEngineReconcileTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Run the existing stats tests to verify no regression**

Run: `php artisan test --filter="MonthlyStatsShiftAwareTest|PunchExceptionApiTest"`
Expected: PASS. (`lateArrivals=0` for the 10:00 punch vs 11:00 shift; `present=1` and `totalWork=8.0` for the accepted-only day.)

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/MonthlyStatsEngineReconcileTest.php
git commit -m "refactor(attendance): dashboard stats count engine statuses, not estimates (#4)

calculateMonthlyStats now derives present/absent/leave man-days from a per-
employee-per-day AttendanceStatusService pass (shared buildMonthlyDayResults)
with holiday/leave/policy, replacing the daysPassed*2/7 weekend estimate and the
any-punch present count. Honors per-employee rosters/off-days and date_of_joining;
percentage/perfectCount are now per-employee. Response shape unchanged.

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Full-suite + live verification

Confirm the whole attendance suite is green (only the two allowed pre-existing failures) and the live endpoints return 200 with reconciled numbers.

**Files:** none (verification only).

- [ ] **Step 1: Run the full attendance suite**

Run: `php artisan test --filter=Attendance`
Expected: PASS except the two documented pre-existing failures (`MobileSyncApiTest > sync push applies leave apply mutation`, `NavigationRoutesTest > any authenticated user can access organization directory`) — and `NavigationRoutesTest` is not in the Attendance filter, so effectively only `MobileSyncApiTest`'s one case may fail if swept in. No NEW failures.

- [ ] **Step 2: Run the broader suite to confirm nothing else broke**

Run: `php artisan test`
Expected: Only the two known pre-existing failures listed above; everything else green.

- [ ] **Step 3: Verify live endpoints by HTTP status**

Ensure `npm run dev` is running (background). With the ms-playwright-mcp Chrome (kill + reopen the process if the profile is locked), log in as `emam@dhakabypass.com` / `123456789` at `https://aero-enterprise-suite.test`, then via Playwright `browser_evaluate` fetch both endpoints for the current month and assert HTTP 200 + that the grid totals reconcile with the dashboard:

```js
// run in browser_evaluate after login
const m = new Date().getMonth() + 1, y = new Date().getFullYear();
const grid = await fetch(`/attendances-admin-paginate?currentMonth=${m}&currentYear=${y}&perPage=200`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
const stats = await fetch(`/attendance/monthly-stats?currentMonth=${m}&currentYear=${y}`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
return { gridStatus: grid.status, statsStatus: stats.status, stats: (await stats.json()).stats?.attendance };
```

Expected: `gridStatus === 200`, `statsStatus === 200`, and a populated `attendance` block (present/absent/leaves/lateArrivals integers). Do NOT create or leave test data on prod; this is read-only verification. (Confirm the exact endpoint paths against `routes/web.php` if either returns 404 — use the route names `attendancesAdmin.paginate` and the monthly-stats route.)

- [ ] **Step 4: Update the roadmap**

In `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`, move #3 and #4 from "Phase A" into the "✅ Already fixed (this work)" section with a one-line note that the grid and dashboard now both derive from `AttendanceStatusService` via `buildMonthlyDayResults`.

- [ ] **Step 5: Commit**

```bash
git add docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md
git commit -m "docs(attendance): mark Phase A #3/#4 (single-engine collapse) done

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage:**
- Shared single-pass helper → Task 1 (`buildMonthlyDayResults`). ✓
- Grid derives hours/status/remarks from engine, deletes `calculateTotalMinutes` → Task 1. ✓
- Dashboard counts engine statuses; absent exact; respects `date_of_joining`/analysis window; per-employee potential/percentage/perfect → Task 2. ✓
- Same loader/holiday/leave/policy as grid + one shared `classifyDay` classifier → both call `getEmployeeUsersWithAttendanceAndLeaves` + `buildMonthlyDayResults` + `classifyDay`. ✓
- 3 intentional behavior changes (policy-aware hours, holiday/off > leave precedence, exact per-employee absents) → covered by Task 1 + Task 2 tests. Leave-with-punch is **preserved** ("On Leave", not silently flipped) with a regression-guard test. ✓
- Backward-compatible JSON shapes → asserted by existing OtBuckets/OffDay/ShiftAware/PunchException tests kept green (Task 1 Step 8, Task 2 Step 6). ✓
- Out of scope (payroll, leave day-count, half-day, termination, worked-on-leave reconciliation) → not touched. ✓

**Placeholder scan:** No TBD/TODO; all code blocks complete; live-verification JS is concrete.

**Type consistency:** `buildMonthlyDayResults` signature identical in Task 1 (Produces) and Task 2 (Consumes). `classifyDay(array $ctx): string` defined in Task 1 Step 4, consumed in Task 1 Step 5 (grid) and Task 2 Step 4 (stats). `mapStatusToDisplay(string $effective, ...)` returns `[symbol, remarks]` consumed via list-destructuring in Task 1 Step 5. `getEmployeeUsersWithAttendanceAndLeaves` 4-arg form defined in Task 2 Step 3 and called in Task 2 Step 4. `DayAttendance` constants used consistently. Loader returns `Collection`; `->firstWhere('id', ...)`/`->count()`/`foreach` all valid on it.

**Note on `date_of_joining`:** confirmed a real `User` fillable column (`app/Models/User.php`); test 2 sets it via mass-assignment.
