# Attendance Phase 3 — Policy Engine (Design Spec)

**App:** Aero-Enterprise-Suite (DBEDC Guardian) · Laravel 11 + Inertia v2 + React 18 (Radix Themes) · MySQL · TZ Asia/Dhaka · single-tenant.
**Status:** Design approved 2026-06-20. Phases 0 (foundation), 1 (shifts & rostering), 2 (workflows) are shipped. This spec defines the full **industrial policy engine** vision and decomposes it into buildable sub-phases; **Phase 3.0 (Policy Core)** is the first implementation plan.
**Prerequisite reading:** `docs/attendance/ATTENDANCE_MODULE.md` (esp. §3 engine, §5 rostering, §13 roadmap); Phase 2 plan `docs/superpowers/plans/2026-06-20-attendance-phase-2-workflows.md`.

---

## 1. Vision & guiding principles

The roster today is an **interpretation** lens (Phase 1) that now also triggers **workflows** (Phase 2). Phase 3 makes it an **enforcement** lens: per-scope, effective-dated, versioned **policies** decide *how strictly* punches are judged and *how* a work-day is priced (grace, rounding, overtime, breaks, differentials, occurrence points).

Design is grounded in how mature WFM systems (UKG/Kronos, ADP) build pay-rule engines, and in US labor-compliance reality (FLSA, California daily/7th-day OT, meal/rest premiums, and the legal constraints on no-fault attendance-point systems).

**Principles (non-negotiable):**

1. **Capture stays permissive — a punch is NEVER blocked.** Even the strictest policy *records* the punch; "restrict" means *it does not count until approved*, not *it is refused*. This preserves the module's never-lose-a-punch / audit-integrity stance and avoids lost time data.
2. **One engine, two integration points.** Policy is applied (a) at **capture** (`AttendancePunchService` — warn/flag/restrict) and (b) at **compute** (the pure `AttendanceStatusService` — rounding, grace tiers, breaks, OT, differentials, points). Compliance is applied *at the point of capture, not bolted on after the fact*, and recomputed deterministically.
3. **Evolving by construction.** The engine is a **registry of single-responsibility `RuleEvaluator`s** (strategy pattern). New rule types plug in without modifying the engine. Each later sub-phase only *registers new evaluators*.
4. **Effective-dated + versioned + simulatable.** Policies have versions with effective dates. A **dry-run simulation** shows the impact of a draft version over historical punches *before* activation. Activation triggers a bounded, idempotent, **audited recompute** of affected days.
5. **Compliance-first.** Protected absences (FMLA/ADA/medical/approved leave) must be structurally incapable of accruing attendance points; OT must distinguish FLSA-qualified vs state daily/double-time as separate buckets. These are designed in, not optional.
6. **Reuse Phase 2.** Flagged/restricted-punch exceptions and points disputes flow through the existing multi-level `AttendanceApprovalService` and the approvals inbox. Audit via the existing `attendance_audit_logs`.
7. **YAGNI per sub-phase.** The full catalog is documented here; each sub-phase ships a coherent slice. Phase 3.0 deliberately excludes overtime/breaks/points (3.1/3.2).

---

## 2. Architecture (target / north-star)

```
                          ┌──────────────────────────────┐
   (userId, date) ───────►│        PolicyResolver         │──► PolicyProfile
   effective-dated,       │  user > designation > dept    │     (composed rule configs,
   versioned, scoped      │  > org > global default       │      resolved for that day/version)
                          └──────────────┬───────────────┘
                                         │ PolicyProfile
        ┌────────────────────────────────┼─────────────────────────────────┐
        ▼ capture-time                    ▼ compute-time
 ┌─────────────────────┐           ┌──────────────────────────────┐
 │ AttendancePunchSvc  │           │   AttendanceStatusService     │  (pure, no DB)
 │  consults CAPTURE    │           │   consults COMPUTE rules via  │
 │  rules: warn/flag/   │           │   the RuleEngine registry     │
 │  restrict (records   │           │   ┌──────────────────────┐    │
 │  always; flags excp) │           │   │  RuleEngine           │    │
 └─────────────────────┘           │   │   registry of          │    │
                                    │   │   RuleEvaluator[]:      │    │
                                    │   │   rounding, graceTiers, │    │
                                    │   │   breaks, overtime,     │    │
                                    │   │   differential, points  │    │
                                    │   └──────────────────────┘    │
                                    └──────────────────────────────┘
                                         │ emits
                          DayAttendance (status/minutes/OT buckets/flags) + PolicyEvents (points, premiums)
```

### 2.1 Components

- **`AttendancePolicy` (model)** + **`attendance_policies` table** — effective-dated, scoped, versioned rows holding rule configuration (see §4).
- **`Contracts\PolicyResolver`** + **`DbPolicyResolver`** (container-bound) — resolves `(userId, date[, versionContext])` → **`PolicyProfile`** (a composed, immutable VO of all rule configs in effect). Precedence: user → designation → department → org → global default. Memoized per request (like `DefaultScheduleResolver`). Supports `asOf(date)` and `forVersion(draftId)` (simulation).
- **`PolicyProfile` (VO)** — typed accessors for each rule group (`strictness()`, `graceTiers()`, `rounding()`, `overtime()`, `breaks()`, `differential()`, `points()`). Unset groups return a neutral default that reproduces current behavior (back-compat).
- **`RuleEngine`** — holds an ordered **registry of `RuleEvaluator`** instances. `apply(DayContext): DayAttendance` runs evaluators in a defined order (rounding → breaks → grace/status → overtime → differential → points), each transforming a mutable `DayContext` accumulator. Pure; no DB.
- **`Contracts\RuleEvaluator`** — `supports(PolicyProfile): bool` and `evaluate(DayContext): void`. One responsibility per evaluator; independently unit-tested.
- **Capture enforcement** — a thin `PunchPolicyGuard` consulted inside `AttendancePunchService::processPunch` after identity/location validation: maps `strictness` + window to a capture outcome (`accept` | `warn` | `flag` | `restrict`) — all of which **persist the punch**; `flag`/`restrict` additionally create an exception record.
- **Exceptions & approvals** — flagged/restricted punches become an approvable surfaced in the Phase 2 Approvals inbox ("Punch Exceptions" section), resolved via `AttendanceApprovalService`. Approve → counts; reject → recorded but excluded.
- **Versioning + simulation + recompute** — `PolicySimulationService` (dry-run over a date range, no writes, returns a diff) and `PolicyRecomputeService` (bounded, idempotent, audited recompute on activation).
- **Audit** — policy CRUD/activation + recompute write `attendance_audit_logs` rows (new actions `policy.*`).

### 2.2 Why this shape

Each unit has one job and a narrow interface: the resolver answers "what rules apply"; each evaluator answers "how does *this* rule change the day"; the engine sequences them; capture and compute are the only two call sites. This keeps the status engine pure and lets every rule be reasoned about and tested in isolation — and lets future phases add evaluators without touching existing ones.

---

## 3. Rule catalog (full vision → which sub-phase delivers it)

| Rule group | Behavior | Integration point | Phase |
|---|---|---|---|
| **Strictness** | `warn` (record + warning) / `flag` (record + needs-approval) / `restrict` (record + provisional, doesn't count until approved) vs the shift window ± `outside_window_minutes` | capture | **3.0** |
| **Grace tiers** | Graduated late/early/half-day bands (e.g. 0–10 on-time, 10–30 late, 30+ half-day) replacing single grace | compute | **3.0** |
| **Rounding** | Clock-in/out rounding: nearest-minute / quarter-hour / 7-minute / custom; rounded vs non-rounded grace | compute | **3.0** |
| **Overtime** | FLSA weekly >40 ×1.5; daily >N ×1.5, >M ×2; 7th-consecutive-day; multipliers; pre-authorization tie-in to Phase 2 OT; separate FLSA vs state buckets | compute | 3.1 |
| **Break / meal** | Auto-deduct unpaid meal; minimum break; missed meal/rest **premium** (one hour at regular rate — CA-style) | compute | 3.1 |
| **Attendance points / occurrences** | Point values per event (late tier / early-leave / absent / no-show); rolling **decay** window; progressive thresholds → actions; **protected-absence exemptions** (FMLA/ADA/medical/approved leave never accrue) | compute (post-status) | 3.2 |
| **Shift differential** | Night/weekend premium windows & multipliers | compute | 3.3 |
| **Holiday rules** | Holiday-worked multipliers / special-day classification | compute | 3.3 |
| **Retro-recompute hardening** | Batched, queued recompute on policy activation across large populations | infra | 3.3 |

---

## 4. Data model

### 4.1 `attendance_policies` (Phase 3.0)

Effective-dated, scoped, **versioned**. Mirrors `shift_assignments` scoping + adds versioning.

| Column | Notes |
|---|---|
| `id` | |
| `name` | human label |
| `scope_type` | enum `org|department|designation|user` |
| `scope_id` | nullable (null for `org`) |
| `priority` | int — tie-break within a scope level |
| `effective_from` / `effective_to` | dates (to nullable = open-ended) |
| `version_group_id` | groups versions of the same logical policy (uuid/int) |
| `version` | int, increments per group |
| `status` | enum `draft|active|archived` — only `active` resolves; `draft` is simulate-only |
| `punch_strictness` | enum `warn|flag|restrict` (default `warn`) |
| `outside_window_minutes` | int (default 120) |
| `grace_tiers` | json — ordered bands `[{upto_minutes, outcome}]` for late & early |
| `rounding` | json — `{strategy: none|nearest|quarter_hour|seven_minute, unit_minutes, direction}` |
| `rule_overrides` | json — reserved, forward-compatible bag for 3.1+ rule configs (overtime/breaks/etc.) so later phases need no migration of existing rows |
| `created_by` / timestamps | audit |

Indexes: `(scope_type, scope_id, status)`, `(version_group_id, version)`, `(effective_from, effective_to)`.

A **global default** policy (scope `org`, seeded from `attendance_settings`) guarantees the resolver always returns a profile. `attendance_settings` remains the source for office hours/weekend/auto-punch-out; policies layer on top.

### 4.2 Punch exceptions (Phase 3.0)

Prefer reusing the Phase 2 approval pattern. Two viable shapes (decide in plan):
- **(a) Flag on `attendances`** — add `needs_approval` (bool) + `policy_status` enum `accepted|provisional|rejected` + `policy_exception_reason`; surface provisional rows in the inbox; approve/reject flips `policy_status`. Lightest.
- **(b) `attendance_punch_exceptions` approvable** (mirrors `AttendanceRegularization`, reuses `AttendanceApprovalService`) — cleanest separation, consistent with Phase 2; links to the `attendances` row.

Recommendation: **(a) for 3.0** (a punch is intrinsically the thing being judged; a flag + status on the row is the least machinery), with the inbox section reusing `AttendanceApprovalService::pendingFor`-style queries over provisional rows. Revisit (b) if exception metadata grows.

### 4.3 Later tables (documented, not built in 3.0)

- `attendance_point_events` + `attendance_point_thresholds` (3.2) — occurrence ledger with decay + protected-absence exemption flags.
- Overtime/break configs live in `rule_overrides` json initially (3.1); promote to columns only if query/reporting needs demand it.

---

## 5. Integration points (detail)

### 5.1 Capture — `AttendancePunchService::processPunch`

After the existing validator-factory checks (geo/wifi/qr/biometric), call `PunchPolicyGuard::evaluate(user, punchMoment, resolvedSchedule, policyProfile)`:
- Compute whether the punch is inside the shift window ± `outside_window_minutes`.
- `warn`: persist; attach a non-blocking `warning` to the JSON response (web + `{success,data}` mobile envelope).
- `flag`: persist; set `needs_approval`, `policy_status=provisional`.
- `restrict` + out-of-window: persist; set `needs_approval`, `policy_status=provisional`, reason = "outside permitted window".
- In-window or no policy: `policy_status=accepted`.
**Never returns a hard deny.** Existing dedupe (30s) and rate-limit are unchanged.

### 5.2 Compute — `AttendanceStatusService`

The engine gains an optional `PolicyProfile` parameter (default neutral). The `RosterScheduleResolver` already produces the `ShiftSchedule`; a sibling resolution produces the `PolicyProfile`; both are passed to the engine by callers (daily/monthly/employee surfaces). The engine delegates to the `RuleEngine`:
1. **Rounding** evaluator adjusts first-in/last-out per policy before minute math.
2. **Grace-tier** evaluator classifies late/early/half-day from tiers (fallback: today's single-grace logic when no tiers — guarantees no regression to existing unit tests).
3. (3.1+) breaks → overtime → differential → points evaluators.
Output: the existing `DayAttendance` (status/worked/late/early/OT/flags) plus, later, structured `PolicyEvents` (points, premiums) for downstream payroll/analytics.

Back-compat: an empty `PolicyProfile` ⇒ identical output to Phase 2. This is a hard test requirement.

---

## 6. Versioning, simulation, recompute

- **Versioning:** edits create a new `draft` version in the same `version_group_id`; activating sets it `active` and supersedes the prior active version's `effective_to`. History is preserved (auditable, like roster days).
- **Simulation (`PolicySimulationService`):** run a `draft` (or any version) over a chosen population + date range, *read-only*, returning a per-day diff (before/after status, late/OT minutes, exceptions count). Powers an admin "Preview impact" before activation — the key trust-builder borrowed from UKG/business-rule engines.
- **Recompute (`PolicyRecomputeService`):** on activation, recompute affected `(user, date)` interpretations within the new version's effective range. Idempotent, audited; for large populations, batched/queued (full queueing hardened in 3.3). Recompute only re-derives *interpretation* (status/minutes); it never edits raw punches.

---

## 7. Permissions, UI, API

- **Permissions:** reuse `attendance.settings` (manage policies + run simulation) and `attendance.manage` (approve punch exceptions). No new permission unless governance demands a dedicated `attendance.policies` later.
- **Admin UI (3.0):** a **Settings → Policies** tab (mirrors the Shift Assignments UI): list scoped policies, CRUD with a strictness selector + grace-tier editor + rounding selector, a **version timeline**, and a **"Preview impact"** simulation panel. Radix Themes; `requestJson(method,url,{params|data})`; Inertia props mapped to plain arrays.
- **Approvals inbox (3.0):** add a **"Punch Exceptions"** section (provisional punches) with approve/reject, reusing the Phase 2 inbox + `AttendanceApprovalService`.
- **Employee surfaces:** punch response shows policy warnings; provisional punches render with a clear "pending approval" badge on the employee page and mobile.
- **Mobile parity (per the cross-repo convention):** policy warnings/provisional badges surface via the `{success,data}` `/api/v1` envelope; no policy *management* on mobile (admin-only).

---

## 8. Compliance (must-haves baked into the design)

- **Protected-absence exemption (3.2):** the points engine must consult leave type / approved-leave status and **structurally skip** point accrual for protected categories (FMLA/ADA/medical/sick/approved leave). Configurable exemption list. (Rationale: EEOC obtained a $20M settlement against an employer whose no-fault policy disciplined FMLA-protected absences.)
- **OT buckets (3.1):** FLSA-qualified (weekly >40) and state daily/double-time tracked as **separate line items**, never conflated, ready for payroll export (Phase 4).
- **Meal/rest premiums (3.1):** missed-break premium computed as a distinct pay event, not folded into worked minutes.
- **Auditability:** every policy change, activation, simulation-to-activation, and recompute is audited; raw punches are immutable.

---

## 9. Decomposition / roadmap

| Sub-phase | Scope | Builds on |
|---|---|---|
| **3.0 Policy Core** (this plan) | `attendance_policies` (effective-dated, scoped, versioned) + `PolicyResolver` + `RuleEngine`/`RuleEvaluator` registry + **strictness** (capture) + **grace tiers** + **rounding** (compute) + punch-exception flag & inbox section + **simulation preview** + **Policies admin UI** + audit. Proves the extensible architecture end-to-end. | Phase 1 resolver pattern, Phase 2 approvals/audit |
| **3.1 Pay rules** | Overtime (daily/weekly/double-time/7th-day, FLSA+state buckets) + break/meal auto-deduct & missed-break premiums. New evaluators only. | 3.0 engine; Phase 2 OT/comp-off |
| **3.2 Occurrence engine** | Attendance points/occurrences: event point values, rolling decay, thresholds→actions, **protected-absence exemptions**, disputes via approvals. | 3.0 engine; leave module |
| **3.3 Differentials & hardening** | Shift/weekend differentials, holiday pay rules, queued large-population retro-recompute. | 3.0–3.2 |

Phase 4 (analytics/payroll exports) and Phase 5 (notifications/anti-spoof/mobile) consume policy outputs.

---

## 10. Phase 3.0 scope (what the implementation plan covers)

**In:** migration `attendance_policies` (+ punch-exception flags on `attendances`); `AttendancePolicy` model + factory; `Contracts\PolicyResolver` + `DbPolicyResolver` (bound) + `PolicyProfile` VO; `RuleEngine` + `Contracts\RuleEvaluator` + three evaluators (Rounding, GraceTiers, plus a Strictness/window helper for capture); thread an optional `PolicyProfile` through `AttendanceStatusService` (neutral default = no regression); `PunchPolicyGuard` in `AttendancePunchService`; punch-exception surfacing + approve/reject reusing `AttendanceApprovalService`; `PolicySimulationService` (read-only diff) + endpoint; `PolicyController` (CRUD + activate + simulate) + routes (`attendance.settings`); **Policies admin UI** tab + **Punch Exceptions** inbox section; global-default policy seeder; audit actions `policy.*`. Full PHPUnit coverage (resolver precedence, each evaluator, capture outcomes, back-compat neutrality, simulation diff, API authz). Run migrations on MySQL `dbedc_guardian`.

**Out (later sub-phases):** overtime, breaks/meal, points/occurrences, differentials, holiday pay, queued mass recompute, mobile policy management.

**Hard constraints (carried from Phases 0–2):** capture never blocks; PHPUnit class-style on sqlite (2 known pre-existing failures stay the only failures); migrate MySQL dev DB after migrations; verify live by HTTP status; Inertia props mapped to plain arrays; `requestJson(method,url,{params|data})`; `npx vite build` only (final consolidated `public/build` commit); mobile `/api/v1` uses the `{success,data}` envelope.

---

## 11. Testing strategy

- **Resolver:** precedence (user>designation>dept>org>global), effective-date selection, version/status (`active` only), memoization.
- **Evaluators (pure, isolated):** rounding strategies (nearest/quarter/seven-minute, both directions); grace-tier classification incl. boundary minutes; **neutral profile reproduces Phase-2 output exactly** (regression guard).
- **Capture:** warn/flag/restrict each *persist* the punch; restrict out-of-window sets provisional; never a hard deny; dedupe/rate-limit unaffected.
- **Exceptions/approvals:** provisional punch appears in inbox; approve → counts; reject → excluded; gated `attendance.manage`.
- **Simulation:** dry-run writes nothing; diff matches a real recompute.
- **API/UI:** policy CRUD/activate/simulate gated `attendance.settings`; employee cannot reach them; live pages 200 by HTTP status.

---

## 12. Open decisions (resolved)

- Restrict = **record-but-provisional**, never block (principle 1). ✅
- Policy storage = **effective-dated, scoped, versioned table + resolver**. ✅
- **Include grace tiers** in 3.0; rounding included as it's low-cost and exercises the compute path. ✅
- **Full vision in this spec; first plan = Phase 3.0 only.** ✅
- Punch-exception shape: **flag-on-`attendances`** for 3.0 (revisit dedicated approvable if metadata grows). ✅
