# Attendance Subsystem Deep-Dive — Design

**Date:** 2026-06-18
**Owner:** Emam Hosen
**Status:** Approved (approach), pending spec review

## Goal

Exhaustively investigate the entire Attendance subsystem — every surface and every CRUD operation across records, settings, and types — to find all bugs, backend↔frontend mismatches, validation/data inconsistencies, authorization gaps, UI/layout defects, and **missing sub-features**. Produce a reviewed findings document, then a fix spec, then implement.

## Approach: Investigation-first, two-phase (Approach A)

The subsystem is highly interconnected (shared services, a validator factory, status casing reused everywhere, settings consumed across surfaces), so piecemeal fixing is unsafe. We separate **investigation** (no code changes) from **remediation** (specced, approved, then implemented).

- **Phase 1 — Investigation:** static code trace + Playwright runtime pass. Output: exhaustive findings doc. **No code changes.**
- **Phase 2 — Remediation:** triage findings → fix spec → `writing-plans` → implement with verification.

This document covers Phase 1's scope/method and the gate into Phase 2.

## Scope

### Surfaces
1. **Employee self-service** — `resources/js/Pages/AttendanceEmployee.jsx`, `resources/js/Tables/AttendanceEmployeeTable.jsx`, `PunchStatusCard` (punch in/out, GPS/photo), `api/queries/useAttendanceQuery.js`.
2. **Admin/manager** — `resources/js/Pages/Attendance/AttendancePage.jsx` + `DailyTimesheetTab.jsx`, `MonthlyCalendarTab.jsx`, `SettingsTab.jsx`, `AbsentSidebar.jsx`, `Components/AttendanceOverview.jsx`.
3. **Settings** — `resources/js/Pages/Settings/AttendanceSettings.jsx`, `app/Http/Controllers/Settings/AttendanceSettingController.php`, `app/Http/Requests/Settings/UpdateAttendanceSettingRequest.php`, `app/Models/HRM/AttendanceSetting.php`.
4. **Types (all configs)** — `app/Models/HRM/AttendanceType.php`, `EmployeeAttendanceType.php`, validator factory (`AttendanceValidatorFactory`, `BaseAttendanceValidator`, geo/polygon/route/IP/`BiometricValidator`), `employee_attendance_types` pivot, biometric-device binding, plus any type-management UI (or its absence).
5. **Touchpoints** — biometric webhook/processing (`BiometricProcessingService`, `Api/BiometricWebhookController`), mobile API (`app/Http/Controllers/Api/V1/AttendanceController.php`), sync (`DataSyncService`), exports (`AttendanceAdminExport`, `AttendanceExport`, jobs), reminders, `AttendanceRateLimit`.

### CRUD coverage matrix (every operation, traced FE → route → controller → service → model → DB, and back)

| Entity | Create | Read | Update | Delete |
|---|---|---|---|---|
| **Attendance records** | punch in/out, manual mark-present, bulk-mark-present, (biometric ingest) | employee list, admin daily timesheet, monthly calendar, stats/overview, exports | edit/correct record, symbol update | delete record |
| **Attendance Settings** | first-time create | load into Settings UI | update (office hours, weekend_days, grace, late rules, etc.) | (reset/defaults if any) |
| **Attendance Types** | create type + config | list/show types, per-employee assignment view | update type/config, assign/unassign employees, bind/unbind biometric device | delete type |

### What we hunt for (taxonomy)
- **Functional bugs** — broken/incorrect CRUD, wrong state transitions, broken navigation.
- **BE↔FE contract mismatches** — key/type/shape differences (e.g. `attendance` vs `attendances`, missing `settings.weekend_days`).
- **Validation asymmetry** — FE rule vs BE rule vs DB column constraint.
- **Authorization / IDOR** — ungated endpoints, cross-user access, role-vs-permission desync.
- **Concurrency** — punch/mark/bulk without transactions or locks; double-submit.
- **Data integrity** — status casing inconsistencies, TIME vs DATETIME, timezone/off-by-one, money/number rounding (n/a here but numeric hours).
- **UI/UX (runtime)** — misalignment, spacing, dark-mode invisibility, responsive breakage, empty states, loading/ghost spinners, missing success feedback, ambiguous microcopy, a11y (focus/aria/keyboard), CLS.
- **Inconsistencies across surfaces** — same concept rendered/validated differently in employee vs admin vs settings.
- **Missing sub-features** — CRUD the UI implies but doesn't deliver; settings/type configs with no management UI; type configs that exist in the model/factory but are unreachable from the UI.

## Method

### Phase 1a — Static trace
Read the full file set above; map each CRUD path end to end; cross-reference FE expectations against BE responses, validation rules against DB columns, and UI gates against route middleware. Capture evidence as `file:line` + snippet. Existing tests (`AttendanceMultiConfigTest`, `AttendancePaginateTest`, `AttendanceExportAndStatsTest`, `MobileAttendanceApiTest`) are read as intent/contract references.

### Phase 1b — Playwright runtime pass
- **App URL:** resolve at runtime — try Laragon `.test` host and `http://127.0.0.1:8000` (APP_URL). Start `php artisan serve` if neither responds.
- **Login:** `emam@dhakabypass.com` / `123456789` (seeded admin/super-admin account). If an Employee-only view is needed and unreachable from this account, flag it and request/seed an employee login.
- **Coverage:** each surface across light/dark themes and desktop/mobile viewports; exercise each CRUD action; record screenshots, console errors, network failures (4xx/5xx, contract mismatches), and visual defects.

### Deliverable (Phase 1 output)
`docs/audit/ATTENDANCE_DEEP_DIVE_2026-06-18.md` — findings organized by **surface** then **CRUD operation**, each with severity, category, `file:line`, evidence, impact, fix direction, and `[RUNTIME]` tags where confirmed live. Includes an explicit **Missing sub-features** section and a **cross-surface inconsistencies** section.

## Phase 2 gate (remediation)
After you review the findings doc, we triage (fix now / later / won't-fix), then I invoke `writing-plans` to produce the implementation plan for the agreed fixes, implemented with tests + verification. **No code changes happen before that gate.**

## Out of scope
- The other 12 features (separate effort).
- Non-attendance modules touched only incidentally.
- New attendance capabilities beyond closing identified gaps (any net-new feature is raised for explicit approval, not assumed).

## Success criteria
- Every cell in the CRUD matrix is traced statically and exercised at runtime (or explicitly noted as unreachable + why).
- Findings doc is evidence-backed and reproducible.
- Missing sub-features are enumerated, not just bugs.
- Clear, triaged path into a fix plan.
