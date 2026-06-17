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

> Table updated as remaining waves complete.

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
