# Active-Features Audit — `pages.jsx`

**Date:** 2026-06-17
**Scope:** Static code audit (no runtime) of the 13 active features exposed in `resources/js/Props/pages.jsx`.
**Method:** One subagent per feature, tracing each page's React components/hooks/API calls and each controller's methods, routes, models, form-requests, and policies. Findings cite `file:line` with code evidence. `[RUNTIME]` marks items needing live confirmation.

**Bug taxonomy applied:** Visual/layout, functional/interaction, usability/UX, accessibility, data/edge-case, compatibility/performance (frontend); logic/business-rule, database/storage, security/access-control, concurrency/performance, infra/config (backend); and frontend↔backend integration gaps (contract, validation asymmetry, auth desync, error handling, over/under-fetch).

---

## Severity index (running)

| Feature | Critical | High | Medium | Low |
|---|---|---|---|---|
| Dashboard | 0 | 2 | 5 | 5 |
| Daily Works | 0 | 3 | 5 | 4 |
| My Attendance | 1 | 3 | 3 | 2 |
| My Leaves | 4 | 4 | 4 | 2 |
| Petty Cash | 2 | 3 | 5 | 3 |
| Organization | 0 | 4 | 7 | 3 |
| Attendances (Admin) | 1 | 4 | 5 | 4 |
| Holidays | 1 | 4 | 5 | 4 |
| Leave Management (Admin) | 2 | 4 | 4 | 3 |
| Users / Roles | 3 | 3 | 4 | 3 |
| Company Details | 1 | 0 | 2 | 4 |
| Request Logs | 2 | 3 | 4 | 4 |
| System Monitoring | 0 | 2 | 2 | 4 |
| **TOTAL** | **19** | **39** | **55** | **44** |

**157 findings across 13 features.** Authorization/IDOR and broken FE↔BE contracts dominate the Critical/High tier.

---

## Dashboard

**Files audited:** `resources/js/Pages/Dashboard.jsx`, `app/Http/Controllers/DashboardController.php`, dashboard widget components (`PunchStatusCard`, `UpdatesCards`, `PersonalOverviewCard`, `AttendanceChartWidget`, `QuickLinksWidget`, `PendingTasksWidget`, `ProjectOverviewWidget`, `UpcomingHolidaysWidget`, `WeatherWidget`, `GreetingBanner`), `routes/web.php`, `AttendanceController` (stats), `AttendanceReportService`, role/permission seeders, `HandleInertiaRequests`.

### Findings

- **[High][Integration/Logic] Backend `updates()` gates own-leaves on a permission that does not exist**
  - Location: `app/Http/Controllers/DashboardController.php:101,107`
  - Evidence: checks `leaves.own.view` but seeders only define `leave.own.view` (singular) — `ComprehensiveRolePermissionSeeder.php:70`, `ModulePermissionSeeder.php:63`.
  - Impact: Employees with only own-leave permission get empty `todayLeaves`/`upcomingLeaves`; UpdatesCards always shows "No one is away."
  - Fix: Use `leave.own.view` to match the seeded permission.

- **[High][Integration] Leave `status` never normalized — case mismatch across app**
  - Location: `DashboardController.php:101-127` vs `AttendanceReportService.php:250` (`'approved'`) vs `AttendanceController.php:570` (`'Approved'`)
  - Evidence: `updates()` returns leaves with no status filter; stats endpoints filter on different casing.
  - Impact: Pending/rejected leaves shown as "away"; attendance counts can silently miss leaves stored with the other casing.
  - Fix: Standardize stored casing; filter `updates()` to approved; lowercase-compare everywhere.

- **[Medium][Backend/Perf] `updates()` loads ALL Employee users, serializes full records**
  - Location: `DashboardController.php:83-93` (`->get()->map(fn($u)=>$u->toArray())`)
  - Impact: Over-fetch + PII exposure of every `users` column to client (FE only uses id/name/profile_image_url); scales with headcount on a hot path.
  - Fix: Select only `id,name,profile_image_url`; avoid full `toArray()`.

- **[Medium][Backend/Perf] N+1 / appended-accessor overhead in `updates()` mapping** — `DashboardController.php:88-93`. `toArray()` triggers appended accessors (e.g. `profile_image_url`) per user. [RUNTIME] confirm appends. Fix: constrain columns, disable appends.

- **[Medium][Frontend] Recharts per-bar coloring broken (bare `<rect>` instead of `<Cell>`)**
  - Location: `AttendanceChartWidget.jsx:49-53`
  - Impact: Present/Absent/Late/Leave bars render uniform/default color; possible console warnings.
  - Fix: Use `<Cell key={i} fill={entry.fill} />`.

- **[Medium][Frontend] Duplicate fetches of `route('stats')` (×3) and `route('updates')` (×2) per load** — `PersonalOverviewCard.jsx:55`, `PendingTasksWidget.jsx:19`, `ProjectOverviewWidget.jsx:18`; `UpdatesCards.jsx:187`, `UpcomingHolidaysWidget.jsx:21`. `/updates` is uncached and runs the full employee+leave query twice. Fix: lift fetch to `Dashboard.jsx` or use a shared cache.

- **[Medium][Integration] Team-stats widget gating may not match route middleware** — `AttendanceChartWidget.jsx:103,121` gates on `attendance.view`; verify `attendance.monthlyStats` (`routes/web.php:462`) middleware matches, else silent 403 → empty team chart. [RUNTIME]

- **[Low][Frontend/A11y] Inline `<style dangerouslySetInnerHTML>` grid-span workaround is fragile** — `Dashboard.jsx:99-107,35-93`. Fix: use Radix responsive `gridColumn` or Tailwind `col-span-*`.

- **[Low][Frontend] Misleading "GPS Accuracy: N/A" tile when location unused** — `PunchStatusCard.jsx:862,1410`.

- **[Low][Frontend] Possible double-punch window before camera modal mounts** — `PunchStatusCard.jsx:887-890` re-enables button before camera dialog opens. [RUNTIME]. Fix: keep `inFlight` guard.

- **[Low][Backend] Dead props on `index()`** — `DashboardController.php:21-26` renders `user/title/status/csrfToken` unused by the page.

- **[Low][Frontend] WeatherWidget indexes hourly array by browser hour against location timezone** — `WeatherWidget.jsx:56-57`.

**Summary:** 0 Critical, 2 High, 5 Medium, 5 Low.

---

## Daily Works

**Files audited:** `resources/js/Pages/Project/DailyWorksUnified.jsx`, `DailyWorkController.php`, `Api/V1/DailyWorkController.php`, `DailyWorkSummaryController.php`, `DailyWorkPaginationService`, `DailyWorkCrudService`, `DailyWorkValidationService`, `DailyWorkPolicy`, `DailyWorkFilterable` trait, `DailyWork` model, `routes/web.php`, `routes/api.php`, `app.jsx`, `bootstrap.js`, `DeleteDailyWorkForm.jsx`.

### Findings

- **[High][Security/IDOR] Web `update`/`delete` skip per-record policy/ownership check**
  - Location: `DailyWorkController.php:252-263`; `DailyWorkCrudService.php:65-101` (update), `:107-131` (delete)
  - Evidence: these paths only `findOrFail($id)` + `update()` with no `$this->authorize(...)`, unlike `updateStatus`/`updateIncharge`/etc. Routes only enforce coarse `permission:daily-works.update/delete` while `DailyWorkPolicy` enforces incharge ownership.
  - Impact: Anyone with the permission can modify/soft-delete ANY record by id, bypassing incharge rules.
  - Fix: Add `$this->authorize('update'|'delete', $dailyWork)` before mutating.

- **[High][Perf/N+1] Per-date query inside summary generation** — `DailyWorkSummaryController.php:629-631` runs `DailyWorkSummary::where('date',$date)->get()` + `Schema::hasTable` per date in a loop (hit on every Summary-tab load/filter). Fix: `whereIn('date',$dates)->get()->groupBy('date')`, hoist schema check.

- **[High][Integration/Runtime] Inconsistent null-safety on `auth.roles` can crash render** — `DailyWorksUnified.jsx:169` uses `auth.roles.includes(...)` while `:73` uses `auth.roles?.includes(...)`. [RUNTIME]. Fix: optional-chain + default `[]`.

- **[Medium][Integration/Contract] `summary`/`inCharges` props computed but never consumed** — `DailyWorkController.php:166-196` runs heavy grouped aggregation each load; page (`:61`) never uses it. Fix: remove or actually seed Summary tab from it.

- **[Medium][Logic] `filterSummary` lets non-admins pass incharge/jurisdiction filters; other paths gate by admin** — `DailyWorkSummaryController.php:150-153` vs `:220-222`. Inconsistent behavior. Fix: gate by `$isAdmin`.

- **[Medium][Frontend/Dead-end] Objections tab links to non-existent `/objections`** — `DailyWorksUnified.jsx:1279-1303`; real route is `objections.index` (`/workspace/objections`, `routes/web.php:207`). Fix: point button at `route('objections.index')`.

- **[Medium][Frontend/State] "Add Work" success doesn't respect active date/filter; dead `newLastPage`** — `DailyWorksUnified.jsx:528-536`. Ghost row until refetch. Fix: refetch or conditionally prepend.

- **[Medium][Frontend] "Add Work" button shown to users lacking `daily-works.create`** — `DailyWorksUnified.jsx:224-231`; generic 403 on submit. Fix: gate on a `can.create` flag.

- **[Low][Perf/Over-fetch] `unified()` loads all users + all reports (+ each report's daily_works) every load** — `DailyWorkController.php:129-139,184-190`.

- **[Low][Frontend] Debug `console.log` of auth/data every render** — `DailyWorksUnified.jsx:75-78,638-655` (+ backend `\Log::debug` of payloads). Info leak/noise.

- **[Low][Logic/Perf] `whereDate('date',...)` defeats index if `date` is datetime** — `DailyWorkFilterable.php:88-96`, `DailyWorkPaginationService.php:251-257`. [RUNTIME] verify index on date/status/incharge/assigned.

- **[Low][Route] `delete-daily-work` route declared twice** — `routes/web.php:238` and `:382`.

**Summary:** 0 Critical, 3 High, 5 Medium, 4 Low.

---

## My Attendance

**Files audited:** `resources/js/Pages/AttendanceEmployee.jsx`, `resources/js/Tables/AttendanceEmployeeTable.jsx`, `resources/js/api/queries/useAttendanceQuery.js`, `AttendanceController.php`, `Api/V1/AttendanceController.php`, `AttendancePunchService.php`, `routes/web.php`, attendance migration, `Http/Kernel.php`, seeders.

### Findings

- **[Critical][Integration] "My Attendance Records" table is permanently empty — contract mismatch**
  - Location: `AttendanceEmployeeTable.jsx:94-99` vs `AttendanceController.php:369-384`
  - Evidence: FE reads `response.data.attendances` (array with `.punches`, `.total_work_minutes`, …); BE `getCurrentUserAttendanceForDate` returns `{ attendance: <model|null> }` (singular, no aggregated fields).
  - Impact: Table always shows empty state regardless of real data.
  - Fix: Return `{ attendances: [...], total }` in the grouped shape scoped to `Auth::id()`, or change FE to read `data.attendance`.

- **[High][Integration/Logic] Single-day endpoint queried for a whole month — month/pagination dead** — `AttendanceEmployeeTable.jsx:78-92`; BE only uses `date`, returns ≤1 record. Employee cannot browse history. Fix: month-scoped self endpoint.

- **[High][Backend/Perf] Missing indexes on `attendances.date` and `(user_id,date)`** — migration `2024_08_04_230646_create_attendances_table.php:14-23` indexes only PK + user_id FK; queries use `whereDate('date')` + `where('user_id')` everywhere. Fix: composite `['user_id','date']` index; avoid `whereDate`.

- **[High][Backend/Race] Punch in/out has no transaction or row lock — double punch-in possible** — `AttendancePunchService.php:18-58,77-83,109-116`; no unique `(user_id,date)` constraint. Fix: `DB::transaction` + `lockForUpdate`, add guarded unique constraint.

- **[Medium][Backend/Data] `punchin`/`punchout` stored as `TIME` not `DATETIME` — overnight shifts lose date** — migration `:18-19`; code compensates with `addDay()` heuristic (`AttendanceController.php:298-299`, API `:153-155,381-383`). Fix: store full datetimes.

- **[Medium][Frontend] No error/empty distinction when contract fails** — `AttendanceEmployeeTable.jsx:94-101,289-295`; masks the Critical bug. 

- **[Medium][Frontend/A11y/Theme] Hardcoded light-only color tokens in stats cards** — `AttendanceEmployee.jsx:158-159,182-184` (`bg-orange-100`, `bg-success-100`, no `dark:`). [RUNTIME] low contrast in dark mode.

- **[Low][Frontend] Redundant forced `refetch()` on month change** — `AttendanceEmployee.jsx:106-108` (query key already re-runs). Extra request/flicker.

- **[Low][Frontend] Dead/unused props & fixed `selectedDate`** — `AttendanceEmployee.jsx:29,48-49,90-96`.

**Summary:** 1 Critical, 3 High, 3 Medium, 2 Low.

---

## My Leaves

**Files audited:** `resources/js/Pages/LeavesEmployee.jsx`, `resources/js/Forms/LeaveForm.jsx`, `resources/js/api/queries/useLeavesQuery.js`, `LeaveController.php`, `Api/V1/LeaveController.php`, `LeaveQueryService`, `LeaveCrudService`, `LeaveValidationService`, `LeaveOverlapService`, `Models/HRM/Leave.php`, `routes/web.php`, `routes/api.php`.

### Findings

- **[Critical][Frontend] Page references undefined `setLeaves`/`setPagination` — runtime ReferenceError**
  - Location: `LeavesEmployee.jsx:135,200,205,263,314,343,368,403,448` — neither declared (`pagination` is a `useMemo` at `:61`).
  - Impact: Every optimistic add/edit/delete + page/rows/year-filter handler throws `setLeaves is not defined`.
  - Fix: Re-introduce `useState` for leaves/pagination, or rewrite handlers via `queryClient.setQueryData`/`refetch`.

- **[Critical][Integration] LeaveForm calls `fetchLeavesStats()` which is never passed (parent passes `refetchStats`)** — `LeaveForm.jsx:27,115` vs `LeavesEmployee.jsx:521,539`. Success path throws after submit. [RUNTIME]. Fix: align prop name.

- **[Critical][Security/IDOR] Web create/update/delete trust client `user_id`/`id` with no ownership check**
  - Location: `LeaveController.php:144,157,195,244`; `LeaveCrudService.php:30,73,117`
  - Evidence: create uses `'user_id' => $data['user_id']`; update/delete `findOrFail($id)` then mutate; route group only guards `permission:leave.own.view`. `LeaveForm.jsx:91` sends selectable `user_id`.
  - Impact: Any user with own-leave permission can CRUD leaves for ANY employee — horizontal privilege escalation.
  - Fix: Force `user_id = Auth::id()` on create; verify ownership/policy on update/delete.

- **[Critical][Security/IDOR] updateStatus/approve/reject/bulkApprove/bulkReject have no authorization** — `LeaveController.php:229-238,417-476,296-360`. Employee can self-approve own leave or approve/reject others, bypassing approval chain. Fix: approver permission/policy + chain validation.

- **[High][Backend] No leave-balance enforcement — negative balance possible** — `LeaveCrudService.php:21-52,57-83`; balance only clamped for display (`max(0,…)`), hiding overdraft. FE `disabled` is client-only. Fix: validate `daysCount <= remaining_days` server-side.

- **[High][Backend] Update path skips overlap check that create has** — `LeaveController.php:192-218` vs `:146-154`; `checkOverlappingLeaves` supports `$excludeLeaveId` but unused. Fix: run overlap check in `update()`.

- **[High][Backend/Race] No transaction/lock around create — double-submit drains balance/duplicates** — `LeaveCrudService.php:21-52`. [RUNTIME]. Fix: transaction + lock + overlap guard.

- **[High][Backend/IDOR] `bulkApprove`/`bulkReject` update by id with no per-leave authorization** — `LeaveController.php:296-360` (contrast API V1 using `approvalService->approve()` with `canApprove`). Fix: route through approval service per leave.

- **[Medium][Backend] Verbose debug logging of leave/holiday data each request** — `LeaveQueryService.php:88-96,143-148`, `LeaveController.php:81-85`. PII in logs. Fix: gate behind `app.debug`.

- **[Medium][Backend/Perf] Over-fetch: all users + `LeaveSetting::all()` ×2 + `Department::all()` from write endpoints** — `LeaveController.php:62,71,179,217,253`, `LeaveQueryService.php:67,318`.

- **[Medium][Integration] `daysCount` is FE calendar-day diff, trusted by BE — ignores weekends/holidays** — `LeaveForm.jsx:56-65` → `LeaveCrudService.php:34`. Client controls deducted days. Fix: compute business days server-side.

- **[Medium][Frontend] Filter logic exists (status/type/dept/employee/month) but no UI and month never reaches query** — `LeavesEmployee.jsx:222-254` vs only Year select (`:608-620`); `useLeaves` sends only year+user_id. Optimistic adds for other months vanish.

- **[Low][Frontend/A11y] Year `<select>` label not associated; no `aria-label`** — `LeavesEmployee.jsx:608-620`.

- **[Low][Frontend] Dead/duplicate modal helpers & unused refs** — `LeavesEmployee.jsx:152-167,500`.

**Summary:** 4 Critical, 4 High, 4 Medium, 2 Low.

---

## Petty Cash

**Files audited:** `PettyCashController.php`, `PettyCashService.php`, `Models/PettyCashLoan.php`, `Models/PettyCashTransaction.php`, `Jobs/ExportPettyCashTransactions.php`, petty-cash migrations, `routes/web.php:720-732`, `resources/js/Pages/PettyCashUnified.jsx`, `Components/PettyCash/{OverviewPanel,TransactionsPanel,AnalyticsPanel}.jsx`, `Forms/{PettyCashLoanForm,PettyCashExpenseForm}.jsx`.

### Findings

- **[Critical][Integration] `activeLoan.id` is undefined — Transactions & Analytics tabs permanently broken**
  - Location: `PettyCashService.php:96-110` (`getLoanSummary()` omits `id`) vs `PettyCashUnified.jsx:152,163` (`loanId={activeLoan.id}`)
  - Impact: Cannot view transactions/analytics or add expense/reimbursement/repayment (forms post `loan_id: undefined`). Core feature non-functional.
  - Fix: Add `'id' => $loan->id` to `getLoanSummary()`.

- **[Critical][Frontend] `TrendUpIcon` used but never imported — Analytics tab crashes** — `AnalyticsPanel.jsx:148` (imports at `:8`). ErrorBoundary blanks the panel. Fix: import the correct icon.

- **[High][Backend/Business-rule] No over-withdrawal/negative-balance guard on expenses & repayments** — `PettyCashService.php:34-47,64-84`; only `min:0.01` validation. `calculateBalance()` goes negative. Fix: reject if amount would push balance below zero.

- **[High][Backend/Race] Balance updates have no transaction or row lock** — `PettyCashService.php:34-84`, `PettyCashLoan.php:55-59` (read-all-then-save). Money feature → lost updates. Fix: `DB::transaction` + `lockForUpdate` on the loan row.

- **[High][Backend/IDOR] `createLoan` allows multiple active loans per user; no uniqueness guard** — `PettyCashService.php:11-32`; `getUserActiveLoan()->first()` then picks one arbitrarily. POST is directly callable despite hidden UI button. Fix: reject if an active loan exists.

- **[Medium][Frontend/State] Loan creation does `window.location.reload()`** — `PettyCashUnified.jsx:31-35`. Fix: `router.reload({ only: ['activeLoan'] })`.

- **[Medium][Integration/Error] Export returns download URL immediately but file is written async** — `PettyCashController.php:327-337`, `TransactionsPanel.jsx:47-72`, `ExportPettyCashTransactions.php:51-80`. 404 if clicked before queue finishes. [RUNTIME]. Fix: status/polling endpoint.

- **[Medium][Backend/N+1] `getTransactionHistory` orders only by `transaction_date` (date cast) + double `getMedia` per row** — `PettyCashService.php:112-145`. Non-deterministic pagination on busy days; media N+1. Fix: add `orderBy('id','desc')`, eager-load `media`.

- **[Medium][Backend/Security] Internal exception messages leaked to clients** — `PettyCashController.php:56,93,130,166,202,233,266,305,341` (`$e->getMessage()` in 500s). Fix: log + generic message.

- **[Medium][Integration/Validation] No `max` amount vs `decimal(10,2)` DB limit** — controllers `numeric|min:0.01` only; columns cap at 99,999,999.99 → DB out-of-range → 500. Fix: `max:99999999.99` FE+BE.

- **[Low][Backend/Business-rule] Transactions allowed on settled/closed loans** — `PettyCashService.php:64-84` never checks `$loan->status`. Fix: reject when `status !== 'active'`.

- **[Low][Backend/Rate-limit] No throttle on money-mutating endpoints** — `routes/web.php:721-732` (`auth,verified` only).

- **[Low][Frontend/A11y] No success toast; loading is plain text, no `aria-live`/spinner** — `PettyCashExpenseForm.jsx:41-43`, `TransactionsPanel.jsx:100-106`.

- **[Low][Frontend] Client-side type/category filter only filters the current 20-row page** — `TransactionsPanel.jsx:94-98` (server-side pagination). Fix: push filters to backend.

**Summary:** 2 Critical, 3 High, 5 Medium, 3 Low.

---

## Organization

**Files audited:** `Organization/OrganizationPage.jsx` + `Tabs/{Employees,Departments,Designations}Tab.jsx`, `Components/{Department,Designation}Form.jsx`, `Components/Delete{Department,Designation}Form.jsx`, `Tables/EmployeeTable.jsx`, `api/queries/{useDepartments,useDesignations}Query.js`, `OrganizationController.php`, `DepartmentController.php`, `DesignationController.php`, `EmployeeController.php`, `Models/HRM/{Department,Designation}.php`, `HandleInertiaRequests.php`, `routes/web.php`.

### Findings

- **[High][Security/IDOR] `organization.index` route has NO permission gate** — `routes/web.php:363` (only outer `auth`), unlike `/employees`, `/departments`, `/designations`. Any authenticated user loads full directory (all users id/name/email/dept/designation, biometric device serials) as Inertia props. Fix: add `permission:employees.view`.

- **[High][Security/Mass-assignment] Department create/update persist `$request->all()`** — `DepartmentController.php:93,163`. Validator runs but raw request is saved; only `$fillable` limits it. Fix: persist `$validator->validated()`.

- **[High][Security] Employee `store`/`update` skip validation, FormRequest, and (store) authorization** — `EmployeeController.php:65-73,122-123`. Raw `$request->all()` → service; caller-supplied `roles` accepted (`$data['roles'] ?? ['Employee']`) — privilege-escalation vector. Fix: FormRequest + explicit role authorization.

- **[High][Integration/Routing] `designations.list` shadowed by `designations/{id}`** — `routes/web.php:672` (show) registered before `:676` (list); `/designations/list` resolves to `show('list')` → `findOrFail('list')`. Dead endpoint. Fix: reorder or `->whereNumber('id')`.

- **[Medium][Backend/Logic] `updateUserDepartment` strict `!==` compares int vs string — always "changed"** — `DepartmentController.php:238`; designation reset to null on every save. Fix: `(int)` both sides.

- **[Medium][Backend/Cascade] Soft-deleting dept/designation orphans children & users** — `DepartmentController.php:184-198`, `DesignationController.php:188-198`; guard only checks employee count, not child departments/designations/`parent_id`. Fix: block or cascade in a transaction.

- **[Medium][Integration/Contract] Designation delete-guard `employee_count` sourced inconsistently** — `DesignationController.php:97-108` vs `DeleteDesignationForm.jsx:30`; OrganizationController uses `withCount('users as employee_count')`, API uses `withCount('users')`. Stale count can enable delete button. Fix: standardize count source.

- **[Medium][Backend/Race] No transaction/lock on user dept+designation reassignment** — `DepartmentController.php:241-248`. [RUNTIME]. Fix: transaction + `lockForUpdate`.

- **[Medium][Backend/Perf] `index` hydrates all users to count them + ships same dataset to 3 props** — `OrganizationController.php:28-63` (`allManagers`/`managers`/`users`). Fix: `count()` queries; reuse one manager prop.

- **[Medium][Backend/Index] Unindexed `LIKE %term%` + filter/order columns** — `DepartmentController.php:27-35`, `DesignationController.php:70-81`, `EmployeeController.php:348-381`. Fix: index `is_active`/`parent_id`/`department_id`/`designation_id`.

- **[Medium][Frontend/State] Employees tab races optimistic delete decrement against refetch** — `EmployeesTab.jsx:139-145` (+ effect `:114-120`); transient wrong counts, can strand user on empty page. Fix: rely on refetch; handle empty-page.

- **[Low][Integration/Validation] Designation `department_id` required by BE but not marked required in form** — `DesignationForm.jsx:56` vs `DesignationController.php:138`. Ambiguous microcopy.

- **[Low][Frontend/Logic] Inline dept/designation change stores string IDs into optimistic state** — `EmployeeTable.jsx:73-86` (number vs string mismatch). Fix: `Number()`.

- **[Low][Security/Logging] Request bodies + full designation payloads + stack traces logged** — `DesignationController.php:110`, `DepartmentController.php:255-275`, `EmployeeController.php:88-91`. PII leak. Fix: remove/redact.

**Summary:** 0 Critical, 4 High, 7 Medium, 3 Low.

---

## Attendances (Admin)

**Files audited:** `Attendance/AttendancePage.jsx` + `{DailyTimesheetTab,MonthlyCalendarTab,AbsentSidebar}.jsx`, `Components/AttendanceOverview.jsx`, `api/queries/useAttendanceQuery.js`, `api/client.js`, `utils/exportUtils.js`, `AttendanceController.php`, `Settings/AttendanceSettingController.php`, `AttendanceReportService.php`, `Models/HRM/Attendance.php`, `routes/web.php` (attendance block).

### Findings

- **[Critical][Security] Admin attendance data/export endpoints exposed to any authenticated user** — `routes/web.php:131-138` ("available to all authenticated users") wraps `getPresentUsersForDate`, `getAbsentUsersForDate`, `exportAdminExcel/Pdf`, `exportExcel/Pdf` with NO `permission:` middleware. `getAbsentUsersForDate` returns every employee's name/email/phone/employee_id/leave records (`AttendanceController.php:491-507`). Fix: move into `permission:attendance.view` group (gated duplicates already exist at `:460-461`).

- **[High][Security] Monthly stats allows arbitrary `userId` drill-down with no policy** — `AttendanceController.php:521-524`; `attendance.view` user can read any single employee's stats via `userId` param. [RUNTIME] confirm intent. Fix: policy check for non-self userId.

- **[High][Integration/Contract] Monthly calendar never receives `settings.weekend_days`** — `MonthlyCalendarTab.jsx:428-429` vs `paginate()` response (`AttendanceController.php:101-108`) which omits `settings`; weekend logic silently falls back to Sat/Sun. Fix: include `AttendanceSetting::first()` in response.

- **[High][Perf] Monthly summary builds full per-day matrix for ALL employees in PHP, FE pulls `perPage:1000`** — `AttendanceController.php:67-99` + `AttendanceReportService.php:115-199` + `useAttendanceQuery.js:39`. O(employees×days×attendances) Carbon parsing; pagination bypassed. Fix: pre-group by date; DB aggregation; real pagination.

- **[Medium][Backend/Logic] `getDailyOverviewStats` hardcodes 09:00 late threshold + ignores dept filter on sub-counts** — `AttendanceController.php:547-562`. Inconsistent stats. Fix: settings-driven threshold; apply `$departmentId` everywhere.

- **[Medium][Backend/Race] `bulkMarkAsPresent` loops `updateOrCreate` with no transaction** — `AttendanceController.php:649-655`. Partial writes on failure. Fix: transaction + unique (user_id,date).

- **[Medium][Backend/Logic] `markAsPresent`/`bulkMarkAsPresent` accept any `users.id`, not just Employees** — `AttendanceController.php:615-643` (`exists:users,id`). Pollutes datasets. Fix: constrain to Employee role.

- **[Medium][Backend/Logic] Leave-status casing mismatch between monthly (`approved`) and daily (`Approved`) stats** — `AttendanceReportService.php:250` vs `AttendanceController.php:570`. [RUNTIME]. Fix: normalize (`LOWER(status)`).

- **[Medium][Frontend/State] 5-second polling refetches heavy timesheet + absent lists unconditionally** — `DailyTimesheetTab.jsx:403-406,374-388`. Network churn; fights React Query cache. Fix: longer interval; focus-gate.

- **[Medium][Integration/Auth-desync] `isAdminView` UI gate not backed by server authorization** — `DailyTimesheetTab.jsx:299`, `MonthlyCalendarTab.jsx:395`; ties to Critical finding. Fix: gate endpoints server-side.

- **[Low][Frontend/UX] Native `confirm()` for delete** — `DailyTimesheetTab.jsx:450`; inconsistent/inaccessible. Fix: Radix AlertDialog.

- **[Low][Frontend] Dead state + wasted `usePresentUsers` fetch per date change** — `DailyTimesheetTab.jsx:305,325-327,363-371`.

- **[Low][Backend] `total_work_minutes` `round(...,2)` on integer minutes is misleading/no-op** — `AttendanceController.php:343`.

- **[Low][Backend] Dead unauthorized `updateAttendance` method (no route, no `authorize`)** — `AttendanceController.php:116-147`. Latent IDOR if wired. Fix: remove or authorize.

**Summary:** 1 Critical, 4 High, 5 Medium, 4 Low.

---

## Holidays

**Files audited:** `Holidays.jsx`, `Forms/HolidayForm.jsx`, `Forms/DeleteHolidayForm.jsx`, `Tables/HolidayTable.jsx`, `HolidayController.php`, `Models/HRM/Holiday.php`, `routes/web.php`, holidays migrations, `ModulePermissionSeeder.php`.

### Findings

- **[Critical][Security] Holiday create AND update require only `holidays.view`** — Form posts to `holidays-add` (`HolidayForm.jsx:99`), registered inside `permission:holidays.view` group (`routes/web.php:251-253`); the same `create()` also updates when `id` present (`HolidayController.php:75-79`). Properly-gated `holiday-add` (`holidays.create`, `:385`) exists but is unused. Fix: point form at gated route / split create+update under `holidays.create`/`holidays.update`.

- **[High][Security] No per-row policy/authorize and no `holidays.update` gate anywhere** — `HolidayController.php:42-102`; no HolidayPolicy exists. Fix: add update route gated by `holidays.update` + authorize.

- **[High][Backend/Business-rule] No overlap validation for holiday ranges** — `HolidayController.php:45-54` (only `toDate >= fromDate`); `getHolidaysInRange()` exists but unused. Duplicate/overlapping holidays corrupt working-day calc. Fix: overlap query (exclude current id) → 422.

- **[High][Backend/Logic] Update path can crash on stale id; `recurrence_pattern` never persisted** — `HolidayController.php:75-79` (`Holiday::find()->update()` → null deref on race); recurring holidays store no pattern. Fix: `findOrFail`/null-check; persist pattern.

- **[High][Integration/Cache] 1-day cached list/stats can serve stale data to other sessions; BE/FE stats diverge** — `HolidayController.php:16-33`, `Holiday.php:13-26`. [RUNTIME]. Fix: single source of truth.

- **[Medium][Integration/Dead-prop] BE `stats` prop sent but FE recomputes** — `HolidayController.php:23-38` vs `Holidays.jsx:18,51-64`. Wasted query. Fix: remove or consume.

- **[Medium][Backend/Logic] `index` returns only `active()` holidays — inactive ones vanish & become uneditable** — `HolidayController.php:16-20,89-91` + toggle `HolidayForm.jsx:191-195`. Dead-end. Fix: don't filter, or add inactive filter.

- **[Medium][Backend/Index] No index on `from_date`/`is_active`/`type`** despite ordering/filtering — holidays migrations. Fix: add indexes.

- **[Medium][Frontend/Validation] No client-side date validation; negative-duration preview possible** — `HolidayForm.jsx:84-115`. Fix: validate required + date order before post.

- **[Medium][Integration/Date] `new Date('YYYY-MM-DD')` parses UTC → off-by-one day/year in negative-offset timezones** — `Holidays.jsx:25,47,55`, `HolidayTable.jsx:71-72,104,169`. [RUNTIME]. Fix: parse date-only as local.

- **[Low][Backend] `delete()` validates `input('id')` but reads `query('id')`** — `HolidayController.php:108-114`. Fragile.

- **[Low][Backend] Delete returns raw `$e->getMessage()`** — `HolidayController.php:134-137`. Info leak.

- **[Low][Frontend] "View Details" menu item has no handler** — `HolidayTable.jsx:142-144`. Dead-end.

- **[Low][Frontend/State] Two writers of `filteredHolidaysData` race; stats can desync from table** — `Holidays.jsx:31,43-49,151`, `HolidayTable.jsx:112-114`. [RUNTIME]. Fix: single owner.

**Summary:** 1 Critical, 4 High, 5 Medium, 4 Low.

---

## Leave Management (Admin)

**Files audited:** `LeavesUnified.jsx`, `Components/LeaveUnified/{AdminLeavesPanel,LeaveSettingsPanel}.jsx`, `Tables/LeaveEmployeeTable.jsx`, `LeaveController.php` (admin/approval), `BulkLeaveController.php`, `Settings/LeaveSettingController.php`, `Requests/BulkLeaveRequest.php`, `Services/Leave/*`, `Models/HRM/Leave.php`, `routes/web.php`, leaves migration.

### Findings

- **[Critical][Security] Row-level approve/reject requires only `leaves.view`, not `leaves.approve`** — `leave-update-status` inside `permission:leaves.view` group (`routes/web.php:301,311`); `LeaveEmployeeTable.jsx:129` posts here; `updateStatus`→`updateLeaveStatus` does `findOrFail->update(status,approved_by)` with no authorization, no chain, no ownership (`LeaveController.php:229-238`, `LeaveCrudService.php:88-110`). Any read-only user can approve/decline anyone's leave. Fix: move to `leaves.approve` group + `canApprove`.

- **[Critical][Backend/Logic] Bulk approve/reject validate against non-existent `leave_applications` table — endpoints dead** — `LeaveController.php:301,334` (`exists:leave_applications,id`); real table is `leaves`. Bulk actions (`AdminLeavesPanel.jsx:184,199`) always 422/500. Fix: `exists:leaves,id`.

- **[High][Backend/Logic] Two status vocabularies; bulk/row path writes `Approved`/`Declined` the chain can't process** — `LeaveCrudService.php:88-110` vs `LeaveApprovalService.php:325` (requires `pending`); bypasses chain, notifications, `approved_at`, `rejection_reason`. Fix: funnel through `LeaveApprovalService` / normalize casing.

- **[High][Backend/Race] Bulk loop has no transaction/lock/chunk/state-guard** — `LeaveController.php:307-312,340-345`, `LeaveCrudService.php:90-96`; approved leaves can be silently flipped. Fix: transaction + `lockForUpdate` + source-state validation.

- **[High][Security] Leave-settings CRUD has no policy; FE gates by role, routes gate by permission (desync)** — `LeaveSettingController.php:32-123` (no authorize), `routes/web.php:348` vs `LeavesUnified.jsx:30-31`. User with `leave-settings.update` but not admin role can delete leave types directly. Fix: align FE/BE criterion + add authorize + `leave-settings.delete`.

- **[Medium][Integration/Validation] Leave-type `days` accepts negative/zero; approval booleans nullable & silently re-defaulted** — `LeaveSettingController.php:36-91`; negative allotments feed balance math (`BulkLeaveController.php:83,179`). Fix: `min:0`; non-nullable booleans.

- **[Medium][Backend/Perf] Admin index over-fetches all users; balance calc loads all year's leaves into PHP** — `routes/web.php:305` (`User::with('department')->get()`), `LeaveQueryService.php:303-316`. Fix: paginate picker; SQL `SUM`/`groupBy`.

- **[Medium][Backend/Perf/N+1] Pending-approvals & approval-stats scan whole table + per-row decode** — `LeaveApprovalService.php:398-449`. Fix: `whereJsonContains` + DB counts.

- **[Medium][Backend/Config] Approval-chain HR role names (`HR Manager`/`HR Head`/`Super Admin`) don't match seeded roles** — `LeaveApprovalService.php:81-85`; HR approval level silently skipped. Fix: match seeded role names.

- **[Low][Backend/Config] Debug logging + extra count query per admin paginate** — `LeaveQueryService.php:88-176`.

- **[Low][Frontend/State] Optimistic bulk approve/reject never calls `fetchLeaves()` — status desync with multi-level chain** — `AdminLeavesPanel.jsx:178-206`. Fix: `fetchLeaves(true)` after success.

- **[Low][Frontend/Logic] Leave-type filter sends lowercased name → BE `LIKE %type%` over-matches** — `AdminLeavesPanel.jsx:214-219` → `LeaveQueryService.php:264-271`. Fix: filter by id, exact match.

**Summary:** 2 Critical, 4 High, 4 Medium, 3 Low.

---

## Users / Roles

**Files audited:** `AdminUnified.jsx`, `Components/AdminUnified/UsersPanel.jsx`, `UserController.php`, `RoleController.php`, `PermissionController.php`, `Services/Admin/UserManagementService.php`, `Requests/{StoreUser,UpdateUser,UpdateUserRole}Request.php`, `Policies/UserPolicy.php`, `Providers/AuthServiceProvider.php`, `Models/User.php`, `Resources/UserResource.php`, `routes/web.php`, `routes/api.php`.

### Findings

- **[Critical][Security/Priv-esc] API user role/permission endpoints have NO authorization** — `routes/api.php:165-175` group is only `['web','auth','throttle:api']`; `UserController::syncUserRoles/syncUserPermissions/giveUserPermission/revokeUserPermission` (`:580,616,651,691`) only `validate()` then mutate. Any logged-in user can POST `/api/users/{own-id}/roles` `{"roles":["Super Administrator"]}`. Fix: add `permission:users.update` + per-method authorize/super-admin guard.

- **[Critical][Security/Priv-esc] API role & permission CRUD lack route-level authorization** — `routes/api.php:140-163`; `RoleController::apiIndex/apiShow` (`:1249,1278`) expose full role/permission matrix to any authenticated user; several role-mutation methods rely only on `canManageRole`. Fix: wrap api `roles`/`permissions` groups in `permission:` middleware mirroring web.

- **[Critical][Security/Auth-bypass] `changePassword` resets any user's password, no authorization** — `UserController.php:215-252` (`findOrFail($id)->update(['password'=>bcrypt(...)])`, no authorize, no current-password check). Currently unrouted but latent account-takeover. Fix: delete or authorize + scope.

- **[High][Security/Priv-esc] "Edit Roles" dialog offers Super Administrator to non-super admins** — `UserManagementService.php:43` returns all roles unfiltered → `UsersPanel.jsx:681-699`; `UserPolicy::updateRoles` lets any Administrator assign Super Administrator. Fix: forbid assigning Super Admin unless actor is Super Admin; filter the role out for non-super.

- **[High][Security/Mass-assignment] `password` & `email_verified_at` in User `$fillable`** — `User.php:106,114`. Raw-input paths could forge verification. Fix: remove `email_verified_at` from fillable; set explicitly.

- **[High][Integration/Broken-route] `users.bulk.role` & `users.bulk.delete` point to non-existent controller methods** — `routes/web.php:419,424` reference `UserController::bulkAssignRole/bulkDelete` (only the service has them). Runtime 500. Fix: implement the controller methods (authorize + transaction).

- **[Medium][Security] Role-change self/escalation guard applied inconsistently across web FormRequest vs plain `syncUserRoles`** — `UserController.php:257-288` + service `:691`. Fix: centralize authorization.

- **[Medium][Security/Availability] `togglePermission` runs `Cache::flush()` + `Artisan::call('cache:clear'/'config:clear')` in prod on every toggle** — `RoleController.php:754-758`. Severe perf/availability hit. Fix: only `forgetCachedPermissions()`.

- **[Medium][Security/Logic] `view-salary` ability referenced but never defined** — `UserResource.php:43`; no `Gate::define('view-salary')`. Salary hidden from legitimate HR/admin. Fix: define the gate/permission.

- **[Medium][Security/Brute-force] No throttle on web user-management mutation routes** — `routes/web.php:400-427`. Fix: add `throttle:`.

- **[Low][Frontend/Validation] Role dialog allows saving empty role set; BE requires `min:1`, error swallowed** — `UsersPanel.jsx:84-100` vs `UpdateUserRoleRequest.php:29`. Generic toast. Fix: disable Save when empty / surface server message.

- **[Low][Backend/Perf] `getAdminUnifiedPageData` dumps all roles+permissions+users into Inertia props unpaginated** — `UserManagementService.php:39-61`.

- **[Low][Security/Info-leak] Error paths return raw `$e->getMessage()` to browser** — `RoleController.php:266-277` + `togglePermission`. Fix: gate behind local env.

**Summary:** 3 Critical, 3 High, 4 Medium, 3 Low.

---

## Company Details

**Files audited:** `Settings/CompanySettings.jsx`, `Forms/CompanyInformationForm.jsx`, `Props/countries.jsx`, `Settings/CompanySettingController.php`, `Models/CompanySetting.php`, company_settings migration, `routes/web.php`.

> Authorization is correct here (route under `permission:company.settings`, nav gate matches). No logo/file upload exists in this feature, so file-upload vulns are N/A. Mass-assignment is bounded (only `$validatedData` persisted).

### Findings

- **[Critical][Integration/Logic] State field is permanently disabled but BE requires it — settings cannot be saved** — `Props/countries.jsx:1-8` (no country has a `states` array) + `CompanyInformationForm.jsx:42-45,129-133` (`disabled={states.length===0}`, always disabled) vs controller `:31` (`state` required). Fresh install can never save; State uneditable on update. Fix: add states data (or make `state` optional/free-text) and don't disable when no list.

- **[Medium][Integration] State always `required` server-side but uncollectable on FE** — same root; only failure path is server 422. Fix: align FE/BE.

- **[Medium][Validation] `postalCode` capped `max:10` server-side vs `string(255)` column, no FE limit** — controller `:32` vs migration `:22`. Rejects valid long postal codes. Fix: raise to ~20, mirror on FE.

- **[Low][Frontend/State] Country change clears state list but not selected `state` value** — `CompanyInformationForm.jsx:37-45` (latent once states added). Fix: reset `state` on country change.

- **[Low][Frontend] Framer-Motion props on a plain `<div>` are inert + cause React warnings** — `CompanySettings.jsx:17-21`. Fix: use `motion.div` or remove.

- **[Low][Backend/Error] Update returns raw `$e->getMessage()` on 500** — `CompanySettingController.php:54-60`. Fix: log + generic message.

- **[Low][Frontend/Feedback] 422 toast reads `data.error` (Laravel sends `message`/`errors`) → always generic** — `CompanyInformationForm.jsx:63-65`. Fix: read `data.message`.

**Summary:** 1 Critical, 0 High, 2 Medium, 4 Low.

---

## Request Logs

**Files audited:** `Settings/RequestLogs.jsx`, `api/queries/useRequestLogsQuery.js`, `api/client.js`, `Settings/RequestLogController.php`, `Models/RequestLog.php`, `Middleware/LogRequestMiddleware.php`, `routes/web.php`, request_logs migration.

### Findings

- **[Critical][Integration] API URLs miss the `settings/` prefix — every request 404s** — `useRequestLogsQuery.js:15,25,37,49,59,70` call `/request-logs/*` but routes are `settings/request-logs/*` (`routes/web.php:531-537`), no axios baseURL rewrite. Entire feature non-functional. Fix: prefix `/settings` or use `route()`.

- **[Critical][Frontend] `setLogs` is undefined — delete/clear throw ReferenceError** — `RequestLogs.jsx:141,149,161,170,178,187` (`logs` is a derived const at `:76`). Fix: drop optimistic `setLogs`, use `invalidateQueries`/`refetch`.

- **[High][Frontend] `viewDetails` calls a `useQuery` hook imperatively (Rules of Hooks violation)** — `RequestLogs.jsx:211-218` (`await useRequestLogsQuery.useLogDetails(id)`). Detail dialog broken. Fix: direct `requestJson` or enabled query.

- **[High][Security] No real authorization — gated by unrelated `attendance.settings` permission** — `routes/web.php:495-538` group; no controller authorize. Logs hold URLs, bodies, headers, identity. Fix: dedicated `system-logs.view/manage` permission for admins.

- **[High][Security] Sensitive-data exposure: header/body sanitization misses keys; broad response capture** — `LogRequestMiddleware.php:92-170` (denylist only top-level exact keys; misses `x-csrf-token`, `set-cookie`, nested `access_token`/`client_secret`/PII; stores response bodies up to 10k). Tokens/PII persisted cleartext. Fix: recursive redactor + default-deny response logging.

- **[Medium][Security] `clearAll` uses `truncate()` — irreversible, bypasses transactions/events** — `RequestLogController.php:101`. Fix: `delete()` + retention/soft-delete + dedicated permission.

- **[Medium][Perf] Unindexed filter/search columns** — `RequestLogController.php:25,32,41` (`method` no index; `url` text leading-wildcard LIKE; `ip_address` LIKE unusable) vs migration `:28-31`. Full scans on high-growth table. Fix: index `method`; exact/prefix/fulltext for url/ip; cap date range.

- **[Medium][Perf] Export effectively unbounded (10k rows, all columns, in-memory `php://temp`)** — `RequestLogController.php:139-162`. Memory spikes; silent truncation. Fix: stream/cursor; surface limit.

- **[Medium][Backend] No FormRequest/date validation on filters** — `RequestLogController.php:44-50` (params bound raw; parameterized so not injection but can throw/empty). Fix: validate `date`/`method`/status.

- **[Low][Frontend] Delete success toasts unreachable (setLogs crash) + native `confirm()`** — `RequestLogs.jsx:137,156`.

- **[Low][Frontend] Redundant `refetch()` on mount + per filter/page change; stale-key refetch** — `RequestLogs.jsx:92-94,102,115,618`.

- **[Low][Frontend] Effect on `paginationData` object identity causes redundant renders** — `RequestLogs.jsx:80-86`.

- **[Low][Integration] Export mutation double-nests params → active filters dropped on export** — `RequestLogs.jsx:194` vs hook signature.

**Summary:** 2 Critical, 3 High, 4 Medium, 4 Low.

---

## System Monitoring

**Files audited:** `Administration/SystemMonitoringEnhanced.jsx`, `api/queries/useSystemMonitoringQuery.js`, `api/client.js`, `SystemMonitoringController.php`, `Services/Monitoring/{SystemHealth,DatabaseAnalytics,SecurityMonitoring,LogParser}Service.php`, `routes/web.php`, `routes/api.php`.

> Authorization is correctly enforced: web (`routes/web.php:654`, `role:Super Administrator`), API (`routes/api.php:125`, same), and nav gate (`pages.jsx:150`) all match. No auth desync.

### Findings

- **[High][Integration/UX] "Export report" route maps to a non-existent controller method** — `routes/web.php:657` → `SystemMonitoringController::exportReport` (undefined); `useSystemMonitoringQuery.js:23` wraps the error HTML into a `.pdf`. A 200 redirect yields a corrupt file + false success toast. Fix: implement `exportReport()` or remove route+button.

- **[High][Integration] Tab `type` values don't match controller `getMetrics` switch** — `SystemMonitoringEnhanced.jsx:70,136-176` sends `overview|database|performance|security|compliance`; controller `:83-100` handles `performance|errors|users|system|default`. `database`/`security`/`compliance` fall to `default` (full expensive overview); `performance` returns grouped data but UI reads `data.performance_summary` (absent) → always "No performance metrics." Fix: align cases + payload shapes.

- **[Medium][Backend/Perf] Unbounded `information_schema.TABLES` query serialized + cached** — `DatabaseAnalyticsService.php:77-93` (no LIMIT; UI only shows `slice(0,50)`); plus all-table index analysis `:116-139`. Fix: LIMIT/paginate server-side.

- **[Medium][Backend/Security] `getSystemOverview` is a single global-keyed cache also exposed as its own GET route** — `SystemMonitoringController.php:62-78`, `routes/api.php:127`; broad infra disclosure (schema, backup filenames, connection counts). Role-gated but no scoping. Fix: confirm role sufficiency; consider not exposing raw overview standalone.

- **[Low][Backend/Security] `shell_exec` for health metrics (`nproc`, `ps aux | grep`)** — `SystemHealthService.php:183,245`. No user input (not injectable) but fragile. Fix: native PHP + `function_exists` guard.

- **[Low][Backend/Security] Path-traversal-prone log helpers (currently unreferenced)** — `LogParserService.php:120-157` (`storage_path('logs/'.$filename)` no `basename`). Latent. Fix: `basename()` + `.log` whitelist before any exposure.

- **[Low][Frontend/UX] Double polling: React Query `refetchInterval:30000` + manual `setInterval`; "Auto refresh" toggle doesn't stop RQ polling** — `SystemMonitoringEnhanced.jsx:87-92`, `useSystemMonitoringQuery.js:14`. Fix: drive `refetchInterval` from state; drop manual timer.

- **[Low][Frontend/UX] Last-updated falls back to `new Date()`, masking up-to-5-min stale cache** — `SystemMonitoringEnhanced.jsx:305`. Fix: show "Unknown"/cached timestamp.

- **[Low][Frontend/UX] Performance/Security/Compliance tabs are raw `JSON.stringify` dumps** — `SystemMonitoringEnhanced.jsx:171-176`.

**Summary:** 0 Critical, 2 High, 2 Medium, 4 Low.

---

## Executive summary & cross-cutting patterns

The same root causes recur across features — fixing them as **patterns** will clear most of the Critical/High tier:

1. **Authorization enforced at the nav/route layer but not the controller (IDOR / privilege escalation).** The most dangerous class. Confirmed in: Users/Roles API (self-grant Super Admin — *worst*), My Leaves (CRUD/approve others' leaves), Leave Management (approve with read-only permission), Daily Works (edit/delete any record), Holidays (create/update with view-only), Attendances admin (ungated PII/export endpoints), Request Logs (wrong permission), Organization (`organization.index` ungated). **Pattern fix:** every mutating/owned-resource endpoint needs a policy/`authorize()` call or a correct `permission:` middleware that matches the UI gate — never rely on hidden buttons.

2. **Frontend↔backend contract breaks that silently disable whole features.** Petty Cash (`activeLoan.id` missing → tabs dead; missing icon import → Analytics crash), My Attendance (table reads `attendances`, BE sends `attendance`), Request Logs (missing `settings/` URL prefix → all 404; `setLogs` undefined), My Leaves (`setLeaves`/`fetchLeavesStats` undefined), Leave Management (`exists:leave_applications` — wrong table), Company Details (uneditable required State field), System Monitoring (missing `exportReport`, tab `type` mismatch). **Pattern fix:** a contract/smoke pass per feature; these are mostly one-line fixes with outsized impact.

3. **Money/state mutations without DB transactions or row locks (race conditions).** Petty Cash, attendance punch in/out, leave create, bulk approve, dept reassignment. **Pattern fix:** wrap read-decide-write in `DB::transaction` + `lockForUpdate`, add unique constraints.

4. **Missing business-rule enforcement server-side.** Leave balance (overdraft), petty-cash over-withdrawal, holiday/leave overlap on update, leave `days` negative. Client `disabled` is the only guard. **Pattern fix:** re-validate every rule server-side.

5. **Over-fetching + missing indexes + leftover debug logging.** All-users-into-props, `whereDate`/leading-wildcard `LIKE` on unindexed columns, `Log::info($request->all())` with PII. Performance + info-disclosure.

### Suggested remediation order
1. **Users/Roles API authorization** (Critical — trivial self-escalation to Super Admin).
2. Remaining IDOR/authorization gaps (Leaves, Daily Works, Holidays, Attendances exports, Request Logs permission).
3. Contract breaks that disable features (Petty Cash, My Attendance, Request Logs, My Leaves, Leave Management, Company Details, System Monitoring export).
4. Transactions/locks on money + attendance + leave + bulk ops.
5. Server-side business-rule validation.
6. Performance/index/logging cleanup.

### Method caveats
This was a **static read**, so `[RUNTIME]`-tagged items (dark-mode contrast, CLS, double-submit windows, timezone off-by-one, status casing) need live confirmation. Pure UI runtime classes from the taxonomy (stuck hover on touch, keyboard traps, focus rings, screen-reader behavior, true responsive breakage) were largely **not** assessable without driving the running app — recommend a follow-up Playwright pass if those matter.
