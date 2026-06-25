# Attendance Phase 1 — Holiday Correctness + Engine Precedence + Data Integrity

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make holidays honor `is_active` and recurrence, make the engine rank weekly-off above leave, and enforce `employee_id` uniqueness — the quick, safe correctness wins from the 10/10 design.

**Architecture:** A single `HolidayService::forRange(from,to)` becomes the one source of holiday occurrences (active-only, with `annual_fixed` recurrence expanded); the three ad-hoc holiday queries in `AttendanceReportService` delegate to it. The pure engine's no-punch precedence is reordered so a rest day is never consumed by leave. A migration resolves duplicate/NULL `employee_id`s and adds a unique index.

**Tech Stack:** Laravel 11, PHP 8.x, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit class-style, Carbon.

## Global Constraints

- Single-engine rule: holidays/weekly-off/leave reach numbers only through `AttendanceStatusService` + `AttendanceReportService::buildMonthlyDayResults`/`classifyDay`. No second path.
- Holiday recurrence supported: `none` (one-off) and `annual_fixed` (same Gregorian month-day each year). Lunar/Islamic holidays are one-off rows HR enters per year — never auto-expanded. `is_active=false` holidays are excluded everywhere.
- Engine no-punch precedence MUST be `holiday > weekly-off(WEEKEND) > leave(ON_LEAVE) > absent`.
- Tests: PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase`. Add NO new failing test. Only allowed pre-existing failures: `MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > any authenticated user can access organization directory`.
- New migrations: `php artisan migrate` on MySQL `dbedc_guardian` (tests use sqlite); note for prod deploy. No `npm run build`; this phase is backend-only (no asset rebuild).
- Backward compatibility: holiday occurrences returned by `HolidayService` must expose `from_date` and `to_date` (Carbon-castable) and `title`/`type`, because consumers read `$h->from_date`/`$h->to_date`.

---

### Task 1: Engine precedence — weekly-off outranks leave (+ worked-on-leave flag)

**Files:**
- Modify: `app/Services/Attendance/AttendanceStatusService.php` (no-punch match ~lines 63-70; add worked-on-leave flag in the has-punches path)
- Test: `tests/Feature/Attendance/EnginePrecedenceTest.php` (create)

**Interfaces:**
- Consumes: `ShiftSchedule` (`isWorkingDay`), `DayAttendance` constants.
- Produces: no signature change. Behavior: no-punch off-day + leave → `WEEKEND` (not `ON_LEAVE`); a punch on a working leave day adds flag `'worked_on_leave'` (status still resolves via downstream `classifyDay`).

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Services\Attendance\AttendanceStatusService;
use App\Services\Attendance\DTO\DayAttendance;
use App\Services\Attendance\DTO\ShiftSchedule;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class EnginePrecedenceTest extends TestCase
{
    private function offDay(): ShiftSchedule
    {
        return ShiftSchedule::nonWorking(Carbon::create(2026, 6, 13)); // a Saturday
    }

    public function test_leave_on_a_rest_day_resolves_to_weekend_not_leave(): void
    {
        $result = app(AttendanceStatusService::class)->resolve(
            new Collection(), // no punches
            $this->offDay(),
            isHoliday: false,
            isOnLeave: true,
        );

        $this->assertSame(DayAttendance::WEEKEND, $result->status);
    }

    public function test_holiday_still_outranks_everything_on_a_rest_day(): void
    {
        $result = app(AttendanceStatusService::class)->resolve(
            new Collection(),
            $this->offDay(),
            isHoliday: true,
            isOnLeave: true,
        );

        $this->assertSame(DayAttendance::HOLIDAY, $result->status);
    }

    public function test_leave_on_a_working_day_with_no_punch_still_on_leave(): void
    {
        $working = new ShiftSchedule(
            start: Carbon::create(2026, 6, 10, 9), end: Carbon::create(2026, 6, 10, 17),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0,
            fullDayMinutes: 420, halfDayMinutes: 210, minPresentMinutes: 60,
            breakMinutes: 60, isWorkingDay: true,
        );

        $result = app(AttendanceStatusService::class)->resolve(
            new Collection(), $working, isHoliday: false, isOnLeave: true,
        );

        $this->assertSame(DayAttendance::ON_LEAVE, $result->status);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=EnginePrecedenceTest`
Expected: `test_leave_on_a_rest_day_resolves_to_weekend_not_leave` FAILS (currently returns ON_LEAVE). The other two should already pass.

- [ ] **Step 3: Reorder the no-punch match**

In `app/Services/Attendance/AttendanceStatusService.php`, change the no-punch block comment and match so weekly-off precedes leave:

```php
        // No punches: holiday > weekly-off > leave > absent.
        // A rest day (weekly-off/holiday) is never consumed by leave.
        if (! $hasPunches) {
            $status = match (true) {
                $isHoliday => DayAttendance::HOLIDAY,
                ! $shift->isWorkingDay => DayAttendance::WEEKEND,
                $isOnLeave => DayAttendance::ON_LEAVE,
                default => DayAttendance::ABSENT,
            };
```

- [ ] **Step 4: Add the worked-on-leave conflict flag**

In the same file, in the has-punches path, after `$hasPunches = $sorted->isNotEmpty();` is established and before the final `return` (place it alongside the other flag pushes, e.g. just after the out-of-schedule flag block ~line 152), add:

```php
        // Punch on an approved-leave working day: surface a conflict for the approver
        // (do NOT silently relabel — classifyDay keeps the day On Leave).
        if ($isOnLeave && $shift->isWorkingDay) {
            $flags[] = 'worked_on_leave';
        }
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=EnginePrecedenceTest`
Expected: PASS (all three).

- [ ] **Step 6: Run engine + report regression**

Run: `php artisan test --filter="MonthlyStatsEngineReconcileTest|MonthlyGridEngineCollapseTest|LeaveStatusGridTest|PerEmployeeSummaryTest"`
Expected: PASS (no new failures). If `LeaveStatusGridTest` seeds a leave on a weekend and asserts ON_LEAVE there, update that assertion to WEEKEND (the new, correct precedence) — read the test first and adjust only such cases.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/AttendanceStatusService.php tests/Feature/Attendance/EnginePrecedenceTest.php
git commit -m "fix(attendance): weekly-off outranks leave in the engine; flag worked-on-leave

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: `HolidayService::forRange` — active-only + annual_fixed recurrence

**Files:**
- Create: `app/Services/Attendance/HolidayService.php`
- Test: `tests/Feature/Attendance/HolidayServiceTest.php` (create)

**Interfaces:**
- Consumes: `App\Models\HRM\Holiday` (`is_active`, `is_recurring`, `recurrence_pattern`, `from_date`, `to_date`).
- Produces: `HolidayService::forRange(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): \Illuminate\Support\Collection` — a collection of `Holiday` occurrences (active only) whose `from_date`/`to_date` fall within (or are shifted into) `[from,to]`. Recurring (`is_recurring=true` or `recurrence_pattern='annual_fixed'`) holidays are cloned once per overlapping year with their `from_date`/`to_date` shifted to that year (same month-day). Task 3 consumes this.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Services\Attendance\HolidayService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_holiday_is_excluded(): void
    {
        Holiday::create(['title'=>'Inactive','from_date'=>'2026-06-10','to_date'=>'2026-06-10','type'=>'company','is_active'=>false,'is_recurring'=>false]);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,6,1), Carbon::create(2026,6,30));
        $this->assertCount(0, $out);
    }

    public function test_one_off_active_holiday_in_range_is_included(): void
    {
        Holiday::create(['title'=>'Company Day','from_date'=>'2026-06-10','to_date'=>'2026-06-10','type'=>'company','is_active'=>true,'is_recurring'=>false]);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,6,1), Carbon::create(2026,6,30));
        $this->assertCount(1, $out);
        $this->assertSame('2026-06-10', Carbon::parse($out->first()->from_date)->toDateString());
    }

    public function test_annual_fixed_recurring_holiday_recurs_in_a_later_year(): void
    {
        // Declared in 2024; must appear on the same month-day in 2026.
        Holiday::create(['title'=>'Independence Day','from_date'=>'2024-03-26','to_date'=>'2024-03-26','type'=>'national','is_active'=>true,'is_recurring'=>true,'recurrence_pattern'=>'annual_fixed']);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,3,1), Carbon::create(2026,3,31));
        $this->assertCount(1, $out);
        $this->assertSame('2026-03-26', Carbon::parse($out->first()->from_date)->toDateString());
    }

    public function test_non_recurring_past_holiday_does_not_recur(): void
    {
        Holiday::create(['title'=>'Eid (lunar, one-off)','from_date'=>'2024-04-10','to_date'=>'2024-04-10','type'=>'religious','is_active'=>true,'is_recurring'=>false]);
        $out = app(HolidayService::class)->forRange(Carbon::create(2026,4,1), Carbon::create(2026,4,30));
        $this->assertCount(0, $out);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=HolidayServiceTest`
Expected: FAIL — `App\Services\Attendance\HolidayService` does not exist.

- [ ] **Step 3: Implement the service**

Create `app/Services/Attendance/HolidayService.php`:

```php
<?php

namespace App\Services\Attendance;

use App\Models\HRM\Holiday;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of holiday occurrences for attendance.
 *
 * Honors is_active everywhere and expands annual_fixed recurrence (same
 * Gregorian month-day each year). Lunar/announced holidays are one-off rows
 * HR enters per year and are never auto-expanded.
 */
class HolidayService
{
    public function forRange(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $active = Holiday::query()->where('is_active', true)->get();

        $occurrences = collect();

        foreach ($active as $holiday) {
            $isRecurring = $holiday->is_recurring || $holiday->recurrence_pattern === 'annual_fixed';

            if (! $isRecurring) {
                $hFrom = Carbon::parse($holiday->from_date)->startOfDay();
                $hTo = Carbon::parse($holiday->to_date)->endOfDay();
                if ($hFrom->lte($to) && $hTo->gte($from)) {
                    $occurrences->push($holiday);
                }

                continue;
            }

            // annual_fixed: project the holiday's month-day into each year spanned by [from,to].
            $baseFrom = Carbon::parse($holiday->from_date);
            $baseTo = Carbon::parse($holiday->to_date);
            $spanDays = $baseFrom->copy()->startOfDay()->diffInDays($baseTo->copy()->startOfDay());

            for ($year = $from->year; $year <= $to->year; $year++) {
                $occFrom = Carbon::create($year, $baseFrom->month, $baseFrom->day)->startOfDay();
                $occTo = $occFrom->copy()->addDays($spanDays)->endOfDay();
                if ($occFrom->lte($to) && $occTo->gte($from)) {
                    $clone = $holiday->replicate();
                    $clone->id = $holiday->id; // keep identity for callers that read it
                    $clone->from_date = $occFrom->toDateString();
                    $clone->to_date = $occTo->toDateString();
                    $occurrences->push($clone);
                }
            }
        }

        return $occurrences->values();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=HolidayServiceTest`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/HolidayService.php tests/Feature/Attendance/HolidayServiceTest.php
git commit -m "feat(attendance): HolidayService::forRange (active-only + annual_fixed recurrence)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Delegate holiday queries to `HolidayService`; persist `recurrence_pattern`

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`getHolidaysForMonth`, `getTotalHolidayDays`, `getWeekendDaysCount`)
- Modify: `app/Http/Controllers/HolidayController.php` (`create` — persist `recurrence_pattern` when recurring; validate it)
- Test: `tests/Feature/Attendance/HolidayIntegrationTest.php` (create)

**Interfaces:**
- Consumes: `HolidayService::forRange` (Task 2).
- Produces: the three report methods now exclude inactive holidays and include recurring occurrences; `getTotalHolidayDays`/`getWeekendDaysCount` count within `[startOfMonth, endOfRange]` using the service's occurrences. No signature changes.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Holiday;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HolidayIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_inactive_holiday_not_counted_in_month_total(): void
    {
        Holiday::create(['title'=>'Inactive','from_date'=>'2026-06-10','to_date'=>'2026-06-10','type'=>'company','is_active'=>false,'is_recurring'=>false]);
        $svc = app(AttendanceReportService::class);
        $this->assertSame(0, $svc->getTotalHolidayDays(2026, 6));
        $this->assertCount(0, $svc->getHolidaysForMonth(2026, 6));
    }

    public function test_annual_fixed_holiday_counted_in_later_year_month(): void
    {
        Holiday::create(['title'=>'Independence Day','from_date'=>'2024-03-26','to_date'=>'2024-03-26','type'=>'national','is_active'=>true,'is_recurring'=>true,'recurrence_pattern'=>'annual_fixed']);
        $svc = app(AttendanceReportService::class);
        $this->assertSame(1, $svc->getTotalHolidayDays(2026, 3));
        $this->assertCount(1, $svc->getHolidaysForMonth(2026, 3));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=HolidayIntegrationTest`
Expected: FAIL — inactive holiday currently counted; the 2024 holiday not found in 2026.

- [ ] **Step 3: Rewrite the three methods to delegate**

In `app/Services/Attendance/AttendanceReportService.php`, add `use App\Services\Attendance\HolidayService;` at the top, then:

Replace `getHolidaysForMonth`:

```php
    public function getHolidaysForMonth(int $year, int $month): Collection
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        return app(HolidayService::class)->forRange($start, $end);
    }
```

Replace the holiday fetch inside `getTotalHolidayDays` and `getWeekendDaysCount` so they use the service. For `getTotalHolidayDays`, replace the `$holidays = Holiday::where(...)->get();` block with:

```php
        $startOfMonth = Carbon::create($year, $month, 1)->startOfDay();
        $endOfMonth = $startOfMonth->copy()->endOfMonth()->endOfDay();
        $holidays = app(HolidayService::class)->forRange($startOfMonth, $endOfMonth);
```

(keep the existing per-holiday day-summing logic below it; it reads `$holiday->from_date`/`$holiday->to_date`, which the occurrences provide).

For `getWeekendDaysCount`, replace the `$holidayRanges = Holiday::where(...)->get()->map(...)` source with the service occurrences:

```php
        $startOfMonth = Carbon::create($year, $month, 1);
        $endOfRange = Carbon::now()->year == $year && Carbon::now()->month == $month
            ? Carbon::today()
            : (clone $startOfMonth)->endOfMonth();

        $holidayRanges = app(HolidayService::class)
            ->forRange($startOfMonth->copy()->startOfDay(), (clone $startOfMonth)->endOfMonth()->endOfDay())
            ->map(function ($holiday) use ($startOfMonth, $endOfRange) {
                $h = Carbon::parse($holiday->from_date);
                $t = Carbon::parse($holiday->to_date);

                return [
                    'start' => $h->greaterThan($startOfMonth) ? $h : $startOfMonth,
                    'end' => $t->lessThan($endOfRange) ? $t : $endOfRange,
                ];
            });
```

(keep the existing weekend-counting loop below unchanged).

- [ ] **Step 4: Persist `recurrence_pattern` in HolidayController**

In `app/Http/Controllers/HolidayController.php::create`, add validation `'recurrence_pattern' => 'nullable|in:annual_fixed'` to the validator rules, and in the `$data` array set:

```php
                'recurrence_pattern' => $request->boolean('is_recurring', false) ? 'annual_fixed' : null,
```

(so a holiday marked recurring is stored as `annual_fixed`, matching the service).

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=HolidayIntegrationTest`
Expected: PASS (both).

- [ ] **Step 6: Regression — engine surfaces still reconcile**

Run: `php artisan test --filter="MonthlyStatsEngineReconcileTest|MonthlyStatsShiftAwareTest|MonthlyGridEngineCollapseTest|PerEmployeeSummaryTest|AttendanceExportAndStatsTest"`
Expected: PASS (no new failures).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php app/Http/Controllers/HolidayController.php tests/Feature/Attendance/HolidayIntegrationTest.php
git commit -m "fix(attendance): holidays honor is_active + annual_fixed recurrence via HolidayService

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: `employee_id` uniqueness — resolve conflicts + unique index

**Files:**
- Create: `database/migrations/2026_06_23_000001_enforce_unique_employee_id.php`
- Test: `tests/Feature/Attendance/EmployeeIdUniquenessTest.php` (create)

**Interfaces:**
- Consumes: `users` table (`employee_id` nullable string/int).
- Produces: every Employee has a non-empty unique `employee_id`; a unique index prevents recurrence. Conflicting/NULL rows get a deterministic placeholder `EMP-{id}` (owner replaces with real values afterward).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EmployeeIdUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_employee_id_has_unique_index(): void
    {
        $indexes = collect(Schema::getIndexes('users'));
        $hasUnique = $indexes->contains(fn ($i) => $i['unique'] && in_array('employee_id', $i['columns'], true));
        $this->assertTrue($hasUnique, 'users.employee_id must have a unique index');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=EmployeeIdUniquenessTest`
Expected: FAIL — no unique index on `employee_id`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_06_23_000001_enforce_unique_employee_id.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1) Give NULL/empty employee_ids a deterministic unique placeholder.
        DB::table('users')->whereNull('employee_id')->orWhere('employee_id', '')
            ->orderBy('id')->each(function ($u) {
                DB::table('users')->where('id', $u->id)->update(['employee_id' => 'EMP-'.$u->id]);
            });

        // 2) De-duplicate any remaining collisions: keep the lowest id, suffix the rest.
        $dupes = DB::table('users')->select('employee_id')->whereNotNull('employee_id')
            ->groupBy('employee_id')->havingRaw('COUNT(*) > 1')->pluck('employee_id');
        foreach ($dupes as $eid) {
            $rows = DB::table('users')->where('employee_id', $eid)->orderBy('id')->get();
            foreach ($rows as $i => $row) {
                if ($i === 0) { continue; } // first keeps the original id
                DB::table('users')->where('id', $row->id)->update(['employee_id' => $eid.'-DUP'.$row->id]);
            }
        }

        Schema::table('users', function (Blueprint $table) {
            $table->unique('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['employee_id']);
        });
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=EmployeeIdUniquenessTest`
Expected: PASS (the migration runs under RefreshDatabase on sqlite).

- [ ] **Step 5: Apply to dev MySQL**

Run: `php artisan migrate`
Expected: `2026_06_23_000001_enforce_unique_employee_id` DONE. Then verify no NULL/dup remain:
`php artisan tinker --execute="echo \App\Models\User::whereNull('employee_id')->count().' nulls; '.(\App\Models\User::select('employee_id')->groupBy('employee_id')->havingRaw('count(*)>1')->get()->count()).' dup groups';"`
Expected: `0 nulls; 0 dup groups`.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_06_23_000001_enforce_unique_employee_id.php tests/Feature/Attendance/EmployeeIdUniquenessTest.php
git commit -m "fix(users): resolve duplicate/NULL employee_id and add unique index

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

> Owner follow-up (data, not code): replace the `EMP-{id}` / `{id}-DUP{n}` placeholders with the real employee numbers for Md. Habibur, Prodip, Md. Munirujjaman, Md. Nazmul Hasan via the user-edit UI.

---

### Task 5: Phase 1 verification + roadmap note

**Files:**
- Modify: `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`

- [ ] **Step 1: Full attendance suite**

Run: `php artisan test --filter=Attendance`
Expected: PASS — only the two allowed pre-existing failures, no new ones.

- [ ] **Step 2: Mark roadmap B2 (holiday correctness) done; note engine precedence + employee_id**

Edit `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`: mark the **B2 — Holiday correctness** bullet ✅ (is_active + annual_fixed recurrence applied via `HolidayService`); add a line that engine precedence (weekly-off > leave) and `employee_id` uniqueness landed in Phase 1. Leave B3 (leave correctness) for Phase 2.

```bash
git add docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md
git commit -m "docs(attendance): Phase 1 done (holiday correctness, engine precedence, employee_id)

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

- [ ] **Step 3: Record deploy state**

Note (deploy notes / memory): Phase 1 adds migration `2026_06_23_000001_enforce_unique_employee_id` (run on prod) and is otherwise backend-only (no asset rebuild). Holiday recurrence requires existing recurring holidays to carry `is_recurring=true`/`recurrence_pattern='annual_fixed'`.

---

## Self-Review

**Spec coverage (Phase 1 portion of the design):**
- Holiday single-source honoring `is_active` + `annual_fixed` recurrence, dedup the three queries: Tasks 2 & 3. ✅
- Lunar = one-off, not expanded: enforced by Task 2 (only `is_recurring`/`annual_fixed` expand); tested (`test_non_recurring_past_holiday_does_not_recur`). ✅
- Engine precedence holiday > weekly-off > leave > absent: Task 1. ✅
- worked-on-leave conflict flag: Task 1 Step 4. ✅
- employee_id backfill + unique constraint: Task 4. ✅
- Global holiday scope (no multi-site build): respected — no scope column touched. ✅

**Placeholder scan:** No TBD/TODO. The only deferred item is the owner replacing `EMP-{id}` placeholders with real numbers — that is an explicit data follow-up, not a code gap (the unique constraint and tests pass with placeholders).

**Type consistency:** `HolidayService::forRange(CarbonInterface,CarbonInterface): Collection` defined in Task 2, consumed identically in Task 3. Occurrences expose `from_date`/`to_date` (Task 2 sets them; Task 3 reads them). `DayAttendance::WEEKEND`/`ON_LEAVE`/`HOLIDAY` used consistently in Task 1. Migration filename `2026_06_23_000001_enforce_unique_employee_id` consistent across Task 4 steps.
