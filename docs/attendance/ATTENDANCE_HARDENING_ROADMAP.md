# Attendance Hardening Roadmap (post-Phase-3.1)

Source: gap analysis against the "10/10 attendance pipeline" (capture → validate → resolve
context → compute engine → exceptions → approval → locked period → payroll), with
cross-cutting concerns (immutable audit, real-time anomaly alerts, reports == engine,
per-site timezone/DST, idempotent replay-safe device sync).

Status legend: ✅ done · 🔭 planned. Severity mirrors the analysis.

---

## ✅ Already fixed (this work)
- **#1 Biometric/device punches now record the REAL device time** (not server processing
  time). `AttendancePunchService::resolvePunchTime()` honours `punch_time` for trusted
  `source=biometric|device`; manual/web punches keep server time (no back-dating).
  Commit family around `3799c28b` + `BiometricPunchTimeTest`. **This also repairs idempotency
  (#9):** `isDuplicatePunch` now compares device time against rows actually stamped with device time.
- Approval routing collapsed to a **single real approver** (manager → HR/admin); the
  arbitrary department-colleague "level 2" heuristic is gone.
- Two-stage **shift swap with counterparty consent** (peer accept → manager approve).
- Super-admin frontend gate bypass; Approvals history filter; swaps consolidated.
- **Shift swap/cover redesign (roster-driven).** Replaced the broken free-text swap: `RosterService::applySwap` now does the correct roster rewrite (2-cell **cover** / 4-cell **swap**) instead of an in-place shift-id exchange that never offloaded the requester's day; `store` requires a same-department counterparty and validates roster availability (open/anonymous path removed); new `swaps/eligible` + `swaps/counterparty-roster` endpoints feed roster-driven pickers; the request form is now Swap|Cover with shifts chosen from real rosters. Spec/plan: `docs/superpowers/{specs,plans}/2026-06-22-shift-swap-cover-redesign*`.
  - **Follow-ups:** the **mobile app** (separate repo `dbedc-mobile-app`) swap UI must be updated to the new `type` + roster-driven endpoints before its swap screen works against this contract; an **open-shift pool/marketplace** (offer a shift to anyone, claim from a board) remains a possible future option.
- **#3/#4 Single-engine collapse (reports == engine).** `AttendanceReportService` now derives
  EVERY displayed number from one `AttendanceStatusService` pass per employee-day via a shared
  `buildMonthlyDayResults()` + `classifyDay()` (holiday/weekly-off > leave precedence). The grid
  (`getUserAttendanceData`) maps that result to symbol/`total_work_hours`/remarks — legacy
  `calculateTotalMinutes()` deleted, hours now honour policy break/rounding. The dashboard
  (`calculateMonthlyStats`) COUNTS engine statuses per employee-day (present/absent/leave/late/OT)
  instead of the `daysPassed*2/7` estimate, respecting per-employee rosters, `date_of_joining`,
  and the ≤today window; approved leave no longer reduces attendance %. Spec/plan:
  `docs/superpowers/{specs,plans}/2026-06-22-attendance-single-engine-collapse*`.
- **#5 only APPROVED leaves mark "On Leave" in the grid** (pending/rejected no longer mask absence).

---

## ✅ Phase A — Critical correctness — DONE (payroll descoped)

- **#1 device time, #3/#4 single-engine collapse, #5 approved-leave** — all done (see "Already fixed").
- **#2 Attendance → Payroll bridge — DESCOPED.** The owner does **not** run payroll in this app;
  payroll is done by the **accounts** team externally. This system's job is to *capture attendance
  correctly and hand accounts trustworthy numbers/exports*. The `Payroll`/`Payslip` models remain
  dormant scaffolding (no routes/controllers/UI). Do NOT build the bridge unless an in-app payroll
  module is actually commissioned. The replacement deliverable is the **per-employee summary export**
  in Phase B1.

---

## 🟦 Phase B — Accounts-focused correctness & deliverable (NEXT, owner's actual need)

Owner requirement: *monitor attendance and give accounts a per-employee monthly Excel*
(present / absent / leave days / total OT hours / late count). Half-days, mid-month transfers,
and terminations are real for this org. Sequenced B1 → B2 → B3.

**B1 — Accounts deliverable + cheap capture correctness**
- **Per-employee monthly summary report + ONE Excel**: row per employee with
  present / absent / leave / OT hours / late (+ department; consider holiday-worked / weekly-off-worked
  since OT comes from those). Reuses the `buildMonthlyDayResults` engine pass that powers the grid —
  leave-days are engine-derived (already exclude weekends/holidays via precedence). This is THE artifact
  handed to accounts. (Existing exports are a day-by-day calendar grid; this is a new summary.)
- **Capture guards (make standard):** reject future-dated punches; bound device clock-drift
  (biometric `resolvePunchTime`); reject out-before-in. Keeps garbage out of the numbers.
- **Termination gate:** stop counting "Absent" after `Offboarding.last_working_date` (mirror the
  `date_of_joining` joiner clamp already in `calculateMonthlyStats`).

**B2 — Holiday correctness** ✅ DONE (2026-06-23, Phase 1 of the 10/10 initiative)
- ✅ `is_active` honored everywhere + ✅ `annual_fixed` recurrence expanded, via the single new
  `HolidayService::forRange()`; the three ad-hoc holiday queries in `AttendanceReportService`
  (`getHolidaysForMonth`/`getTotalHolidayDays`/`getWeekendDaysCount`) now delegate to it. Lunar/Islamic
  holidays stay one-off (HR enters per year — no auto-Hijri). `recurrence_pattern` now persisted on create.
- *Still open (later):* per-location/department holiday scoping (multi-site only — schema-ready, not built).

**Also landed in Phase 1 (2026-06-23):**
- ✅ **Engine precedence**: `AttendanceStatusService` no-punch order is now `holiday > weekly-off > leave > absent`
  (a rest day is never consumed by leave); `worked_on_leave` conflict flag added.
- ✅ **`employee_id` uniqueness**: dup/NULL backfilled (placeholders) + unique index (migration
  `2026_06_23_000001_enforce_unique_employee_id`, applied to dev MySQL; **run on prod**).
- ✅ **Night-shift overnight web punch-out**: a manual (no-`check_type`) 08:00 punch now closes the prior
  evening's 20:00 night-shift row instead of opening a new one (`processPunchInTransaction` consults
  `findOpenAttendanceToClose`); day-shift workers unaffected. Covered by `NightShiftOvernightPunchTest`.
- Design + plan: `docs/superpowers/specs/2026-06-23-attendance-leaves-holidays-10of10-design.md`,
  `docs/superpowers/plans/2026-06-23-attendance-phase1-holidays-engine-integrity.md`.

**B3 — Leave correctness for the handoff** ✅ DONE (2026-06-25, Phase 2 of the 10/10 initiative)
- ✅ **Half-day leave** (0.5 precision): `is_half_day` + `half_day_session` on `leaves`, decimal `no_of_days`;
  engine `AttendanceStatusService::resolve` takes a per-day leave **fraction** (0/0.5/1.0)+session; half-day
  reconciles as 0.5 leave + 0.5 present (worked) or 0.5 leave + 0.5 absent (no-show); dashboard + per-employee
  summary count fractionally (`numify` keeps whole counts as int).
- ✅ **Paid/unpaid flag** (`LeaveSetting.is_paid`): per-employee summary + export split **Paid Leave vs LWP**.
- ✅ **Server-side `no_of_days`** via `LeaveDayCalculator` (roster working-days − weekly-off − holidays, 0.5 for
  half-day); client `daysCount` no longer trusted; balance check now float (no half-day truncation).
- ✅ **Status normalization** to canonical `{pending,approved,rejected,cancelled}` + data migration; all write-paths updated.
- ✅ **Leave audit trail** (`leave_audit_logs`, immutable) on create/update/status/approve/reject/delete.
- ✅ **Validation hardening** (half-day single-date + session) + **relational FK** `leaves.user_id → users` (cascade).
- ✅ `getLeaveCountsArray` approved-only filter. Spec/plan:
  `docs/superpowers/{specs/2026-06-23-attendance-leaves-holidays-10of10-design.md,plans/2026-06-25-attendance-phase2-leave-correctness.md}`;
  ledger `.superpowers/sdd/progress-phase2.md`. **5 migrations applied to dev MySQL — run on prod** (see plan's deploy section).
- *Next:* Phase 3 (leave balance/accrual ledger), Phase 4 (holiday module hardening).

**Phase 3 — Leave balance/accrual ledger** ✅ DONE (2026-06-25, live-verified)
- ✅ **Append-only immutable `leave_ledger`** (signed transactions; balance = running sum; `UPDATED_AT=null`). Replaces the dead `leave_accruals`/`leave_carry_forwards` scaffolding.
- ✅ **Configurable per-type policy** on `LeaveSetting` (`accrual_method`, `accrual_rate`, `probation_months`, `prorate_on_join`, `carry_forward_cap`, `carry_expiry_months`, `is_encashable`, `allow_negative`).
- ✅ **Services**: `LeaveLedgerService` (post/balance/available/consume/reverse/isTracked), `LeaveAccrualService` (idempotent annual grant + monthly accrual w/ probation+proration), `CarryForwardService` (cap + expiry), `LeaveEncashmentService` (recorded, no payout).
- ✅ **Lifecycle**: approval posts `consumption`; reject/cancel/delete/edit reverses; apply enforced via `available()` (tracked-only — dormant until seeded). Removed dead `getRemainingDays`.
- ✅ **Commands**: `leave:seed-ledger` (pro-rated cut-over, idempotent), `leave:accrue`, `leave:grant-annual`, `leave:carry-forward`, `leave:expire-carried`, `leave:reconcile-ledger` (drift check); scheduled in Kernel; retired `leave:accrue-monthly`/`leave:reset-annual`.
- ✅ **UI**: `/leave-balances` API + ledger-backed balance cards (Entitled / Accrued / Taken / Remaining / Carried). Live-verified for emam (Casual 7 taken/3 left; Earned 6 accrued).
- 2 migrations on dev MySQL — **run on prod**; then review per-type policy + run `leave:seed-ledger {year}` (dry-run first) + enable schedule. Spec: `docs/superpowers/specs/2026-06-25-leave-balance-accrual-ledger-design.md`; plan: `docs/superpowers/plans/2026-06-25-leave-balance-accrual-ledger.md`; ledger `.superpowers/sdd/progress-phase3.md`.

### Initiative status: Attendance · Leaves · Holidays = industry-standard 10/10 (single-site)
Phases 1–4 + Phase 3 complete. Attendance engine + holiday module + leave (fractional, audited, balance-tracked) all reconcile through one schedule layer + one engine pass, with consistent immutable audit and enforced relational integrity. Documented non-goals remain: in-app payroll (external), algorithmic Hijri (HR enters lunar dates), multi-site holiday scoping (schema-ready).

**Phase 4 — Holiday module hardening** ✅ DONE (2026-06-25, incl. live-verified UI)
- ✅ **Immutable `holiday_audit_logs`** trail on create/update/delete/restore/copy_year (mirrors leave/attendance audit).
- ✅ **Soft-delete** holidays (`deleted_at`) — recoverable; `restore` endpoint; `HolidayService::forRange` transparently excludes trashed.
- ✅ **Copy-last-year bulk import** (`HolidayImportService::copyYear`, `holidays-copy-year` route) — clones a year's holidays, overlap-safe (whereDate interval test), Feb-29 clamped; removes per-year manual re-entry drudgery (HR still edits lunar dates).
- ✅ **Validation**: `recurrence_pattern` constrained to `{none, annual_fixed}`; `is_recurring=false` stores null (coherent).
- ✅ **UI**: `Copy year` header action + `CopyYearForm` dialog (from/to year, skip-overlap callout) + Annual/Inactive badges in `HolidayTable`. Live-verified via Playwright (emam). Restore is endpoint-only (a trashed-holidays view is a separate enhancement).
- 2 migrations applied to dev MySQL (`holiday_audit_logs`, `holidays.deleted_at`) — **run on prod**. Plan: `docs/superpowers/plans/2026-06-25-attendance-phase4-holiday-hardening.md`; ledger `.superpowers/sdd/progress-phase4.md`.

## 🟠 Phase B — High (fraud / integrity / multi-site)

- **#6 GPS-spoofing defense.** `PolygonLocationValidator` only ray-casts the reported point.
  Add `mock_location` rejection, an accuracy floor, teleport/speed sanity vs last punch, and
  (optional) device attestation. (pipeline §2 validate)
- **#7 Half-day / short leave modeling.** Leave is whole-day only; a half-day-leave +
  half-day-work day isn't reconciled into hours/status.
- **#8 Termination / pre-join guard.** Nothing blocks attendance after termination or before
  join date; the biometric path resurrects `withTrashed()` users and auto-creates soft-deleted
  placeholders. Add a guard in capture.
- **#10 Approval hardening.** Now single-level (good), but add: explicit **no-self-approval**
  guard (a manager whose `report_to` is themselves), optional **multi-level + SLA escalation**
  (pipeline §6), and audit of every decision.
- **#11 Timezone / DST model.** Everything is server-local `Carbon::now()`. Add per-site
  timezone + DST-aware computation for multi-site / DST-crossing overnight shifts. (cross-cutting)

## 🟡 Phase C — Medium (defense-in-depth / UX)

- **#12 Clear React-Query cache on logout.** No `queryClient.clear()`/`removeQueries` on
  logout; `attendanceStore.clearAttendanceData()` is never called; keys aren't namespaced by
  user. Isolation currently relies on Inertia's full page reload — accidental, not by design.
  Add an explicit clear on logout so a future client-side logout can't bleed User A's data to User B.
- **#13 Stronger photo proof.** Optional base64-over-JSON thumbnail (≤10000 chars); add
  liveness/hash/EXIF checks or drop the pretense.
- **#14 Active missing-punch handling.** Auto-punch-out exists, but a missing punch otherwise
  just becomes an open "Not Punched Out" row; auto-push anomalies into the approver/exception
  queue (pipeline §5) instead of relying on manual regularization.

## 🟠/🟡 Phase B/C — Holiday / Leave / Weekend relationship gaps (audit findings)

**Holidays ↔ attendance (model standard, integration partial):**
- Recurrence (`is_recurring`/`recurrence_pattern`) is NEVER applied — holidays match literal
  `from_date/to_date` year/month, so annual recurring holidays don't recur. Apply recurrence.
- `is_active` not filtered in `getHolidaysForMonth()` — inactive holidays still count.
- No location/department scoping — holidays are global; add per-location/calendar scoping for multi-site.

**Leaves ↔ attendance (not comprehensive):**
- Whole-day only — no half-day/hourly leave (#7); a half-day-leave + half-day-work day isn't reconciled.
- `no_of_days` is CLIENT-supplied (`$data['daysCount']`) — compute server-side, excluding
  weekends + holidays (standard), and stop trusting the client.
- No paid/unpaid flag on `LeaveSetting` — payroll (#2) can't tell which leave deducts pay.
- Link is indirect (date overlap, no FK); engine gets only `isOnLeave` (now approved-only — fixed #5).

**Weekends / weekly-offs (per-roster good, reporting inconsistent):**
- Sources: global `AttendanceSetting.weekend_days` + per-employee roster off-days (good, standard).
- Reporting misuses it: monthly stats estimate weekends as `daysPassed*2/7` (#4); grid shading uses
  only the GLOBAL weekend set (ignores roster off-days); leave day-counts ignore weekends.
- No first-class weekend-work → comp-off/OT rule.

## Cross-cutting (thread through all phases)
Immutable audit (who/what/when/IP), real-time anomaly alerts to approvers, reports==engine,
per-site timezone+DST, idempotent replay-safe device sync.

---

### The through-line
The most expensive defects are **wrong timestamps in (#1 — fixed)** and **no payroll bridge +
dual math out (#2/#3/#4)**. The compute engine is strong but wasted while inputs are wrong and
outputs are recomputed multiple ways and never reach payroll. Phase A is the priority.
