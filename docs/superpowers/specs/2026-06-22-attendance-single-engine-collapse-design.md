# Attendance Single-Engine Collapse — Design (Phase A #3/#4)

**Date:** 2026-06-22
**Scope:** `app/Services/Attendance/AttendanceReportService.php` only (internal refactor; no schema, no route, no API-shape changes).
**Roadmap items:** #3 (collapse dual hours-math in the grid) + #4 (dashboard stats reconcile with the grid). See `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md`.

---

## 1. Problem

The module's stated invariant is **"everything that interprets a work-day flows through one engine, so daily / monthly / stats always agree."** Today they don't:

- **`getUserAttendanceData()` (monthly grid).** Computes the displayed `total_work_hours`, status symbol, and `remarks` from a **legacy `calculateTotalMinutes()`** (raw `punchout − punchin`) plus ad-hoc branching. It *separately* calls `AttendanceStatusService` only to attach the additive `ot_minutes`/`worked_minutes`/… buckets. So the headline hours ignore policy rounding/break/grace while the buckets next to them honor them — the two can disagree on the same cell.
- **`calculateMonthlyStats()` (dashboard).** Calls the engine with **no holiday / leave / policy** context, counts "present" as *any day with a punch*, and estimates **absent man-days** with a global `daysPassed * 2/7` weekend guess against a single org-wide potential figure — ignoring per-employee rosters, off-days, and mid-month joiners.

Result: grid hours ≠ bucket hours, and dashboard present/absent/leave ≠ what the grid shows.

## 2. Goal

Make **one engine pass per employee-day** the single source for every displayed number on both surfaces, using the **same holiday / leave / policy** the grid already loads. The grid and the dashboard must become two *views* of the identical `DayAttendance` results, so they cannot diverge.

**Hard constraints (must not regress):**
- The `paginate` JSON cell shape is a public contract: `{ status:<symbol>, punch_in, punch_out, total_work_hours:"HH:MM", remarks, ot_minutes, worked_minutes, double_time_minutes, regular_minutes, break_deducted_minutes, policy_events }`. Frontend `MonthlyCalendarTab.jsx` reads `status` as a **symbol** (√ ▼ # / ▽) and `total_work_hours`/`remarks` in a tooltip; exports (`AttendanceAdminExport`, `ExportAttendanceReport`) read the same keys. Keep all keys.
- `calculateMonthlyStats()` response shape unchanged: `meta{month,scope,totalEmployees,workingDays,holidays,weekends}`, `attendance{present,absent,leaves,lateArrivals,percentage,perfectCount}`, `hours{totalWork,averageDaily,overtime}`.
- `policy_status = 'rejected'` punches stay excluded (already filtered in the shared loader).
- Existing green tests must stay green: `MonthlyCalendarOtBucketsTest`, `MonthlyGridOffDayTest`, `MonthlyStatsShiftAwareTest`, `PunchExceptionApiTest`.

## 3. Architecture — shared single-pass helper

Introduce one private method that resolves the engine result for an employee-day, and have both public methods consume it:

```
buildMonthlyDayResults(User $user, int $year, int $month, Collection $holidays, ?CarbonInterface $until = null)
    : array<string /*YYYY-MM-DD*/, array{result: DayAttendance, holiday: ?Holiday, leave: ?Leave}>
```

- Resolves, per day: `ShiftSchedule` (via `ScheduleResolver`), `holiday` (from the passed month holidays), `leave` (from `$user->leaves`, already approved-only), `PolicyProfile` (via `PolicyResolver`).
- Calls `AttendanceStatusService::resolve($punches, $schedule, isHoliday, isOnLeave, now, policy)` **once** → `DayAttendance`.
- `$until` lets the dashboard stop at "today" for current months (skip future-day absents); the grid passes `null` (whole month).

Both consumers load users through the **same loader** the grid already uses — `getEmployeeUsersWithAttendanceAndLeaves()` (eager-loads `attendances` filtered `policy_status != rejected` and `leaves` filtered `status = approved`). This is what guarantees "same holiday/leave/policy as the grid."

### 3.1 `getUserAttendanceData()` becomes a display mapper

For each day it takes the `DayAttendance` + context and maps to the cell shape. Symbol/hours/remarks now derive from the **engine result**, not `calculateTotalMinutes()`:

| Engine `status` | symbol | remarks |
|---|---|---|
| `present` / `late` / `half_day` / `short` (a worked day) | `√` | `isHoliday` → `"Present on Holiday"` when `worked>0` else (today?`"Currently Working"`:`"Not Punched Out"`); else `worked>0` → `"Present"`, else (today?`"Currently Working"`:`"Not Punched Out"`) |
| `holiday` | `#` | `"Holiday"` |
| `on_leave` | `leaveType.symbol ?? '/'` | `"On Leave"` |
| `weekend` (non-working, no punch) | `▽` | `"Day Off"` |
| `absent` | `▼` | `"Absent"` |

- `total_work_hours` = `formatHHMM(result.worked_minutes)` — **the change for #3**: hours now reflect policy break/rounding (a 7h continuous punch under a 30-min unpaid-meal policy displays `06:30`, matching the `break_deducted`/`worked_minutes` buckets).
- `punch_in`/`punch_out` from the day's attendance rows (first punchin / last row with a punchout), unchanged.
- Buckets continue to come from the same `result` (no second engine call).
- `calculateTotalMinutes()` is deleted.

### 3.2 `calculateMonthlyStats()` becomes a status aggregator

Load employees via the shared loader (all employees for global scope; the single user for single scope), then for each employee call `buildMonthlyDayResults(..., until: analysisEndDate)` and aggregate **by counting statuses** + summing engine minutes, respecting `date_of_joining` (skip days before a user joined) and `analysisEndDate` (≤ today for current months):

- `present` man-days = count of `present | late | half_day | short`.
- `absent` man-days = count of `absent` (engine already returns this only for a working, non-holiday, non-leave, non-off day with no punch — replaces the `2/7` estimate and naturally honors per-employee rosters + joiners).
- `leaves` man-days = count of `on_leave` (engine precedence holiday > leave means a leave that lands on a holiday is **not** double-counted; weekends/off-days never count as leave).
- `lateArrivals` = count of days with `late_minutes > 0` (unchanged semantics; keeps `MonthlyStatsShiftAwareTest` green).
- `hours.totalWork` = Σ`worked_minutes`/60; `hours.overtime` = Σ`ot_minutes`/60 — both now **with policy** applied.
- `averageDaily` = totalWork / present man-days.
- `percentage` = present / potential, where potential is now the **sum over employees of their own scheduled working days** (count of days whose resolved schedule `isWorkingDay && !holiday`, within join…analysisEndDate) — not a flat global figure.
- `perfectCount` = employees whose present man-days ≥ their own scheduled working days so far.
- `meta.holidays` / `meta.weekends` / `meta.workingDays` keep using the existing calendar helpers (they describe the calendar, not per-employee man-days).

## 4. Behavior changes (intentional, documented)

These follow directly from making the engine the single source; they correct existing grid/stats divergence and are the point of #3/#4:

1. **Grid hours reflect policy** (break/rounding) instead of raw span. With no active policy, numbers are byte-identical to today (neutral policy path is unchanged in the engine).
2. **Holiday > leave precedence in the grid.** Today the grid's `elseif ($leave)` makes leave win over a holiday on a no-punch day ("On Leave"); the engine (and now the grid) makes **holiday win** ("Holiday"). Correct standard — you don't consume leave on a holiday — and required for grid==stats.
3. **A leave day where the employee actually punched shows as a worked day**, not "On Leave" (leave only paints a cell when there are no punches), matching the engine and the stats. (Half-day leave reconciliation remains out of scope → Phase B #7.)
4. **Absent man-days are now exact per-employee**, including off-days/rosters and excluding pre-join days, instead of a `2/7` estimate.

## 5. Out of scope

- The Payroll bridge (#2), leave server-side day-count + holiday recurrence (#3 of the task list), half-day leave (#7), termination guard (#8). `date_of_joining` clamping is included because it's cheap and #4 explicitly calls out joiners; termination clamping waits for #8.
- No resolver/performance refactor. The dashboard now does per-employee×per-day resolution (same cost the grid already pays); for this single-tenant app that is acceptable. **Documented follow-up:** `RosterScheduleResolver` issues ~3 queries per employee-day — a future batch/caching pass would help large orgs. Not done here.

## 6. Testing

PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase`. New tests:

- **Grid hours == buckets:** a day under an active breaks policy → `total_work_hours` equals `formatHHMM(worked_minutes)` (e.g. `06:30`, not `07:00`).
- **Grid holiday>leave:** no-punch day that is both holiday and approved-leave → symbol `#` / `"Holiday"`.
- **Grid leave-with-punch:** approved-leave day with punches → `√` / `"Present"` (not "On Leave").
- **Stats absent is exact:** controlled small month/roster → `attendance.absent` equals the hand-counted engine absents (proves the `2/7` estimate is gone), and `present`+`absent`+`leaves` reconcile against the grid for the same user/month.
- **Stats respects joiners:** a user with `date_of_joining` mid-month accrues no absents before joining.
- **Regression:** all four existing tests stay green.

Verify live by HTTP status via Playwright fetch against `https://aero-enterprise-suite.test` (`attendancesAdmin.paginate` + monthly-stats), read-only on prod.
