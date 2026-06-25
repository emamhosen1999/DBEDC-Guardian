# Attendance · Leaves · Holidays — 10/10 Industry-Standard Design

**Date:** 2026-06-23
**Status:** Approved (design). Overarching design for a phased initiative; each phase gets its own implementation plan.
**Source:** Audit of 2026-06-23 (this session) + architectural decisions confirmed with the owner.
**Related:** `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md` (Phases B2/B3 map onto Phase 1/2 here).

## Goal

Bring the leave/holiday **integration** and a few data-integrity gaps up to the same standard as the (already strong) attendance engine, so every surface — grid, dashboard, accounts summary — derives correct numbers from one schedule layer and one engine pass. The compute core is ~9/10; this initiative lifts leave/holiday integration from ~6/10 to 10/10.

## Unifying principle (already half-built)

- **One schedule layer** (`roster_days` ← shift assignments ← rotation patterns ← global default) defines *working vs rest day per employee per date*. Weekly-offs (fixed, varied, rotating) live here — never as leave records.
- **One pure engine pass** (`AttendanceStatusService`) derives status / worked / late / OT per employee-day; `AttendanceReportService::buildMonthlyDayResults` + `classifyDay` feed every surface.
- **Leaves and holidays are clean inputs** to that engine — not duplicated or ad-hoc logic. This design removes the remaining places where they aren't.

## Architectural decisions (confirmed)

| Area | Decision |
|---|---|
| Sequencing | Phased: P1 correctness → P2 leave correctness → P3 accrual (accrual last). |
| Holiday recurrence | `annual_fixed` recurs same Gregorian date/year (national/fixed days); **lunar/Islamic holidays entered per-year by HR** (no algorithmic Hijri — govt moon-sighting governs). `is_active` honored everywhere. |
| Holiday scope | Global now; schema left scope-ready for future multi-site (YAGNI — not built now). |
| Leave granularity | Full-day + **half-day with AM/PM session**. Engine receives a per-day fraction (0/0.5/1.0) + session. |
| Leave day-count | **Server-side** from the employee's roster working-days in range (excludes their weekly-off + holidays), summing 0.5/1.0. Client `daysCount` no longer trusted. |
| Worked-on-rest | Present + all hours OT-eligible + **policy-driven comp-off**; a punch on an approved-leave day raises a `worked_on_leave` approver conflict. |
| Paid/unpaid | `is_paid` per **leave type** (`LeaveSetting`). Accounts summary splits paid-leave vs LWP. |
| Leave status | Normalize to `{pending, approved, rejected, cancelled}` + data migration; code updated. |
| employee_id | Backfill the 4 bad rows (owner supplies values) → **unique constraint**. |
| Weekend model | **Proper rosters** (per-employee approved weekly-off); retire the "Weekend" leave type; off-day moves = roster-edit/swap. |

---

## Phase 0 (cross-cutting, in flight) — Weekend → roster migration

Already underway this session (Emam Hosen converted; the per-employee off-day list approved by the owner). Remaining:
- Bootstrap each approved employee's roster (their weekly-off + Day/Night 9–5 shift), forward from Jun 2026; back up + delete their "Weekend" leaves from Jun 2026 onward (keep pre-June history).
- Decide off-days for the ~10 employees with no recent "Weekend" leaves (owner input).
- Retire the **"Weekend" `LeaveSetting`** type once all employees are rostered (stop creating Weekend leaves; the engine precedence fix in P1 also neutralizes any residual Friday Weekend-leaves immediately since Friday is the global off).
- This is a **data migration** (a reversible artisan command with a dry-run), not application code; tracked separately from P1/P2/P3 code.

## Phase 1 — Holiday correctness + Engine precedence + Data integrity

**Spec to follow:** `docs/superpowers/specs/2026-06-2x-attendance-phase1-*.md` (written when planned).

### 1a. Holiday single source
- New `HolidayService::forRange(CarbonInterface $from, CarbonInterface $to): Collection` (or a method on the existing report service) that:
  - filters `is_active = true`;
  - returns concrete one-off holidays whose range intersects `[from,to]`;
  - **expands** `recurrence_pattern = 'annual_fixed'` rows into the queried year(s) (same month-day), intersected with `[from,to]`;
  - lunar/announced holidays are plain one-off rows (no expansion) — HR enters each year.
- `getHolidaysForMonth`, `getTotalHolidayDays`, `getWeekendDaysCount` all delegate to this one method (dedupe the three ad-hoc queries). Engine, grid, dashboard, summary, exports unaffected in shape — only the source is corrected.
- Honest scope: recurrence supports `none` and `annual_fixed` now; `nth_weekday`/lunar-auto are explicitly out (YAGNI / unreliable).

### 1b. Engine precedence
- In `AttendanceStatusService::resolve`, reorder the no-punch match to `holiday > !isWorkingDay (WEEKEND) > isOnLeave (ON_LEAVE) > ABSENT`. `classifyDay` already intends weekly-off > leave, so engine and report layer become consistent; a leave never consumes a rest day. Update the comment.
- `worked_on_leave` flag: when a punch lands on an approved-leave **working** day, keep the existing ON_LEAVE precedence but emit a flag for the approver/exception queue (no silent relabel).

### 1c. Data integrity
- Backfill the conflicting/empty `users.employee_id` (owner supplies correct values for the two `126`s and two NULLs) → add a unique index (nullable-safe, or require non-null for Employees).

### Phase 1 tests
- Inactive holiday no longer suppresses attendance; an `annual_fixed` holiday recurs in a later year; lunar one-off only affects its entered year.
- Leave overlapping a weekly-off resolves to Day Off (not leave) at the engine; summary/dashboard reconcile.
- employee_id uniqueness enforced; backfill correct.

## Phase 2 — Leave correctness

**Spec to follow:** `docs/superpowers/specs/2026-06-2x-attendance-phase2-*.md`.

### 2a. Model & migration
- `leaves`: add `is_half_day` (bool) + `half_day_session` (enum `first_half|second_half`, nullable).
- `leave_settings`: add `is_paid` (bool, default true); add `symbol`, `is_earned` to `$fillable` (columns already exist).
- Normalize `leaves.status` to `{pending, approved, rejected, cancelled}` + data migration converting existing `Approved/New/Pending` rows; update model accessor, the engine loader filter, and all controllers/queries to the canonical values.

### 2b. Server-side day-count
- On create/update, compute `no_of_days` server-side: iterate `[from_date,to_date]`, count only the employee's **roster working-days** (exclude weekly-off + holidays via the Phase-1 `HolidayService`), and apply 0.5 for a half-day leave. Stop trusting the client `daysCount`.

### 2c. Engine integration (fraction, not bool)
- `buildMonthlyDayResults` resolves a per-day **leave fraction** (0 / 0.5 / 1.0) + session from the (approved) leave overlapping that date; `AttendanceStatusService::resolve` accepts the fraction so a half-day leave + half-day worked reconciles to 0.5 present + 0.5 leave in worked-minutes, status, and the counts.
- `classifyDay` and the accounts summary count fractional leave days; the summary gains **paid-leave days vs LWP days** columns from `is_paid`.

### Phase 2 tests
- Half-day leave (AM) + afternoon punch → 0.5 leave + 0.5 present; day-count excludes weekends/holidays; status normalization migration; paid/LWP split in summary; single-engine reconciliation holds with fractions.

## Phase 3 — Leave balance/accrual ledger *(sketch; full spec when reached)*

Outline only (not specced for implementation yet):
- A `leave_ledger` (entitlement opening → periodic accrual → consumption from approved leaves → carry-forward cap → optional encashment), per employee per leave-type per period.
- Balances become first-class/auditable, replacing the implicit `LeaveSetting.days`. Consumes Phase-2 fractional day-counts.
- Revisit and write a dedicated design+spec after P1/P2 ship.

## Testing strategy (all phases)
- PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase`; single-engine reconciliation (summary == dashboard == grid) is the invariant every phase must preserve.
- Migrations include data-conversion tests; backfills are reversible/idempotent where possible.
- Allowed pre-existing failures only: `MobileSyncApiTest > sync push applies leave apply mutation`; `NavigationRoutesTest > any authenticated user can access organization directory`. No new failures.

## Rollout
- Each phase ships independently behind existing `attendance.view`/policy gates; no big-bang.
- New migrations run on dev MySQL `dbedc_guardian` and noted for prod; one consolidated `public/build` only when frontend changes (P2 summary columns).

## Non-goals
- No payroll/pay-amount computation (accounts run payroll externally).
- No algorithmic Hijri/lunar holiday computation.
- No multi-site holiday scoping build now (schema-ready only).
- No hourly/partial-hour leave (half-day is the floor).
