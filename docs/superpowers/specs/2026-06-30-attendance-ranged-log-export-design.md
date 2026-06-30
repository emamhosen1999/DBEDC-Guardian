# Date-ranged, Filterable Attendance Log + Export — Design

**Date:** 2026-06-30
**Status:** Approved (design)
**Area:** Attendance (HRM)

## Problem

Today the attendance feature exports only **single-day** (`AttendanceExport`) or **whole-month**
(`AttendanceAdminExport`) snapshots. There is no arbitrary **date-range** log, no
status/department/designation **filters** on the log, and the export ignores the on-screen
employee search (it re-queries all employees). This diverges from the HRIS standard, where the
attendance log is a filterable report whose export mirrors exactly what is filtered.

## Goal

Enhance the existing **Daily Timesheet tab** in place so it supports:

- A date **range** with quick presets (Today, Yesterday, This Week, Last Week, This Month,
  Last Month, Custom). Default = Today.
- **Filters**: Status, Department, Designation, plus the existing employee search.
- An on-screen ranged **log** (read-only, paginated) when a multi-day range is selected.
- **XLSX + PDF** export that always reflects the currently applied range + filters + search,
  generated server-side from the same query ("what you see is what you export").

Non-goals: CSV export (explicitly excluded), changes to the monthly calendar export, changes to
the mobile app for this iteration.

## Approved approach

Enhance the existing tab (no new tab, no modal-only flow). Mode auto-switches by range width.

### 1. Behavior / UX

The Daily Timesheet tab gains a date **range** (from + to + preset Select). Default = Today, so
existing daily usage is unchanged.

- **Single day selected** (`from == to`) → **Daily mode**: the current experience verbatim —
  present table + Absent sidebar + Map + inline time-correction + mark-present. No regressions.
- **2+ day range** (`to > from`) → **Log mode**: the day-only widgets (Absent sidebar, Map,
  inline edit, mark-present) hide. A read-only paginated table shows columns:
  `Date · Employee · Clock In · Clock Out · Work Hours · Status`. Department / Designation /
  Status filters + employee search apply.
- **Export (XLSX / PDF)** always reflects the currently applied range + filters + search.

Mode is derived on the client as `isRange = (to > from)`.

### 2. Single source of truth (core principle)

One new service method:

```
AttendanceReportService::getRangedAttendanceLog(Carbon $from, Carbon $to, array $filters): array
```

It produces normalized **one-row-per-employee-per-day** records with derived status. **Both** the
on-screen log endpoint **and** the export job consume it. This guarantees the export matches the
screen.

It reuses the existing, proven status engine (`ScheduleResolver` / `PolicyResolver` /
`AttendanceStatusService`) via the same path `getUserAttendanceData` already uses. Because that
engine is **month-keyed**, the range method iterates per-month across `[from, to]`, reuses the
per-day results, and trims to the exact range — staying on the single collapsed engine rather than
forking attendance logic.

Each normalized row contains at minimum:
`date`, `user_id`, `employee_id`, `employee_name`, `department`, `designation`, `clock_in`,
`clock_out`, `work_hours` (and `worked_minutes`), `status` (derived display status), `remarks`.

### 3. Backend

- **Employee selection**: extend `getEmployeeUsersWithAttendanceAndLeaves` (already accepts
  `departmentId`) to also accept `designationId` and an `employee` keyword. The range method
  selects the eligible employees once, then materializes their per-day rows across the range.
- **Status filtering** is applied on the **derived** status of materialized rows — not the raw
  punch query — because "Absent", "On Leave", "Holiday", and "Weekend" have no attendance row and
  therefore cannot be expressed by the `attendances`-table `applyFilters` `status` clause
  (which only distinguishes present/absent by `punchin` null-ness). Department, Designation, and
  employee-keyword filtering happen at the employee-selection layer.
- **New endpoint**: `GET /attendance/log` → returns paginated normalized rows plus an echo of the
  applied filters (`{ rows, pagination, applied_filters }`). Admin/team-scoped. Role scoping
  (managers → their team only) is enforced **in the service**, not in the UI.
- **Export**: generalize the `ExportAttendanceReport` queued job to accept a filter payload
  (`from`, `to`, `status`, `department_id`, `designation_id`, `employee`, `type`). Two new
  builders:
  - `AttendanceRangeExport` — styled XLSX with a metadata header (date range, applied filters,
    generated-by, generated-at) + a summary block (totals: present / absent / on-leave) + the
    one-row-per-employee-per-day grid.
  - `attendance_range_pdf` Blade view — same content, landscape A4.
  Both consume `getRangedAttendanceLog`. The existing `AttendanceExport` (daily) and
  `AttendanceAdminExport` (monthly calendar) are left untouched.
- **Performance / guardrails**:
  - XLSX built via chunked iteration over the normalized rows to bound memory.
  - PDF capped at ~62 days; beyond the cap the request is refused with a clear message
    (DomPDF is memory-heavy). XLSX has no hard cap but uses chunked writes.
  - Reuses the existing queued-job dispatch + `checkExportStatus($filename)` polling and the
    `{ success, queued, filename, download_url }` response contract already in place.

### 4. Frontend (`resources/js/Pages/Attendance/DailyTimesheetTab.jsx`)

- Replace the single `TextField type="date"` with a range control: `from` date + `to` date + a
  preset `Select` (Today, Yesterday, This Week, Last Week, This Month, Last Month, Custom).
  Selecting a preset sets both dates; Custom enables manual from/to.
- Derive `isRange = dayjs(to).isAfter(dayjs(from), 'day')`.
- Add Department / Designation / Status `Select`s (rendered in admin view, Log mode). Keep the
  existing employee search `TextField`.
- New React Query hook `useAttendanceLog({ from, to, page, perPage, filters })` powering the
  Log-mode table. Daily mode keeps the current hooks (`useDailyTimesheet`, `usePresentUsers`,
  `useAbsentUsers`) and widgets verbatim.
- The Excel/PDF export buttons send the active range + filters to the generalized export endpoint
  (replacing the current single-`date` payload). In Daily mode (`from == to`) the same endpoint is
  used with `from == to`, so the export path is unified and always filter-aware.

### 5. Testing

- **Service** (`getRangedAttendanceLog`):
  - Range spanning a month boundary returns correct, de-duplicated per-day rows trimmed to range.
  - Status derivation correct for present / absent / on-leave / holiday / weekend / incomplete.
  - Department + Designation + employee-search filter combinations select the right employees.
  - Status filter applied on derived status (e.g. `status=absent` returns no-punch employees).
  - Role scoping: a manager sees only their team; an admin sees all.
- **Export**:
  - Filtered export row count equals the filtered on-screen row count (WYSIWYG guarantee).
  - XLSX metadata header reflects the actual applied range + filters.
  - PDF day-cap enforced (request beyond cap refused with message).
- **Frontend**:
  - Single-day selection renders the Daily widgets (sidebar/map/edit).
  - Multi-day selection hides them and renders the log.
  - Changing a filter updates both the table and the subsequent export payload.

## Reuse summary (what already exists)

- `AttendanceRepository::applyFilters` already supports `from_date` / `to_date`, `employee`,
  `department_id`, `designation_id`, and a punch-based `status`.
- `AttendanceReportService::getUserAttendanceData` already derives per-day status via the collapsed
  status engine (month-keyed).
- `ExportAttendanceReport` is already a queued `ShouldQueue` job; `checkExportStatus` polling and
  the `{ success, queued, filename, download_url }` contract already exist.
- Users already have `department` and `designation` relations.

## Trade-off accepted

The ranged log materializes employee×day rows through the status engine rather than running a raw
`attendances` query. It is slightly heavier, but it is the only way "Absent / On Leave / Holiday"
rows and status-filtering can exist in the log, and it is what makes the export trustworthy. This
matches mature HRIS behavior.
