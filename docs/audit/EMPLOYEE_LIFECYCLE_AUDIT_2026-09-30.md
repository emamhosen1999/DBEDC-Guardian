# Employee Lifecycle & Department-Scoped Administration — Audit

**Date:** 2026-09-30 · **Scope:** DBEDC-Guardian (web + `/api/v1`) and dbedc-mobile-app
**Baseline:** HRIS lifecycle practice (BambooHR / Workday / SuccessFactors / Zoho People) and the Bangladesh Labour Act 2006 (BLA)
**Method:** Static trace of route → middleware → policy → controller → query → page/screen. Laravel 11.51, Spatie Permission 6.25, Carbon 3.11.

> **Verification limits.** This machine has no `pdo_sqlite` or `pdo_mysql` driver, so PHPUnit feature tests could not run. Every finding below comes from reading the code and the migrations in this repo. Schema findings say "per migrations". If production was altered by hand, confirm against the live DB. BLA points are an engineering reading of the Act, not legal advice; have labour counsel sign off on the formulas.

---

## 1. Executive verdict

**The lifecycle is not yet industry standard.** Time, attendance and leave are relatively mature: approval chains, ledgers and idempotency are in place. The newest layer (payroll, F&F settlement, assets, probation) has three problems:

1. **It cannot run against the schema.** Payroll and F&F query columns and tables that no migration creates.
2. **It is unguarded.** Destructive and financial endpoints are gated by the read permission `employees.view`.
3. **It trusts the client.** F&F amounts are taken verbatim from the browser.

**Can you create an admin who operates on everything, but only inside their department? No, not today.** The closest option is the `Department Manager` role plus `users.department_id`. Scope is enforced for reading the employee directory and for listing and viewing on/offboardings. It is **not** enforced for payroll, assets, settlement, leave or OT approval, absence cases, KPIs/stats, petty cash, O&M, or onboarding/offboarding writes. The model also allows only one department per admin, has no delegation or expiry, and **fails open** when an admin's `department_id` is null.

### Stage scorecard (0–5)

| Stage | Score | Why |
|---|---|---|
| Recruitment (Job / Application / Offer) | 0.5 | Models exist; no routes, controllers or UI. No offer → employee conversion. |
| Pre-boarding | 0 | No new-hire portal, document collection or Day-1 prep. |
| Onboarding | 2.5 | Good BLA-aware checklist and automatic biometric push. No department scope on writes; tasks aren't linked to Assets or Roster; the new hire can't see their own checklist. |
| Probation → confirmation | 0.5 | Columns plus an endpoint that no UI calls, gated by a permission that doesn't exist. The migration marked **every existing employee** `probationary`. |
| Transfer / promotion / pay change | 1 | Values are overwritten in place. No effective date, history, approval, or re-routing of pending approvals. |
| Attendance / leave / OT | 3.5 | Mature engine. Undermined by approve-anything override, self-approval, and a crash on the fallback approver. |
| Payroll & payslips | 0.5 | Non-functional per migrations. No lock, tax, allowances, employee self-service (ESS), or bank file. |
| Assets | 1.5 | Basic CRUD. No custody history; "lost" becomes "available"; not linked to F&F. |
| Offboarding / clearance | 2 | Solid absence → abscond pipeline. Access is revoked on day 1 of notice; employees are auto-deleted before LWD; no resignation self-service. |
| F&F settlement | 0.5 | Non-functional per migrations. Client-supplied amounts; no maker-checker or state machine; formulas diverge from BLA. |
| Certificates | 1.5 | Hard-coded "satisfactory conduct" for every exit type; not persisted or numbered. |
| Rehire | 1 | Restore exists. Abscond or offboarding logic blocks a second exit; no reset of balances or probation. |

---

## 2. Findings (Critical → Low)

Format: **ID — title** · location · repro · impact · fix

### CRITICAL

**C1 — Privilege escalation to Super Administrator via self-update**
[UserPolicy.php:37](../../app/Policies/UserPolicy.php#L37), [UserController.php:216](../../app/Http/Controllers/UserController.php#L216), [UserManagementService.php:118](../../app/Services/Admin/UserManagementService.php#L118)
- **Repro:** As HR Manager (holds `users.update`), send `PUT /users/{own employee_id}` with `{"roles":["Super Administrator"], "salary_amount": 999999}`.
- `UserPolicy::update` returns `true` for self, so `UpdateUserRequest` authorizes. Global roles skip the department branch, and `updateUser()` calls `syncRoles($roles)` unchecked. The Super-Admin guard exists only in `updateUserRole`/`bulkAssignRole`.
- **Impact:** Any Administrator or HR Manager can become Super Admin and set their own salary, designation, department or `employee_id` (the primary key).
- **Fix:** In `update()`, when `$target->is($actor)`, whitelist profile-only fields. If `roles` is present, require `can('updateRoles', $target)` plus a hierarchy check (the granted role's `hierarchy_level` must be greater than the actor's). Move salary, department, designation and `employee_id` behind dedicated, audited endpoints.

**C2 — Payroll run and asset CRUD gated by a read permission**
[web.php:819-845](../../routes/web.php#L819-L845), [pages.jsx:165-180](../../resources/js/Props/pages.jsx#L165-L180)
- `POST /hr/payroll/generate`, `POST/PUT/DELETE /hr/assets*` and `GET /hr/payroll/payslip/{id}` all sit behind `permission:employees.view`. The seeder grants that permission to **Department Manager, Project Manager and Team Lead**.
- **Impact:** A Team Lead can regenerate company-wide payroll (which resets every row to `draft`), read every payslip by ID enumeration, and delete assets. The menu shows these pages to the same roles.
- **Fix:** Use the already-seeded `hr.payroll.view|process` permissions, add `hr.assets.view|manage`, add policies, and apply department scope (Phase 1).

**C3 — Offboarding revokes access and biometrics on day 1 of the notice period**
[OffboardingController.php:134](../../app/Http/Controllers/HRM/OffboardingController.php#L134), [ProcessOffboardingLwd.php](../../app/Jobs/ProcessOffboardingLwd.php)
- `ProcessOffboardingLwd::dispatch()->afterCommit()` has no `delay()`. It runs immediately: it terminates all sessions and tokens and queues `DELETE_USER` on every terminal.
- **Repro:** Record a resignation with LWD +60 days. The employee is logged out and can't punch in, so they're marked absent for the whole notice period, which feeds absent-day deductions.
- **Fix:** `->delay($lwd->endOfDay())` plus a daily scheduled sweep for due offboardings (in case the queue loses the job). Re-dispatch idempotently if the LWD changes.

**C4 — Offboarding auto-completes and soft-deletes the employee before LWD or settlement**
[OffboardingController.php:169](../../app/Http/Controllers/HRM/OffboardingController.php#L169), [:279](../../app/Http/Controllers/HRM/OffboardingController.php#L279)
- Ticking the last checklist task sets `completed` and calls `$employee->delete()`, regardless of LWD, F&F status or unreturned assets.
- **Impact:** The employee disappears from payroll and lists mid-notice, with an unpaid F&F. It combines badly with C2/C3.
- **Fix:** Completion requires LWD reached, all assets returned, and the F&F `paid` (or explicitly waived). Deactivation happens in the LWD job, not in the checklist toggle.

**C5 — Payroll generation cannot run (schema mismatch)**
[PayrollController.php:106](../../app/Http/Controllers/HRM/PayrollController.php#L106)
- `payrolls` migration = `id` plus timestamps only. There is **no `payslips` migration**. `overtime_requests` has `requested_minutes`, not `overtime_hours`. Result: `QueryException` on the first salaried employee, and the whole batch rolls back.
- **Fix:** Build a proper payroll schema (see Phase 2) before exposing the page, and put the page behind a feature flag until then (a `FeatureFlag` model already exists).

**C6 — F&F calculation cannot run (schema mismatch)**
[SettlementController.php:40-68](../../app/Http/Controllers/HRM/SettlementController.php#L40-L68)
- It queries `leave_ledger.employee_id`, `.balance` and `leave_type='earned'`. The real columns are `user_id`, `balance_after`, and `leave_type` as an FK integer.
- It also reads `petty_cash_loans.remaining_amount` and `.installment_amount`. The real column is `current_balance`, and there's no installment column.
- **Result:** every "Calculate F&F" click returns 500.

**C7 — F&F amounts are client-trusted, overwritable and not guarded by state**
[SettlementController.php:117-200](../../app/Http/Controllers/HRM/SettlementController.php#L117-L200)
- `store` persists whatever `net_payable` and totals the browser sends. `updateOrCreate` resets status to `draft`, even on a **paid** settlement.
- `approve` has no status check and no maker-checker: the preparer can approve their own voucher, and approving a `paid` voucher reverts it.
- `disburse` doesn't require `approved`, so a draft can be paid, and it can be paid twice. It sets **all** of the employee's active loans to `settled`/0, whatever amount was actually deducted.
- There's no policy or department scope, so anyone with `hr.offboarding.view` can read any employee's salary and bank account.
- **Fix:** Compute on the server only (the client sends adjustments with reasons). Enforce the state machine draft → approved → paid under `lockForUpdate`, add `unique(offboarding_id)`, require approver ≠ preparer and approver ≠ subject, post loan recovery to the loan ledger for the deducted amount, and make approved and paid rows immutable.

**C8 — Lifecycle migration destroys data and mislabels every employee**
[2026_09_29_000002_enhance_employee_lifecycle_suite.php:13,38,83-84](../../database/migrations/2026_09_29_000002_enhance_employee_lifecycle_suite.php#L13)
- `Schema::dropIfExists('assets')` drops the 2024 `assets` table and its data.
- `employment_status` defaults to `probationary` for every existing user.
- `final_settlements` cascades on delete from both `offboardings` and `users`, so force-deleting either erases financial records.
- **Action now:** Check whether this migration has already run in production. If so, restore old asset rows from backup and backfill `employment_status='confirmed'` where `date_of_joining` is older than the probation period. Change the FKs to `restrictOnDelete`.

### HIGH

**H1 — Department scope is three inconsistent models, and web fails open**
- The web has two models:
  - [ScopeResolver.php](../../app/Services/Directory/ScopeResolver.php) scopes by role `Department Manager` plus own `department_id`.
  - [UserController.php](../../app/Http/Controllers/UserController.php) and `paginateEmployees` scope any non-global user with a non-null department.
- Mobile [ResolvesTeamMembers.php](../../app/Http/Controllers/Api/V1/Concerns/ResolvesTeamMembers.php) uses `departments.manager_id`, the `report_to` tree, and the role.
- **Result:** web and mobile disagree about who is on a manager's team. A non-global user with `department_id = null` sees **all** employees. A Project Manager is wrongly restricted to their own department in the directory.

**H2 — Leave approval: any `leaves.approve` holder can approve anyone's leave, including their own**
[LeaveApprovalService.php:550](../../app/Services/Leave/LeaveApprovalService.php#L550), [:189-212](../../app/Services/Leave/LeaveApprovalService.php#L189-L212)
- `canOverride()` returns true for `leaves.approve`, which the Department Manager holds. There's no check that the leave's owner differs from the actor, and no department check.
- **Fix:** Override only for `leaves.manage` or global roles. Always reject `leave.user_id === actor`. Scope overrides to the actor's managed departments.

**H3 — OT and regularization: fallback approver query crashes; a departed manager strands requests**
[AttendanceApprovalService.php:29](../../app/Services/Attendance/AttendanceApprovalService.php#L29)
- `->where('id', '!=', …)`: the `users.id` column was dropped (PK is now `employee_id`). Every OT or regularization request from someone **without a `report_to`** throws.
- If `report_to` points to a soft-deleted manager, the chain is built to that user and no one can approve it (there's no admin override here).
- An empty chain auto-approves.

**H4 — "Initiate offboarding" employee picker crashes once any offboarding is active**
[OffboardingController.php:221](../../app/Http/Controllers/HRM/OffboardingController.php#L221)
- Same dropped-`id` bug. `whereNotIn` with an empty array compiles to `1=1`, so the picker works only while no offboardings are open.
- Run `grep -rn "where[A-Za-z]*('id'" app` over every User query to catch the rest.

**H5 — Onboarding/offboarding writes ignore department scope**
[OnboardingPolicy](../../app/Policies/OnboardingPolicy.php), [OffboardingPolicy.php](../../app/Policies/OffboardingPolicy.php)
- `create`, `update` and `delete` check only the permission. A Department Manager can offboard anyone, including a Super Admin or themselves, and completing that offboarding soft-deletes the target (C4).
- `eligibleEmployees`, `absenceCases`, `generateNotice`, `resolveAbsenceCase` and header stats are company-wide.
- `update()` updates task IDs not bound to the parent offboarding (`OffboardingTask::where('id', …)`), which is an IDOR.

**H6 — UserPolicy `toggleStatus` / `manageDevices` department branch is dead code**
- Both methods end with an unconditional permission check, so a Department Manager with `employees.update` can deactivate or reset devices for **any** user.

**H7 — The Department Manager edit path silently demotes users**
[UserController.php:228-234](../../app/Http/Controllers/UserController.php#L228-L234)
- Non-global editors force `roles = ['Employee']` on every save. When a Department Manager edits a Team Lead's phone number, that user's Team Lead role is removed.

**H8 — Payroll logic (once the schema exists)**
- Weekly rest is hard-coded to 4 days (wrong in months with 5 Fridays) and holidays are ignored, even though a `Holiday` model exists.
- Employees without biometric attendance are marked absent all month.
- Basic is hard-coded at 60%.
- Loan installments are deducted but never posted to the loan, so they're re-deducted forever, and F&F then recovers the full balance again.
- OT that was granted as comp-off is also paid in cash.
- Offboarded, soft-deleted employees get no final-month payroll, while active leavers are paid by both payroll and F&F.
- Regenerating a month overwrites approved or paid rows.
- `TaxSlab`, `PayrollAllowance` and `PayrollDeduction` exist but are unused.

**H9 — BLA formula gaps in F&F (confirm with counsel)**
- Resignation (s.27(4)): 14 days' wages per completed year for 5–10 years, 30 days for ≥10. The code uses a flat 30 days' basic for ≥5 years.
- Termination (s.26) pays **zero** in the code. BLA requires notice or pay in lieu plus 30 days per completed year.
- Absconded workers under s.27(3A) are deemed to have resigned; the code pays 0.
- Retirement (s.28) and death (s.19, paid to the nominee) aren't modelled.
- Carbon 3 `diffInYears()` returns a float, so partial years are paid as years (should be `floor`).
- s.123(2) (dues within 30 working days) isn't tracked.

**H10 — Probation module unreachable**
[web.php:849](../../routes/web.php#L849)
- The route requires `employees.edit`, which isn't seeded, so only Super Admin (via `Gate::before`) passes. No UI calls `/employees/{id}/confirm`.
- There's no `probation_end_date` computation, reminder, extension or rejection path. The comment cites s.4(4); verify the clause (probation is s.4(7)–(8)).

**H11 — Premature asset data loss and wrong states**
[AssetController.php:109-160](../../app/Http/Controllers/HRM/AssetController.php#L109-L160)
- Return nulls `assignee_id`, and there's no custody-history table, so "who had laptop X in March" is unanswerable.
- `lost` becomes `available`.
- `assign` doesn't check the current status, so it silently steals an asset from another holder, or assigns a damaged or disposed one, or assigns to a departed employee.
- Damage and loss never feed `asset_damage_deduction`.

### MEDIUM

- **M1:** Transfer ([DepartmentController.php:247](../../app/Http/Controllers/DepartmentController.php#L247)) compares an int to a request string, so `designation_id` is always reset. There's no effective date or history, `report_to` isn't updated, and pending approvals aren't re-routed.
- **M2:** No audit trail for employee master changes (role, salary, department, status). Only `Log::info`.
- **M3:** `UserController::stats` / `employeeStats` and the offboarding and onboarding header stats are company-wide for scoped users, which leaks KPIs.
- **M4:** Petty cash authorizes by hard-coded role names (`Manager`, which isn't seeded; `Accountant`, `Finance Manager`), not permissions. There's no department scope and no self-approval block.
- **M5:** `onboarding.eligibleEmployees` returns every user who ever lived in the system, unscoped and unpaginated. Biometric enrolment falls back to **all** active terminals when there's no device mapping, which grants physical access at every site.
- **M6:** `LeaveController` ships `allUsers` (the entire directory) as an Inertia prop to every leave page viewer.
- **M7:** Certificates hard-code the company name and "satisfactory conduct" (a legal risk for dismissed employees). `reference_no` uses the print year and is never persisted, so reprints next year produce a different number.
- **M8:** `stancl/tenancy` is installed but not wired into any route. Tenant isolation isn't in play today; don't rely on it.

### LOW / UX

- **U1:** The settlement modal re-runs `calculate` after save, approve or disburse, so a **paid voucher is displayed with live recomputed numbers**, not what was paid.
- **U2:** Approve and Disburse have no confirmation dialog. Disburse is hard-coded to `bank_transfer` with no payment reference field.
- **U3:** Asset return and certificate load failures only go to `console.error`, so the user gets no feedback. Assets and Payroll use `alert()`/`confirm()` (7 calls), while the rest of the app uses `showToast` (91 imports) and `DeleteConfirmDialog`.
- **U4:** Dates use `new Date().toLocaleDateString()`, which is browser-locale dependent. The offboarding form defaults LWD to *today* and `notice_days_required` to 30 (BLA permanent-worker resignation notice is 60 days).
- **U5:** Settlement buttons are gated only by `hr.offboarding.update`, so the UI invites one person to prepare, approve and pay.

---

## 3. Department-admin scope matrix

✅ enforced · ⚠️ partial or inconsistent · ❌ leaks or unscoped · — no such endpoint

| Module | List | View one | Create | Update | Approve / act | Stats | Mobile API |
|---|---|---|---|---|---|---|---|
| Employees (directory) | ⚠️ fail-open if dept null | ⚠️ same | ✅ forced to own dept | ⚠️ demotes roles (H7) | — | ❌ | ⚠️ different model |
| Role / permission grant | — | — | — | ✅ Admin+ only | ❌ C1 self-escalation | — | — |
| Dept transfer | — | — | — | ✅ | — | — | — |
| Status toggle / devices | — | — | — | ❌ H6 | — | — | — |
| Onboarding | ✅ | ✅ | ❌ | ❌ | ❌ | ❌ | — |
| Offboarding | ✅ | ✅ | ❌ | ❌ (can delete any user) | ❌ | ❌ | — |
| Absence cases | ❌ | ❌ | — | ❌ resolve / abscond | ❌ | ❌ | — |
| F&F settlement | — | ❌ salary and bank IDOR | ❌ | ❌ | ❌ no maker-checker | — | — |
| Payroll / payslip | ❌ | ❌ IDOR | ❌ company-wide run | — | — | ❌ | — |
| Assets | ❌ | ❌ | ❌ | ❌ | — | ❌ | — |
| Leave | ⚠️ | ⚠️ | ✅ own | ⚠️ | ❌ H2 override + self | ⚠️ | ⚠️ |
| OT / regularization | ✅ chain | ✅ | ✅ own | — | ✅ chain / ❌ H3 crash | — | ✅ |
| Daily Works / RFI | ✅ policy | ✅ | ⚠️ not deep-verified | ⚠️ | ⚠️ | ⚠️ | ✅ epoch |
| Global search | ⚠️ fail-open if dept null | — | — | — | — | — | — |
| Petty cash | ❌ role-name based | ⚠️ owner check | ⚠️ | ⚠️ | ❌ M4 | ❌ | — |
| O&M, Quality, Reports, Letters | ❌ no dept refs found (not deep-verified) | | | | | | |

---

## 4. Journey maps

### Admin (HR / department head)

```
Hire ──► [no ATS/offer flow ✗] ──► create user (Employees) ──► Onboarding: pick employee
      ▲ friction: must create the user before onboarding; picker lists everyone ever
Onboarding checklist ──► biometric push (all terminals if unmapped ⚠) ──► tasks ticked by hand
      ▲ asset + roster tasks not linked to the Assets / Roster modules
Probation ──► [no reminder, no UI to confirm/extend ✗]
Transfer / promote ──► overwrite dept/designation/salary [no effective date, no history ✗]
Monthly payroll ──► "Generate" [500 ✗ C5] ──► no approve/lock/pay step
Resignation ──► HR must initiate (no employee request) ──► access revoked NOW [✗ C3]
      ──► tick checklist ──► employee auto-deleted on last tick [✗ C4]
F&F ──► Calculate [500 ✗ C6] ──► Save/Approve/Disburse, one person, no confirm [✗ C7/U2]
Certificate ──► always "satisfactory" [⚠ M7], not stored
```

### Employee (web + mobile)

```
Day 1 ──► can't see own onboarding checklist or assigned assets ✗
Probation ──► no visibility of status or end date ✗
Monthly ──► no "My Payslips" on web or mobile ✗ (payroll pages need employees.view)
Leave / OT / regularization ──► good on both platforms ✓ (OT crashes if no manager ✗ H3)
Resign ──► no self-service resignation request ✗
Notice period ──► logged out and biometric removed on day 1 ✗ ──► marked absent
Exit ──► no view of clearance, F&F status or amount; no certificate download ✗
```

Mobile has no lifecycle screens at all (no payslip, asset, onboarding, offboarding or probation screens in `app/(tabs)`).

---

## 5. Phased fix plan

**Phase 0 — Stop the bleeding (1–3 days, before anything else)**
1. C1: lock down self-update and add a role hierarchy check on every role-grant path.
2. C2: switch payroll and asset routes to `hr.payroll.*` / `hr.assets.*`, and fix the menu gating.
3. C3/C4: delay the LWD job until LWD; make completion depend on LWD, assets and F&F.
4. H3/H4: fix every `where*('id', …)` on `users`, plus a regression test per call site.
5. C5/C6: put Payroll and F&F behind a feature flag (off) until Phase 2.
6. C8: audit production (did the migration run? asset backup? `employment_status` backfill?) and change the settlement FKs to restrict.
7. H2: remove self-approval, and limit leave override to `leaves.manage` or global roles.

**Phase 1 — One department-scope model (≈1 week)**
- New `DepartmentScope` service: `managedDepartmentIds(User): array|ALL`. Sources:
  - global roles → ALL
  - `departments.manager_id`
  - a new `user_department_scopes` table (`user_id`, `department_id`, `scope_role`, `starts_at`, `expires_at`, `granted_by`) for multi-department admins and acting charge
  - `Department Manager` + own department
  - **empty → self only (fail closed)**
- An Eloquent scope `User::visibleTo($actor)` and `whereEmployeeVisibleTo($actor)` for HR models keyed by `employee_id`.
- Policies for Payroll, Payslip, Asset, FinalSettlement, AbsenceCase, Leave and OT that call the service. Replace `ScopeResolver`, the inline `UserController` checks and `ResolvesTeamMembers` with it, so web and mobile agree.
- Permission-driven admin: a "Department Admin" role gets the operational permissions; the data scope comes from the service, not from role names.
- **Test matrix:** a data-provider feature test over every lifecycle endpoint × {same department, other department, self, global}, asserting 200/403/404. This is the proof the prompt asked for.

**Phase 2 — Data integrity (≈2 weeks)**
- Payroll schema and service: effective-dated salary structure (basic and allowances from data, not 60%); weekly-off and holidays from the roster and `Holiday`; draft → approved → locked → paid; loan installment posted to the loan ledger; exclude comp-off OT; payslip PDF; final-month handling for leavers.
- F&F: server-side compute only, adjustments with reasons, state machine with `lockForUpdate`, maker-checker, BLA formulas per exit reason (resignation, termination, dismissal, retirement, death, abscond), nominee payee, 30-working-day due-date tracking, and a stored snapshot shown in the UI.
- Asset custody history, a `lost` status, and damage or loss feeding the F&F deduction.
- Employment history table for transfers, promotions and pay changes, with effective dates and approvals. On transfer or manager exit, re-route pending approvals and bump `sync_epoch`.
- Audit log on the employee master and all lifecycle transitions.

**Phase 3 — Journeys and UX (≈2 weeks)**
- ESS on web and mobile: My Payslips, My Assets, Submit Resignation, My Exit Status, and certificate download.
- Probation dashboard: due-date list, confirm, extend or terminate, with reminders.
- Recruitment offer → create employee → onboarding.
- Replace `alert`/`confirm` with `showToast` / `ConfirmDialog`; confirmations on money actions; a payment reference field; locale-stable date and currency formatting; a per-employee lifecycle timeline.

---

## 6. Notifications workstream (added 2026-09-30)

Full findings: [NOTIFICATIONS_AUDIT_2026-09-30.md](NOTIFICATIONS_AUDIT_2026-09-30.md). Verdict: **partially working**. The engine is sound, but only about 8 features are wired.

- **Notif-0 (with Phase 0):**
  - The cron worker doesn't drain the `notifications`/`security` queues.
  - Forgot-password never sends mail, and the OTP is written to the logs.
  - The missing `PushNotification` class breaks `TaskNotificationService`.
  - FCM tokens appear in logs.
  - **Rotate the mail password committed in `.env.example`.**
- **Notif-1 (with Phase 1):**
  - `after_commit` for queued notifications.
  - Seed the missing type keys (`om.alert`, `hr.offboarding_initiated`, `attendance.absence_streak_escalation`).
  - A shared recipient helper covering department scope, the actor and inactive users.
  - Push-token pruning and Expo receipts.
  - Fix the 22:17 reminder that notifies everyone.
- **Notif-2 (with Phase 2):** absence notices to the employee, offboarding tasks, scope grants, probation due, leave cancel and escalation, level-2+ approvers.
- **Notif-3 (with Phase 3):**
  - Mobile notification list, bell and badge (plus API).
  - A deep-link contract for push payloads.
  - Unregister the token on logout.
  - Web push click handler.
  - Generic lock-screen text and `bn` localisation.
- **Notif-4 (with Phase 2/3 module rebuilds):** assets, payroll/payslip, F&F, onboarding, petty cash, NCR, lane closure, letters, security alerts.

---

## 7. Deploy #1 and production verification (2026-09-30)

- **Shipped** `31f3d9a29` to erp.dhakabypass.com:
  - Phases 0, 1a and 1b, the Department Admin role, and attendance/roster/dashboard scoping.
  - The mobile web build under `/mobile`.
  - A pre-deploy DB backup is at `~/backups/erp-predeploy-20260930-160608.sql.gz`.
  - All 6 new migrations ran, with 0 errors in the log afterwards.
- **Mahdi scenario verified live.** Mahadi Hasan, employee 1537, Department Admin with home department Inspection (id 30), no scope grants:
  - He sees only himself.
  - Another department's employee returns 404, and writing to another department's user returns 403.
  - Excluded modules return 403.
  - The nav matches the spec exactly, and the mobile `/auth/me` response is correct.
  - Test device registrations were removed afterwards.

### New findings from production (add to the plan)
- **P-1 Scheduler cron was lost after Sept 19.** Only the camera streaming line survived. **Fixed:** the ERP and `public_html` lines were restored at the old 5-minute cadence. Move the ERP line to every minute once the 22:17 `attendance:reminders` bug (Notif F-7) is fixed. At a 5-minute cadence, :17 never fires.
- **P-2 Stray scripts in the server app root.** `test_user_model.php`, `test_roles_res.php`, `test_leave_routes.php`, `test_inertia_user.php`, `verify_opt2.php`, `LeaveController.php` and `unresolved_errors.json` sit there. Confirm whether they're web-reachable, then remove them. This needs the owner's decision.
- **P-3 18 employees have no `date_of_joining`**, so they stay "probationary". HR needs to fix the data. This feeds the probation dashboard and lifecycle metrics.
- **P-4 New users get their PIN on every terminal.** `UserManagementService::createUser` → `queueBiometricSync` falls back to **all** terminals when the user has no device mapping. Extend the M5 fix, which so far covers onboarding only.
- **P-5 The GitHub mirror is behind production.** Deploys push straight to the server; push `main` to GitHub after each deploy.
- **P-6 The mobile web was also uploaded by FTP.** `deploy-web.ps1` uploads to `/mobile`, bypassing git, and those untracked files blocked this deploy. Ship `/mobile` only through the tracked `mobile/` folder.
- **P-7 OTA "v3.0.0 v9" is pending.** Publishing must use the CRLF fingerprint procedure; see `production-deploy` memory and the deploy notes.
- **P-8 No forced password change at first login.** Add `must_change_password`, as industry standard for admin-set or shared passwords, and set it for Mahdi.
