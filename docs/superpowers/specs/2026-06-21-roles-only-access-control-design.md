# Roles-Only Access Control + Stale-Feature Trim — Design Spec

**Date:** 2026-06-21
**Branch (suggested):** `refactor/roles-only-access-control`
**Status:** Awaiting user review

## 1. Goal

Replace the current three-layer access system (Spatie roles **+** Spatie permissions **+** hardcoded permission maps) with a single, simple **roles-only strict hierarchy**. In the same effort, delete code for features that are not reachable from the live navigation (`resources/js/Props/pages.jsx`), while preserving everything the mobile app and shared infrastructure depend on.

Two outcomes:
- **A. Roles-only hierarchy** — one effective role level per user; access is decided by "minimum required level"; no permission entity, no Spatie, no hardcoded maps.
- **B. Stale-feature trim** — remove controllers/models/policies/pages/migrations/seeders for unbuilt modules.

## 2. Current state (what exists today)

- **Spatie `laravel-permission` v6** with ~10 roles and ~400 permissions; role→permission mapping seeded in `ComprehensiveRolePermissionSeeder`.
- **Permission-based gating everywhere:** 60 route guards (`middleware('permission:x.y')`), ~284 `$user->can()` / policy checks across ~60 backend files, frontend gating on the `auth.permissions` array shared in `HandleInertiaRequests`.
- **Hardcoded duplication:** `permissionUtils.js` `getRolePermissions()` map (role keys/permission names that don't even match the real Spatie ones — dead/misleading), `RolePermissionService::ENTERPRISE_MODULES` constant, redundant middleware (`CheckPermission`, `PermissionMiddleware`), the disabled "Module Permission Registry."
- **Live role usage (dev DB, 31 users):** Super Administrator (1), Administrator (4), Department Manager (1), Employee (29). The other 6 roles have **0** users.

## 3. Part A — Roles-only strict hierarchy

### 3.1 Role set (fresh, 6 tiers, linear)

Lower level number = more powerful. Each tier inherits everything below it.

| Level | Role | Adds on top of the tier below |
|------:|------|-------------------------------|
| 1 | **Super Admin** | System internals: impersonation, system monitoring, backups, request logs, (legacy module registry — being removed) |
| 2 | **Admin** | User & role management, company settings |
| 3 | **HR Manager** | Manage org/workforce: employees, departments, designations, holidays, attendance records & corrections, attendance settings (shifts/policies/biometric/roster), leave settings, work-locations, letters, bulk leave admin |
| 4 | **Manager** | Team oversight: view employees, view all attendance, **approve leaves**, approve attendance requests (regularization/overtime/swaps) |
| 5 | **Daily Works Manager** | Manage all daily-works, tasks, reports, RFI objections (create/update/delete/assign/import/export across everyone) |
| 6 | **Employee** | Baseline self-service: own profile, own attendance (view/punch), own leaves, view + create own daily-works & tasks, petty cash, own devices, notifications |

**Ordering decision (confirm at review):** Manager (4) sits **above** Daily Works Manager (5) — a people-manager who approves leaves also gets full daily-works management. If Daily Works Manager should instead be the broader org-wide role above Manager, swap levels 4 and 5.

### 3.2 User-role mapping (migration)

| Old role | New role |
|----------|----------|
| Super Administrator | Super Admin |
| Administrator | Admin |
| HR Manager (0 users) | HR Manager |
| Project Manager (0 users) | Daily Works Manager |
| Department Manager | Manager |
| Team Lead / Senior Employee / Contractor / Intern (0 users) | Employee |
| Employee | Employee |

A user with multiple roles resolves to the **minimum** (most powerful) level.

### 3.3 Data model

- Keep a `roles` table: `id`, `name`, `level` (int, unique), `description`. Seeded with the 6 roles above.
- Keep a `role_user` pivot (`role_id`, `user_id`). (Reuse Spatie's `model_has_roles` data via migration, then drop Spatie tables — see 3.7.)
- **Drop**: `permissions`, `role_has_permissions`, `model_has_permissions` tables and the Spatie package.
- `User` gets: `roles()` relation, `level` accessor (`min` of role levels, default = Employee level 6 / or "no access" if none), `hasMinRole(string|int)` helper, `isSuperAdmin()`.

### 3.4 Enforcement (single source of truth)

- **One `Gate::before`** in `AuthServiceProvider`: grant if `$user->level <= $requiredLevel` for ability checks expressed as levels; keep the existing self-protection guard (a user cannot delete / change-role / toggle-status on themselves).
- **One middleware** `role.min:{Role}` (alias) replacing all `permission:*` and ad-hoc `role:*` guards. It maps the role name to its level and allows when `user.level <= that level`.
- **Per-route minimum tier** is declared at the route (the "capability" lives in the route definition, not a DB table). View-vs-manage distinctions are preserved by tagging different routes with different tiers.
- **Row-level ownership** (e.g. an Employee editing only their own daily work, Manager seeing their team) stays in the surviving policies (`DailyWorkPolicy`, `RfiObjectionPolicy`, `UserPolicy`), rewritten to use `hasMinRole()` + ownership instead of `can('permission')`.

### 3.5 Route → minimum-tier matrix

| Domain / routes | Min role (level) |
|-----------------|------------------|
| dashboard, profile.own, attendance.own (view/punch), leave.own, petty-cash, my-devices, notifications, own daily-works view/create, tasks view | Employee (6) |
| daily-works manage (create/update/delete/import/export/assign/incharge/status), daily-works-summary, objections/RFI, reports, tasks manage | Daily Works Manager (5) |
| employees.view, attendance.view (records + exports), attendance.manage (approve regularization/overtime/swaps), leaves.view, leaves.approve/reject/bulk, leave-summary, pending-approvals | Manager (4) |
| employees CRUD, departments CRUD, designations CRUD, holidays CRUD, jurisdiction/work-locations CRUD, leave-settings, attendance.correct, attendance.settings (shifts/policies/biometric/roster), letters, leaves.create/delete (bulk admin) | HR Manager (3) |
| users CRUD, roles CRUD, company.settings, request-logs | Admin (2) |
| system-monitoring, users.impersonate, backups, (module registry → removed) | Super Admin (1) |

### 3.6 Frontend

- `HandleInertiaRequests` shares `auth.role` (name), `auth.level` (int), `auth.isSuperAdmin`. **Remove** the `auth.permissions` array and the Super-Admin permission-list hack.
- `pages.jsx` and component gates switch from `permissions.includes('x.y')` to `level <= N` (or a small `can(level)` helper).
- **Delete** `resources/js/utils/permissionUtils.js` (hardcoded map) and replace its `hasPermission`/`isAdmin` consumers (34 files) with the level helper.
- The Admin "Users/Roles" UI (`AdminUnified`, `RolesPanel`) becomes role-management only: assign roles to users, edit role name/description/level. **No** per-permission toggles.

### 3.7 Spatie removal sequence

1. Add `roles.level` + `role_user` (or confirm reuse of `model_has_roles`); backfill levels and remap users (3.2).
2. Migrate `User` trait off `Spatie\Permission\Traits\HasRoles` to the new lightweight relation/helpers.
3. Replace all route guards with `role.min:*`; rewrite surviving policies; update `Gate::before`.
4. Update mobile API (`routes/api.php`): remove `permissions` endpoints + `PermissionController`; reduce `RoleController` to role CRUD (no permission sync). Confirm `Api/V1/*` behavior unchanged for the app (role names still returned).
5. Update frontend (3.6).
6. Remove the Spatie package, its config, migrations references, `ComprehensiveRolePermissionSeeder`, `RolePermissionService`, permission seeders.

## 4. Part B — Stale-feature trim (keep only `pages.jsx`-reachable features)

### 4.1 KEEP — active features

Backend controllers: Dashboard, DailyWork, DailyWorkSummary, RfiObjection, Objection, Report, Task, Attendance, all `HRM/*` (Roster, Shift, ShiftSwap, CompOff, PunchException, Policy, Regularization, Overtime), Leave, BulkLeave, Settings/{LeaveSetting, AttendanceSetting, BiometricDevice, RequestLog, CompanySetting}, Employee, Department, Designation, Organization, Jurisdiction, Holiday, Profile, ProfileImage, Education, Experience, User, Role (reduced), Device, UserDevice, Notification, PettyCash, SystemMonitoring, Letter, Auth/*, Api/* (incl. **all `Api/V1/*` for mobile**), VersionController, BiometricWebhook, LocaleController, ApkDownload.

Policies: `DailyWorkPolicy`, `RfiObjectionPolicy`, `UserPolicy` (rewritten for roles-only).

Frontend pages: everything currently under `resources/js/Pages/` (Attendance, Employees, Organization, Project, Settings, Profile, Holidays, Leaves*, PettyCash, WorkLocations, Administration, AdminUnified, Auth, Errors, InstallApp, UserDevices).

### 4.2 REMOVE — stale / unrouted

- **Controllers with no routes:** `HR/TrainingController`, `HR/RecruitmentController`, `HR/OnboardingController`, `HR/SkillsController`, `HR/TimeOffController`, `HR/BenefitsController`, `HR/WorkplaceSafetyController`, `HR/TimeOffManagementController`, `PerformanceDashboardController`, `PicnicController`, `PermissionController`.
- **Module Permission Registry (disabled):** `ModuleController` + routes, models `Module`/`SubModule`/`ModuleComponent`, `ModulePermissionService`, related seeders/migrations.
- **Stale policies:** Benefit, Checklist, Competency, DocumentCategory, HrDocument, Offboarding, OffboardingStep, Onboarding, OnboardingStep, QualityCalibration, QualityInspection, QualityNCR, SafetyIncident, SafetyInspection, SafetyTraining, Skill (16 policies + their `AuthServiceProvider` registrations).
- **Models / migrations / seeders** backing only the above (Benefit, Competency, Skill, Safety*, Onboarding*, Offboarding*, HrDocument, DocumentCategory, Quality*, training/recruitment tables) — **only after** confirming no FK or active-model references.
- **Permission infra:** `RolePermissionService`, `ComprehensiveRolePermissionSeeder`, `ModulePermissionSeeder`, `EventPermissionsSeeder`, permission-related console commands (`DiagnoseRolePermissions`, `TestRoleSystem`, `TestCompleteNavigation`), redundant middleware (`CheckPermission`, `PermissionMiddleware`).

### 4.3 Removal safety rule

For every model/migration deletion: grep for references in **surviving** code first (relations, casts, FKs, factories, tests). If a stale model is referenced by an active one, the reference is removed/neutralized in the same step or the model is retained. Migrations already run on the live MySQL DB are **not** retroactively deleted in a way that breaks `migrate:fresh`; instead add a forward migration to drop dead tables (per the project's MySQL-migration rule).

## 5. Sequencing & risk

Execute **Part A first** (delivers the core value, lower blast radius), verify, then **Part B**. They share the `AuthServiceProvider`/seeder edits so doing A first avoids reworking deletions.

**Known hard spots:**
- "Manager sees only their team" needs a row-level ownership check; the level alone grants "view all." Decide per-domain whether Manager = all or own-team (default in this spec: Manager sees all; tighten later if needed).
- ~284 call sites + 34 frontend files is mechanical but large; do it domain-by-domain with the matrix in §3.5.
- Mobile `Api/V1` must keep returning role names and must not depend on removed permission endpoints.

## 6. Verification

- After Part A: log in as each of the 6 roles (seed one test user each) and confirm nav + page access matches §3.5; confirm mobile `Api/V1` auth + dashboard still return expected role data; `php artisan migrate` clean on MySQL dev DB.
- After Part B: `php artisan route:list` has no dangling references; `composer dump-autoload` + app boots; grep shows no references to removed classes; smoke-test every `pages.jsx` route.
- No `npm run build` (auto-commits) — use `npm run dev` and test at `https://aero-enterprise-suite.test`.

## 7. Out of scope

- Building any of the removed features later (separate effort).
- Changing business logic of surviving features.
- Re-introducing per-permission granularity (explicitly rejected in favor of coarse role-name/hierarchy gating).
