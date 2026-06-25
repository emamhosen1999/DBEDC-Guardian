# Attendance · Leaves · Holidays — 10/10 Industry-Standard Design

**Date:** 2026-06-23 (revised 2026-06-25 — Phase 1 done; folded in module-hardening scope: expanded Phase 2, new Phase 4 holiday hardening, cross-cutting validation/audit sweep)
**Status:** Approved (design). Overarching design for a phased initiative; each phase gets its own implementation plan. **Phase 1 COMPLETE & merged** (2026-06-23).
**Source:** Audit of 2026-06-23 (this session) + architectural decisions confirmed with the owner; module-hardening scope confirmed 2026-06-25.
**Related:** `docs/attendance/ATTENDANCE_HARDENING_ROADMAP.md` (Phases B2/B3 map onto Phase 1/2 here).

## Goal

Bring the leave/holiday **integration** and a few data-integrity gaps up to the same standard as the (already strong) attendance engine, so every surface — grid, dashboard, accounts summary — derives correct numbers from one schedule layer and one engine pass. The compute core is ~9/10; this initiative lifts leave/holiday integration from ~6/10 to 10/10.

## Unifying principle (already half-built)

- **One schedule layer** (`roster_days` ← shift assignments ← rotation patterns ← global default) defines *working vs rest day per employee per date*. Weekly-offs (fixed, varied, rotating) live here — never as leave records.
- **One pure engine pass** (`AttendanceStatusService`) derives status / worked / late / OT per employee-day; `AttendanceReportService::buildMonthlyDayResults` + `classifyDay` feed every surface.
- **Leaves and holidays are clean inputs** to that engine — not duplicated or ad-hoc logic. This design removes the remaining places where they aren't.

### Relational model & wiring (standard)

The three modules relate two ways, and conflating them is the classic mistake:

- **Ownership → physical FK.** A leave is owned by a user and typed by a leave-setting; an audit row is owned by its subject. These get real, enforced foreign keys: `leaves.user_id → users.id` (cascade), `leaves.leave_type → leave_settings.id`, `leave_audit_logs.leave_id → leaves.id`, `attendance_audit_logs.attendance_id → attendances.id`, `holidays.{created_by,updated_by} → users.id`. Eloquent relationships are defined both directions and used instead of raw `DB::table` joins where a relationship exists. (Today `leaves` carries **no** enforced FKs — only a plain `user_id` index — so this is real hardening, done as part of Phase 2; verified 0 orphan/null `user_id` rows on prod-shaped dev data, so enforcement is safe.)
- **Temporal overlay → calendar-layer join (NOT a per-row FK).** Whether a given (employee, date) is *present / on-leave / holiday / weekly-off* is **derived** by the engine overlaying the roster (working vs rest) + `HolidayService::forRange` (date intersection) + the approved leave overlapping that date. You do **not** stamp `attendance.holiday_id`/`attendance.leave_id` — a day is a computed classification, not an owned child of one holiday or leave row. This date-range intersection in `buildMonthlyDayResults` is the standard HRIS pattern and is already how the single engine works; Phase 2 only enriches the leave side of that overlay from a boolean to a fraction. Keeping the overlay computed (not physically linked) is what lets one engine pass stay the single source of truth.

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
| Leave audit | **Immutable leave audit trail** (who/what/when/IP) mirroring `AttendanceAuditService`/`attendance_audit_logs`. None exists today — every create/update/status-change/delete is logged. |
| Validation integrity | Cross-cutting sweep: validation rules must match real column types/canonical values (the Phase-1 final review caught a stale `employee_id => integer` rule in `ProfileValidationService`). Leave validation hardened (canonical status enum, half-day fields, day-count no longer client-trusted). |
| Holiday hardening | Holiday **model** brought to attendance's standard (Phase 4): audit trail, soft-delete, copy-last-year/bulk import (manual per-year lunar entry stays, but the drudgery is removed), validation robustness, admin UI. |
| Relational wiring | Attendance · Leave · Holiday wired the **standard** way: (1) physical FKs only where true parent-child ownership exists (`leaves.user_id`→`users`, `leaves.leave_type`→`leave_settings`, `*_audit_logs.{leave,attendance}_id`, `holidays.{created,updated}_by`); (2) holiday/leave → attendance related through the **calendar/schedule layer** (per-(user,date) date-range intersection in the engine), NOT per-row FKs — the industry standard for temporal overlays. No `attendance.leave_id`/`attendance.holiday_id` columns (a day is *derived*, not *owned*). |

---

## Phase 0 (cross-cutting, in flight) — Weekend → roster migration

Already underway this session (Emam Hosen converted; the per-employee off-day list approved by the owner). Remaining:
- Bootstrap each approved employee's roster (their weekly-off + Day/Night 9–5 shift), forward from Jun 2026; back up + delete their "Weekend" leaves from Jun 2026 onward (keep pre-June history).
- Decide off-days for the ~10 employees with no recent "Weekend" leaves (owner input).
- Retire the **"Weekend" `LeaveSetting`** type once all employees are rostered (stop creating Weekend leaves; the engine precedence fix in P1 also neutralizes any residual Friday Weekend-leaves immediately since Friday is the global off).
- This is a **data migration** (a reversible artisan command with a dry-run), not application code; tracked separately from P1/P2/P3 code.

## Phase 1 — Holiday correctness + Engine precedence + Data integrity ✅ DONE (2026-06-23)

**Plan:** `docs/superpowers/plans/2026-06-23-attendance-phase1-holidays-engine-integrity.md` · **Ledger:** `.superpowers/sdd/progress-phase1.md`.
**Landed:** `HolidayService::forRange` (active-only + `annual_fixed` recurrence, year-boundary + Feb-29 safe); the 3 ad-hoc holiday queries delegated; engine no-punch precedence `holiday > weekly-off > leave > absent` + `worked_on_leave` conflict flag; `employee_id` unique migration (applied to dev MySQL, **run on prod**); night-shift overnight web punch-out fix. Full attendance suite 245 pass. Final-review fix: `ProfileValidationService` `employee_id` `integer`→`string|max:50` (the validation-integrity seed for the cross-cutting sweep below).

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

## Phase 2 (NEXT) — Leave correctness

**Plan to follow:** `docs/superpowers/plans/2026-06-25-attendance-phase2-leave-correctness.md`.

### 2a. Model & migration
- `leaves`: add `is_half_day` (bool, default false) + `half_day_session` (enum `first_half|second_half`, nullable). `no_of_days` widened to **decimal(5,1)** so 0.5 persists. Add `created_by`/edit provenance only if not derivable from the new audit log (prefer the audit log).
- `leave_settings`: add `is_paid` (bool, default true); add `symbol`, `is_earned` to `$fillable` (columns already exist — see `create_leave_settings`/`add_is_earned` migrations).
- Normalize `leaves.status` to `{pending, approved, rejected, cancelled}` + data migration converting existing rows (dev DB currently holds `Approved`,`New`,`Pending`; map `Approved→approved`, `New→pending`, `Pending→pending`, `Declined/Rejected→rejected`, case-insensitively). Update the model `status_color` accessor, the engine loader filter (`AttendanceReportService` `where('status','approved')`), `LeaveValidationService` enum, `LeaveCrudService` (`'new'`/`'declined'` literals), and the controller bulk-status `in:` rules + `'declined'` call-sites to the canonical values.

### 2b. Server-side day-count
- New `LeaveDayCalculator` (injects the Phase-1 `HolidayService` + the `ScheduleResolver` contract): given user + `[from,to]` + half-day flag/session, iterate the range, count only the employee's **roster working-days** (`ShiftSchedule::isWorkingDay`, excluding holidays from `HolidayService::forRange`), apply 0.5 when `is_half_day`. `LeaveCrudService::createLeave/updateLeave` compute `no_of_days` from this — **client `daysCount` is no longer trusted** (kept only as an optional display hint, never written). Balance enforcement (`getRemainingDays`) consumes the server figure.

### 2c. Engine integration (fraction, not bool)
- `AttendanceStatusService::resolve` gains `float $leaveFraction = 0.0` + `?string $leaveSession = null` (replacing the `bool $isOnLeave` meaning: `>0` ⇒ on leave). `DayAttendance` carries `leave_fraction`/`leave_session`. `buildMonthlyDayResults` resolves the per-day fraction (0 / 0.5 / 1.0) + session from the approved leave overlapping that date and passes it in.
- **Reconciliation rules (locked):** on a working day — `fraction 1.0` no punch ⇒ ON_LEAVE (1.0 leave); `fraction 0.5` no punch ⇒ 0.5 leave + **0.5 absent** (the worked half was a no-show); `fraction 0.5` + punch ⇒ 0.5 leave + 0.5 present; `fraction 1.0` + punch ⇒ ON_LEAVE + `worked_on_leave` conflict (unchanged). Holiday/weekly-off still outrank leave (fraction ignored on rest days).
- `classifyDay` and the accounts summary count **fractional** leave days; the summary gains **paid-leave days vs LWP days** columns from `LeaveSetting.is_paid`. `getLeaveCountsArray` adds a **status filter** (approved-only, matching the grid) so the leave-type tallies reconcile with the engine.

### 2d. Leave audit logging (NEW — none exists today)
- Mirror `AttendanceAuditService` + `attendance_audit_logs`: new `leave_audit_logs` table (immutable, `UPDATED_AT = null`), `LeaveAuditLog` model, `LeaveAuditService::record(action, leaveId, before, after, reason, request)`. Wire `create`/`update`/`status-change`/`approve`/`reject`/`delete` in `LeaveCrudService` + `LeaveApprovalService` to log who/what/when/IP. No read UI required in P2 (data captured; surfacing is a later UI task).

### 2e. Validation hardening (NEW)
- `LeaveValidationService`: canonical `status` enum; add `isHalfDay`/`halfDaySession` rules (session `required_if:isHalfDay,true`, half-day forces `fromDate == toDate`); drop the authoritative role of `daysCount` (accept but ignore, or remove). Reject half-day spanning multiple dates. Keep the existing `before:+1year` / `min:5 reason` guards.

### Phase 2 tests
- Half-day leave (first_half) + afternoon punch → 0.5 leave + 0.5 present; half-day no-punch → 0.5 leave + 0.5 absent; full-day leave unchanged; `LeaveDayCalculator` excludes weekly-offs + holidays and applies 0.5; status normalization data-migration converts `Approved/New/Pending`; paid/LWP split in summary; `getLeaveCountsArray` approved-only; single-engine reconciliation (summary == dashboard == grid) holds with fractions; every CRUD/approval path writes a `leave_audit_logs` row; validation rejects multi-date half-day.

## Phase 3 — Leave balance/accrual ledger

**Full spec:** `docs/superpowers/specs/2026-06-25-leave-balance-accrual-ledger-design.md` (written 2026-06-25; owner direction: standardize to industry-standard 10/10 — full carry-forward + encashment, correct pro-rated seeding, configurable per-type policy, append-only immutable ledger). Original sketch retained below for history:
- A `leave_ledger` (entitlement opening → periodic accrual → consumption from approved leaves → carry-forward cap → optional encashment), per employee per leave-type per period.
- Balances become first-class/auditable, replacing the implicit `LeaveSetting.days`. Consumes Phase-2 fractional day-counts.
- Note: `leave_accruals` + `leave_carry_forwards` tables + `AccrueMonthlyLeaves`/`ResetAnnualLeaves` commands already exist as partial scaffolding — Phase 3 reconciles/replaces them with the ledger, it does not start from zero.
- Revisit and write a dedicated design+spec after P1/P2 ship.

## Phase 4 (NEW) — Holiday module hardening *(sketch; full plan when reached)*

Bring the holiday **module** up to attendance's standard now that `HolidayService` (P1) made its *integration* correct. Locked decision stands: `annual_fixed` auto-expand + lunar/Eid entered manually per year (no auto-Hijri).
- **Audit trail + soft-delete:** `holiday_audit_logs` (mirror the leave/attendance audit) + `softDeletes()` on `holidays` so a deleted holiday is recoverable and the change is attributable.
- **Copy-last-year / bulk import:** an admin action that clones a prior year's holidays into the new year (pre-filling Gregorian/national days; HR then edits the lunar dates per that year's moon-sighting). Removes the per-year manual re-entry drudgery without introducing unreliable auto-Hijri.
- **Validation robustness:** `from_date <= to_date`, no silent overlap duplicates, `recurrence_pattern` constrained to `{none, annual_fixed}`, `is_active`/`is_recurring` coherent.
- **UI:** holiday admin screen reflects recurrence + active state + the copy-year action.

## Cross-cutting — Validation-rule integrity & audit consistency *(threads through P2/P4)*

- **Validation-rule integrity sweep:** the P1 final review caught a stale `employee_id => integer` rule (column is now varchar). Sweep `*ValidationService` / FormRequests for rules that no longer match real column types or canonical enum values; the leave status normalization (2a) is the first beneficiary.
- **Audit consistency across HR modules:** attendance has an immutable audit log; leave (P2) and holiday (P4) get the same. Standardize on the `actor_id / action / before / after / reason / ip`, `UPDATED_AT = null` shape so all HR audit logs read identically.

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
