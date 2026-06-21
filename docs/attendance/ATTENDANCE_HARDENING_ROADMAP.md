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

---

## 🔴 Phase A — Critical correctness (do next, highest money/compliance impact)

- **#2 Attendance → Payroll bridge (NOT IMPLEMENTED).** `Payroll` has `present_days`,
  `absent_days`, `overtime_hours`, `overtime_amount` but nothing populates them from
  attendance — no `PayrollService`, command, controller, or routes. The engine's
  `ot_minutes`/`double_time_minutes` reach the report API and then go nowhere. Build a
  `PayrollService` that reads LOCKED-period `DayAttendance` results and writes payroll
  inputs (present/absent/OT/double-time/leave). Gate on a locked/frozen period (pipeline §7).
- **#3 Collapse dual "hours" math to the single engine.** `AttendanceReportService::
  getUserAttendanceData()` computes displayed `total_work_hours`/status/remarks with its own
  legacy `calculateTotalMinutes()` and only uses the engine for additive buckets — so the
  grid's headline hours ignore rounding/break/grace while the buckets next to them don't.
  Make the report derive ALL displayed numbers from `AttendanceStatusService`. (Reports == engine.)
- **#4 Dashboard stats reconcile with the grid.** `calculateMonthlyStats()` calls the engine
  with NO policy/holiday/leave, and estimates absent man-days with `daysPassed * 2/7` against a
  global potential figure (ignores per-employee rosters, mid-month joiners/leavers). Recompute
  per-employee via the engine with the same policy the grid uses.
- **#5 Leave status filtering (in progress).** The grid marks a day "On Leave" for ANY
  overlapping leave (pending/rejected included), masking absences, while stats count
  approved-only. Filter the grid to APPROVED leaves. → being fixed now.

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

## Cross-cutting (thread through all phases)
Immutable audit (who/what/when/IP), real-time anomaly alerts to approvers, reports==engine,
per-site timezone+DST, idempotent replay-safe device sync.

---

### The through-line
The most expensive defects are **wrong timestamps in (#1 — fixed)** and **no payroll bridge +
dual math out (#2/#3/#4)**. The compute engine is strong but wasted while inputs are wrong and
outputs are recomputed multiple ways and never reach payroll. Phase A is the priority.
