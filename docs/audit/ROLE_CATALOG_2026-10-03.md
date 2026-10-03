# Role Catalog and Assignment Matrix (2026-10-03)

**Status:** design only, awaiting the owner's approval. Nothing here has been applied, and no code, migration or production data was changed.

**Sources:**
- Production RBAC snapshot taken 2026-10-03 14:56: 5 departments, 35 active users, 17 roles, 449 permissions.
- The coordinator's post-snapshot notes, which match the snapshot.
- Code at `7190e1b06`.
- `php artisan route:list --json`: 736 authenticated routes.
- The mobile app's `src/auth`.

**Versions verified in composer.lock / vendor:**
- laravel/framework 11.51.0
- spatie/laravel-permission 6.25.0: `teams=false`; `permission.events_enabled` is missing from `config/permission.php`, so it defaults to false.
- inertiajs/inertia-laravel 2.x
- laravel/sanctum 4.3.2

**Standards applied:**
- ANSI INCITS 359-2012: core and hierarchical RBAC, static and dynamic separation of duties (SSD / DSD).
- ISO/IEC 27001:2022: A.5.3, A.5.15, A.5.18, A.8.2.
- Microsoft Entra: role definition × scope (administrative units); privileged-role protection.
- Workday: constrained/unconstrained security groups and step-level business-process security.

**Abbreviations:** SA Super Administrator · AD Administrator · HRM HR Manager · DA Department Admin · DM Department Manager · LM Line Manager · TL Team Lead · OMD O&M Director · QM Quality Manager · QC Quality Contributor · DWM Daily Works Manager · DWC Daily Works Contributor · MI Maintenance Inspector · MIQ "Maintenance Inspector / QC Specialist" · TMC TMC Operator · HPO Highway Patrol Officer · EMP Employee.

---

## 1. Verdict and recommended model

The owner's model is current practice; keep it, with the eight corrections in §1.2:
- a base role,
- composable functional roles,
- scoped administrative roles,
- department defaults,
- governed per-person exceptions.

The catalog goes from 17 roles to **15 after Phase A** (data only) and **14 after Phase B** (code).

### 1.1 Layers

| Layer | Roles | Assigned by | Data scope | Practice |
|---|---|---|---|---|
| L0 Base | Employee | System at hire (`DepartmentDefaultRoles::rolesForNewUser`) | self | IGA birthright access |
| L1 Department default | DWC (QC); later QC (O-6) | System, from `departments.default_roles`; only SA edits the rule | own records (incharge / assigned / jurisdiction) | Workday auto-assignment by organization; Entra dynamic groups |
| L2 Functional | QM, DWM, OMD, MI, TMC, HPO, QC | SA, from the approved matrix | the holder's department records (Phase B: from DepartmentScope) | NIST role engineering: one role per job function per module |
| L3 Administrative / scope | LM, DM (Phase A only), DA, HRM, AD, SA | SA | reporting subtree / department / global, from `DepartmentScope` | Entra role × administrative unit; Workday constrained vs. unconstrained |
| L4 Exception | `access.self-administration` (artisan only); `user_department_scopes` grants (time-boxed) | SA, with a reason, logged | per person | ISO A.5.3 documented SoD exception |

**Catalog rules** (each one becomes a test, §7.3):

| # | Rule | Today |
|---|---|---|
| R1 | Decide authorization on permissions. Role names may appear only in `DepartmentScope::GLOBAL_ROLES`. | 107 role-name checks in 41 PHP files, plus 58 in React |
| R2 | One named permission per action. Route, policy, UI and mobile check the same name. Data scope comes only from DepartmentScope. | §2.3 |
| R3 | `hierarchy_level` is administrative rank only (ARBAC). Functional roles sit at 45–55, so every administrator outranks their holders. | O&M Director is 15 |
| R4 | A department default must be default-eligible: level ≥ 50, with no approve, admin, settings or delete-others permission. | Validation only forbids 5 names |
| R5 | Every active user holds Employee. Functional roles carry no self-service permissions. | 123 and 896 lack Employee |
| R6 | No direct permissions except L4 exceptions. | 8 redundant rows |
| R7 | A leaver loses every role at the last working day or soft delete. A restore re-provisions only Employee plus the department defaults. | Roles survive offboarding and come back on restore |

### 1.2 Deviations from the target model

| # | Target model | Recommendation | Why |
|---|---|---|---|
| V1 | Keep `Department Manager` and `Team Lead` as line-management roles | **Phase A:** rename TL to **Line Manager**; slim DM. **Phase B:** merge DM into LM; give a non-head department scope through a `user_department_scopes` grant | DM's only distinct meaning is "scope = own department" (`DepartmentScope::DEPARTMENT_HEAD_ROLE`). Entra and Workday keep one role definition and vary the scope; putting scope in the role multiplies roles. |
| V2 | Line managers are assigned by hand | LM is a **derived** birthright: anyone with ≥ 1 active direct report. Phase B adds a reconciler from `report_to`; in Phase A the SA assigns it. | Approval chains already route by `report_to`. Five level-1 approvers (120, 126, 127, 1536, 130) lack `leaves.approve`. On web the route returns 403; on mobile they can approve, because the service checks only chain position. Their requests fall back to admin override; audit §9 found all 14 chain-approved leaves since 2026-07-10 were decided that way. |
| V3 | DM keeps or sheds functional permissions | **Shed:** quality to QM, daily works to DWM, dead modules removed | NIST: each permission belongs to the role that owns the function. DM mixes 8 modules. |
| V4 | Levels for functional roles unspecified | Functional roles at 45–55. OMD moves from 15 to 45 in Phase B. | At level 15 a functional role outranks HRM (20) and DA (25). Wang Fu can manage Fahim today (§4.5). |
| V5 | Candidate roles: reports viewer, training coordinator, performance reviewer, toll, asset custodian, timekeeper, petty-cash requester/approver/manager | **Reserved:** define them now, create them only when a route or UI exists or a holder is named (§4.4). A petty-cash requester is the base Employee role, not a separate role. | Reports, Training, Performance and Toll have no route. Creating these roles adds no access and adds A.5.18 review burden (role explosion). |
| V6 | Quality Contributor / Quality Manager | Create both. Make QC a QC department default only after B8 and owner decision O-6. | A second default role today desynchronizes the hard-coded `BASE_ROLES` in `pages.jsx` from `User::hasOnlyBaseRoles()`. |
| V7 | Only SA assigns roles | Make these SA-only too: role *definition* (`roles.create/update/delete`, `permissions.assign`), scope grants (`department.scopes.manage`) and department-default rules. Strip them from AD and HRM. | Entra treats an AU-scoped assignment as a role assignment (ISO A.8.2). Today AD and HRM hold `employees.access.manage` and AD may edit `default_roles`. |
| V8 | Not covered | Leaver deprovisioning (R7); a dedicated SA admin account plus a second break-glass SA | ISO A.5.18 removal of access; Microsoft privileged access model. 151 is both the only SA and the TMC Manager's daily account. |

---

## 2. Findings (Task A)

### 2.1 Catalog health

| Measure | Count | Detail |
|---|---|---|
| Permissions | 449 | |
| Live: enforced by a route, controller/service, nav, React or mobile | 134 | |
| Policy-only: a policy exists but the module has no route | 40 | hr.benefits, checklists, competencies, documents, safety, skills; quality.inspections, quality.calibrations |
| Dead: referenced nowhere outside seeders | 275 | listed below this table |
| Used in code but never created | 12 | §2.2 |
| Employee: held / live | 28 / 7 | Every employee carries 21 dead grants. |
| Authenticated routes without permission middleware | 169 of 736 | Mobile v1 attendance (40), daily works (19) and leave (14) are controller-checked; petty cash has 18 routes. |

The 275 dead permissions span analytics, compliance, lms, project-management, recruitment (`jobs`, `job-*`), `training-*`, `performance-*`, reports, documents, event, feedback, finance/CRM/SCM/retail, `hr.selfservice.*`, `hr.analytics.*`, `hr.employee.*` and `hr.timeoff.*` (except approve).

### 2.2 Permissions used but never created

| Permission | Used in | Effect today | Fix |
|---|---|---|---|
| `monitoring.camera.view` | 13 web/api routes, nav, mobile menu | Only SA, through `Gate::before`, reaches the CCTV console | **A:** create it; grant SA and AD |
| `leaves.manage` | LeaveController (9 checks), `LeaveApprovalService::canOverride`, LeaveBalanceController, Leave{Crud,Query,Validation}Service, LeaveForm.jsx | Leave admin override works for SA only, plus the role-name list (§2.4) | **A:** create it; grant HRM and AD (0 holders) |
| `daily-works.own.view` | DashboardController, CommandCenterService, 4 dashboard widgets, mobile `menuAccess` | Always false | **B:** use `daily-works.view` |
| `hr.safety.{incidents,inspections,training}.delete` | 3 HR safety policies | Module has no routes | Create with the module, or delete the references |
| `hr.training.manage` | `TrainingMaterial` via `hasPermissionTo()` | Would throw `PermissionDoesNotExist` (HTTP 500) if reached; module has no routes | **B:** use `can()` |
| `permissions.{view,create,update,delete}` | PermissionController | `/api/permissions` CRUD is route-gated by `roles.view\|roles.update\|permissions.assign`; the controller lets only SA through | **B:** gate on `permissions.assign` (SA) |
| `leaves.own.view` | `permission:leaves.view,leaves.own.view` on `/leave-summary` | The comma makes the second value the guard argument, so the guard is undefined (500). A later duplicate route shadows it. | Delete the duplicate route |

### 2.3 Enforcement disagreements (route ↔ nav ↔ UI ↔ mobile)

| # | Area | Problem | Severity |
|---|---|---|---|
| E1 | **Aeon AI copilot** (`/aeon/*`, `config('aeon.enabled', true)`, any logged-in user) | `QueryTool` queries every table except 11 framework tables. `RowScope` keys on 5 non-existent roles. A table without a `user_id`/`employee_id` column comes back whole: `daily_works`, `quality_ncrs`, `site_instructions`, `model_has_roles`, `role_has_permissions`, `self_administration_logs` and most `om_*` tables. | **Critical**: reads bypass the permission model |
| E2 | Daily Works, mobile | The list needs only a login (`ListDailyWorksRequest::authorize`). `DailyWorkService::isPrivilegedUser` uses 8 role names (Admin, Super Admin, Daily Work Manager, Consultant, HRM, Project Manager, SA, AD) to grant company-wide view, status and incharge. | High |
| E3 | Daily Works, web | Status, assign, inspection, completion and bulk updates are gated only by `daily-works.view` plus the policy. The Import button, the incharge/jurisdiction filters and the Jurisdictions tab are gated by role names (AD, SA, "Daily Work Manager"), so DM holders hold `daily-works.import` but never see Import. | High |
| E4 | Line approvals | Web uses `leaves.approve` and `attendance.correct\|create\|update`; mobile uses chain position or `isManagerUser` (V2). Attendance-request decisions ride on record-correction permissions. | High |
| E5 | O&M | 22 nav items are gated on permissions their routes don't accept; for example, the SLA Matrix nav uses `om.sla.view` but the route needs `om.dashboard.view`. 13 granular `om.*` permissions gate the nav only. `/om/lookups` POST/PUT/DELETE is gated by `om.dashboard.view`, so **TMC Operators can edit O&M master data**. | High (lookups) |
| E6 | Request logs | Nav uses `request_logs.view`; the routes require `attendance.settings`. | Medium |
| E7 | Quality | The NCR register route exists but has no nav entry. Moving an NCR to closed/verified uses `quality.ncr.update`, so whoever raises an NCR can also close and verify it. | Medium |
| E8 | Tasks (legacy) | `/tasks-all` returns **every** task to any `tasks.view` holder who is not a Supervision Engineer, QC Inspector or Administrator (fails open; covers all 22 DWC holders). Neither web nor mobile calls it. | Medium |
| E9 | Petty cash | 18 routes are ungated. Approval uses `role_or_permission:petty-cash.approve\|Manager\|Accountant\|Finance Manager`, and those 3 roles don't exist. | Medium |
| E10 | Seeders | Additive (`givePermissionTo`) and LIKE-wildcard (`daily-works.%`) seeding causes drift: production DM holds `employees.create/delete/restore`, which the seeder never grants. Re-seeding re-creates retired roles. `MonitoringRoleSeeder` creates 4 empty roles. | Medium |
| E11 | Self-service leave | `/leave-add`, `/leave-update` and `/leave-delete` are gated by `leave.own.view`. | Low |
| E12 | Admin surfaces | Device sessions, feature flags and client diagnostics use `users.view` (plus `scope.global` on writes), not a named per-action permission. | Low |

### 2.4 Role-name authorization (phantom and legacy roles)

| Role name | Exists? | Used in | Effect |
|---|---|---|---|
| `Admin` | yes: 0 permissions, 2 holders, none of them active | LeaveController (3 checks), `LeaveApprovalService::canOverride`, LeaveValidationService, GlobalSearchController, RfiObjection controller and policy, `ResolvesTeamMembers`, `DailyWorkService::isPrivilegedUser`, mobile `roles.js` | An "empty" role that grants leave override, company-wide Daily Works, RFI admin and mobile manager status. Restoring either holder resurrects all of it. **Delete.** |
| `Project Manager` | yes, 0 holders | RfiObjection controller and policy, `isPrivilegedUser`, ObjectionService | Grants company-wide Daily Works and RFI admin if ever assigned. **Retire.** |
| `Super Admin`, `Consultant`, `HR Head`, `Daily Work Manager` | no | RFI flows, `isPrivilegedUser`, leave/attendance HR fallback, DailyWorks UI (4 files), `useObjectionsAccess` | Dead branches. RFI review/resolve is effectively SA-only, and DW admin UI is AD/SA-only. |
| `Manager`, `Accountant`, `Finance Manager` | no | `PettyCashController::LEGACY_ROLES`, 3 routes | Petty-cash approval is SA-only. |
| `Department Head`, `Incharge`, `Managing Director`, `Project Director` | no | Aeon `RowScope` | E1 |
| `Supervision Engineer` used as a role | no | `TaskController`, `TaskCrudService` via `User::role()` | That scope **throws** `RoleDoesNotExist` (verified in vendor). |
| `Department Manager` / `Employee` (real roles) | yes | DW scope (3 services), Safety/HrDocument/Benefit policies, `DailyWorkPolicy` (`hasRole('Employee')` 6 times), `User::role('Employee')` lists (6) | Scope keyed on role names. 123 and 896 lack Employee, so they drop out of attendance, roster, swap, policy and report lists. |

### 2.5 Data scope by module

| Module | Mechanism today | Gap | Target |
|---|---|---|---|
| People, attendance, leave, onboarding/offboarding, assets, petty cash | `DepartmentScope` | Petty cash `LEGACY_ROLES` | Keep; drop legacy roles |
| Daily Works (web) | role/designation rules: SA/AD all; **DM role** → own department; SE/QCI/AQCI designations; Employee role → jurisdiction/`report_to` | A DA who isn't a DM gets no department scope; keyed on role names | B3: contributors keep the incharge/jurisdiction rule; managers use `DepartmentScope::managedDepartmentIds()` |
| Daily Works (mobile) | `isPrivilegedUser` role list | E2 | Same as web |
| Quality NCR | none: every `quality.ncr.view` holder sees all NCRs | Acceptable: this is a single project register (ISO 9001 §8.7), visible only to quality-role holders | Add department scope when multi-project |
| O&M / TMC | none (company-wide) | Single O&M operation | Keep |
| Tasks | designation; otherwise everything | E8 | Retire the module |
| Reports, Performance, Training, Compliance | no routes | — | Scope when built |
| Aeon | `RowScope` | E1 | Entity → permission map plus DepartmentScope |

### 2.6 Models without UI

| Model | Permissions | Policy | Route / UI | Gaps |
|---|---|---|---|---|
| SiteInstruction | **none** | none | none (Command Center counts only) | no SoftDeletes; `department` is free text, not a foreign key |
| QualityInspection | `quality.inspections.*` | QualityInspectionPolicy (unscoped) | none; the nav "Inspection Checklists" item points at `om.inspections` | |
| QualityCalibration | `quality.calibrations.*` | QualityCalibrationPolicy (unscoped) | none | |
| QualityNCR | `quality.ncr.*` | QualityNCRPolicy, unused (the controller calls `can()` directly) | `/quality/ncr`, no nav entry | soft-deleted; no restore route |

### 2.7 Accounts and data hygiene

| # | Finding |
|---|---|
| H1 | 123 (Abul Bashar) and 896 (Wang Fu) lack Employee, so they are excluded from six `User::role('Employee')` employee lists. |
| H2 | 31 role rows sit on accounts missing from the active snapshot (soft-deleted): Employee 15, DWC 13, Admin 2, TMC 1. `ProcessOffboardingLwd` and `deleteUser` keep roles. |
| H3 | 8 redundant direct grants: `leave.own.view` × 7 (120, 122, 123, 126, 127, 130, 131) and `attendance.export` (169). The `access.self-administration` grant to 1537 is the documented exception. |
| H4 | 123 reports to himself. Inspection (30) has no head. O&M (21) has 0 members and is headed by 896. |
| H5 | There is one SA (151), and it is also the TMC Manager's daily account. |
| H6 | `default_roles` validation forbids only 5 names, so AD could make DM or OMD a whole department's default. |
| H7 | Spatie `syncRoles()` / `syncPermissions()` detach everything, then attach (verified in vendor). If the pivot tables are MyISAM (audit P-9), a mid-way failure leaves a user with no roles. |
| H8 | `syncRoles` writes only `Log::info`. There is no access audit table, and Spatie role/permission events are off. |

---

## 3. Current roles (Task B)

| Role | Lvl | Holders (active / DB) | Perms (live / dead) | Assessment | Target |
|---|---|---|---|---|---|
| SA | 1 | 1 / 1 | 447 (132 / 315) | global; also 151's daily account | keep; O-11 |
| AD | 10 | 0 / 0 | 444 (132 / 312) | holds access administration | keep; strip access administration (V7) |
| OMD | 15 | 1 / 1 | 77 (46 / 31) | O&M plus all quality, daily works, compliance (dead) and people views; level outranks HRM and DA | slim to `om.*`; level 45 in Phase B |
| HRM | 20 | 0 / 0 | 242 (80 / 162) | `users.impersonate` (dead); access administration; settlement manage + approve + disburse in one role | keep; strip; SoD 5 |
| Project Manager | 20 | 0 / 0 | 40 (21 / 19) | role-name grants (§2.4) | **retire** |
| DA | 25 | 2 / 2 | 52 (46 / 6) | exact, tested, clean | keep |
| DM | 30 | 3 / 3 | 86 (50 / 36) | line management + people admin + 6 functional modules + dead HR modules | slim (A); merge into LM (B) |
| MIQ | 35 | 1 / 1 | 43 (30 / 13) | one-person role (the seeder names Habibur), mixing QC, O&M and DW | **split** into MI + QM + DWM |
| TL | 40 | 0 / 0 | 37 (18 / 19) | team approvals plus dead training/performance permissions | **rename** to Line Manager (5 permissions) |
| TMC | 45 | 4 / 5 | 26 (21 / 5) | `daily-works.*` via a LIKE wildcard; self-service duplicates | slim to 11 `om.*` |
| Senior Employee | 50 | 0 / 0 | 26 | no use | **retire** |
| Admin | 50 | 0 / 2 | 0 | hidden grants (§2.4) | **delete** |
| HPO | 55 | 0 / 0 | 27 (24 / 3) | `daily-works.*`; duplicates | slim to 12 `om.*` (no holders) |
| EMP | 60 | 33 / 48 | 28 (7 / 21) | 2 active users lack it | keep; B wires `own.*`; C prunes dead |
| DWC | 60 | 22 / 35 | 4 (4 / 0) | `tasks.view` is the fail-open legacy read (E8) | drop `tasks.view` |
| Contractor / Intern | 70 / 80 | 0 / 0 | 8 / 5 | no holders | **retire** |

---

## 4. Target catalog (Task C)

### 4.1 Roles after Phase A

Only the SA assigns any of these roles (owner decision); "System" means automatic assignment by a rule the SA owns.

| Role | Type | Level A → B | Scope | Dept default | Assigned by | Holders after A |
|---|---|---|---|---|---|---|
| Super Administrator | global admin | 1 | global (`Gate::before`) | forbidden | SA | 151 |
| Administrator | global admin | 10 | global | forbidden | SA | — |
| HR Manager | global HR | 20 | global | forbidden | SA | — |
| Department Admin | scoped people admin | 25 | managed departments: own (`department.admin`), `manager_id` and grants | forbidden | SA | 169, 1537 |
| Department Manager | line, department | 30 → **retired in B** | own department (role-based) | not eligible | SA | 123, 169, 896 |
| **Line Manager** (renamed TL) | line, team | 40 | reporting subtree ∪ headed departments ∪ grants | not eligible (derived in B) | SA → system | 120, 126, 127, 1536, 130 |
| O&M Director | functional manager | 15 → **45** | O&M records (single operation) | not eligible | SA | 896 |
| **Quality Manager** (new) | functional manager | 45 | project NCR register; B: own department | not eligible | SA | 123, 127, 169, 896 |
| **Daily Works Manager** (new) | functional manager | 45 | own department's works (A: through the DM role or designation; B: DepartmentScope) | not eligible | SA | 123, 127, 169, 896 |
| **Maintenance Inspector** (renamed MIQ) | functional contributor | 35 → **50** (in A) | O&M records | not eligible | SA | 127 |
| TMC Operator | functional contributor | 45 → 50 | TMC / O&M records | not eligible | SA | 306, 308, 309, 397 |
| Highway Patrol Officer | functional contributor | 55 → 50 | O&M records | not eligible | SA | — |
| **Quality Contributor** (new) | functional contributor | 55 | NCR register | candidate (O-6) | SA / system | — |
| Employee | base | 60 | self | everyone | system | 35 |
| Daily Works Contributor | functional default | 60 | own works (incharge / assigned / jurisdiction) | QC (11) | system | 22 |

**Retired in Phase A:** Admin, Project Manager, Senior Employee, Contractor, Intern. Retiring Admin is safe: `hasRole()` with an unknown name returns false (a `contains` check, verified in vendor), and no `User::role('Admin')` call exists.

### 4.2 Exact permission sets, Phase A (existing permissions only)

| Role | n | Permissions |
|---|---|---|
| Employee | 28 | unchanged: `attendance.own.{punch,view}`, `leave.own.{view,create,update,delete}`, `profile.own.{view,update}`, `profile.password.change`, `core.{dashboard,stats,updates}.view`, `communications.own.view`, `hr.selfservice.*` (10), `performance-reviews.own.view`, `training-feedback.own.{view,create}`, `training-assignment-submissions.create`, `hr.safety.incidents.create` |
| Daily Works Contributor | 3 | `daily-works.{view,create,export}`; −`tasks.view` |
| Daily Works Manager | 6 | `daily-works.{view,create,update,delete,import,export}` |
| Quality Contributor | 6 | `quality.view`, `quality.ncr.{view,create,update}`, `quality.inspections.view`, `quality.calibrations.view` |
| Quality Manager | 15 | `quality.*`: `quality.view`, `quality.dashboard.view`, `quality.settings`, `quality.ncr.{view,create,update,delete}`, `quality.inspections.{view,create,update,delete}`, `quality.calibrations.{view,create,update,delete}` |
| O&M Director | 26 | `om.*` (all 26) |
| Maintenance Inspector | 13 | `om.dashboard.view`, `om.maintenance.{view,manage}`, `om.pm.manage`, `om.inspections.manage`, `om.inventory.manage`, `om.equipment.{view,manage}`, `om.safety.{view,manage}`, `om.sla.view`, `om.research.view`, `om.ai.manage` |
| TMC Operator | 11 | `om.dashboard.view`, `om.traffic.{view,manage}`, `om.toll.{view,manage}`, `om.incidents.{view,manage}`, `om.equipment.view`, `om.shift.manage`, `om.sla.view`, `om.safety.view` |
| Highway Patrol Officer | 12 | `om.dashboard.view`, `om.incidents.{view,manage}`, `om.patrol.manage`, `om.maintenance.{view,manage}`, `om.safety.{view,manage}`, `om.tppd.{view,manage}`, `om.shift.manage`, `om.research.view` |
| Line Manager | 5 | `employees.view`, `attendance.view`, `leaves.view`, `leaves.approve`, `holidays.view` |
| Department Manager | 26 | `employees.{view,create,update,delete,restore,placement.update}`, `departments.view`, `designations.view`, `attendance.{view,create,update,correct,delete,export,manage}`, `holidays.view`, `leaves.{view,create,update,approve}`, `hr.onboarding.{view,create,update}`, `hr.offboarding.{view,create,update}` |
| Department Admin | 52 | unchanged (`ComprehensiveRolePermissionSeeder::departmentAdminPermissionNames()`) |
| HR Manager | 240 | current set − `employees.access.manage`, `department.scopes.manage`, `users.impersonate`; + `leaves.manage` |
| Administrator | 440 | current set − `employees.access.manage`, `department.scopes.manage`, `roles.{create,update,delete}`, `permissions.assign`; + `monitoring.camera.view`, `leaves.manage` |
| Super Administrator | 449 | the whole catalog except `access.self-administration` and `department.admin`, as today |

New permissions in Phase A: `monitoring.camera.view` (module `om`) and `leaves.manage` (module `hrm`).

### 4.3 Phase B: named permissions that need code

Every row keeps or extends today's access for the listed roles. None removes a capability that is in use.

| New / rewired permission | Gates today | Change (route + policy + UI + mobile) | Roles |
|---|---|---|---|
| `attendance.requests.approve` | web `attendance.correct\|create\|update`; mobile `isManagerUser` | regularization, punch-exception, OT and swap decisions | LM, DA, HRM, AD |
| `leaves.approve` | web route; mobile not checked | Require it on both, and keep the chain-position rule | LM, DA, HRM, AD |
| `leave.own.{create,update,delete}`, `profile.password.change` (existing) | `leave.own.view` / ungated | split self-service routes | Employee |
| `petty-cash.own.{request,view}` | ungated | loan request and own history; drop `LEGACY_ROLES` | Employee |
| `daily-works.{status.update,assign,inspection.update}` | `daily-works.view` + policy | the DW sub-actions, web and mobile | DWC, DWM |
| `daily-works.incharge.update` | role SA/AD | incharge change | DWM |
| `daily-works.objections.{create,review}` | creator check / phantom roles | create = own scope; review/resolve/reject with reviewer ≠ creator | DWC (create), DWM (review) |
| `jurisdiction.{view,create,update,delete}` (existing) | HRM; DW tab gated by role | DW Jurisdictions tab; Work Locations UI moves to `attendance.settings` | DWM (−HRM), O-5b |
| `quality.ncr.close`, `quality.ncr.verify` | `quality.ncr.update` | closed/verified transitions; verifier ≠ raiser/closer | QM |
| `quality.si.{view,create,update,delete,close}` | — | Site Instruction register: add SoftDeletes and a `department_id` foreign key | QC: view/create/update; QM: all |
| `om.lookups.manage` | `om.dashboard.view` | `/om/lookups` writes | OMD (SA, AD) |
| `request_logs.{view,delete,clear_all}` (existing) | `attendance.settings` | request-log routes | SA, AD |
| `aeon.use` + entity → permission map | login only | chat; `QueryTool` refuses unmapped tables; `RowScope` uses DepartmentScope | Employee (`aeon.use`); data by each entity's view permission |

### 4.4 Reserved roles (defined now, created when triggered)

| Role | Permissions when created | Scope | Trigger | SoD |
|---|---|---|---|---|
| Petty Cash Approver | `petty-cash.approve`, `petty-cash.view-all` | grant per department, or global | owner names an approver (today only SA) | ⊥ Custodian |
| Petty Cash Custodian | `petty-cash.manage`, `petty-cash.view-all` | same | owner names a custodian | ⊥ Approver |
| Attendance Timekeeper | `attendance.{view,create,update,correct,export}`, `attendance.roster.manage` | `user_department_scopes` grant | a department without a DA needs roster keeping (e.g. TMC; today 151 does it as SA) | no self-correction (existing DSD) |
| Asset Custodian | `hr.assets.{view,manage}` | grant | owner names a storekeeper (DA covers this today) | — |
| Toll Inspector | `om.toll.{view,manage}` | Inspection (30) | a toll audit UI (route exists, no UI) | candidate Inspection default |
| Reports Viewer | `reports.view` | grant | a Reports module with routes | — |
| Training Coordinator | `training-{sessions,enrollments,materials,assignments}.*` | grant | a Training module | — |
| Performance Reviewer | `performance-reviews.{view,create,update}` | subtree | a Performance module | reviewer ≠ approver (LM) |
| Settlement Disburser (finance) | `hr.settlement.disburse` | global | `hr_final_settlement` flag turned on | ⊥ `hr.settlement.approve` |

### 4.5 Hierarchy, outranks and the subset rule

The decision chain in `canManage` runs in this order:
1. The ancestor guard blocks the action.
2. For a target in the actor's managed departments, `withinDelegation` applies:
   - the target is not global;
   - the target's managed departments ⊆ the actor's;
   - and either the actor `outranks` the target, or the target's elevated permissions ⊆ the actor's.
3. Outside managed departments, the actor must strictly outrank the target.

| Actor → target | Today | After A | After B | Deciding rule |
|---|---|---|---|---|
| 169 Fahim → 123 Bashar | ✗ | ✗ | ✗ | ancestor guard (123 is his manager) |
| 169 → 896 Wang Fu | ✗ | ✗ | ✗ | 896 heads O&M (21) ⊄ {11}; also not outranked while OMD is 15. **After B this depends on 896 staying head of 21 (O-10).** |
| 169 → the other 19 QC staff (incl. LM / QM / DWM holders) | ✓ | ✓ | ✓ | best level 25 < 40 / 45 / 60; stays 19 of 21 |
| 896 → 169 | **✓** (15 < 25) | ✓ | ✗ | Phase B fix: a functional role no longer outranks an administrator |
| 123 → 169 | ✗ | ✗ | ✗ (✓ if O-7 gives 123 DA) | Fahim's DA permissions ⊄ Bashar's |
| 123 → 896 | ✗ | ✗ | ✗ | 896's departments {11, 21} ⊄ {11} |
| LM (126) → 122, 131 | n/a | approvals only | approvals only | 40 < 60; LM holds no employee write permissions |
| 1537 Mahadi → 160002 | ✓ | ✓ | ✓ | unchanged; self via the exception only |

Every functional or default role sits at level ≥ 45, so it never changes who a DA (25) or DM/LM (30/40) can administer. Department defaults stay "ordinary" for the subset rule. Because `DepartmentDefaultRoles::allManaged()` is global, rule R4 (low-privilege defaults) is mandatory.

### 4.6 Department default roles

| Department | Today | Phase A | Phase B option |
|---|---|---|---|
| Quality Control (11) | [DWC] | [DWC] | [DWC, Quality Contributor] (O-6) |
| Contract (12) | — | — | — |
| O&M (21) | — | — | — (0 members) |
| TMC (22) | — | — | — (2 of 6 members aren't operators; keep TMC Operator assigned individually) |
| Inspection (30) | — | — | Toll Inspector once the toll UI ships |

Changes to the default-role rule:
- Only the SA may edit it; today `DepartmentController` also allows AD.
- Add the eligibility rule R4 to the validation.
- `pages.jsx` must read a server flag (`auth.isBaseOnly`) instead of the hard-coded `BASE_ROLES`, before a second default role is added (B8).

---

## 5. Assignment matrix (Task D)

**How to read the loss column:**
- *functional*: a capability that is in use is removed. Owner pre-check required.
- *no-consumer*: the route exists but no web or mobile client calls it.
- *covered*: the permission is UI-only without a route, or its effect is still provided by another permission.
- *dead*: not enforced anywhere.

Proposed roles are for Phase A.

| ID | Name | Dept | Designation | Reports to | Current | Proposed | Gained | Lost | Direct grants removed |
|---|---|---|---|---|---|---|---|---|---|
| 120 | Debashis Jha | QC | Supervision Engineer | 123 | EMP+DWC | EMP+DWC+**LM** | +5 | 1 no-consumer (`tasks.view`) | `leave.own.view` |
| 122 | Subrata Kumar Chaki | QC | QC Inspector | 126 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | `leave.own.view` |
| 123 | Md. Abul Bashar | QC | QC Manager (head) | 123 (self) | DM+DWC | **EMP**+DM+DWC+**DWM+QM** | +25 | 2 no-consumer (`tasks.*`); 5 covered; 29 dead | `leave.own.view` |
| 126 | Prodip Kumar Saha | QC | Supervision Engineer | 123 | EMP+DWC | EMP+DWC+**LM** | +5 | 1 no-consumer | `leave.own.view` |
| 127 | Md. Habibur Rahman | QC | Supervision Engineer | 123 | EMP+MIQ+DWC | EMP+DWC+**MI+QM+DWM+LM** | +5 | 1 no-consumer | `leave.own.view` |
| 131 | Md. Fuad Amin | QC | QC Inspector | 126 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | `leave.own.view` |
| 142 | Md. Sobuj | QC | QC Inspector | 127 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 143 | Md. Uzzal Mia | QC | QC Inspector | 120 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 145 | Md. Babar Sardar | QC | Asst. QC Inspector | 1536 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 149 | Muhammad Rajibul Hoque Molla | QC | Electro Mechanical Engineer | 123 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 152 | Md. Emamul Hasan Jasim | QC | Office Engineer | 123 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 159 | A. K. M Shifur Rahaman | QC | QC Inspector | 120 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 169 | Md. Fahim Hossain | QC | Office Engineer | 123 | DM+EMP+DA+DWC | EMP+DA+DM+DWC+**QM+DWM** | +11 | 2 no-consumer; 5 covered; 29 dead | `attendance.export` |
| 272 | Md. Main Uddin Sarker | QC | Asst. QC Inspector | 1536 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 304 | Md. Mazed Mia | QC | Office Assistant | 123 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 307 | Md. Abdul Hannan | QC | Office Assistant | 123 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 356 | Nymul Islam | QC | Asst. QC Inspector | 127 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 538 | A.S.M Oli Ahammed | QC | Asst. QC Inspector | 1536 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 1536 | Md. Raisul Islam Rahat | QC | Supervision Engineer | 123 | EMP+DWC | EMP+DWC+**LM** | +5 | 1 no-consumer | — |
| 7 | MD. Munirujjaman | QC | QC Inspector | 127 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 896 | Wang Fu | QC | Deputy QC Manager; head of O&M | 123 | DM+OMD+DWC | **EMP**+DM+OMD+DWC+**QM+DWM** | +14 | 2 no-consumer; 5 covered; 44 dead (incl. `compliance.*`) | — |
| 97 | Md. Nazmul Hasan | QC | Asst. QC Inspector | 120 | EMP+DWC | EMP+DWC | 0 | 1 no-consumer | — |
| 130 | Kazi Rayhan | Contract | QS Engineer (head) | 123 | EMP | EMP+**LM** | +5 | 0 | `leave.own.view` |
| 154, 155, 301, 302 | Shajahan Ali, Razib Mia, Suza Ud Doula, Sudip Dhar Sharma | Contract | Surveyor / Junior QS | 130 | EMP | EMP | 0 | 0 | — |
| 151 | Emam Hosen | TMC | TMC Manager (head) | 896 | SA+EMP | SA+EMP | 0 | 0 | — (O-11) |
| 306, 308, 309, 397 | Tanvir Mahmud Saif, Zidan Ul Azim, Md. Ali Amzad, Keshab Lal Kundu | TMC | TMC Operator | 151 | EMP+TMC | EMP+TMC | 0 | **6 functional each** (`daily-works.*`): **pre-check Q2, O-5** | — |
| 310 | Md. Razon Miah | TMC | TMC Admin Assistant | 151 | EMP | EMP | 0 | 0 | — |
| 1537 | Mahadi Hasan | Inspection | Toll Inspector Incharge | — | EMP+DA (+exception) | unchanged | 0 | 0 | keeps `access.self-administration` |
| 160002 | MD HAIDAR HOSSAIN | Inspection | Toll Inspector | 1537 | EMP | EMP | 0 | 0 | — |

**Gains in detail:**
- **LM** (120, 126, 127, 1536, 130): `employees.view`, `attendance.view`, `leaves.view`, `leaves.approve`, `holidays.view`. Their scope is their reporting subtree; for 130 it is all of Contract, because he is its recorded head.
- **QM** (123, 169): `quality.ncr.delete`, `quality.inspections.*`, `quality.calibrations.*`, `quality.settings`, `quality.dashboard.view`.
- **EMP** (123, 896): 14 self-service permissions, mostly dead. They also start appearing in the attendance, roster and report lists (H1).

**Losses in detail:**
- *Covered* (123, 169, 896):
  - `hr.timeoff.approve`: they remain managers on mobile through department headship.
  - `projects.analytics`: the Command Center is still shown via `daily-works.view`.
  - `performance-reviews.{view,update,approve}`: UI component only, no route.
- *Dead*: hr analytics, skills, competencies, documents, benefits, safety and timeoff permissions; `reports.*`; `tasks.{assign,update,delete}`; `performance-analytics.view`; `performance-reviews.create`; and `compliance.*` (896).

**Totals for Phase A:**
- 27 of 35 users change; 8 users are unchanged (154, 155, 301, 302, 310, 151, 1537, 160002).
- +75 permission grants, of which **27 are live**: 25 Line Manager grants plus `quality.ncr.delete` for 123 and 169.
- Losses: **24 functional** (TMC `daily-works.*`, subject to Q2), 25 no-consumer (`tasks.*`), 15 covered, 102 dead.
- 8 direct grants removed, none of them a loss.
- 31 orphan role rows removed (H2), plus the `Admin` role.

**Fahim's "full CRUD on all QC entities", delivered through roles:**

| QC entity | Create | Read | Update | Delete | Source |
|---|---|---|---|---|---|
| NCR (soft delete) | ✓ | ✓ | ✓ | ✓ (gained) | QM. Phase B adds close/verify. |
| Daily Works / RFI | ✓ | ✓ | ✓ | ✓ + import/export | DWM; department scope via DM in A, DepartmentScope in B. Phase B adds incharge and objection review. |
| RFI objections | ✓ | ✓ | own | own | policy + DWC/DWM. Phase B: `daily-works.objections.review`. |
| Inspections, Calibrations | permissions ✓ | | | | QM. No UI yet (§2.6). |
| Site Instructions | — | — | — | — | No permissions or UI. Phase B: `quality.si.*` via QM. |
| Jurisdictions | — | — | — | — | SA-only today. Phase B: DWM (O-5b). |

**Phase B per-holder deltas (DM retirement, O-7):**

| Holder | Roles after Phase B | Loses (live) | Keeps through |
|---|---|---|---|
| 169 Fahim | EMP+DA+**LM**+DWC+QM+DWM | `departments.view`; `attendance.{delete,manage}` (UI flags only) | DA (people / attendance / leave / lifecycle) |
| 123 Bashar | EMP+**LM**+DWC+QM+DWM (+DA if O-7) | Without DA: `employees.{create,update,delete,restore,placement.update}`, `attendance.{create,update,correct,delete,manage,export}`, `leaves.{create,update}`, `hr.on/offboarding.{create,update}`, `departments.view`, `designations.view` | LM gives team view and approvals; his headship gives department scope |
| 896 Wang Fu | EMP+**LM**(+QC scope grant)+OMD+DWC+QM+DWM | the same people-admin set | LM + grant; and he can no longer administer Fahim (§4.5) |

---

## 6. Segregation of duties (Task E)

| # | Conflict | Type | Today | Target enforcement |
|---|---|---|---|---|
| S1 | Access administration (roles, scopes, default rules) vs. operational duties on the same account | SSD (privileged-account separation) | 151 is both the SA and the TMC Manager; there is one SA | A dedicated SA account, a second break-glass SA, and a non-privileged daily account (O-11) |
| S2 | Request vs. approve one's own leave, OT, regularization, swap or petty-cash loan | DSD | Enforced in the services ✓ | Keep; covered by tests |
| S3 | A Department Admin acting on himself | DSD exception | `access.self-administration`, per person, logged and announced ✓ | Add an expiry date and annual recertification |
| S4 | Petty-cash approval vs. custody (recording / closing) | SSD | Nobody holds either; SA only | Approver ⊥ Custodian (§4.4), checked at assignment |
| S5 | F&F prepare vs. approve vs. disburse | DSD + SSD | prepare ≠ approve enforced ✓; approve and disburse both sit in HRM | Disburse goes to a finance role (§4.4) |
| S6 | NCR raise/close vs. verify | DSD | None (`quality.ncr.update` covers every transition) | `quality.ncr.close` / `verify`, with verifier ≠ raiser and ≠ closer |
| S7 | RFI objection create vs. review/resolve | DSD | Review is effectively SA-only (phantom roles) | `daily-works.objections.review`, with reviewer ≠ creator |
| S8 | Role definition vs. role assignment | SSD | Both SA | Accept at this size; dual control (a second SA approves) for SA-role changes |
| S9 | Default-role rule editing vs. role assignment | — | SA and AD | SA only |
| S10 | Authority over one's own manager | DSD | `DepartmentScope::ancestorIds` ✓ | Keep |
| S11 | Line approver vs. attendance record correction | — | DA holds both, with audit history | Accept for DA; LM gets approve only |

**How each type is enforced:**
- **SSD:** a `config/access.php` `ssd` list, checked by a new `UserManagementService::assertSodConstraints()` on every role assignment, by the apply command (§7.2), and by a scheduled `access:review` report.
- **DSD:** service checks, which already exist for S2, S3, S5 and S10 and must be added for S6 and S7.

---

## 7. Rollout (Task F)

The owner approves §5 and §8 first. Production runs only after a verified batch, following the production-deploy procedure.

### 7.1 Pre-flight (read-only, production)

```sql
-- Q1 orphan role rows (expect 31)
SELECT mhr.model_id, r.name, u.deleted_at FROM model_has_roles mhr JOIN roles r ON r.id = mhr.role_id
LEFT JOIN users u ON u.employee_id = mhr.model_id
WHERE mhr.model_type = 'App\\Models\\User' AND (u.employee_id IS NULL OR u.deleted_at IS NOT NULL);
-- Q2 TMC daily-works footprint (O-5: remove daily-works.* from TMC only if 0)
SELECT COUNT(*) FROM daily_works WHERE incharge IN ('306','308','309','397') OR assigned IN ('306','308','309','397');
-- Q3 legacy task endpoint use, 90 days (expect 0)
SELECT COUNT(*) FROM request_logs WHERE (url LIKE '%/tasks-all%' OR url LIKE '%/task/add%') AND created_at > NOW() - INTERVAL 90 DAY;
-- Q4 storage engines of the RBAC tables (H7)
SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
AND TABLE_NAME IN ('roles','permissions','role_has_permissions','model_has_roles','model_has_permissions','departments');
```

### 7.2 Phase A: catalog data and tooling (no change to authorization code paths)

| Step | Artifact | Content |
|---|---|---|
| A1 | migration `…_create_access_audit_logs_table` | `access_audit_logs`: actor_id, subject_id, action, before (json), after (json), reason, plan_hash, ip, user_agent, created_at. Turn on `permission.events_enabled` and add a `RoleAttached`/`RoleDetached`/`PermissionAttached`/`PermissionDetached` listener that writes to it. The four events exist in 6.25 (verified). |
| A2 | migration `…_seed_role_catalog_v1_additive` | `Permission::firstOrCreate` for `monitoring.camera.view` and `leaves.manage`. `Role::firstOrCreate` for QM (45), QC (55) and DWM (45) with their exact sets. Rename by id: TL → Line Manager (40) and MIQ → Maintenance Inspector (50), only if the new name is free. Set LM's new set (it has 0 holders). MI keeps its current 43 permissions until A4. **Nothing is removed yet**, so no current holder loses access. |
| A3 | `php artisan access:apply-catalog` (new command) | The approved §5 plan (JSON generated from this document); see below. |
| A4 | migration `…_sync_role_catalog_v1_exact` | Exact sets for DM, OMD, MI, TMC, HPO, DWC, HRM and AD (§4.2). Delete retired roles **only if they have 0 holders**. Refuse to run if any active user would lose a permission outside the plan's `expected_losses`. Pivots use diff-based `$role->permissions()->sync($ids)` (only the differences), not Spatie `syncPermissions()`, which detaches everything first (H7). `forgetCachedPermissions()` before and after. `down()` restores the frozen previous sets. |
| A5 | seeders | `ComprehensiveRolePermissionSeeder` and `OmRbacSeeder` switch to `syncPermissions` from one `RoleCatalog` definition. Remove the LIKE wildcards, the `createRoles` entries for retired roles, and `MonitoringRoleSeeder`'s empty roles. |

Run order on deploy day: A1 → A2 → A3 (dry-run, backup, apply, verify) → A4. A4 sits in the next deploy, after A3 is verified.

**`access:apply-catalog`:**
- **Modes:** `--dry-run` (the default), `--backup`, `--apply --actor=<SA id> --reason="…" --plan-hash=…`, `--restore=<backup>`.
- **Dry-run:**
  1. Load the plan and resolve users `withTrashed`.
  2. Compute current vs. target roles and effective permissions.
  3. Check that every active user gets Employee.
  4. Run the SSD check.
  5. Abort on any loss not listed in `expected_losses`.
  6. Print the per-user diff and the plan hash.
- **Backup:** JSON of `roles`, `permissions`, `role_has_permissions`, `model_has_roles`, `model_has_permissions` and `departments.default_roles`, written to `storage/app/access-backups/<ts>/`, plus a `mysqldump` of those tables.
- **Apply:**
  1. Refuse if the hash differs from the dry-run.
  2. For each user, inside `DB::transaction`: call `App\Services\Admin\UserManagementService::syncRoles($user, $target)`, which also bumps the mobile sync epoch.
  3. Revoke direct permissions the plan doesn't keep, then `app(DepartmentScope::class)->forget($user)`.
  4. Write an `access_audit_logs` row: action `catalog.apply`, before/after, reason, hash.
  5. Detach roles from trashed users (31 rows).
  6. Delete `Admin` once it has 0 holders.
- **Verify:** recompute and compare with the plan; exit non-zero on drift; notify the SAs.
- **Restore:** re-applies a backup through the same path, with an audit row.

### 7.3 Tests

Run in Docker (`dev-php:8.3`), one file at a time.

| Test | Asserts |
|---|---|
| `Access/RoleCatalogSpecTest` | each role's exact set and level; retired roles absent; SA = catalog − 2; seeder sets == migration constants (pattern: `DepartmentAdminRoleTest`) |
| `Access/PermissionReferenceIntegrityTest` | every permission in route middleware and in `can`/`hasPermissionTo`/`hasAnyPermission` literals exists (no phantoms). Every catalog permission is referenced, or listed in a reserved allowlist that has a review date. |
| `Access/RoleNameAuthorizationTest` (B) | static scan: no `hasRole`/`hasAnyRole`/`User::role`/`roles.includes` outside an allowlist (R1) |
| `Access/CatalogDelegationTest` | a production-mirrored QC fixture: the §4.5 table row by row (19 of 21; 123 and 896 protected; LM approves its subtree only); defaults stay non-elevated |
| `Access/CatalogRouteMatrixTest` | generalize `DepartmentAdminRouteMatrixTest` to every catalog role (deny by default; no 5xx) |
| `NavigationRoutesTest` + `pages.test.jsx` | for each nav item, nav gate ⊆ route permissions (22 O&M mismatches + Request Logs are known failures until B6); per-role nav snapshots for EMP, EMP+DWC, LM, QM, DWM, TMC, DA, DM |
| `Access/AssignmentPlanTest` | the plan validates: users and roles exist, every active user has Employee, SSD holds, `expected_losses` matches §5 |
| mobile `menuAccess.test.js` | O&M section for `om.*` only; a Quality section for `quality.*` (B8) |

### 7.4 Phase B: code, in priority order

| # | Change | Fixes |
|---|---|---|
| B1 | Aeon: `aeon.use`; entity → view-permission map; deny unmapped tables; `RowScope` via DepartmentScope | E1 (Critical) |
| B2 | Replace every role-name check with permissions; delete the phantom names; DW `isPrivilegedUser` becomes a permission | §2.4, R1 |
| B3 | Daily Works: scope via DepartmentScope; mobile list requires `daily-works.view`; split the sub-actions (§4.3); UI gates on permissions | E2, E3 |
| B4 | Line management: `attendance.requests.approve`; leave approval aligned between web and mobile; LM reconciler from `report_to`; the managers picker (`/api/users/managers/list`) based on reports, not role names | E4, V2 |
| B5 | Leaver: strip roles at LWD and soft delete; restore re-provisions Employee + defaults only | R7, H2 |
| B6 | O&M: align routes to the granular permissions; `om.lookups.manage`; request-log routes on `request_logs.*` | E5, E6 |
| B7 | Quality: NCR nav; close/verify; Site Instruction register; inspection and calibration UI | E7, §2.6 |
| B8 | `auth.isBaseOnly` from the server; mobile Quality section split from O&M | V6 |
| B9 | `default_roles`: SA only, plus the eligibility rule R4 | H6 |
| B10 | SSD guard in `UserManagementService`; `access:review` quarterly report (privileged roles every quarter, others twice a year; ISO A.5.18) | §6 |
| B11 | Retire DM: LM + grants per §5; OMD 45, TMC/HPO 50 | V1, V4 |
| B12 | Petty cash: `petty-cash.own.*`; drop `LEGACY_ROLES`; create the Approver and Custodian roles when named | E9 |

### 7.5 Phase C: catalog cleanup (roadmap decision O-13)

- Delete dead namespaces that are not on the roadmap. Removing one from SA and AD has no effect, because SA bypasses checks.
- Retire `hr.selfservice.*`, which duplicates `*.own.*`.
- Retire `tasks.*` together with the legacy Tasks module.

---

## 8. Owner decisions

| # | Decision | Recommendation |
|---|---|---|
| O-1 | Approve the Phase A catalog (§4.2) and matrix (§5) | Approve |
| O-2 | Make Abul Bashar (123) Quality Manager + Daily Works Manager (gains NCR delete, inspections, calibrations, settings) | Yes; he is the QC Manager and head |
| O-3 | Habibur (127): keep today's breadth through MI + QM + DWM, or reduce to Quality Contributor (losing NCR/inspection/calibration delete and settings, DW update/delete/import, and O&M manage). After B3, DWM widens his DW view to all QC. | Preserve now; review at the first quarterly access review |
| O-4 | Line Manager for 120, 126, 127, 1536 and 130 (+5 permissions each; web approvals match mobile) | Yes |
| O-5 | Remove `daily-works.*` from TMC Operator (requires Q2 = 0); O-5b: give DWM `jurisdiction.*` in Phase B | Yes; yes |
| O-6 | Quality Contributor: a QC department default (NCR raise/update for all 22), individual assignment (e.g. Supervision Engineers and QC Inspectors), or none | Individual assignment to Supervision Engineers after B7 and B8 |
| O-7 | Phase B DM retirement: should Bashar keep people administration (add DA), or leave it with Fahim? Wang Fu: a standing QC scope grant as deputy? | Leave it with Fahim; grant Wang Fu the scope |
| O-8 | Abul Bashar's `report_to` (currently himself) | Set to null (top of the organization; requests route to HR/SA) or to the client's Project Director if he gets an account |
| O-9 | Inspection (30) head | Set `manager_id = 1537` (no access change) |
| O-10 | Keep 896 as head of the empty O&M department? This is what protects him from Fahim after Phase B. | Keep, or protect him explicitly with an O&M scope grant |
| O-11 | A dedicated SA account for Emam Hosen plus a second break-glass SA; his daily account becomes EMP + LM (+ TMC functions) | Yes |
| O-12 | Remove the 31 orphan role rows and delete `Admin` | Yes |
| O-13 | Which dead modules are on the roadmap (Phase C) | List them |
| O-14 | Who holds Petty Cash Approver and Custodian | Name them, or SA only |
| O-15 | Strip access administration from AD and HRM (`employees.access.manage`, `department.scopes.manage`, `roles.*` writes, `permissions.assign`) and restrict `default_roles` editing to SA | Yes (owner rule: only SA assigns roles) |
