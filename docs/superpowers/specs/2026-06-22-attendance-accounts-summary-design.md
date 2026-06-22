# Attendance — Accounts Monthly Summary + Capture Guards + Termination Gate (Phase B1)

**Date:** 2026-06-22
**Phase:** B1 of the Attendance Hardening Roadmap (`docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`).
**Status:** Design approved (column set = FULL/auditable, owner-confirmed 2026-06-22). Ready for plan.

## Problem / Owner's actual need

The owner does **not** run payroll in this app (descoped — accounts do it externally). The system's
job is to *capture attendance correctly and hand accounts a trustworthy per-employee monthly Excel*:
present / absent / leave days / total OT hours / late count, per employee, per month. Half-days,
mid-month transfers, and **terminations** are real for this org.

Today the only exports are a **day-by-day calendar grid** (`AttendanceAdminExport`) — useful for
eyeballing a month, useless as an accounts handoff (accounts want one row per employee with totals).
There is no summary artifact, and three correctness gaps would poison its numbers:
1. No per-employee monthly summary report or export.
2. Capture accepts garbage: future-dated punches, unbounded biometric clock-drift, out-before-in.
3. Absent keeps accruing after an employee's last working day (no termination clamp) — the joiner
   clamp (`date_of_joining`) exists in `calculateMonthlyStats`, the leaver clamp does not.

## Non-goals (explicit)

- **No payroll bridge, no pay math.** OT/late/leave are *counts and hours*, not money.
- **No half-day precision** in this phase — present/leave stay whole-day (B3). Documented as a caveat
  in the sheet header so accounts know the limitation.
- **No holiday recurrence / `is_active`** fix here — that is B2 (the summary inherits whatever
  `getHolidaysForMonth` returns today; B2 improves it for free since the summary reuses the engine).
- **No new permission.** Reuse `attendance.view` (the gate on all existing admin attendance reads/exports).
- **No change to the existing calendar grid export** (`AttendanceAdminExport`) — this is additive.

## The single-engine constraint (non-negotiable)

The summary numbers MUST derive from the **same** `AttendanceStatusService` pass that powers the grid
and dashboard, via the existing private `buildMonthlyDayResults()` + `classifyDay()` on
`AttendanceReportService`. No second counting path. This is the whole point of the Phase A
single-engine collapse — the accounts sheet cannot be allowed to diverge from what the admin sees on
screen. Therefore the new method lives **on `AttendanceReportService`** (same class → can call the
private engine helpers) and counts effective statuses exactly the way `calculateMonthlyStats` already does.

---

## Deliverable 1 — Per-employee monthly summary report + ONE Excel

### 1a. Service method

`AttendanceReportService::getPerEmployeeMonthlySummary(int $year, int $month, ?int $departmentId = null): array`

- Loads users via the existing `getEmployeeUsersWithAttendanceAndLeaves($year, $month, $departmentId)`
  (same approved-leave / non-rejected-punch filters as every other surface).
- `$holidays = $this->getHolidaysForMonth($year, $month)` (one fetch, reused across employees).
- Computes `$analysisEndDate` exactly like `calculateMonthlyStats`:
  `endOfMonth->isFuture() ? now()->endOfDay() : endOfMonth`. A partial current month must not count
  not-yet-arrived days as Absent.
- For each user: `buildMonthlyDayResults($user, $year, $month, $holidays, $analysisEndDate, $resolver, $policyResolver, $statusEngine)`,
  then iterate the day contexts and accumulate using `classifyDay($ctx)` — **the same classifier the
  dashboard uses**, so counts reconcile to the dashboard exactly:
  - skip `$ctx['before_join']` days (joiner clamp — already provided by the engine);
  - skip days **after the employee's termination date** (leaver clamp — see Deliverable 3);
  - `worked = [PRESENT, LATE, HALF_DAY, SHORT]` → `present++`; accrue `ot_minutes`; `late++` if `late_minutes > 0`;
  - `ABSENT` → `absent++`;
  - `ON_LEAVE` → `leave++`;
  - **Holidays-worked**: `$ctx['holiday'] && $ctx['attendances']->isNotEmpty()` → `holidaysWorked++`
    (classifyDay returns PRESENT for these; they are *extra* days worked, not scheduled working days);
  - **Weekly-off-worked**: `!$ctx['holiday'] && !$ctx['schedule']->isWorkingDay && hasPunch` (off-day work);
  - **Working days** (scheduled, in active window): `$ctx['schedule']->isWorkingDay && !$ctx['holiday']`
    → `workingDays++`. This yields the reconciliation identity **Present + Absent + Leave = Working days**
    (leave consumes a scheduled working day; holiday/weekly-off-worked are tracked separately because OT
    comes from them).
- `attendancePercentage = workingDaysExclLeave > 0 ? round(present / workingDaysExclLeave * 100, 1) : 0`,
  where `workingDaysExclLeave = present + absent` — identical to the dashboard's `potentialManDays`
  (scheduled working, non-leave). Leave never drags the percentage down.

**Return shape** (array of rows + meta), one row per employee:
```
[
  'meta' => ['month' => 'June 2026', 'generatedAt' => ISO, 'departmentId' => ?int, 'departmentName' => ?string],
  'rows' => [[
    'employee_name', 'employee_id', 'department',
    'present', 'absent', 'leave',
    'ot_hours' (float, 1dp), 'late' (int),
    'holidays_worked' (int), 'weekly_off_worked' (int),
    'working_days' (int), 'attendance_percentage' (float, 1dp),
  ], ...]
]
```
Rows ordered the same as the grid loader (designation hierarchy, then name).

### 1b. Excel writer

New `app/Exports/AttendancePerEmployeeSummaryExport.php` (plain PhpSpreadsheet, mirroring
`AttendanceAdminExport`'s style/borders helpers — no Maatwebsite dependency). FULL / auditable 12-column
layout (owner-approved):

| # | Column | Source |
|---|--------|--------|
| 1 | Employee | `name` |
| 2 | Emp ID | `employee_id` |
| 3 | Department | department name (null → "—") |
| 4 | Present | worked-effective day count |
| 5 | Absent | ABSENT count (post joiner/leaver clamp) |
| 6 | Leave | ON_LEAVE count |
| 7 | OT Hours | Σ ot_minutes / 60, 1dp |
| 8 | Late | days with late_minutes > 0 |
| 9 | Holidays Worked | holiday days with a punch |
| 10 | Weekly-off Worked | off-day days with a punch |
| 11 | Working Days | scheduled working days in window (= 4+5+6) |
| 12 | Attendance % | 4 / (4+5) × 100, 1dp |

- Title row: company header (reuse the `AttendanceAdminExport` constant) + month + a small caveat line:
  *"Whole-day leave/present model — half-days not yet split (counts may treat a half-day as a full day)."*
- A **totals footer row** (sum of numeric columns; attendance % left blank or org-wide avg) so accounts
  get a sheet-level cross-check.
- `export($year, $month, $departmentId)` returns `response()->download()` of the xlsx
  (synchronous — one row/employee is cheap; no queue/poll needed). Filename:
  `DBEDC_Attendance_Summary_{Month_Year}{_Dept?}.xlsx`.

### 1c. Endpoint + wiring

- Route (in the `permission:attendance.view` group near the other export routes, web.php ~487):
  `GET /attendance/monthly-summary/export` → `AttendanceController::exportMonthlySummary`.
- Controller method: read `month` (`YYYY-MM`) and optional `department_id`, validate, call the export,
  return the download. On failure return a clean JSON 4xx/5xx (match existing export error shape).
- Frontend: in `useAttendanceQuery.js` add `useExportMonthlySummary()` (mutation, `responseType: 'blob'`,
  GET `/attendance/monthly-summary/export` with `{ month, department_id }`). `handleExportResponse`
  already supports a direct binary blob, so no new util needed.
- UI: in `MonthlyCalendarTab.jsx`, add a **"Summary (Excel)"** button next to the existing Excel/PDF
  buttons (admin-only block, `isAdminView`). It reuses the toolbar's already-selected `selectedMonth`
  and `selectedDepartmentId` (so filters = month + optional department, exactly the existing controls).
  Reuse the `downloading` state pattern.

---

## Deliverable 2 — Capture guards (keep garbage out of the numbers)

All in `AttendancePunchService`; all return clean `422` via the existing `['status'=>'error','message','code']`
contract (the controller already maps that to an HTTP response). Capture-never-silently-corrupts, but
these are *validation rejections*, distinct from the "capture is never blocked by a logging/policy failure"
rule (which is about internal faults, not bad input).

1. **Reject future-dated punches.** In `resolvePunchTime`, when a trusted device timestamp parses to a
   moment **after now + small skew tolerance**, treat it as drift (see #2) rather than accepting a future
   punch. A manual/web punch is always `now()` so it can't be future. Net: no attendance row may carry a
   punch in the future.
2. **Bound biometric clock-drift** in `resolvePunchTime`. Define `MAX_CLOCK_DRIFT` (proposed **±2 hours** —
   research/confirm against ZKTeco back-download behavior, since legitimate back-downloads carry *old* real
   times and must still be honoured; the bound is about *implausible* drift, esp. future). If the device
   timestamp is beyond the allowed window (notably future-skewed), **fall back to server time** and log a
   warning (mirror the existing `Log::warning` style) rather than recording a bogus moment. Legit
   past back-downloads within bound are still honoured (the #1 device-time fix stands).
3. **Reject out-before-in** in `punchOut`: if the resolved out-moment is `<=` the row's `punchin`, return a
   `422` ("Punch-out cannot be before punch-in") instead of writing an inverted interval that yields
   negative/garbage worked minutes.

Each guard gets a focused engine/service test (future punch rejected; drifted-future device time falls
back to server time; legit old back-download still honoured; out-before-in rejected).

---

## Deliverable 3 — Termination gate

Stop counting **Absent** (and stop counting the day toward Working Days / the % denominator) after an
employee's last working day — mirroring the existing `date_of_joining` joiner clamp.

- Termination date = `Offboarding.last_working_date` for the employee. There is **no** `User → Offboarding`
  relationship today; add `User::offboarding()` (or `latestOffboarding`) resolving the **most recent**
  offboarding by `last_working_date` (an employee could in principle have prior cancelled records — prefer
  a non-cancelled record; confirm during implementation whether to filter `status != cancelled`).
- In `buildMonthlyDayResults`, alongside the `before_join` flag, compute `after_termination`:
  `$lastWorking !== null && $date->startOfDay()->greaterThan($lastWorking)`. Resolve `$lastWorking` once
  per user (eager-load to avoid an N+1; the loader's `with([...])` should include the offboarding relation).
- Consumers skip `after_termination` days exactly as they skip `before_join`:
  - `calculateMonthlyStats` (dashboard) — add the skip next to the existing `before_join` continue;
  - `getPerEmployeeMonthlySummary` (Deliverable 1) — skip in its accumulation loop.
  - The **grid** (`getUserAttendanceData`) currently shows all days; a terminated employee's post-term days
    should not read as Absent. Decide minimal treatment: simplest correct option is to mark post-term days
    as a neutral non-absent status in the grid (e.g. blank/"—") OR leave the grid as-is and only fix the
    counts. **Recommended:** fix the *counts* (dashboard + summary) in this phase and leave the grid display
    untouched unless a quick neutral marker is trivial — grid cosmetics are lower priority than the accounts
    numbers. Confirm during implementation; do not expand scope.

Tests: a user offboarded mid-month is not Absent for days after `last_working_date` in both the dashboard
stats and the summary; days on/before `last_working_date` still count normally; no offboarding record →
unchanged behavior.

---

## Reconciliation guarantees (why FULL columns)

For any employee row: **Present + Absent + Leave = Working Days**, and **Attendance % = Present /
(Present + Absent)**. Holidays-worked and Weekly-off-worked are reported separately (they explain OT
hours) and are *not* part of the Working Days identity. This lets accounts cross-check every row and the
footer totals without trusting a black box — the reason the owner chose FULL over LEAN.

## Testing strategy

- PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase` (project standard).
- **Summary == dashboard**: build a fixture month (present/late/absent/leave/holiday-worked/off-day-worked,
  a joiner mid-month, a leaver mid-month) and assert `getPerEmployeeMonthlySummary` per-employee counts
  reconcile to `calculateMonthlyStats` aggregates and to the identity above.
- **Endpoint**: `attendance.view` user gets a 200 xlsx; unauthorized blocked; department filter narrows rows.
- **Capture guards** and **termination gate**: as listed under their deliverables.
- Add **no** new failing test. Only allowed pre-existing failures: `MobileSyncApiTest > sync push applies
  leave apply mutation`; `NavigationRoutesTest > any authenticated user can access organization directory`.

## Migrations

None expected — `offboardings.last_working_date` already exists; this only adds an Eloquent relationship
and read-side logic. (If implementation finds a missing column, raise it before adding a migration.)

## Files touched

- `app/Services/Attendance/AttendanceReportService.php` — new `getPerEmployeeMonthlySummary()`; add
  `after_termination` to `buildMonthlyDayResults`; skip it in `calculateMonthlyStats`; eager-load offboarding
  in `getEmployeeUsersWithAttendanceAndLeaves`.
- `app/Exports/AttendancePerEmployeeSummaryExport.php` — **new**.
- `app/Http/Controllers/AttendanceController.php` — new `exportMonthlySummary()`.
- `routes/web.php` — new route in the `attendance.view` group.
- `app/Models/User.php` — `offboarding()` relationship.
- `app/Services/Attendance/AttendancePunchService.php` — three capture guards.
- `resources/js/api/queries/useAttendanceQuery.js` — `useExportMonthlySummary()`.
- `resources/js/Pages/Attendance/MonthlyCalendarTab.jsx` — "Summary (Excel)" button.
- Tests under `tests/Feature/Attendance/` and `tests/Unit` as appropriate.

## Build / deploy rules (obey)

- `npm run dev` for live testing; **never** `npm run build`. Build assets with `npx vite build` ONLY;
  frontend task commits SOURCE-ONLY; ONE consolidated `public/build` rebuild+commit at the end.
- New migrations (none expected) would need `php artisan migrate` on MySQL `dbedc_guardian`.
- Verify live via Playwright HTTP status; clean up any seeded test data afterward.
