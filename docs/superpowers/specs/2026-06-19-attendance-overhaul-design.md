# Attendance Module Overhaul — Design & Roadmap

**Date:** 2026-06-19
**Owner:** Emam Hosen
**Status:** Approved (design); pending spec review → implementation plan (Phase 0 + 1)
**Stack:** Laravel 11, Inertia v2, React 18 (Radix Themes), MySQL, Pest/PHPUnit, Vite. Single-tenant. Timezone: Asia/Dhaka (store UTC, render app TZ).

## 1. Goal
Evolve the attendance module from a single-fixed-day system into a comprehensive, correct, enterprise-grade platform: shift/roster aware, workflow-driven, policy-configurable, analytics-rich, and payroll-ready — without discarding the strong parts already built (multi-config validation types, biometric ADMS, capture channels).

## 2. What exists today (keep & build on)
- **Capture:** web + mobile punch, manual/bulk mark-present, biometric (ZKTeco ADMS push, devices, templates, commands, att-logs).
- **Validation types (good):** geo-polygon, WiFi/IP, route-waypoint, QR, biometric — multi-config + `validation_mode` (any/all) + per-employee assignment via `AttendanceValidatorFactory`.
- **Views:** admin daily timesheet, monthly calendar, employee "My Attendance", daily/monthly stat overviews, Team Locations map.
- **Settings (global singleton):** office hours, break, late/early/overtime thresholds, weekend days, auto-punch-out.
- **Plumbing:** Excel/PDF exports, reminder job, rate-limiting, holiday + leave integration.

## 3. Gap analysis (brutal)
**Tier 1 — structural:** no shifts/rostering (whole system assumes one fixed 09:00–18:00 day); no real overtime system; no regularization/correction workflow; no policy engine (single global settings row); no attendance approval workflows.
**Tier 2 — correctness/trust:** hardcoded 09:00 late logic; night/overnight shifts broken (`TIME` columns + `addDay()` heuristics, no unique `(user_id,date)` guard, no transaction/lock on punch); forgotten punch-out handling weak; anti-spoofing toothless (GPS jump only logged); audit trail not enforced.
**Tier 3 — experience/insight/integration:** thin reporting; no payroll-ready output; broken/absent notifications (Firebase misconfigured); thin self-service; global-only holidays/weekends; mobile offline/face gaps; thin test coverage.

## 4. Roadmap (6 phases, each independently shippable)
| Phase | Theme | Unlocks |
|---|---|---|
| **0** | Foundation & correctness | datetime punches, transactions/idempotency, enforced audit, single status engine, policy-driven (not hardcoded) status |
| **1** | Shifts & rostering | shift defs (incl. night), rotation patterns, effective-dated assignment, materialized roster + overrides, swaps; shift-aware status |
| **2** | Workflows | regularization + OT request/approval (leave-style chains), comp-off/TOIL |
| **3** | Policy engine | per-dept/designation/employee, effective-dated rules, grace tiers, half-day/short |
| **4** | Analytics & payroll-ready output | scorecards, trends, absenteeism, muster, compliance + payroll-ready data/exports |
| **5** | Notifications, anti-spoof, mobile | alerts/escalations, mock-GPS/device-binding/face-match, offline sync |

This spec details **Phase 0 and Phase 1**. Phases 2–5 get their own specs.

---

## 5. Phase 0 — Foundation & Correctness

### 5.1 Schema / data integrity
- **Punch times → full `DATETIME`.** Migrate `attendances.punchin`/`punchout` (currently `TIME`) to nullable `DATETIME` (UTC). Backfill existing rows by combining `date` + time. Remove the `if (punchOut < punchIn) addDay()` heuristics across `AttendanceController`, `Api/V1/AttendanceController`, `AttendanceReportService` once datetimes are authoritative.
- **Indexes/constraints:** composite index `(user_id, date)` on `attendances`; keep multiple punch rows per day allowed (punch pairs), so no unique constraint on `(user_id,date)` — instead an **idempotency guard** in the punch service (reject identical punch within N seconds; reject open-punch when one already open).
- **Concurrency:** wrap punch read-decide-write in `DB::transaction` + `lockForUpdate` on the user's open punch row.
- **Timezone:** persist UTC; serialize a plain `YYYY-MM-DD` business `date` (app TZ) so the client never mis-buckets (fixes the observed UTC date-key issue).

### 5.2 Status engine (keystone)
`App\Services\Attendance\AttendanceStatusService` — pure, deterministic.
- **Input:** `userId`, `date`, the day's punch rows, resolved shift (Phase 1; Phase 0 uses the global office time as the "default shift"), policy (Phase 0 = global settings), holidays, leaves.
- **Output (`DayAttendance` DTO):** `status` enum (`present|absent|late|half_day|short|on_leave|holiday|weekend|day_off`), `worked_minutes`, `late_minutes`, `early_leave_minutes`, `ot_minutes`, `first_in`, `last_out`, `is_complete`, `flags[]` (e.g. `missing_punch_out`).
- **Single source of truth:** daily timesheet, monthly calendar, daily/monthly stats, employee page, and (later) payroll all read from this — deletes the divergent ad-hoc calculations and the hardcoded `09:00`.

### 5.3 Audit trail
- Enforce `attendance_audit_logs` writes on every create/update/correct/delete of attendance: `actor_id`, `action`, `attendance_id`, `before` (json), `after` (json), `reason`, `ip`. Immutable (no update/delete of logs). Surface in admin as a per-record history.

### 5.4 Phase 0 acceptance
- Night shift spanning midnight computes correct worked hours (datetime test).
- Concurrent double-punch produces one consistent result (transaction test).
- Late/absent derived from policy, not literal `09:00` (status-engine test).
- Every admin edit writes an audit row (feature test).
- Existing surfaces (timesheet/calendar/stats/employee) render identical or corrected numbers sourced from the status engine.

---

## 6. Phase 1 — Shifts & Rostering

### 6.1 Data model (new)
- **`shifts`**: `id, name, code, type(enum: fixed|flexible|open), start_time, end_time, crosses_midnight(bool), break_minutes, grace_in_minutes, grace_out_minutes, full_day_minutes, half_day_minutes, min_present_minutes, core_start_time(null, flexible), core_end_time(null, flexible), color, is_active, timestamps`.
- **`shift_rotation_patterns`**: `id, name, code, cycle_length_days, definition(json: ordered array, each item = shift_id | "off"), is_active`.
- **`shift_assignments`**: `id, scope_type(enum: user|department|designation|org), scope_id(nullable for org), shift_id(nullable), rotation_pattern_id(nullable), anchor_date(date, rotation phase origin), effective_from(date), effective_to(date null=open), priority(int), assigned_by, timestamps`. Exactly one of `shift_id` / `rotation_pattern_id` set.
- **`roster_days`** (materialized): `id, user_id, date, shift_id(nullable=off), source(enum: pattern|rule|manual|swap), assignment_id(nullable), note, locked(bool), timestamps`. Unique `(user_id, date)`.
- **`shift_swap_requests`**: `id, requester_id, requester_date, counterparty_id(nullable), counterparty_date(nullable), requested_shift_id(nullable), reason, status(enum: pending|approved|rejected|cancelled), approval_chain(json), approved_by, timestamps`.

### 6.2 Services
- **`ShiftService`** — CRUD for shifts, rotation patterns, assignments (validates effective-date overlaps, single shift/pattern rule).
- **`RosterService`**:
  - `resolveShift(userId, date): ?Shift` with precedence **manual roster override > approved swap > user assignment > designation rule > department rule > org default**; rotation phase computed from `anchor_date` + `cycle_length_days`.
  - `generateRoster(scope, fromDate, toDate)` — materialize `roster_days` for a range (idempotent upsert; never overwrites `locked`/`manual`/`swap` rows).
  - `applySwap(swapRequest)` — on approval, write `roster_days` overrides with `source=swap`.
- Both feed **`AttendanceStatusService`** (Phase 0): `resolveShift()` replaces the "default shift," making late/grace/absent/half-day/OT-base shift-aware. Absent = a `roster_days` working day with no punch (off/weekend/holiday excluded).

### 6.3 UI
- **Settings → Shifts:** shift definitions CRUD; rotation patterns builder (visual cycle).
- **Roster tab** on `/attendance` (new tab beside Daily/Monthly/Settings): month grid (employees × days) showing shift codes/colors; assign by employee/dept (effective-dated); per-cell manual override + off-days; "Generate roster" for a range; filters by department.
- **Swap approvals** list (admin) + statuses.
- **Employee self-service:** "My Roster" section on `/attendance-employee` (upcoming shifts) + "Request swap".
- Reuses existing Radix table/calendar patterns; small focused components (`RosterCalendar`, `ShiftForm`, `RotationPatternForm`, `SwapRequestForm`).

### 6.4 Phase 1 acceptance
- `resolveShift` returns correct shift for: fixed assignment; rotation pattern on cycle day N; manual override; approved swap; falls back through designation→department→org default.
- Night shift via roster computes hours across midnight.
- Absent only on scheduled working days; off/weekend/holiday never "absent".
- Late uses the resolved shift's `start_time` + `grace_in_minutes`, not global office time.
- Roster generation is idempotent and preserves manual/swap/locked days.
- Swap approval rewrites both parties' `roster_days`.

---

## 7. Cross-cutting principles
- **One status engine** consumed everywhere; controllers stay thin; services small and independently testable.
- **Effective-dated** config (assignments now; policies in Phase 3) — never destructive edits to history.
- **TDD**: each service method and resolution rule gets feature/unit tests before implementation.
- **Backward compatibility:** until Phase 1 ships, Phase 0's status engine treats the global office time as a single default shift, so current behavior is preserved but correct.

## 8. Out of scope (this spec)
- Phases 2–5 (workflows, policy engine, analytics/payroll, notifications/anti-spoof/mobile) — separate specs.
- Building a payroll module (we only emit payroll-ready data later, in Phase 4).
- Multi-tenancy (app is single-tenant).

## 9. Success criteria
- Night/rotating shifts produce correct, auditable attendance.
- Late/absent/half-day/OT-base are shift- and policy-derived, from one engine, with zero hardcoded times.
- Admins can define shifts/patterns, roster a team (with overrides + swaps), and trust the numbers; employees can see their roster.
- Full test coverage for the status engine and roster resolution.
