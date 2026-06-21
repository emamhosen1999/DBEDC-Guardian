# Attendance Phase 3.1 — Pay Rules (Breaks + Daily Overtime) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the first compute-side **pay rules** to the Phase 3.0 policy engine — auto-deducted unpaid meal breaks and **daily** overtime (daily threshold ×1.5, double-time ×2) — by registering two new single-responsibility `RuleEvaluator`s, with zero rewrite of existing evaluators and byte-identical back-compat for neutral policies.

**Architecture:** Phase 3.0 shipped a registry `RuleEngine` of `RuleEvaluator`s (Rounding, GraceTiers) run over a per-day `DayContext` inside the pure `AttendanceStatusService`, resolved per employee-day by a `PolicyResolver` → immutable `PolicyProfile`. Phase 3.1 is **purely additive**: `PolicyProfile` gains `breaks()`/`overtime()` accessors (read from the policy row's existing `rule_overrides` json — no migration), a `BreaksEvaluator` deducts unpaid meal from worked minutes before status, and a `DailyOvertimeEvaluator` splits worked minutes into regular/OT/double-time buckets after status. The engine registration order becomes rounding → breaks → graceTiers → overtime. A neutral `PolicyProfile` (no breaks/overtime config) skips the gate entirely ⇒ identical Phase 2/3.0 output.

**SCOPE (read this — it bounds the plan):** This plan delivers: **(0) overnight-session handling** — **(0a)** match a post-midnight punch-OUT to the prior day's open IN row at capture, AND **(0b)** make the live "today" punch-status query surface the still-open overnight session after midnight so the employee UI keeps showing **"checked in" + a Check Out button + a running timer** past 00:00 (without these BOTH, a night-shift worker's screen flips back to "Check In" when the date rolls over). Then the **per-day** pay rules: **(1) break/meal auto-deduct** and **(2) daily overtime** (daily >N ×1.5, double-time >M ×2, multipliers, optional pre-authorization flagging). It **explicitly DEFERS** to a separate **Phase 3.1b** plan: FLSA **weekly** overtime (>40 hrs/week ×1.5), **7th-consecutive-day** premium, and separate FLSA-vs-state buckets — because those require a **multi-day aggregation pass** over a week of already-computed `DayAttendance` results, which is a different evaluation granularity than the per-day `RuleEngine` and warrants its own design (a `WeeklyOvertimeAllocator` service, not a per-day `RuleEvaluator`). Do NOT build weekly/consecutive-day OT in this plan.

**Day-attribution model (decided, standard, already de-facto in this codebase):** a worked session is attributed to its **shift-start (punch-in) date** — NOT split at midnight. This is the night-shift default in standard T&A systems (UKG/Kronos/ADP) and is already how the schema (`attendances.date` = punch-in start-of-day) and the engine (authoritative-datetime worked minutes, `ShiftSchedule.crossesMidnight`) behave. Task 0 only fixes the one gap (matching the post-midnight OUT to the open IN); it does NOT change attribution. Strict calendar-split / night-differential math is a Phase 3.3 concern computed from the authoritative timestamps inside the differential evaluator, without changing attribution.

**Tech Stack:** PHP 8.3, Laravel 11, Inertia v2, React 18 + Radix Themes, MySQL (prod) / sqlite `:memory:` (tests), PHPUnit 11 (class-style, NOT Pest), Vite, dayjs.

## Global Constraints

- **Back-compat is a hard test requirement.** A neutral `PolicyProfile` (no breaks AND no overtime AND no graceTiers AND no rounding, strictness `warn`) MUST make `AttendanceStatusService` produce output identical to Phase 3.0. The entire `--filter=Attendance` suite must stay green with ZERO edits to existing assertions. Gate ALL new behavior behind `! $policy->isNeutral()`, and extend `isNeutral()` so a breaks/overtime-only policy is correctly NON-neutral.
- **No migration in this phase.** Breaks/overtime configs live in the policy row's existing `rule_overrides` json column (reserved by Phase 3.0). Do NOT add columns. (Therefore no MySQL `migrate` step — but DO sanity-check `rule_overrides` round-trips on the MySQL dev DB once, since it's a real json column.)
- **Evaluators are pure** (no DB, no clock, no I/O) and **single-responsibility**. `BreaksEvaluator` only deducts meal + flags; `DailyOvertimeEvaluator` only splits OT buckets. Register them; never edit `RoundingEvaluator`/`GraceTiersEvaluator`.
- **Engine order is load-bearing and must be:** rounding → breaks → graceTiers → overtime. Breaks deduct from `workedMinutes` BEFORE status thresholds read it; overtime reads the post-break `workedMinutes` AFTER status.
- **Capture is untouched.** This phase is compute-only. Do not modify `AttendancePunchService`/`PunchPolicyGuard`.
- **Tests are PHPUnit class-style** (NOT Pest), sqlite `:memory:` + `RefreshDatabase`. Backend tasks are TDD. Run `php artisan test --filter=<Class>`.
- **2 KNOWN pre-existing failures** remain the ONLY allowed failures: `MobileSyncApiTest > sync push applies leave apply mutation`, `NavigationRoutesTest > any authenticated user can access organization directory`. Add no NEW failure.
- **Frontend:** client signature `requestJson(method, url, { params | data })`; web endpoints return PLAIN json. Build with `npx vite build` ONLY (NEVER `npm run build`). Frontend task commits are SOURCE-ONLY (do NOT stage `public/build`); ONE consolidated `public/build` rebuild + commit in the final sweep.
- **Reuse, don't reinvent:** the engine pieces live in `app/Services/Attendance/` (`RuleEngine`, `Contracts/RuleEvaluator`, `DTO/DayContext`, `DTO/PolicyProfile`, `Rules/*Evaluator`, `DbPolicyResolver`). `DayAttendance` DTO is `app/Services/Attendance/DTO/DayAttendance.php`. Match Phase 3.0 evaluator style exactly.
- Match existing code style; Radix Themes only; no new dependencies; YAGNI.

---

## File Structure

**Backend — new**
- `app/Services/Attendance/Rules/BreaksEvaluator.php` — deducts unpaid meal from `DayContext.workedMinutes`; flags meal deduction / missed-break premium.
- `app/Services/Attendance/Rules/DailyOvertimeEvaluator.php` — splits `DayContext.workedMinutes` into regular / daily-OT (×1.5) / double-time (×2) buckets.
- Tests: `tests/Unit/Attendance/BreaksEvaluatorTest.php`, `tests/Unit/Attendance/DailyOvertimeEvaluatorTest.php`.

**Backend — modify**
- `app/Services/Attendance/DTO/PolicyProfile.php` — add `?array $breaks`/`?array $overtime` constructor params + `breaks()`/`overtime()` accessors; extend `isNeutral()` to require both null.
- `app/Services/Attendance/DbPolicyResolver.php` — read `rule_overrides['breaks']` / `rule_overrides['overtime']` from the matched policy into the `PolicyProfile`.
- `app/Services/Attendance/DTO/DayContext.php` — add accumulators `int $regularMinutes`, `int $otMinutes`, `int $doubleTimeMinutes`, `int $breakDeductedMinutes`, `array $policyEvents` (all defaulted).
- `app/Services/Attendance/DTO/DayAttendance.php` — add `int $double_time_minutes = 0`, `int $regular_minutes = 0`, `int $break_deducted_minutes = 0`, `array $policy_events = []` (trailing, defaulted; additive `toArray()` keys).
- `app/Services/Attendance/AttendanceStatusService.php` — register the two new evaluators in the engine (correct order); when an overtime policy is present, populate OT buckets from the context; when a breaks policy is present, worked-minute math reflects the deduction; build the extended `DayAttendance`. All gated behind `! $policy->isNeutral()`.

**Frontend — modify**
- `resources/js/Forms/PolicyForm.jsx` — add a **Breaks** sub-editor and an **Overtime** sub-editor that read/write `rule_overrides.breaks` / `rule_overrides.overtime`.

**Frontend — verify only**
- `resources/js/Pages/Attendance/Components/PoliciesManager.jsx` — confirm the policy payload round-trips `rule_overrides` (it already POSTs the whole form; add `rule_overrides` to the submitted body if not already included).

**Backend — capture + operational correctness (Tasks 0a/0b/0c, independent of the pay rules — can ship as a quick batch first)**
- `app/Services/Attendance/AttendancePunchService.php` (0a) — overnight open-punch matching so a post-midnight OUT closes the prior day's open IN row for `crossesMidnight` shifts.
- `app/Services/Attendance/AttendanceQueryService.php` (0b) — make `getTodayAttendance` surface the still-open prior-day overnight session so the employee UI shows "checked in" + Check Out past midnight.
- `app/Console/Commands/AttendanceAutoPunchOut.php` + `app/Console/Kernel.php` (0c) — enforce the orphaned `auto_punch_out` setting by closing forgotten open rows at their resolved shift end (overnight-aware).
- Tests: `tests/Feature/Attendance/OvernightPunchTest.php` (0a), `tests/Feature/Attendance/OvernightTodayStatusTest.php` (0b), `tests/Feature/Attendance/AutoPunchOutCommandTest.php` (0c).

---

### Task 0a: Overnight capture fix — match a post-midnight punch-out to the prior day's open punch-in

**Why first:** Today, both punch lookups are scoped to the punch's own server-date (`whereDate('date', $punchDate)` at `AttendancePunchService.php:96` and `:242`). So when a night-shift worker punches IN at 23:00 (row dated day N, left open) and OUT at 06:00 the next morning, the OUT is not matched to the open day-N row: a `check_type:'out'` returns the 422 "No open attendance record to punch out from", and a manual toggle creates a stray new day-N+1 row while the day-N row stays open (`missing_punch_out`). That strands night-shift hours across two days and corrupts the per-day worked-minutes the pay rules (Tasks 3-5) consume. This task makes the post-midnight OUT close the prior open row when the resolved shift crosses midnight, collapsing the overnight session onto ONE row attributed to the shift-start day. **Capture is still never blocked**; this only changes WHICH open row an out-punch closes.

**Files:**
- Modify: `app/Services/Attendance/AttendancePunchService.php`
- Test: `tests/Feature/Attendance/OvernightPunchTest.php`

**Interfaces:**
- Produces: a private `findOpenAttendanceToClose(int $userId, CarbonInterface $punchMoment): ?Attendance` — returns today's open row (existing behavior) OR, if none, the previous day's open row when (a) the resolved shift for the open row's punch-in `crossesMidnight` AND (b) the out-punch is within `MAX_OVERNIGHT_HOURS` (= 18) of that punch-in. Used ONLY at the punch-OUT resolution points (the `$isOutPunch` branch and the toggle-to-out branch, in both `processPunch` and `processPunchInTransaction`). Punch-IN dedup/decision logic is UNCHANGED. Schedule resolved via the bound `App\Services\Attendance\Contracts\ScheduleResolver`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Attendance/OvernightPunchTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendancePunchService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OvernightPunchTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function nightShiftSettings(): void
    {
        // Default schedule resolver reads AttendanceSetting; 22:00->06:00 crosses midnight.
        AttendanceSetting::create([
            'office_start_time' => '22:00', 'office_end_time' => '06:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
    }

    public function test_post_midnight_out_closes_prior_day_open_row(): void
    {
        $this->nightShiftSettings();
        $user = User::factory()->create();

        // Punch IN at 23:00 on Jun 19 (row dated Jun 19, open).
        Carbon::setTestNow(Carbon::parse('2026-06-19 23:00:00'));
        $svc = app(AttendancePunchService::class);
        $svc->processPunch($user, new Request(['check_type' => 'in']));

        $open = Attendance::where('user_id', $user->id)->whereDate('date', '2026-06-19')->first();
        $this->assertNotNull($open);
        $this->assertNull($open->punchout);

        // Punch OUT at 06:00 on Jun 20.
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $res = $svc->processPunch($user, new Request(['check_type' => 'out']));

        $this->assertSame('success', $res['status']);
        $open->refresh();
        $this->assertNotNull($open->punchout);                       // prior-day row closed
        $this->assertSame('2026-06-20 06:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
        // No stray Jun 20 row created.
        $this->assertSame(0, Attendance::whereDate('date', '2026-06-20')->count());
    }

    public function test_day_shift_out_does_not_match_prior_day(): void
    {
        // Default 09:00-17:00 day shift (no AttendanceSetting => not crossing midnight).
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $res = app(AttendancePunchService::class)->processPunch($user, new Request(['check_type' => 'out']));
        // No crossesMidnight shift => prior-day row NOT matched => 422, prior row stays open.
        $this->assertSame('error', $res['status']);
        $this->assertNull(Attendance::whereDate('date', '2026-06-19')->first()->punchout);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=OvernightPunchTest` → FAIL (`test_post_midnight_out_closes_prior_day_open_row` returns the 422 error / leaves the row open).

- [ ] **Step 3: Implement `findOpenAttendanceToClose`** — add to `AttendancePunchService` (uses the bound `ScheduleResolver`; add `use App\Services\Attendance\Contracts\ScheduleResolver;` if helpful, or fully-qualify):

```php
    private const MAX_OVERNIGHT_HOURS = 18;

    private function findOpenAttendanceToClose(int $userId, \Carbon\CarbonInterface $punchMoment): ?Attendance
    {
        // 1) Today's open row — existing behavior.
        $today = Attendance::where('user_id', $userId)
            ->whereDate('date', $punchMoment->copy()->startOfDay())
            ->whereNull('punchout')
            ->latest()
            ->first();
        if ($today) {
            return $today;
        }

        // 2) Overnight: prior-day open row whose shift crosses midnight, within the bounded window.
        $prior = Attendance::where('user_id', $userId)
            ->whereDate('date', $punchMoment->copy()->subDay()->startOfDay())
            ->whereNull('punchout')
            ->latest()
            ->first();
        if (! $prior || ! $prior->punchin) {
            return null;
        }
        $in = Carbon::parse($prior->punchin);
        if ($in->diffInHours($punchMoment) > self::MAX_OVERNIGHT_HOURS) {
            return null;
        }
        $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)->resolve($userId, $in);

        return $shift->crossesMidnight ? $prior : null;
    }
```

- [ ] **Step 4: Wire it into the punch-OUT resolution ONLY** — in BOTH `processPunch` and `processPunchInTransaction`, at the `$isOutPunch` branch and the toggle-to-out branch (the `! $isInPunch && ... && ! $existingAttendance->punchout` path), resolve the row to close via `$this->findOpenAttendanceToClose($user->id, $punchTime)` instead of the today-scoped `$existingAttendance`. Concretely, for the OUT branch:

```php
        if ($isOutPunch) {
            $openRow = $this->findOpenAttendanceToClose($user->id, $punchTime);
            if ($openRow) {
                return $this->punchOut($openRow, $request, $user, $punchTime);
            }

            return ['status' => 'error', 'message' => 'No open attendance record to punch out from.', 'code' => 422];
        }
```

Leave the punch-IN dedup/"Already punched in" logic using the existing today-scoped `$existingAttendance` UNCHANGED (do not let an overnight prior-day row suppress a legitimate new-day punch-in). In `processPunchInTransaction`, apply the same change with the lock (`findOpenAttendanceToClose` may add `->lockForUpdate()` on the prior-day query inside the transaction variant — mirror the existing `lockForUpdate` usage).

- [ ] **Step 5: Run + regression** — `php artisan test --filter=OvernightPunchTest` → PASS (2 tests). Then `php artisan test --filter="Punch|Attendance"` → all green (day-shift behavior unchanged; the bounded `crossesMidnight` guard means non-overnight shifts are unaffected). Capture the count.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/AttendancePunchService.php tests/Feature/Attendance/OvernightPunchTest.php
git commit -m "fix(attendance): match post-midnight punch-out to prior open row for overnight shifts"
```

---

### Task 0b: Overnight live status — the punch UI shows "checked in" + Check Out past midnight

**Why:** The employee punch screen (web + mobile) derives its Check-In/Check-Out state from `AttendanceQueryService::getTodayAttendance(userId)`, which reads `AttendanceRepository::getTodayAttendance` → `getUserAttendanceForDate(userId, Carbon::today())` — scoped to TODAY only. So when the date rolls past midnight during a night shift, the open prior-day row is invisible: the UI flips back to "Check In" and the running timer appears to reset. Task 0a fixes capture (a device's `out` closes the right row); 0b fixes the LIVE DISPLAY so a human sees the correct state and can press Check Out after midnight. Same bounded rule as 0a (shift `crossesMidnight` AND open punch within `MAX_OVERNIGHT_HOURS` = 18).

**Files:**
- Modify: `app/Services/Attendance/AttendanceQueryService.php`
- Test: `tests/Feature/Attendance/OvernightTodayStatusTest.php`

**Interfaces:**
- Consumes: bound `ScheduleResolver`, `AttendanceRepository`.
- Produces: `getTodayAttendance` returns the still-open overnight session among `punches` when (a) today has no open row, (b) the prior day has an open row whose resolved shift `crossesMidnight`, and (c) the open punch-in is within 18h of now. The open session's elapsed time is included in `total_production_time` (the running timer continues across midnight). When no overnight session applies, output is unchanged (back-compat for day shifts).

- [ ] **Step 1: Write the failing test** — `tests/Feature/Attendance/OvernightTodayStatusTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use App\Services\Attendance\AttendanceQueryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OvernightTodayStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_open_overnight_session_shows_as_checked_in_after_midnight(): void
    {
        AttendanceSetting::create([
            'office_start_time' => '22:00', 'office_end_time' => '06:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 23:00:00', 'punchout' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $data = app(AttendanceQueryService::class)->getTodayAttendance($user->id);

        // The open overnight session is surfaced => UI shows "checked in" + Check Out.
        $this->assertNotEmpty($data['punches']);
        $open = collect($data['punches'])->firstWhere('punchout_time', null);
        $this->assertNotNull($open, 'open overnight session should be present');
        $this->assertSame('23:00:00', $open['punchin_time']);
    }

    public function test_day_shift_open_row_does_not_leak_into_next_day(): void
    {
        $user = User::factory()->create(); // no AttendanceSetting => default 09:00-17:00 (no crossesMidnight)
        Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-20 06:00:00'));
        $data = app(AttendanceQueryService::class)->getTodayAttendance($user->id);
        $this->assertEmpty($data['punches']); // prior-day day-shift row NOT surfaced
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=OvernightTodayStatusTest` → FAIL (the open overnight row is not surfaced).

- [ ] **Step 3: Implement** — in `AttendanceQueryService::getTodayAttendance`, after loading today's `$attendances`, if none of them is open (no null `punchout`), look for a prior-day open overnight row and prepend it. Add a private helper mirroring Task 0a's bounded rule:

```php
    private function openOvernightSession(int $userId): ?Attendance
    {
        $now = Carbon::now();
        $prior = $this->attendanceRepository
            ->getUserAttendanceForDate($userId, $now->copy()->subDay())
            ->firstWhere('punchout', null);
        if (! $prior || ! $prior->punchin) {
            return null;
        }
        $in = Carbon::parse($prior->punchin);
        if ($in->diffInHours($now) > 18) {
            return null;
        }
        $shift = app(\App\Services\Attendance\Contracts\ScheduleResolver::class)->resolve($userId, $in);

        return $shift->crossesMidnight ? $prior : null;
    }
```

Then, where `$attendances` is built (line ~28), merge the overnight session in when today has no open row:

```php
        $attendances = $this->attendanceRepository->getTodayAttendance($userId);
        if (! $attendances->contains(fn ($a) => $a->punchout === null)) {
            if ($overnight = $this->openOvernightSession($userId)) {
                $attendances = collect([$overnight])->concat($attendances);
            }
        }
```

The rest of the method (mapping to `punches`, `total_production_time`) is unchanged — the open overnight row maps with `punchout_time => null` and its elapsed time (punchin → now) flows into the running total, so the UI shows the live timer continuing across midnight. (If you prefer DRY across 0a/0b, extract a shared `App\Services\Attendance\OvernightSessionResolver` with the bounded rule and use it in both — optional; note it in your report.)

- [ ] **Step 4: Run + regression** — `php artisan test --filter=OvernightTodayStatusTest` → PASS (2 tests). Then `php artisan test --filter=Attendance` → all green (day shifts unaffected — the helper only fires for `crossesMidnight` shifts with an open prior-day row).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceQueryService.php tests/Feature/Attendance/OvernightTodayStatusTest.php
git commit -m "fix(attendance): surface open overnight session in today-status so UI shows Check Out past midnight"
```

---

### Task 0c: Enforce the orphaned `auto_punch_out` setting (close forgotten open punches at shift end)

**Why (GAP A):** `AttendanceSetting` already has `auto_punch_out` (bool) + `auto_punch_out_time`, exposed in the settings UI — but **no scheduler command enforces it** (`app/Console/Kernel.php` schedules reminders/leave/biometric jobs, none for auto-punch-out). So a forgotten punch-out leaves a row open indefinitely: the worker shows perpetually "checked in", the live timer grows without bound, and the next day stacks a second open row. This task adds the missing scheduled command. It must be **overnight-aware**: close each open row at its resolved **shift end** (which, for a `crossesMidnight` shift, is the next-day end) — NOT a naive same-day time — and must NOT close a row whose shift hasn't ended yet (someone legitimately still on shift, including a night shift before its morning end).

**Files:**
- Create: `app/Console/Commands/AttendanceAutoPunchOut.php`
- Modify: `app/Console/Kernel.php` (schedule it hourly)
- Test: `tests/Feature/Attendance/AutoPunchOutCommandTest.php`

**Interfaces:**
- Consumes: `AttendanceSetting`, bound `ScheduleResolver`, `AttendanceAuditService`.
- Produces: artisan command `attendance:auto-punch-out`. When `auto_punch_out` is enabled, for each open (`punchout IS NULL`) attendance whose `date` is within the last 2 days and whose resolved shift `end < now`, set `punchout = shift->end`, audit action `attendance.auto_punch_out`. Open rows still within their shift, and all rows when the setting is disabled, are untouched.

- [ ] **Step 1: Write the failing test** — `tests/Feature/Attendance/AutoPunchOutCommandTest.php`:

```php
<?php

namespace Tests\Feature\Attendance;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AutoPunchOutCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_closes_open_row_after_shift_end(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-06-19 20:00:00')); // past 17:00 end
        Artisan::call('attendance:auto-punch-out');

        $open->refresh();
        $this->assertNotNull($open->punchout);
        $this->assertSame('2026-06-19 17:00:00', Carbon::parse($open->punchout)->format('Y-m-d H:i:s'));
    }

    public function test_does_not_close_row_still_within_shift(): void
    {
        AttendanceSetting::create([
            'auto_punch_out' => true, 'office_start_time' => '09:00', 'office_end_time' => '17:00',
            'weekend_days' => ['friday', 'saturday'],
        ]);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-19 14:00:00')); // before end
        Artisan::call('attendance:auto-punch-out');
        $this->assertNull($open->fresh()->punchout);
    }

    public function test_disabled_setting_closes_nothing(): void
    {
        AttendanceSetting::create(['auto_punch_out' => false, 'office_start_time' => '09:00', 'office_end_time' => '17:00']);
        $user = User::factory()->create();
        $open = Attendance::factory()->for($user)->create([
            'date' => '2026-06-19', 'punchin' => '2026-06-19 09:00:00', 'punchout' => null,
        ]);
        Carbon::setTestNow(Carbon::parse('2026-06-19 20:00:00'));
        Artisan::call('attendance:auto-punch-out');
        $this->assertNull($open->fresh()->punchout);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=AutoPunchOutCommandTest` → FAIL (command not registered).

- [ ] **Step 3: Implement the command** — `app/Console/Commands/AttendanceAutoPunchOut.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\HRM\Attendance;
use App\Models\HRM\AttendanceSetting;
use App\Services\Attendance\AttendanceAuditService;
use App\Services\Attendance\Contracts\ScheduleResolver;
use Carbon\Carbon;
use Illuminate\Console\Command;

class AttendanceAutoPunchOut extends Command
{
    protected $signature = 'attendance:auto-punch-out';

    protected $description = 'Close forgotten open punches at their resolved shift end when auto_punch_out is enabled.';

    public function handle(ScheduleResolver $schedules, AttendanceAuditService $audit): int
    {
        $settings = AttendanceSetting::first();
        if (! $settings || ! $settings->auto_punch_out) {
            return self::SUCCESS;
        }

        $now = Carbon::now();
        $rows = Attendance::whereNull('punchout')
            ->whereNotNull('punchin')
            ->whereDate('date', '>=', $now->copy()->subDays(2)->toDateString())
            ->get();

        $closed = 0;
        foreach ($rows as $row) {
            $in = Carbon::parse($row->punchin);
            $shift = $schedules->resolve($row->user_id, $in);
            $end = $shift->isWorkingDay ? $shift->end->copy() : $in->copy()->endOfDay();
            if ($now->lessThan($end)) {
                continue; // still on shift
            }
            $before = $row->only(['punchout']);
            $row->update(['punchout' => $end]);
            $audit->record('attendance.auto_punch_out', $row->id, $before, $row->only(['punchout']), 'auto punched out at shift end', null);
            $closed++;
        }

        $this->info("Auto-punched-out {$closed} open attendance row(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Schedule it** — in `app/Console/Kernel.php` `schedule()`, add (match the existing scheduling style):

```php
        $schedule->command('attendance:auto-punch-out')->hourly();
```

- [ ] **Step 5: Run + regression** — `php artisan test --filter=AutoPunchOutCommandTest` → PASS (3 tests). Then `php artisan test --filter=Attendance` → all green.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/AttendanceAutoPunchOut.php app/Console/Kernel.php tests/Feature/Attendance/AutoPunchOutCommandTest.php
git commit -m "feat(attendance): enforce auto_punch_out setting via scheduled command (closes forgotten punches at shift end)"
```

---

### Task 1: Extend `PolicyProfile` + `DbPolicyResolver` with breaks/overtime configs

**Files:**
- Modify: `app/Services/Attendance/DTO/PolicyProfile.php`, `app/Services/Attendance/DbPolicyResolver.php`
- Test: extend `tests/Feature/Attendance/PolicyResolverTest.php`

**Interfaces:**
- Produces: `PolicyProfile::__construct(string $strictness='warn', int $outsideWindowMinutes=120, ?array $graceTiers=null, ?array $rounding=null, ?array $breaks=null, ?array $overtime=null)`; accessors `breaks(): ?array`, `overtime(): ?array`; `isNeutral(): bool` returns true ONLY when `strictness==='warn' && graceTiers===null && rounding===null && breaks===null && overtime===null`. `DbPolicyResolver` reads `$match->rule_overrides['breaks'] ?? null` and `$match->rule_overrides['overtime'] ?? null` into the profile.

- [ ] **Step 1: Write the failing test** — append to `tests/Feature/Attendance/PolicyResolverTest.php`:

```php
    public function test_resolves_breaks_and_overtime_from_rule_overrides(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 50,
            'effective_from' => '2026-01-01',
            'rule_overrides' => [
                'breaks' => ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360],
                'overtime' => ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5],
            ],
        ]);
        $p = app(\App\Services\Attendance\Contracts\PolicyResolver::class)->resolve($u->id, \Carbon\Carbon::parse('2026-06-20'));
        $this->assertSame(30, $p->breaks()['unpaid_meal_minutes']);
        $this->assertSame(480, $p->overtime()['daily_threshold_minutes']);
        $this->assertFalse($p->isNeutral());
    }

    public function test_policy_with_only_rule_overrides_is_not_neutral_but_grace_rounding_absent(): void
    {
        $u = User::factory()->create();
        AttendancePolicy::factory()->create([
            'scope_type' => 'org', 'scope_id' => null, 'version_group_id' => 51,
            'effective_from' => '2026-01-01', 'punch_strictness' => 'warn',
            'rule_overrides' => ['overtime' => ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]],
        ]);
        $p = app(\App\Services\Attendance\Contracts\PolicyResolver::class)->resolve($u->id, \Carbon\Carbon::parse('2026-06-20'));
        $this->assertFalse($p->isNeutral()); // overtime config makes it non-neutral
        $this->assertNull($p->graceTiers());
    }
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=PolicyResolverTest` → FAIL (`breaks()`/`overtime()` undefined).

- [ ] **Step 3: Extend `PolicyProfile`** — add the two constructor params (after `$rounding`) and accessors; extend `isNeutral()`:

```php
    public function __construct(
        private readonly string $strictness = 'warn',
        private readonly int $outsideWindowMinutes = 120,
        private readonly ?array $graceTiers = null,
        private readonly ?array $rounding = null,
        private readonly ?array $breaks = null,
        private readonly ?array $overtime = null,
    ) {}

    public function breaks(): ?array { return $this->breaks; }
    public function overtime(): ?array { return $this->overtime; }

    public function isNeutral(): bool
    {
        return $this->strictness === 'warn'
            && $this->graceTiers === null
            && $this->rounding === null
            && $this->breaks === null
            && $this->overtime === null;
    }
```

- [ ] **Step 4: Extend `DbPolicyResolver`** — in the `return new PolicyProfile(...)` at the end, add:

```php
        return new PolicyProfile(
            strictness: $match->punch_strictness,
            outsideWindowMinutes: $match->outside_window_minutes,
            graceTiers: $match->grace_tiers,
            rounding: $match->rounding,
            breaks: $match->rule_overrides['breaks'] ?? null,
            overtime: $match->rule_overrides['overtime'] ?? null,
        );
```

(`rule_overrides` is array-cast on the model, so `$match->rule_overrides['breaks'] ?? null` is safe when the column is null.)

- [ ] **Step 5: Run to verify it passes** — `php artisan test --filter=PolicyResolverTest` → PASS. Then `php artisan test --filter=Attendance` → all green (neutral profiles unaffected: existing PolicyProfile constructions pass no breaks/overtime ⇒ still neutral).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/DTO/PolicyProfile.php app/Services/Attendance/DbPolicyResolver.php tests/Feature/Attendance/PolicyResolverTest.php
git commit -m "feat(attendance): surface breaks/overtime policy configs from rule_overrides"
```

---

### Task 2: Extend `DayContext` + `DayAttendance` with break/OT accumulators

**Files:**
- Modify: `app/Services/Attendance/DTO/DayContext.php`, `app/Services/Attendance/DTO/DayAttendance.php`
- Test: `tests/Unit/Attendance/DayAttendanceShapeTest.php` (new — proves additive fields default to back-compat values)

**Interfaces:**
- Produces: `DayContext` gains public mutable `int $regularMinutes = 0`, `int $otMinutes = 0`, `int $doubleTimeMinutes = 0`, `int $breakDeductedMinutes = 0`, `array $policyEvents = []` (trailing, defaulted — existing named-arg constructions unaffected). `DayAttendance` gains readonly `int $double_time_minutes = 0`, `int $regular_minutes = 0`, `int $break_deducted_minutes = 0`, `array $policy_events = []` (trailing, defaulted) and additive `toArray()` keys.

- [ ] **Step 1: Write the failing test** — `tests/Unit/Attendance/DayAttendanceShapeTest.php`:

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayAttendance;
use Tests\TestCase;

class DayAttendanceShapeTest extends TestCase
{
    public function test_new_fields_default_to_back_compat_values(): void
    {
        $d = new DayAttendance(
            status: DayAttendance::PRESENT, worked_minutes: 480, late_minutes: 0,
            early_leave_minutes: 0, ot_minutes: 0, first_in: null, last_out: null,
            is_complete: true, flags: [],
        );
        $this->assertSame(0, $d->double_time_minutes);
        $this->assertSame(0, $d->break_deducted_minutes);
        $this->assertSame([], $d->policy_events);
        $arr = $d->toArray();
        $this->assertSame(0, $arr['double_time_minutes']);
        $this->assertArrayHasKey('policy_events', $arr);
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=DayAttendanceShapeTest` → FAIL (unknown named args).

- [ ] **Step 3: Extend `DayContext`** — add after `public PolicyProfile $policy`:

```php
        public int $regularMinutes = 0,
        public int $otMinutes = 0,
        public int $doubleTimeMinutes = 0,
        public int $breakDeductedMinutes = 0,
        public array $policyEvents = [],
```

- [ ] **Step 4: Extend `DayAttendance`** — add after `public readonly array $flags`:

```php
        public readonly int $double_time_minutes = 0,
        public readonly int $regular_minutes = 0,
        public readonly int $break_deducted_minutes = 0,
        public readonly array $policy_events = [],
```

and add the matching keys to `toArray()`:

```php
            'double_time_minutes' => $this->double_time_minutes,
            'regular_minutes' => $this->regular_minutes,
            'break_deducted_minutes' => $this->break_deducted_minutes,
            'policy_events' => $this->policy_events,
```

- [ ] **Step 5: Run + back-compat sweep** — `php artisan test --filter=DayAttendanceShapeTest` → PASS. Then `php artisan test --filter=Attendance` → ALL green. **If any existing test asserts the FULL `toArray()` via `assertEquals`/`assertSame` on the whole array, the additive keys will break it** — in that case the existing test was asserting an exact shape; STOP and report it (do not edit the assertion without controller adjudication — it may indicate a consumer that needs the new keys). Expected: existing tests assert individual fields (`->status`, `->worked_minutes`), so they stay green.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Attendance/DTO/DayContext.php app/Services/Attendance/DTO/DayAttendance.php tests/Unit/Attendance/DayAttendanceShapeTest.php
git commit -m "feat(attendance): add break/overtime accumulators to DayContext + DayAttendance (defaults = back-compat)"
```

---

### Task 3: `BreaksEvaluator` — auto-deduct unpaid meal

**Files:**
- Create: `app/Services/Attendance/Rules/BreaksEvaluator.php`
- Test: `tests/Unit/Attendance/BreaksEvaluatorTest.php`

**Interfaces:**
- Consumes: `DayContext` (`workedMinutes`, `shift`, `policy`), `PolicyProfile::breaks()`.
- Produces: `BreaksEvaluator implements RuleEvaluator`. `supports(PolicyProfile $p): bool` true when `$p->breaks()` is a non-empty array with `unpaid_meal_minutes > 0`. `evaluate(DayContext $ctx): void`: when `workedMinutes >= meal_threshold_minutes`, subtract `unpaid_meal_minutes` from `ctx.workedMinutes` (floored at 0), record the amount in `ctx.breakDeductedMinutes`, and append flag `meal_deducted`. Config shape: `{unpaid_meal_minutes:int, meal_threshold_minutes:int (default 360), missed_break_premium_minutes?:int}`. (Missed-break PREMIUM detection — emitting `policyEvents[]=['type'=>'missed_break_premium','minutes'=>N]` when worked exceeds threshold but no interior break gap exists — is **deferred to 3.1b** with pay context; v1 does auto-deduct only.)

- [ ] **Step 1: Write the failing test** — `tests/Unit/Attendance/BreaksEvaluatorTest.php`:

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\Rules\BreaksEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

class BreaksEvaluatorTest extends TestCase
{
    private function shift(): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'), end: Carbon::parse('2026-06-19 18:00'),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    private function ctx(int $worked, PolicyProfile $p): DayContext
    {
        return new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:00'), lastOut: Carbon::parse('2026-06-19 18:00'),
            workedMinutes: $worked, flags: [], shift: $this->shift(), policy: $p,
        );
    }

    public function test_deducts_unpaid_meal_when_worked_exceeds_threshold(): void
    {
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = $this->ctx(540, $p); // 9h worked
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(510, $ctx->workedMinutes);      // 540 - 30
        $this->assertSame(30, $ctx->breakDeductedMinutes);
        $this->assertContains('meal_deducted', $ctx->flags);
    }

    public function test_no_deduction_below_threshold(): void
    {
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = $this->ctx(300, $p); // 5h worked < 6h threshold
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(300, $ctx->workedMinutes);
        $this->assertSame(0, $ctx->breakDeductedMinutes);
    }

    public function test_no_double_deduction_when_break_already_taken(): void
    {
        // GAP I: break_out/break_in punches already EXCLUDE the break from worked minutes
        // (they are out/in punches). A 45-min taken break >= the 30-min unpaid meal, so the
        // auto-deduct must NOT deduct again. Span 09:00->18:00 = 540; worked 495 => 45 break taken.
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:00'), lastOut: Carbon::parse('2026-06-19 18:00'),
            workedMinutes: 495, flags: [], shift: $this->shift(), policy: $p,
        );
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(495, $ctx->workedMinutes);       // unchanged — break already taken
        $this->assertSame(0, $ctx->breakDeductedMinutes);
    }

    public function test_deducts_only_the_shortfall_when_partial_break_taken(): void
    {
        // 15-min taken break, 30-min unpaid meal required => deduct only the 15-min shortfall.
        $p = new PolicyProfile(breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360]);
        $ctx = new DayContext(
            firstIn: Carbon::parse('2026-06-19 09:00'), lastOut: Carbon::parse('2026-06-19 18:00'),
            workedMinutes: 525, flags: [], shift: $this->shift(), policy: $p, // 540 span - 525 = 15 taken
        );
        (new BreaksEvaluator)->evaluate($ctx);
        $this->assertSame(510, $ctx->workedMinutes);       // 525 - 15 shortfall
        $this->assertSame(15, $ctx->breakDeductedMinutes);
    }

    public function test_does_not_support_neutral_policy(): void
    {
        $this->assertFalse((new BreaksEvaluator)->supports(PolicyProfile::neutral()));
    }
}
```

(Note: `test_deducts_unpaid_meal_when_worked_exceeds_threshold` above uses a single pair where span == workedMinutes ⇒ 0 break taken ⇒ full 30-min deduct, so it stays correct under the netting logic.)

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=BreaksEvaluatorTest` → FAIL (class missing).

- [ ] **Step 3: Implement `BreaksEvaluator`**:

```php
<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

class BreaksEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $b = $policy->breaks();

        return is_array($b) && (int) ($b['unpaid_meal_minutes'] ?? 0) > 0;
    }

    public function evaluate(DayContext $ctx): void
    {
        $b = $ctx->policy->breaks();
        $meal = (int) ($b['unpaid_meal_minutes'] ?? 0);
        $threshold = (int) ($b['meal_threshold_minutes'] ?? 360);

        if ($meal <= 0 || $ctx->workedMinutes < $threshold) {
            return;
        }

        // GAP I — never double-deduct: break_out/break_in punches already excluded the break
        // from workedMinutes, so the break ALREADY TAKEN = (span firstIn..lastOut) - workedMinutes.
        // Auto-deduct only the SHORTFALL between the required unpaid meal and what was taken.
        $breakTaken = 0;
        if ($ctx->firstIn && $ctx->lastOut) {
            $span = max(0, (int) round($ctx->firstIn->diffInMinutes($ctx->lastOut)));
            $breakTaken = max(0, $span - $ctx->workedMinutes);
        }

        $deduct = min(max(0, $meal - $breakTaken), $ctx->workedMinutes);
        if ($deduct <= 0) {
            return; // worker already took at least the required unpaid meal
        }

        $ctx->workedMinutes -= $deduct;
        $ctx->breakDeductedMinutes += $deduct;
        $ctx->flags[] = 'meal_deducted';
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `php artisan test --filter=BreaksEvaluatorTest` → PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/Rules/BreaksEvaluator.php tests/Unit/Attendance/BreaksEvaluatorTest.php
git commit -m "feat(attendance): breaks evaluator (auto-deduct unpaid meal)"
```

---

### Task 4: `DailyOvertimeEvaluator` — daily OT + double-time buckets

**Files:**
- Create: `app/Services/Attendance/Rules/DailyOvertimeEvaluator.php`
- Test: `tests/Unit/Attendance/DailyOvertimeEvaluatorTest.php`

**Interfaces:**
- Consumes: `DayContext` (`workedMinutes`), `PolicyProfile::overtime()`.
- Produces: `DailyOvertimeEvaluator implements RuleEvaluator`. `supports(PolicyProfile $p): bool` true when `$p->overtime()` is a non-empty array with `daily_threshold_minutes > 0`. `evaluate(DayContext $ctx): void`: split the post-break `workedMinutes` into `regularMinutes` (≤ daily threshold), `otMinutes` (above daily threshold, below double-time threshold), `doubleTimeMinutes` (above double-time threshold). Writes those three onto `ctx`. When `require_preauthorization` is true, append flag `ot_needs_preauth` (interpretation only — never suppresses capture or counting; reconciliation with Phase 2 OvertimeRequest is a reporting concern). Config shape: `{daily_threshold_minutes:int, daily_multiplier:float (default 1.5), double_time_threshold_minutes?:int, double_time_multiplier?:float (default 2.0), require_preauthorization?:bool}`. The multipliers are carried for the payroll layer (Phase 4); the evaluator computes MINUTES per bucket (not pay).

- [ ] **Step 1: Write the failing test** — `tests/Unit/Attendance/DailyOvertimeEvaluatorTest.php`:

```php
<?php

namespace Tests\Unit\Attendance;

use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;
use App\Services\Attendance\DTO\ShiftSchedule;
use App\Services\Attendance\Rules\DailyOvertimeEvaluator;
use Carbon\Carbon;
use Tests\TestCase;

class DailyOvertimeEvaluatorTest extends TestCase
{
    private function shift(): ShiftSchedule
    {
        return new ShiftSchedule(
            start: Carbon::parse('2026-06-19 09:00'), end: Carbon::parse('2026-06-19 18:00'),
            crossesMidnight: false, graceInMinutes: 0, graceOutMinutes: 0, fullDayMinutes: 0,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
    }

    private function ctx(int $worked, PolicyProfile $p): DayContext
    {
        return new DayContext(
            firstIn: null, lastOut: null, workedMinutes: $worked, flags: [],
            shift: $this->shift(), policy: $p,
        );
    }

    public function test_splits_regular_ot_and_double_time(): void
    {
        // daily OT after 8h (480), double-time after 12h (720). Worked 13h (780).
        $p = new PolicyProfile(overtime: [
            'daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5,
            'double_time_threshold_minutes' => 720, 'double_time_multiplier' => 2.0,
        ]);
        $ctx = $this->ctx(780, $p);
        (new DailyOvertimeEvaluator)->evaluate($ctx);
        $this->assertSame(480, $ctx->regularMinutes);     // first 8h
        $this->assertSame(240, $ctx->otMinutes);          // 8h..12h = 4h
        $this->assertSame(60, $ctx->doubleTimeMinutes);   // 12h..13h = 1h
    }

    public function test_no_double_time_threshold_means_all_overage_is_ot(): void
    {
        $p = new PolicyProfile(overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]);
        $ctx = $this->ctx(600, $p); // 10h
        (new DailyOvertimeEvaluator)->evaluate($ctx);
        $this->assertSame(480, $ctx->regularMinutes);
        $this->assertSame(120, $ctx->otMinutes);
        $this->assertSame(0, $ctx->doubleTimeMinutes);
    }

    public function test_under_threshold_is_all_regular(): void
    {
        $p = new PolicyProfile(overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5]);
        $ctx = $this->ctx(420, $p); // 7h
        (new DailyOvertimeEvaluator)->evaluate($ctx);
        $this->assertSame(420, $ctx->regularMinutes);
        $this->assertSame(0, $ctx->otMinutes);
    }

    public function test_does_not_support_neutral_policy(): void
    {
        $this->assertFalse((new DailyOvertimeEvaluator)->supports(PolicyProfile::neutral()));
    }
}
```

- [ ] **Step 2: Run to verify it fails** — `php artisan test --filter=DailyOvertimeEvaluatorTest` → FAIL.

- [ ] **Step 3: Implement `DailyOvertimeEvaluator`**:

```php
<?php

namespace App\Services\Attendance\Rules;

use App\Services\Attendance\Contracts\RuleEvaluator;
use App\Services\Attendance\DTO\DayContext;
use App\Services\Attendance\DTO\PolicyProfile;

class DailyOvertimeEvaluator implements RuleEvaluator
{
    public function supports(PolicyProfile $policy): bool
    {
        $o = $policy->overtime();

        return is_array($o) && (int) ($o['daily_threshold_minutes'] ?? 0) > 0;
    }

    public function evaluate(DayContext $ctx): void
    {
        $o = $ctx->policy->overtime();
        $dailyThreshold = (int) ($o['daily_threshold_minutes'] ?? 0);
        $dtThreshold = (int) ($o['double_time_threshold_minutes'] ?? 0); // 0 = no double-time

        if ($dailyThreshold <= 0) {
            return;
        }

        $worked = max(0, $ctx->workedMinutes);
        $regular = min($worked, $dailyThreshold);
        $overage = max(0, $worked - $dailyThreshold);

        if ($dtThreshold > $dailyThreshold) {
            $otBand = max(0, $dtThreshold - $dailyThreshold);
            $ot = min($overage, $otBand);
            $doubleTime = max(0, $worked - $dtThreshold);
        } else {
            $ot = $overage;
            $doubleTime = 0;
        }

        $ctx->regularMinutes = $regular;
        $ctx->otMinutes = $ot;
        $ctx->doubleTimeMinutes = $doubleTime;

        if (! empty($o['require_preauthorization']) && ($ot + $doubleTime) > 0) {
            $ctx->flags[] = 'ot_needs_preauth';
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes** — `php artisan test --filter=DailyOvertimeEvaluatorTest` → PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/Rules/DailyOvertimeEvaluator.php tests/Unit/Attendance/DailyOvertimeEvaluatorTest.php
git commit -m "feat(attendance): daily overtime evaluator (regular/OT/double-time buckets)"
```

---

### Task 5: Thread breaks + overtime through `AttendanceStatusService` (back-compat)

**Files:**
- Modify: `app/Services/Attendance/AttendanceStatusService.php`
- Test: extend `tests/Unit/Attendance/AttendanceStatusServiceTest.php` (or add `tests/Unit/Attendance/PayRulesStatusTest.php`) with a breaks+OT case and a neutral back-compat case.

**Interfaces:**
- Consumes: `BreaksEvaluator`, `DailyOvertimeEvaluator`, the extended `DayContext`/`DayAttendance`.
- Produces: when `! $policy->isNeutral()`, the engine runs `new RuleEngine(new RoundingEvaluator, new BreaksEvaluator, new GraceTiersEvaluator, new DailyOvertimeEvaluator)` (ORDER MATTERS). After the engine: `workedMinutes` reflects the break deduction; when `$policy->overtime()` is set, the returned `DayAttendance` carries `ot_minutes`/`double_time_minutes`/`regular_minutes` from the context (overriding the legacy simple-OT calc); `break_deducted_minutes` and merged `policy_events`/flags are carried. Neutral ⇒ legacy path unchanged.

- [ ] **Step 1: Write the failing tests** — add to the status-service test (class-style, `Tests\TestCase`). Use a shift with `start 09:00`, `end 18:00`, `fullDayMinutes 480`, working day. Example assertions:

```php
    public function test_breaks_and_daily_ot_apply_together(): void
    {
        // Worked 09:00-19:00 = 600 min. Breaks: 30 unpaid meal over 360 threshold -> 570.
        // OT: daily threshold 480 -> regular 480, OT 90, double-time 0.
        $policy = new \App\Services\Attendance\DTO\PolicyProfile(
            breaks: ['unpaid_meal_minutes' => 30, 'meal_threshold_minutes' => 360],
            overtime: ['daily_threshold_minutes' => 480, 'daily_multiplier' => 1.5],
        );
        $shift = new \App\Services\Attendance\DTO\ShiftSchedule(
            start: \Carbon\Carbon::parse('2026-06-19 09:00'), end: \Carbon\Carbon::parse('2026-06-19 18:00'),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0, fullDayMinutes: 480,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
        $punches = collect([(object) ['punchin' => \Carbon\Carbon::parse('2026-06-19 09:00'), 'punchout' => \Carbon\Carbon::parse('2026-06-19 19:00')]]);
        $r = (new \App\Services\Attendance\AttendanceStatusService)->resolve($punches, $shift, policy: $policy);
        $this->assertSame(570, $r->worked_minutes);        // meal deducted
        $this->assertSame(30, $r->break_deducted_minutes);
        $this->assertSame(90, $r->ot_minutes);             // 570 - 480
        $this->assertSame(0, $r->double_time_minutes);
    }

    public function test_neutral_policy_pay_rules_back_compat(): void
    {
        $shift = new \App\Services\Attendance\DTO\ShiftSchedule(
            start: \Carbon\Carbon::parse('2026-06-19 09:00'), end: \Carbon\Carbon::parse('2026-06-19 18:00'),
            crossesMidnight: false, graceInMinutes: 15, graceOutMinutes: 0, fullDayMinutes: 480,
            halfDayMinutes: 0, minPresentMinutes: 0, breakMinutes: 0, isWorkingDay: true,
        );
        $punches = collect([(object) ['punchin' => \Carbon\Carbon::parse('2026-06-19 09:00'), 'punchout' => \Carbon\Carbon::parse('2026-06-19 19:00')]]);
        $a = (new \App\Services\Attendance\AttendanceStatusService)->resolve($punches, $shift);
        $b = (new \App\Services\Attendance\AttendanceStatusService)->resolve($punches, $shift, policy: \App\Services\Attendance\DTO\PolicyProfile::neutral());
        $this->assertSame($a->worked_minutes, $b->worked_minutes);   // no meal deduction under neutral
        $this->assertSame($a->ot_minutes, $b->ot_minutes);           // legacy OT preserved
        $this->assertSame(600, $b->worked_minutes);
        $this->assertSame(0, $b->break_deducted_minutes);
    }
```

- [ ] **Step 2: Run to verify they fail** — `php artisan test --filter=<your status test class>` → FAIL.

- [ ] **Step 3: Thread the evaluators** — in `AttendanceStatusService::resolve`, locate the existing `! $policy->isNeutral()` engine block (added in Phase 3.0). Replace the `RuleEngine` construction to include the two new evaluators in the load-bearing order, and copy the new accumulators back:

```php
        $policy ??= PolicyProfile::neutral();
        if (! $policy->isNeutral()) {
            $ctx = new DayContext($firstIn, $lastOut, $workedMinutes, $flags, $shift, $policy);
            (new RuleEngine(
                new RoundingEvaluator,
                new BreaksEvaluator,
                new GraceTiersEvaluator,
                new DailyOvertimeEvaluator,
            ))->apply($ctx);
            $firstIn = $ctx->firstIn;
            $lastOut = $ctx->lastOut;
            $flags = $ctx->flags;
            // Rounding boundary-delta recompute stays as-is (Phase 3.0), THEN breaks reduce worked:
            if ($policy->rounding()) {
                // ... existing boundary-delta block unchanged ...
            }
            // Breaks already mutated $ctx->workedMinutes inside the engine; adopt it:
            $workedMinutes = $ctx->workedMinutes;
        }
```

IMPORTANT ordering nuance: the Phase 3.0 rounding recompute derives `$workedMinutes` from the rounded boundaries. The `BreaksEvaluator` runs INSIDE the same engine pass (after rounding) and mutates `$ctx->workedMinutes`. To avoid the rounding recompute CLOBBERING the break deduction, restructure so the rounding recompute happens on the CONTEXT before breaks, or simply set `$ctx->workedMinutes` from the rounding recompute BEFORE the engine, and let breaks deduct from it. Cleanest: compute the rounding-adjusted worked minutes FIRST (pre-engine, as today), pass it into the `DayContext`, and let `BreaksEvaluator` deduct from `$ctx->workedMinutes`; then `$workedMinutes = $ctx->workedMinutes` after the engine. Verify with the Task 5 test that worked=570 (rounding off here, so just 600→570). Document whichever structure you choose in the report.

Then, after the late/early block, when overtime policy is present, take OT from the context instead of the legacy calc:

```php
        $otMinutes = ...; // existing legacy calc
        $doubleTimeMinutes = 0;
        $regularMinutes = 0;
        $breakDeducted = 0;
        if (! $policy->isNeutral() && $policy->overtime()) {
            $otMinutes = $ctx->otMinutes;
            $doubleTimeMinutes = $ctx->doubleTimeMinutes;
            $regularMinutes = $ctx->regularMinutes;
        }
        if (! $policy->isNeutral() && $policy->breaks()) {
            $breakDeducted = $ctx->breakDeductedMinutes;
        }
```

Finally, build the extended `DayAttendance` with the new trailing fields (`double_time_minutes: $doubleTimeMinutes, regular_minutes: $regularMinutes, break_deducted_minutes: $breakDeducted, policy_events: $ctx->policyEvents ?? []`). Under a neutral policy NONE of this runs ⇒ identical Phase 3.0 output.

- [ ] **Step 4: Run the new tests + FULL back-compat sweep** — `php artisan test --filter=<status test>` (PASS), then `php artisan test --filter=Attendance` → ALL green with ZERO edits to existing assertions. If a pre-existing assertion changes, the neutral path leaked — re-gate strictly behind `! $policy->isNeutral() && $policy->overtime()/breaks()`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Attendance/AttendanceStatusService.php tests/Unit/Attendance/...
git commit -m "feat(attendance): thread breaks + daily overtime through the status engine (neutral = back-compat)"
```

---

### Task 6: Surface OT buckets + break deduction in reports/API (additive)

**Files:**
- Modify: the daily/monthly surface(s) that already serialize `DayAttendance` (grep `->toArray()` on a `DayAttendance` and the controllers/services that build attendance day payloads — e.g. `AttendanceReportService`, `AttendanceController` daily-overview). Add the new fields to whatever array shape is sent to the frontend, ONLY where a `DayAttendance` is already serialized.
- Test: extend the relevant feature test (or add a focused one) asserting an OT-policy day exposes `ot_minutes` + `double_time_minutes` in the payload.

**Interfaces:**
- Consumes: extended `DayAttendance::toArray()`.
- Produces: the existing daily/monthly JSON gains `double_time_minutes`/`break_deducted_minutes`/`regular_minutes` additively (no removed/renamed keys; back-compat for existing consumers).

- [ ] **Step 1: Locate the serialization seam** — grep for where `DayAttendance` is converted to the payload sent to the attendance UI. If it already spreads `->toArray()`, the new keys flow automatically — in that case this task is a TEST + verification only. If it hand-picks keys, add the three new keys.
- [ ] **Step 2: Write/extend a feature test** — create a user with an active OT policy (`rule_overrides.overtime`), an attendance row with a long day, hit the daily/monthly endpoint, assert the response includes the new bucket fields with correct values. (PHPUnit class-style; map Eloquent props to arrays before `Inertia::render` if applicable.)
- [ ] **Step 3: Implement** the minimal additive change (or none if auto-flowed).
- [ ] **Step 4: Run** the feature test + `php artisan test --filter=Attendance` → green.
- [ ] **Step 5: Commit**

```bash
git add <touched files>
git commit -m "feat(attendance): expose OT buckets + break deduction in attendance payloads"
```

---

### Task 7: Frontend — Breaks + Overtime sub-editors in `PolicyForm`

**Files:**
- Modify: `resources/js/Forms/PolicyForm.jsx` (add Breaks + Overtime sub-editors writing `rule_overrides.breaks` / `rule_overrides.overtime`); verify `resources/js/Pages/Attendance/Components/PoliciesManager.jsx` submits `rule_overrides` in the create/update payload.
- Verify: `npx vite build` clean. SOURCE-ONLY commit.

**Interfaces:**
- Consumes (web, PLAIN json, `requestJson(method,url,{data})`): the existing `POST/PUT /attendance/policies` accept `rule_overrides` (validated `nullable|array` in `PolicyController` — confirm; if the controller doesn't yet persist `rule_overrides`, add it to the validated+stored fields as `nullable|array`).
- Produces: `PolicyForm` Breaks sub-editor (number `unpaid_meal_minutes`, number `meal_threshold_minutes`) and Overtime sub-editor (number `daily_threshold_minutes`, number `daily_multiplier`, number `double_time_threshold_minutes`, number `double_time_multiplier`, checkbox `require_preauthorization`), assembled into `form.rule_overrides = { breaks?, overtime? }` and submitted. Empty/blank sub-editors send `null` for that key (so a policy with no breaks stays neutral on that axis).

- [ ] **Step 1: Confirm the controller persists `rule_overrides`** — read `app/Http/Controllers/HRM/PolicyController.php`; ensure `rule_overrides` is in `policyRules()` (`'rule_overrides' => 'nullable|array'`) and in the stored/mapped fields + `mapPolicy()`. If missing, add it (this is a tiny backend touch; commit it WITH the frontend in this task or as a tiny preceding commit). Add/extend `PolicyApiTest` to assert a posted `rule_overrides` round-trips.
- [ ] **Step 2: Read the sibling sub-editor patterns** in `PolicyForm.jsx` (the existing grace-tier rows editor + rounding sub-editor) and mirror their controlled-input style.
- [ ] **Step 3: Build the Breaks + Overtime sub-editors**, assembling `rule_overrides` and nulling empty groups. Surface 422 via the existing toast util.
- [ ] **Step 4: Verify** — `npx vite build` compiles clean (NEVER `npm run build`). Do NOT stage `public/build`.
- [ ] **Step 5: Commit (SOURCE ONLY)**

```bash
git add resources/js/Forms/PolicyForm.jsx resources/js/Pages/Attendance/Components/PoliciesManager.jsx app/Http/Controllers/HRM/PolicyController.php tests/Feature/Attendance/PolicyApiTest.php
git commit -m "feat(attendance): breaks + overtime policy sub-editors (rule_overrides)"
```

---

### Task 8: Phase 3.1 acceptance sweep

**Files:** Verify only + one consolidated `public/build` rebuild.

- [ ] **Step 1: Full suite** — `php artisan test`; only the 2 known pre-existing failures. Pay special attention: the entire `--filter=Attendance` set is green (neutral back-compat) with NO edited assertions, AND `OvernightPunchTest` (0a) + `OvernightTodayStatusTest` (0b) pass.
- [ ] **Step 2: MySQL `rule_overrides` round-trip check** — no migration this phase, but confirm a breaks/overtime policy persists + resolves on the MySQL dev DB `dbedc_guardian`: create one via tinker with `rule_overrides`, resolve it, confirm `breaks()`/`overtime()` return the values (the json column round-trips on MySQL, not just sqlite).
- [ ] **Step 3: Build + commit assets** — `npx vite build`; `git add public/build && git commit -m "build(attendance): rebuild assets for Phase 3.1 pay rules"`.
- [ ] **Step 4: Live E2E (HTTP status, authenticated)** — (pay rules) as an `attendance.settings` admin: create a policy with breaks (30/360) + daily OT (480/1.5, DT 720/2.0), activate it, hit a daily/monthly attendance surface for a user with a long day → 2xx, payload shows deducted worked minutes + OT buckets. (overnight 0a/0b) with a `crossesMidnight` shift configured, verify `getTodayAttendance` (e.g. `GET /attendance` today-status payload or the mobile `/api/v1` today endpoint) returns the open prior-day session as `checked-in` when `now` is past midnight — drive via the authenticated browser `fetch` with `Carbon::setTestNow` not available in prod, so instead seed an open overnight row dated "yesterday" relative to the server clock and confirm the today-status response surfaces it. **Clean up any test policy AND any seeded overnight row you create** (leave only real data + the Global Default). Confirm `/attendance` + `/attendance-employee` render 200.
- [ ] **Step 5: Closeout** — `git commit --allow-empty -m "test(attendance): Phase 3.1 pay rules acceptance sweep"`.

---

## Self-Review

**Spec coverage (§3 rule catalog, 3.1 rows + correctness gaps):** overnight capture match → Task 0a; overnight live-status (UI shows Check Out past midnight) → Task 0b; orphaned `auto_punch_out` enforcement → Task 0c; break/meal auto-deduct (with no-double-deduct netting, GAP I) → Tasks 3,5,7; daily overtime + double-time + multipliers (carried) + pre-auth flagging → Tasks 4,5,7; configs in `rule_overrides` (no migration) → Tasks 1,7; back-compat gate → Tasks 1,2,5,8; surfacing for downstream → Task 6. **DEFERRED (documented, not built):** FLSA weekly >40, 7th-consecutive-day, FLSA-vs-state buckets → **Phase 3.1b** (needs a `WeeklyOvertimeAllocator` multi-day pass; separate plan); missed-break PREMIUM pay event (needs pay-rate context) → 3.1b/Phase 4; OT pre-auth reconciliation against Phase 2 `OvertimeRequest` (3.1 only FLAGS `ot_needs_preauth`) → reporting layer / Phase 4. ✅

**Edge-case gaps addressed (the "find more gaps" sweep):** (A) forgotten punch-out leaving rows open forever → Task 0c auto-close; (I) double meal deduction when a break punch was already taken → Task 3 nets out `span - workedMinutes`; (midnight) post-00:00 OUT not matched + UI losing the session → Tasks 0a/0b. Day-shift behavior is provably unaffected by 0a/0b/0c (all gated on `crossesMidnight` / shift-end), and neutral-policy output is unaffected by 3-7 (gated on `! isNeutral()`).

**Placeholder scan:** Backend Tasks 1-5 carry full code + tests. Task 6 is locate-then-additive (the serialization seam must be found in-repo — it's a verification+small-touch task, like Phase 3.0's report-surfacing). Task 7 mirrors the existing `PolicyForm` sub-editor patterns (named explicitly). ✅

**Type consistency:** `PolicyProfile::breaks()/overtime()` return `?array`; `DayContext` accumulators (`regularMinutes/otMinutes/doubleTimeMinutes/breakDeductedMinutes/policyEvents`) and `DayAttendance` fields (`regular_minutes/double_time_minutes/break_deducted_minutes/policy_events`) are used identically across `BreaksEvaluator`, `DailyOvertimeEvaluator`, and `AttendanceStatusService`. Config shapes (`breaks.{unpaid_meal_minutes,meal_threshold_minutes}`, `overtime.{daily_threshold_minutes,daily_multiplier,double_time_threshold_minutes,double_time_multiplier,require_preauthorization}`) match between the evaluators (read), the resolver (`rule_overrides`), and the frontend (write). ✅

**Back-compat guard:** `isNeutral()` extended to require breaks+overtime null; every new behavior gated behind `! $policy->isNeutral()` (and per-axis `$policy->breaks()/overtime()`); the full `--filter=Attendance` set must stay green unchanged (Tasks 1,2,5,8). The one real risk — additive `DayAttendance::toArray()` keys breaking a full-array-equality assertion — is explicitly checked in Task 2 Step 5. ✅
