# Attendance Completion (10/10) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the four backend-without-frontend gaps and the one engine-divergence gap found in the post-overhaul review, so the attendance module is fully operable end-to-end: assign shifts/rotations to people, approve/reject swaps, assign roster cells by click, view per-record audit history, and make Monthly stats shift-aware (one engine everywhere).

**Architecture:** Backend adds the few missing read/list/delete/reject endpoints over models that already exist; the Monthly stats path is migrated onto the existing `ScheduleResolver` + `AttendanceStatusService` (the same seam the Daily Overview already uses). Frontend adds focused Radix components wired through the real `requestJson(method, url, {params|data})` client and React Query, mounted into the existing Roster tab / Settings tab / Daily Timesheet.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia v2, React 18 + Radix Themes, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit 11, Vite, `@tanstack/react-query`, dayjs.

## Global Constraints

- **Tests are PHPUnit class-style** (NOT Pest), sqlite `:memory:` + `RefreshDatabase`. Run `php artisan test --filter=<Class>`. Backend tasks are TDD.
- **There are exactly 2 KNOWN pre-existing failures** unrelated to this work: `MobileSyncApiTest > sync push applies leave apply mutation` and `NavigationRoutesTest > any authenticated user can access organization directory`. No task may add a NEW failure.
- **`requestJson` real signature is `requestJson(method, url, { params | data })`** (see `resources/js/api/client.js`). The brief's older snippets used `(url,{method,body})` — that form is WRONG; always use the real one.
- **After adding any migration, run `php artisan migrate`** against the MySQL dev DB `dbedc_guardian` (mysql bin `/c/laragon/bin/mysql/mysql-8.4.3-winx64/bin`). The sqlite test suite will NOT catch a missing MySQL table — this caused live 500s last round. (No task here adds a migration, but heed it if one becomes necessary.)
- **Verify frontend by HTTP status, not rendered shell.** Empty-state UIs render identically whether a GET returns 200 or 500 — drive the real endpoint (Playwright `fetch` or the network panel) and confirm 200.
- **Every multi-step write wraps in `DB::transaction`.** Admin mutations stay gated: management endpoints under `permission:attendance.settings`; employee endpoints under `permission:attendance.own.view`; corrections under `attendance.correct`; viewing under `attendance.view`.
- **Build frontend with `npx vite build`** — NEVER `npm run build` (its postbuild auto-commits/pushes). Dev server at `https://aero-enterprise-suite.test`.
- Match existing code style; Radix Themes only on the frontend; no new dependencies; YAGNI.

---

## File Structure

**Backend — modified**
- `app/Http/Controllers/HRM/ShiftController.php` — add `assignmentsIndex`, `destroyAssignment`.
- `app/Http/Controllers/HRM/ShiftSwapController.php` — add `reject`.
- `app/Http/Controllers/AttendanceController.php` — add `auditHistory`; extend `indexUnified` props (employees, designations).
- `app/Services/Attendance/AttendanceReportService.php` — migrate `calculateMonthlyStats` and `getUserAttendanceData` onto the engine.
- `routes/web.php` — routes for the new endpoints.

**Frontend — created**
- `resources/js/Forms/ShiftAssignmentForm.jsx` — create an effective-dated assignment.
- `resources/js/Pages/Attendance/Components/AssignmentManager.jsx` — list + delete assignments + open the form (mounted in `ShiftsSettings`).
- `resources/js/Pages/Attendance/Components/SwapApprovals.jsx` — admin swap list with approve/reject (mounted in the Roster tab).
- `resources/js/Pages/Attendance/Components/RosterCellPopover.jsx` — shift-picker popover for assigning a roster cell.
- `resources/js/Pages/Attendance/Components/AuditHistoryModal.jsx` — per-record audit history dialog.

**Frontend — modified**
- `resources/js/Pages/Attendance/ShiftsSettings.jsx` — mount `<AssignmentManager/>`.
- `resources/js/Pages/Attendance/RosterTab.jsx` + `Components/RosterCalendar.jsx` — wire the cell popover; mount `<SwapApprovals/>`.
- `resources/js/Pages/Attendance/DailyTimesheetTab.jsx` — add a "History" action per record opening `<AuditHistoryModal/>`.
- `resources/js/Pages/Attendance/AttendancePage.jsx` — thread the `employees`/`designations` props to `ShiftsSettings`.

---

### Task 1: Assignment list + delete endpoints

**Files:**
- Modify: `app/Http/Controllers/HRM/ShiftController.php`
- Modify: `routes/web.php` (the `permission:attendance.settings` group, near the existing `attendance.assignments.store` route)
- Test: `tests/Feature/Attendance/AssignmentCrudApiTest.php`

**Interfaces:**
- Consumes: `ShiftAssignment` model (relations `shift()`, `rotationPattern()`), `ShiftService` (existing).
- Produces:
  - `GET /attendance/shift-assignments` (name `attendance.assignments.index`) → `{ assignments: [ {id, scope_type, scope_id, shift:{id,code,name}|null, rotation_pattern:{id,name}|null, anchor_date, effective_from, effective_to, priority} ] }`, newest first.
  - `DELETE /attendance/shift-assignments/{id}` (name `attendance.assignments.destroy`) → `{message}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssignmentCrudApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');

        return $admin;
    }

    public function test_lists_assignments_with_shift_and_scope(): void
    {
        $shift = Shift::factory()->create(['code' => 'MOR', 'name' => 'Morning']);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => 7, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $res = $this->actingAs($this->admin())->getJson(route('attendance.assignments.index'));

        $res->assertOk();
        $res->assertJsonPath('assignments.0.scope_type', 'user');
        $res->assertJsonPath('assignments.0.shift.code', 'MOR');
    }

    public function test_deletes_an_assignment(): void
    {
        $shift = Shift::factory()->create();
        $a = ShiftAssignment::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson(route('attendance.assignments.destroy', $a->id))
            ->assertOk();

        $this->assertDatabaseMissing('shift_assignments', ['id' => $a->id]);
    }

    public function test_employee_without_permission_forbidden(): void
    {
        $emp = User::factory()->create();
        $this->actingAs($emp)->getJson(route('attendance.assignments.index'))->assertForbidden();
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AssignmentCrudApiTest`
Expected: FAIL — routes `attendance.assignments.index` / `.destroy` not defined.

- [ ] **Step 3: Add the controller methods**

In `app/Http/Controllers/HRM/ShiftController.php`, add:

```php
    public function assignmentsIndex(): JsonResponse
    {
        $assignments = \App\Models\HRM\ShiftAssignment::with(['shift:id,code,name', 'rotationPattern:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($a) => [
                'id' => $a->id,
                'scope_type' => $a->scope_type,
                'scope_id' => $a->scope_id,
                'shift' => $a->shift ? ['id' => $a->shift->id, 'code' => $a->shift->code, 'name' => $a->shift->name] : null,
                'rotation_pattern' => $a->rotationPattern ? ['id' => $a->rotationPattern->id, 'name' => $a->rotationPattern->name] : null,
                'anchor_date' => $a->anchor_date?->toDateString(),
                'effective_from' => $a->effective_from?->toDateString(),
                'effective_to' => $a->effective_to?->toDateString(),
                'priority' => $a->priority,
            ]);

        return response()->json(['assignments' => $assignments]);
    }

    public function destroyAssignment(int $id): JsonResponse
    {
        $assignment = \App\Models\HRM\ShiftAssignment::findOrFail($id);
        DB::transaction(fn () => $assignment->delete());

        return response()->json(['message' => 'Assignment deleted.']);
    }
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, inside the `permission:attendance.settings` group (next to `attendance.assignments.store`), add:

```php
        Route::get('/attendance/shift-assignments', [\App\Http\Controllers\HRM\ShiftController::class, 'assignmentsIndex'])->name('attendance.assignments.index');
        Route::delete('/attendance/shift-assignments/{id}', [\App\Http\Controllers\HRM\ShiftController::class, 'destroyAssignment'])->name('attendance.assignments.destroy');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AssignmentCrudApiTest`
Expected: PASS (3 cases).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/HRM/ShiftController.php routes/web.php tests/Feature/Attendance/AssignmentCrudApiTest.php
git commit -m "feat(attendance): list + delete shift assignments"
```

---

### Task 2: Swap reject endpoint

**Files:**
- Modify: `app/Http/Controllers/HRM/ShiftSwapController.php`
- Modify: `routes/web.php` (the `permission:attendance.settings` group, next to `attendance.swaps.approve`)
- Test: `tests/Feature/Attendance/SwapRejectApiTest.php`

**Interfaces:**
- Produces: `POST /attendance/swaps/{id}/reject` (name `attendance.swaps.reject`) → sets `status='rejected'`, `approved_by = auth id` (the decider), does NOT call `applySwap`. Returns `{message, swap}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\RosterDay;
use App\Models\HRM\ShiftSwapRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SwapRejectApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.settings']);
    }

    public function test_reject_sets_status_and_does_not_write_roster(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.settings');
        $requester = User::factory()->create();

        $swap = ShiftSwapRequest::create([
            'requester_id' => $requester->id, 'requester_date' => '2026-06-19', 'status' => 'pending',
        ]);

        $this->actingAs($admin)
            ->postJson(route('attendance.swaps.reject', $swap->id))
            ->assertOk();

        $this->assertSame('rejected', $swap->fresh()->status);
        $this->assertSame($admin->id, $swap->fresh()->approved_by);
        $this->assertSame(0, RosterDay::count()); // applySwap NOT called
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=SwapRejectApiTest`
Expected: FAIL — route not defined.

- [ ] **Step 3: Add the method + route**

In `ShiftSwapController.php`:

```php
    public function reject(Request $request, int $id): JsonResponse
    {
        $swap = ShiftSwapRequest::findOrFail($id);

        $swap->update([
            'status' => 'rejected',
            'approved_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Swap rejected.', 'swap' => $swap->fresh()]);
    }
```

In `routes/web.php`, next to `attendance.swaps.approve`:

```php
        Route::post('/attendance/swaps/{id}/reject', [\App\Http\Controllers\HRM\ShiftSwapController::class, 'reject'])->name('attendance.swaps.reject');
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=SwapRejectApiTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/HRM/ShiftSwapController.php routes/web.php tests/Feature/Attendance/SwapRejectApiTest.php
git commit -m "feat(attendance): reject shift swap requests"
```

---

### Task 3: Audit-history read endpoint

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php` (add `auditHistory`)
- Modify: `routes/web.php` (the `permission:attendance.view` group)
- Test: `tests/Feature/Attendance/AuditHistoryApiTest.php`

**Interfaces:**
- Consumes: `AttendanceAuditLog` (relation `actor()`).
- Produces: `GET /attendance/{id}/audit` (name `attendance.audit.history`, `id` numeric) → `{ logs: [ {id, action, before, after, reason, ip, actor:{id,name}|null, created_at} ] }`, newest first.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AuditHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_returns_audit_rows_for_a_record_newest_first(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');
        $att = Attendance::factory()->create();

        AttendanceAuditLog::create([
            'actor_id' => $admin->id, 'attendance_id' => $att->id, 'action' => 'update',
            'before' => ['punchin' => '09:00'], 'after' => ['punchin' => '09:15'], 'reason' => 'fix',
        ]);

        $res = $this->actingAs($admin)->getJson(route('attendance.audit.history', $att->id));

        $res->assertOk();
        $res->assertJsonPath('logs.0.action', 'update');
        $res->assertJsonPath('logs.0.actor.id', $admin->id);
        $res->assertJsonPath('logs.0.reason', 'fix');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AuditHistoryApiTest`
Expected: FAIL — route not defined.

- [ ] **Step 3: Add the controller method**

In `AttendanceController.php` add:

```php
    public function auditHistory(int $id): JsonResponse
    {
        $logs = \App\Models\HRM\AttendanceAuditLog::with('actor:id,name')
            ->where('attendance_id', $id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'action' => $log->action,
                'before' => $log->before,
                'after' => $log->after,
                'reason' => $log->reason,
                'ip' => $log->ip,
                'actor' => $log->actor ? ['id' => $log->actor->id, 'name' => $log->actor->name] : null,
                'created_at' => $log->created_at?->toIso8601String(),
            ]);

        return response()->json(['logs' => $logs]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/web.php`, inside the `permission:attendance.view` group (near `attendance.dailyOverview`), add:

```php
        Route::get('/attendance/{id}/audit', [AttendanceController::class, 'auditHistory'])->whereNumber('id')->name('attendance.audit.history');
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=AuditHistoryApiTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php routes/web.php tests/Feature/Attendance/AuditHistoryApiTest.php
git commit -m "feat(attendance): per-record audit history endpoint"
```

---

### Task 4: Migrate monthly stat numbers onto the engine

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`calculateMonthlyStats`)
- Test: `tests/Feature/Attendance/MonthlyStatsShiftAwareTest.php`

**Interfaces:**
- Consumes: `App\Services\Attendance\Contracts\ScheduleResolver`, `App\Services\Attendance\AttendanceStatusService`, `App\Models\HRM\Attendance`.
- Produces: `calculateMonthlyStats` derives `lateArrivals` and `overtime` per user-day from the resolved shift + engine (not the global `office_start_time` / hardcoded `480`). Response shape unchanged (`meta`, `attendance`, `hours`). The existing TODO comment is removed.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class MonthlyStatsShiftAwareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_monthly_late_uses_resolved_shift_not_global_nine(): void
    {
        // Global office start 09:00, but the user's shift starts 11:00.
        AttendanceSetting::create([
            'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'break_time_duration' => 0, 'late_mark_after' => 0,
            'early_leave_before' => 0, 'overtime_after' => 0,
            'weekend_days' => ['friday'], 'auto_punch_out' => false,
        ]);

        $user = User::factory()->create();
        $user->assignRole('Employee');
        $shift = Shift::factory()->create(['start_time' => '11:00', 'end_time' => '19:00', 'grace_in_minutes' => 0, 'full_day_minutes' => 0]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id, 'shift_id' => $shift->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        // Thursday 2026-06-18, punch 10:00 — LATE vs global 09:00, but ON-TIME vs the 11:00 shift.
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-18',
            'punchin' => Carbon::parse('2026-06-18 10:00'),
            'punchout' => Carbon::parse('2026-06-18 18:00'),
        ]);

        $stats = app(AttendanceReportService::class)->calculateMonthlyStats(6, 2026, false, $user->id);

        // Engine-aware: 10:00 is before the 11:00 shift start -> NOT late.
        $this->assertSame(0, $stats['attendance']['lateArrivals']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MonthlyStatsShiftAwareTest`
Expected: FAIL — current code compares against global 09:00, so the 10:00 punch counts as late (`lateArrivals` = 1).

- [ ] **Step 3: Migrate the late/OT derivation to the engine**

In `AttendanceReportService::calculateMonthlyStats`, remove the TODO comment added in the prior round and replace the per-record late check + per-record `> 480` overtime block with an engine-driven per-user-per-day computation. Concretely, resolve services at the top of the method:

```php
        $resolver = app(\App\Services\Attendance\Contracts\ScheduleResolver::class);
        $statusEngine = app(\App\Services\Attendance\AttendanceStatusService::class);
```

Then, inside the `foreach ($recordsByUser as $uId => $userRecords)` loop, replace the inner `foreach ($userRecords as $record) { ... late check ... hours ... }` with a per-day grouping that runs the engine:

```php
            $byDay = $userRecords->groupBy(fn ($r) => Carbon::parse($r->date)->toDateString());
            foreach ($byDay as $dayStr => $dayRecords) {
                $shift = $resolver->resolve((int) $uId, Carbon::parse($dayStr));
                $day = $statusEngine->resolve($dayRecords, $shift);

                if ($day->late_minutes > 0) {
                    $totalLateArrivals++;
                }
                $totalWorkMinutes += $day->worked_minutes;
                $totalOvertimeMinutes += $day->ot_minutes;
            }
```

(Keep everything else — present man-days, leave man-days, absent, percentages, averages — exactly as it is. `worked_minutes`/`ot_minutes` now come from the engine; the engine already sums multiple punches and handles night shifts.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MonthlyStatsShiftAwareTest`
Expected: PASS.

- [ ] **Step 5: Run the existing stats/export suite for regressions**

Run: `php artisan test --filter=Attendance`
Expected: PASS (AttendanceExportAndStatsTest, DailyOverviewStatsTest, etc. still green). The 2 known pre-existing failures are unrelated.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/MonthlyStatsShiftAwareTest.php
git commit -m "fix(attendance): monthly stats late/OT derive from shift-aware engine"
```

---

### Task 5: Make the monthly calendar grid shift-aware (off-days never absent)

**Files:**
- Modify: `app/Services/Attendance/AttendanceReportService.php` (`getUserAttendanceData`)
- Test: `tests/Feature/Attendance/MonthlyGridOffDayTest.php`

**Interfaces:**
- Consumes: `ScheduleResolver` (to know whether a day is a working day for that user).
- Produces: `getUserAttendanceData` marks a no-punch day as a **day-off** symbol `▽` with remark `Day Off` when the resolved schedule for that user/day is non-working (`isWorkingDay === false`), instead of `Absent`. Holiday/leave/present logic is unchanged; only the "no punch + not holiday + not leave" branch becomes shift-aware. The TODO comment is removed.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Shift;
use App\Models\HRM\ShiftAssignment;
use App\Models\HRM\ShiftRotationPattern;
use App\Models\User;
use App\Services\Attendance\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class MonthlyGridOffDayTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_punch_on_a_rostered_off_day_is_day_off_not_absent(): void
    {
        $user = User::factory()->create();
        $shift = Shift::factory()->create();
        // 2-day rotation: work, off. Anchor 2026-06-01 => 06-02 is an OFF day.
        $pattern = ShiftRotationPattern::factory()->create([
            'cycle_length_days' => 2, 'definition' => [$shift->id, 'off'],
        ]);
        ShiftAssignment::factory()->create([
            'scope_type' => 'user', 'scope_id' => $user->id,
            'shift_id' => null, 'rotation_pattern_id' => $pattern->id,
            'anchor_date' => '2026-06-01', 'effective_from' => '2026-06-01',
        ]);

        $user->setRelation('attendances', new Collection);
        $user->setRelation('leaves', new Collection);

        $data = app(AttendanceReportService::class)
            ->getUserAttendanceData($user, 2026, 6, collect(), collect());

        // 06-02 is an OFF day in the rotation, no punches -> Day Off, not Absent.
        $this->assertSame('Day Off', $data['2026-06-02']['remarks']);
        $this->assertSame('Absent', $data['2026-06-01'] === null ? 'Absent' : $data['2026-06-01']['remarks'] ?? 'Absent');
    }
}
```

> Note: `getUserAttendanceData($user, $year, $month, $holidays, $leaveTypes)` reads `$user->attendances` and `$user->leaves` relations — the test pre-sets them to empty collections so day 1 (a working day) is Absent and day 2 (off) is Day Off.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MonthlyGridOffDayTest`
Expected: FAIL — current code marks every no-punch non-holiday/non-leave day as `Absent` regardless of roster, so `2026-06-02` is `Absent`.

- [ ] **Step 3: Make the no-punch branch shift-aware**

In `getUserAttendanceData`, resolve the schedule for the day and, in the default `Absent` branch (the final `else`/fallthrough where `$attendancesForDate->isEmpty()` and no holiday/leave), check the resolved schedule. At the top of the per-day `for` loop body (after `$date`/`$dateString` are computed), add:

```php
            $resolver = app(\App\Services\Attendance\Contracts\ScheduleResolver::class);
            $schedule = $resolver->resolve($user->id, $date);
```

Then change the final default state so that when there are no punches, no holiday, and no leave, a **non-working** day becomes Day Off:

```php
            // Defaults
            $symbol = '▼';
            $punchIn = null;
            $punchOut = null;
            $totalWorkHours = '00:00';
            $remarks = ! $schedule->isWorkingDay ? 'Day Off' : 'Absent';
            if (! $schedule->isWorkingDay) {
                $symbol = '▽';
            }
```

(Leave the holiday / leave / present branches untouched — they overwrite `$symbol`/`$remarks` as before. Only the untouched default now reflects Day Off. Remove the TODO comment added previously.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MonthlyGridOffDayTest`
Expected: PASS.

- [ ] **Step 5: Regression sweep**

Run: `php artisan test --filter=Attendance`
Expected: PASS (only the 2 known pre-existing failures).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendanceReportService.php tests/Feature/Attendance/MonthlyGridOffDayTest.php
git commit -m "fix(attendance): monthly grid marks rostered off-days as Day Off, not Absent"
```

---

### Task 6: Thread employees + designations into the unified page props

**Files:**
- Modify: `app/Http/Controllers/AttendanceController.php` (`indexUnified`)
- Test: `tests/Feature/Attendance/UnifiedPagePropsTest.php`

**Interfaces:**
- Produces: the Inertia page rendered by `indexUnified` receives, in addition to its current props, `employees` (`[{id, name, department_id, designation_id}]`, Employee-role users) and `designations` (`[{id, title}]`). These feed the assignment form's pickers (`departments` is already provided).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UnifiedPagePropsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::firstOrCreate(['name' => 'Employee']);
        Role::firstOrCreate(['name' => 'Admin']);
        Permission::firstOrCreate(['name' => 'attendance.view']);
    }

    public function test_unified_page_includes_employees_and_designations(): void
    {
        $emp = User::factory()->create(['name' => 'Picker Emp']);
        $emp->assignRole('Employee');
        $admin = User::factory()->create();
        $admin->assignRole('Admin');
        $admin->givePermissionTo('attendance.view');

        $this->actingAs($admin)
            ->get(route('attendance.unified'))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('employees')
                ->has('designations')
            );
    }
}
```

> If `assertInertia` / `Inertia\Testing\AssertableInertia` is unavailable in this project, fall back to asserting the response is 200 and contains the employee name in the Inertia data payload (`$this->actingAs($admin)->get(...)->assertOk()` + `assertSee('Picker Emp', false)`). Use whichever the project already uses (grep `assertInertia` in tests/).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=UnifiedPagePropsTest`
Expected: FAIL — `employees`/`designations` props absent.

- [ ] **Step 3: Add the props**

In `AttendanceController::indexUnified`, add `employees` and `designations` to the Inertia render array (mirror how `departments` is already provided). Use the existing `Designation` model (`App\Models\HRM\Designation`) and the `Employee` role:

```php
            'employees' => \App\Models\User::role('Employee')
                ->select('id', 'name', 'department_id', 'designation_id')
                ->orderBy('name')
                ->get(),
            'designations' => \App\Models\HRM\Designation::select('id', 'title')->orderBy('title')->get(),
```

(Confirm the `Designation` model path + `title` column by reading `app/Models/HRM/Designation.php`; if the display column differs, use the real one.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=UnifiedPagePropsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/AttendanceController.php tests/Feature/Attendance/UnifiedPagePropsTest.php
git commit -m "feat(attendance): provide employees + designations to the unified page"
```

---

### Task 7: Frontend — Shift Assignment manager (create/list/delete) in Settings

**Files:**
- Create: `resources/js/Forms/ShiftAssignmentForm.jsx`
- Create: `resources/js/Pages/Attendance/Components/AssignmentManager.jsx`
- Modify: `resources/js/Pages/Attendance/ShiftsSettings.jsx` (mount `<AssignmentManager/>`; accept `employees`/`departments`/`designations` props)
- Modify: `resources/js/Pages/Attendance/AttendancePage.jsx` (pass `employees`/`designations` to the Settings tab's `<SettingsTab/>` → `<ShiftsSettings/>`; if SettingsTab doesn't forward props, pass via context or directly mount ShiftsSettings with props)
- Verify: `npx vite build` + Playwright (create an assignment → 201, appears in list).

**Interfaces:**
- Consumes: `GET/DELETE /attendance/shift-assignments`, `POST /attendance/shift-assignments`, `GET /attendance/shifts`, `POST /attendance/rotation-patterns` list (reuse `GET /attendance/shifts` for the shift picker; rotation patterns: there's no list endpoint — the form's pattern picker may be omitted or use a free-text id; prefer shift assignment for v1 and allow rotation by id). Props `employees`, `departments`, `designations` from the page.
- Produces: an admin can assign a shift to org/department/designation/user with `anchor_date`/`effective_from`/`effective_to`/`priority`; the list shows existing assignments with a delete action.

- [ ] **Step 1: Create `ShiftAssignmentForm.jsx`**

A Radix `Dialog` form. Fields: `scope_type` (Select: org/department/designation/user); a conditional scope picker (org → none; department → Select from `departments`; designation → Select from `designations`; user → Select from `employees`); `shift_id` (Select from shifts, required); `anchor_date`, `effective_from` (date inputs, required); `effective_to` (optional); `priority` (number, default 0). On submit POST `/attendance/shift-assignments`. Mirror `ShiftForm.jsx`'s structure and the real `requestJson('post', url, { data })` call. Show the server's 422 message (overlap / both-set) as a toast/inline error.

```jsx
import React, { useState, useMemo } from 'react';
import { Dialog, Flex, Select, TextField, Button, Text } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';

export default function ShiftAssignmentForm({ open, onOpenChange, onSaved, employees = [], departments = [], designations = [] }) {
    const empty = { scope_type: 'org', scope_id: '', shift_id: '', anchor_date: '', effective_from: '', effective_to: '', priority: 0 };
    const [form, setForm] = useState(empty);
    const [error, setError] = useState('');
    const set = (k, v) => setForm(f => ({ ...f, [k]: v }));

    const { data: shiftsData } = useQuery({ queryKey: ['shifts'], queryFn: () => requestJson('get', '/attendance/shifts'), enabled: open });
    const shifts = shiftsData?.shifts || [];

    const scopeOptions = useMemo(() => {
        if (form.scope_type === 'department') return departments.map(d => ({ id: d.id, label: d.name }));
        if (form.scope_type === 'designation') return designations.map(d => ({ id: d.id, label: d.title }));
        if (form.scope_type === 'user') return employees.map(e => ({ id: e.id, label: e.name }));
        return [];
    }, [form.scope_type, departments, designations, employees]);

    const save = async () => {
        setError('');
        try {
            const payload = {
                ...form,
                scope_id: form.scope_type === 'org' ? null : Number(form.scope_id) || null,
                shift_id: Number(form.shift_id),
                priority: Number(form.priority) || 0,
                effective_to: form.effective_to || null,
            };
            await requestJson('post', '/attendance/shift-assignments', { data: payload });
            onSaved?.();
            onOpenChange(false);
            setForm(empty);
        } catch (e) {
            setError(e?.response?.data?.message || e?.message || 'Failed to save assignment.');
        }
    };

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="500px">
                <Dialog.Title>Assign Shift</Dialog.Title>
                <Flex direction="column" gap="3">
                    <Select.Root value={form.scope_type} onValueChange={v => { set('scope_type', v); set('scope_id', ''); }}>
                        <Select.Trigger placeholder="Scope" />
                        <Select.Content>
                            <Select.Item value="org">Whole organization</Select.Item>
                            <Select.Item value="department">Department</Select.Item>
                            <Select.Item value="designation">Designation</Select.Item>
                            <Select.Item value="user">Employee</Select.Item>
                        </Select.Content>
                    </Select.Root>

                    {form.scope_type !== 'org' && (
                        <Select.Root value={String(form.scope_id)} onValueChange={v => set('scope_id', v)}>
                            <Select.Trigger placeholder={`Select ${form.scope_type}`} />
                            <Select.Content>
                                {scopeOptions.map(o => <Select.Item key={o.id} value={String(o.id)}>{o.label}</Select.Item>)}
                            </Select.Content>
                        </Select.Root>
                    )}

                    <Select.Root value={String(form.shift_id)} onValueChange={v => set('shift_id', v)}>
                        <Select.Trigger placeholder="Shift" />
                        <Select.Content>
                            {shifts.map(s => <Select.Item key={s.id} value={String(s.id)}>{s.name} ({s.code})</Select.Item>)}
                        </Select.Content>
                    </Select.Root>

                    <Flex gap="3">
                        <label><Text size="1">Anchor date</Text><TextField.Root type="date" value={form.anchor_date} onChange={e => set('anchor_date', e.target.value)} /></label>
                        <label><Text size="1">Effective from</Text><TextField.Root type="date" value={form.effective_from} onChange={e => set('effective_from', e.target.value)} /></label>
                        <label><Text size="1">Effective to</Text><TextField.Root type="date" value={form.effective_to} onChange={e => set('effective_to', e.target.value)} /></label>
                    </Flex>
                    <TextField.Root type="number" placeholder="Priority" value={form.priority} onChange={e => set('priority', e.target.value)} />

                    {error && <Text color="red" size="2">{error}</Text>}
                    <Flex justify="end" gap="2">
                        <Button variant="soft" color="gray" onClick={() => onOpenChange(false)}>Cancel</Button>
                        <Button onClick={save}>Save</Button>
                    </Flex>
                </Flex>
            </Dialog.Content>
        </Dialog.Root>
    );
}
```

- [ ] **Step 2: Create `AssignmentManager.jsx`** (list + delete + open form)

```jsx
import React, { useState } from 'react';
import { Box, Flex, Table, Button, IconButton, Text, Badge } from '@radix-ui/themes';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import ShiftAssignmentForm from '@/Forms/ShiftAssignmentForm';

export default function AssignmentManager({ employees = [], departments = [], designations = [] }) {
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);
    const { data } = useQuery({ queryKey: ['shift-assignments'], queryFn: () => requestJson('get', '/attendance/shift-assignments') });
    const refresh = () => qc.invalidateQueries({ queryKey: ['shift-assignments'] });

    const scopeLabel = (a) => {
        if (a.scope_type === 'org') return 'Org';
        const list = a.scope_type === 'department' ? departments : a.scope_type === 'designation' ? designations : employees;
        const found = list.find(x => x.id === a.scope_id);
        const name = found ? (found.name || found.title) : `#${a.scope_id}`;
        return `${a.scope_type}: ${name}`;
    };

    const remove = async (id) => {
        if (!confirm('Delete this assignment?')) return;
        await requestJson('delete', `/attendance/shift-assignments/${id}`);
        refresh();
    };

    return (
        <Box mt="5">
            <Flex justify="between" align="center" mb="3">
                <Text size="3" weight="bold">Shift Assignments</Text>
                <Button onClick={() => setOpen(true)}><PlusIcon /> Assign shift</Button>
            </Flex>
            <Table.Root variant="surface">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Scope</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Shift</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Effective</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Priority</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {(data?.assignments || []).map(a => (
                        <Table.Row key={a.id}>
                            <Table.Cell>{scopeLabel(a)}</Table.Cell>
                            <Table.Cell>{a.shift ? <Badge>{a.shift.code}</Badge> : (a.rotation_pattern ? `Pattern: ${a.rotation_pattern.name}` : '—')}</Table.Cell>
                            <Table.Cell>{a.effective_from} → {a.effective_to || '∞'}</Table.Cell>
                            <Table.Cell>{a.priority}</Table.Cell>
                            <Table.Cell><IconButton variant="soft" color="red" onClick={() => remove(a.id)}><TrashIcon /></IconButton></Table.Cell>
                        </Table.Row>
                    ))}
                    {(data?.assignments || []).length === 0 && (
                        <Table.Row><Table.Cell colSpan={5}><Text color="gray" size="2">No assignments yet.</Text></Table.Cell></Table.Row>
                    )}
                </Table.Body>
            </Table.Root>
            <ShiftAssignmentForm open={open} onOpenChange={setOpen} onSaved={refresh}
                employees={employees} departments={departments} designations={designations} />
        </Box>
    );
}
```

- [ ] **Step 3: Mount in `ShiftsSettings.jsx` + thread props**

Read `ShiftsSettings.jsx` and `AttendancePage.jsx`/`SettingsTab.jsx`. Accept `employees`/`departments`/`designations` props on `ShiftsSettings` and render `<AssignmentManager employees={employees} departments={departments} designations={designations} />` after the Shifts table (and below the existing Rotation-patterns button). Thread the props from `AttendancePage` (which now receives `employees`/`designations` from Task 6 via `usePage().props`) down to `ShiftsSettings` — if `SettingsTab` is the intermediary, either forward the props or have `ShiftsSettings` read them directly from `usePage().props` (`const { employees = [], departments = [], designations = [] } = usePage().props;`). Prefer reading from `usePage().props` inside `ShiftsSettings` to avoid prop-drilling through SettingsTab.

- [ ] **Step 4: Build + verify in browser**

Run: `npx vite build` (NOT npm run build). With the dev server, open `/attendance` → Settings → confirm "Shift Assignments" section renders; create an assignment for a user/shift; confirm the POST returns 201 (network panel) and the row appears; delete it. Verify the endpoints by status, not just the rendered shell.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Forms/ShiftAssignmentForm.jsx resources/js/Pages/Attendance/Components/AssignmentManager.jsx resources/js/Pages/Attendance/ShiftsSettings.jsx resources/js/Pages/Attendance/AttendancePage.jsx
git commit -m "feat(attendance): shift-assignment manager UI (create/list/delete)"
```

---

### Task 8: Frontend — admin Swap Approvals in the Roster tab

**Files:**
- Create: `resources/js/Pages/Attendance/Components/SwapApprovals.jsx`
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx` (mount `<SwapApprovals/>` below the calendar)
- Verify: `npx vite build` + browser.

**Interfaces:**
- Consumes: `GET /attendance/swaps`, `POST /attendance/swaps/{id}/approve`, `POST /attendance/swaps/{id}/reject`.
- Produces: a list of swap requests (requester, dates, status); pending ones get Approve / Reject buttons; approving invalidates the roster query so the grid refreshes.

- [ ] **Step 1: Create `SwapApprovals.jsx`**

```jsx
import React from 'react';
import { Box, Flex, Table, Button, Badge, Text } from '@radix-ui/themes';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';

const statusColor = { pending: 'amber', approved: 'green', rejected: 'red', cancelled: 'gray' };

export default function SwapApprovals() {
    const qc = useQueryClient();
    const { data } = useQuery({ queryKey: ['swaps'], queryFn: () => requestJson('get', '/attendance/swaps') });

    const act = useMutation({
        mutationFn: ({ id, decision }) => requestJson('post', `/attendance/swaps/${id}/${decision}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['swaps'] });
            qc.invalidateQueries({ queryKey: ['roster'] });
        },
    });

    const swaps = data?.swaps || [];

    return (
        <Box mt="5">
            <Text size="3" weight="bold">Swap Requests</Text>
            <Table.Root variant="surface" mt="2">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Requester</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Requester date</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Counterparty</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {swaps.map(s => (
                        <Table.Row key={s.id}>
                            <Table.Cell>{s.requester?.name || `#${s.requester_id}`}</Table.Cell>
                            <Table.Cell>{s.requester_date}</Table.Cell>
                            <Table.Cell>{s.counterparty?.name || (s.counterparty_id ? `#${s.counterparty_id}` : '—')}{s.counterparty_date ? ` (${s.counterparty_date})` : ''}</Table.Cell>
                            <Table.Cell><Badge color={statusColor[s.status] || 'gray'}>{s.status}</Badge></Table.Cell>
                            <Table.Cell>
                                {s.status === 'pending' && (
                                    <Flex gap="2">
                                        <Button size="1" color="green" loading={act.isPending} onClick={() => act.mutate({ id: s.id, decision: 'approve' })}>Approve</Button>
                                        <Button size="1" color="red" variant="soft" loading={act.isPending} onClick={() => act.mutate({ id: s.id, decision: 'reject' })}>Reject</Button>
                                    </Flex>
                                )}
                            </Table.Cell>
                        </Table.Row>
                    ))}
                    {swaps.length === 0 && (
                        <Table.Row><Table.Cell colSpan={5}><Text color="gray" size="2">No swap requests.</Text></Table.Cell></Table.Row>
                    )}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}
```

- [ ] **Step 2: Mount in `RosterTab.jsx`**

Import `SwapApprovals` and render `<SwapApprovals />` inside the Roster `Card`, below the `RosterCalendar`.

- [ ] **Step 3: Build + verify**

Run: `npx vite build`. In the browser, create a swap as an employee (employee page → Request swap), then as admin open `/attendance` → Roster → confirm the pending swap lists with Approve/Reject; Approve → status flips to approved (verify the POST → 200) and the roster query refetches.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/Components/SwapApprovals.jsx resources/js/Pages/Attendance/RosterTab.jsx
git commit -m "feat(attendance): admin swap approvals (approve/reject) in roster tab"
```

---

### Task 9: Frontend — roster cell assign-via-click popover

**Files:**
- Create: `resources/js/Pages/Attendance/Components/RosterCellPopover.jsx`
- Modify: `resources/js/Pages/Attendance/RosterTab.jsx` (provide shifts list + an assign handler; manage the selected-cell state) and/or `Components/RosterCalendar.jsx` (open the popover on cell click)
- Verify: `npx vite build` + browser.

**Interfaces:**
- Consumes: `GET /attendance/shifts` (shift options), `PUT /attendance/roster/cell` (`{user_id, date, shift_id|null}`).
- Produces: clicking a roster cell opens a popover listing shifts + an "Off (clear)" option; choosing one PUTs the cell (manual override) and refetches the roster.

- [ ] **Step 1: Create `RosterCellPopover.jsx`**

```jsx
import React from 'react';
import { Popover, Flex, Button, Text } from '@radix-ui/themes';

/** shifts: [{id,code,name,color}]; onPick(shiftIdOrNull) */
export default function RosterCellPopover({ open, onOpenChange, anchor, shifts = [], onPick }) {
    return (
        <Popover.Root open={open} onOpenChange={onOpenChange}>
            <Popover.Trigger>{anchor}</Popover.Trigger>
            <Popover.Content width="220px">
                <Text size="1" color="gray">Assign shift</Text>
                <Flex direction="column" gap="1" mt="2">
                    {shifts.map(s => (
                        <Button key={s.id} size="1" variant="soft" style={{ justifyContent: 'flex-start' }} onClick={() => onPick(s.id)}>
                            <span style={{ width: 10, height: 10, borderRadius: 2, background: s.color, display: 'inline-block', marginRight: 6 }} />
                            {s.name} ({s.code})
                        </Button>
                    ))}
                    <Button size="1" variant="soft" color="gray" onClick={() => onPick(null)}>Off (clear)</Button>
                </Flex>
            </Popover.Content>
        </Popover.Root>
    );
}
```

- [ ] **Step 2: Wire it in `RosterTab.jsx` / `RosterCalendar.jsx`**

In `RosterTab.jsx`: fetch shifts (`useQuery(['shifts'], () => requestJson('get','/attendance/shifts'))`); add a mutation `assignCell` that PUTs `/attendance/roster/cell` with `{ data: { user_id, date, shift_id } }` and invalidates `['roster', from, to]`. Track the clicked cell (`{ userId, date }`) in state; pass an `onCellClick(userId, date)` to `RosterCalendar` that sets that state + opens the popover. Render `RosterCellPopover` with the shifts list and `onPick={(shiftId) => assignCell.mutate({ user_id: selected.userId, date: selected.date, shift_id: shiftId })}`. (Keep it simple: a single popover anchored near the grid is acceptable; the existing clear-on-click can be replaced by this popover.)

- [ ] **Step 3: Build + verify**

Run: `npx vite build`. Create a shift + assignment, Generate roster, then click a cell → popover lists shifts → pick one → cell updates (PUT → 200), reload persists. Pick "Off (clear)" → cell clears.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/Components/RosterCellPopover.jsx resources/js/Pages/Attendance/RosterTab.jsx resources/js/Pages/Attendance/Components/RosterCalendar.jsx
git commit -m "feat(attendance): assign roster cell via shift-picker popover"
```

---

### Task 10: Frontend — per-record audit history modal

**Files:**
- Create: `resources/js/Pages/Attendance/Components/AuditHistoryModal.jsx`
- Modify: `resources/js/Pages/Attendance/DailyTimesheetTab.jsx` (add a "History" action per record row that opens the modal)
- Verify: `npx vite build` + browser.

**Interfaces:**
- Consumes: `GET /attendance/{id}/audit`.
- Produces: a dialog showing each audit row (action, actor, reason, before→after, timestamp) for an attendance record id.

- [ ] **Step 1: Create `AuditHistoryModal.jsx`**

```jsx
import React from 'react';
import { Dialog, Flex, Table, Text, Badge, Code } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';

export default function AuditHistoryModal({ open, onOpenChange, attendanceId }) {
    const { data, isLoading } = useQuery({
        queryKey: ['audit', attendanceId],
        queryFn: () => requestJson('get', `/attendance/${attendanceId}/audit`),
        enabled: open && !!attendanceId,
    });
    const logs = data?.logs || [];

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="640px">
                <Dialog.Title>Audit History</Dialog.Title>
                {isLoading ? <Text>Loading…</Text> : (
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>When</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Action</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>By</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Change</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {logs.map(l => (
                                <Table.Row key={l.id}>
                                    <Table.Cell><Text size="1">{l.created_at ? new Date(l.created_at).toLocaleString() : ''}</Text></Table.Cell>
                                    <Table.Cell><Badge>{l.action}</Badge></Table.Cell>
                                    <Table.Cell>{l.actor?.name || '—'}</Table.Cell>
                                    <Table.Cell>{l.reason || '—'}</Table.Cell>
                                    <Table.Cell><Code size="1">{JSON.stringify(l.before)} → {JSON.stringify(l.after)}</Code></Table.Cell>
                                </Table.Row>
                            ))}
                            {logs.length === 0 && <Table.Row><Table.Cell colSpan={5}><Text color="gray" size="2">No history.</Text></Table.Cell></Table.Row>}
                        </Table.Body>
                    </Table.Root>
                )}
            </Dialog.Content>
        </Dialog.Root>
    );
}
```

- [ ] **Step 2: Add the "History" action in `DailyTimesheetTab.jsx`**

Read `DailyTimesheetTab.jsx`'s per-record Actions cell (it already has edit/delete-style action buttons). Add a small icon button (e.g. `CounterClockwiseClockIcon` from `@radix-ui/react-icons`) that sets `historyId` state to the record's attendance id and opens `<AuditHistoryModal open={!!historyId} attendanceId={historyId} onOpenChange={() => setHistoryId(null)} />`. Use the record's primary attendance id (the row's `id` / `punchin_id` — match whichever the row exposes; the audit rows are keyed by `attendance_id`, the individual punch row id used in corrections).

- [ ] **Step 3: Build + verify**

Run: `npx vite build`. As admin, correct a record (creates an audit row), then click History on that row → modal shows the update with before→after, actor, reason. Confirm the GET `/attendance/{id}/audit` returns 200.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Pages/Attendance/Components/AuditHistoryModal.jsx resources/js/Pages/Attendance/DailyTimesheetTab.jsx
git commit -m "feat(attendance): per-record audit history modal"
```

---

### Task 11: Completion acceptance sweep

**Files:** Verify only.

- [ ] **Step 1: Full suite**

Run: `php artisan test`
Expected: all green except the 2 known pre-existing failures.

- [ ] **Step 2: Build**

Run: `npx vite build` — succeeds, no errors.

- [ ] **Step 3: End-to-end browser walkthrough (verify by HTTP status, not shells)**

With the dev server, as admin:
1. Settings → create a Shift, create a Rotation pattern, **create a Shift Assignment** (user → shift). 
2. Roster → **Generate roster** → cells populate for the assigned user. Click a cell → **assign a different shift via popover** → persists.
3. Employee page → **Request swap**; back as admin → Roster → **Swap Requests** → Approve → status approved + roster refetches.
4. Daily Timesheet → correct a record → **History** → audit row shows.
5. Monthly Calendar → confirm a rostered **off-day shows "Day Off"** (not Absent) and late counts reflect the shift.
Confirm each underlying GET/POST/PUT returns 2xx in the network panel.

- [ ] **Step 4: Commit the closeout**

```bash
git commit --allow-empty -m "test(attendance): completion acceptance sweep (assignments, swaps, roster cell, audit, shift-aware monthly)"
```

---

## Self-Review

**Spec coverage (the 5 gaps):**
1. Shift-assignment UI → Tasks 1 (list/delete API), 6 (employee/designation props), 7 (form + manager). ✅
2. Admin swap approvals → Tasks 2 (reject API), 8 (approvals UI). ✅
3. Roster cell assign-via-click → Task 9. ✅
4. Audit-trail viewing → Tasks 3 (read API), 10 (modal). ✅
5. Monthly stats engine divergence → Tasks 4 (stat numbers), 5 (calendar grid off-days). ✅

**Placeholder scan:** Backend tasks carry full code + tests. Frontend tasks carry full component code; the mount steps (7-step3, 9-step2, 10-step2) describe exact wiring against files the implementer must read first (`ShiftsSettings`, `RosterTab`, `DailyTimesheetTab`) because their internals weren't in scope to reproduce verbatim — each names the exact insertion point and the exact endpoint/handler. The rotation-pattern picker in the assignment form is intentionally deferred (no list endpoint) — assignment by shift covers the operable path; note it as a follow-up rather than a gap.

**Type consistency:** `requestJson(method, url, {data})` used uniformly; route names (`attendance.assignments.index/destroy`, `attendance.swaps.reject`, `attendance.audit.history`) match between backend tasks and the frontend consumers; `ScheduleResolver`/`AttendanceStatusService` signatures match their Phase-0/1 definitions (`resolve(userId, date)` → `?Shift`/`ShiftSchedule`; engine `resolve(Collection, ShiftSchedule)` → `DayAttendance`).
