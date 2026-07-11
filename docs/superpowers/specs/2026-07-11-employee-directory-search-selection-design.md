# Unified Employee Directory, Search & Selection — Design

**Date:** 2026-07-11
**Status:** Approved (design), pending spec review → implementation plan
**Repo:** DBEDC-Guardian (Laravel 12 + React 18 + Inertia v2 + Radix Themes)

## Problem

Employee finding/selection is fragmented and non-scalable across the system.

**Main list** (`/employees` → `UserController@employees` → `UserManagementService::paginateEmployees`):
- Good: server-side pagination; filters for search (name/email/phone/employee_id LIKE), department, designation, attendanceType, role, status/showDeleted.
- Gaps: sort is hard-coded `created_at desc` (no column sort, no direction toggle); no saved views/segments; no active-filter chips or "clear all"; no URL persistence (filter state lives only in react-query params, so views aren't shareable/bookmarkable); the list renders as cards, not a sortable data table.

**Pickers/dropdowns in forms** (21 files: Leave, BulkLeave, Roster, MarkPresent, Profile, AddUser, Employment, etc.):
- Dominant anti-pattern: load ALL users to the browser (`allUsers`, `allManagers = User::…->get()`) and filter in-memory — does not scale and duplicates filter logic across 21 places with subtle drift (`getSafeName`, string coercion, inconsistent active/deleted handling).
- No async/typeahead server search endpoint exists (`/directory/search` does not exist).
- Four different select libraries in play: `@radix-ui/themes` Select (215 uses), `react-select` (1), `primereact` (1), `react-aria-components` (3). No single canonical people-picker.
- `DepartmentEmployeeSelector` is the closest shared picker (department→employee cascade, client-side name search) but is not reused everywhere and is still client-side.

**Net:** the backend query is ~70% there; the interaction layer is fragmented and won't scale. This is the gap between "works at 250 employees" and 100/100.

## Industry standard (target behavior)

**List/table** (Workday / BambooHR / Rippling / Deel people directory parity):
- Server-driven search + faceted filters (with counts) + any-column sort + pagination, all URL-persisted (shareable, back-button-safe).
- Global search ranked prefix-first across name, employee ID, email, phone.
- Facets: department, designation, employment/attendance type, status, work location, role, reports-to (manager).
- Preset segments (one-click smart views) + bulk select/actions + column visibility + density + export.
- Solid empty/loading/error states, keyboard nav, a11y.

**Every picker/dropdown:**
- One shared, server-backed, debounced typeahead used everywhere.
- Searches name/ID/email; returns lightweight rows (id, name, employee_id, avatar, department, designation).
- Each row: avatar + name + employee_id, with department · designation secondary line for disambiguation.
- Scoped variants by contract: by department, by manager, exclude-self, exclude-deleted, single vs multi-select, "my team only".
- Keyboard-first, virtualized, never ships the whole user table to the client.

## Approved decisions

| Decision | Choice |
|----------|--------|
| Scope | **Full unification** — shared query contract + `/directory/search` + one `<EmployeePicker>`, migrate the list AND all 21 form usages |
| Picker foundation | **react-aria-components ComboBox** (already a dep; best-in-class a11y/async), styled to Radix Themes tokens; retire react-select + primereact for people-selection |
| Search scope | **Permission-scoped** — endpoint resolves requester RBAC scope first; caller `scope` can only further narrow, never widen |
| Saved views | **Preset segments + URL-persistence** — no new DB table |

## Architecture — one contract, two consumers

```
                 ┌─────────────────────────────────────────┐
                 │  EmployeeDirectoryQuery (service)        │
                 │  · search (name/id/email/phone, ranked)  │
                 │  · facets (dept/desig/type/status/mgr…)  │
                 │  · sort (any column, dir)                │
                 │  · PERMISSION SCOPE resolver (RBAC)      │
                 └───────────────┬──────────────────────────┘
             ┌───────────────────┴───────────────────┐
             ▼                                        ▼
   GET /employees/paginate                 GET /directory/search   (NEW, lightweight)
   (full rows, facet counts)               (debounced typeahead, minimal rows)
             │                                        │
             ▼                                        ▼
   <EmployeeTable> (list page)             <EmployeePicker>  (react-aria ComboBox)
                                            └── used by all 21 forms/dropdowns
```

A single PHP service, `EmployeeDirectoryQuery`, owns the entire search/filter/sort/scope contract. `paginateEmployees` is refactored to delegate to it (no behavior loss). A new thin `/directory/search` endpoint calls the same service in "lightweight" mode. Filter logic never lives in the browser again.

### Unit boundaries

- **`EmployeeDirectoryQuery` (service)** — *does:* builds the scoped, filtered, sorted employee query and returns either full rows (+ facet counts) or lightweight rows. *used by:* `UserController@employees` and `DirectoryController@search`. *depends on:* `User` model, RBAC/permission layer, `Department`/`Designation`/`AttendanceType`.
- **`ScopeResolver` (within/alongside the service)** — *does:* turns the authenticated requester into an allowed-id constraint (admin/HR = all; manager = reports-to subtree; dept-head = department). *used by:* `EmployeeDirectoryQuery`. *depends on:* roles/permissions, `reports_to` relation.
- **`/directory/search` endpoint** — *does:* validates `q`/`scope`/`limit`/`excludeIds`, calls the service lightweight mode, returns JSON. *depends on:* service, auth.
- **`<EmployeePicker>` (React)** — *does:* async, debounced, keyboard-first people combobox (single/multi), scoped. *used by:* all 21 forms. *depends on:* `/directory/search`, react-query, react-aria ComboBox.
- **`<EmployeeTable>` upgrade** — *does:* sortable, faceted, URL-persisted list with preset segments. *depends on:* `/employees/paginate`.

## The `/directory/search` endpoint

- **Input:** `q` (string), `scope` (enum: `all` | `department:{id}` | `manager:{id}` | `myteam`), `limit` (default 20), `excludeIds[]` (self-exclusion is expressed here, not via `scope`), optional facet filters.
- **Ranking:** exact `employee_id` → prefix name → contains name → email/phone. Diacritic-insensitive.
- **Row shape (lightweight):** `{ id, name, employee_id, avatar_url, department_name, designation_name }`.
- **Scope enforcement:** server resolves requester RBAC scope first, THEN applies caller-supplied `scope` as a further narrowing. A caller can never widen beyond what they are allowed to see. A manager passing `scope=all` still only receives their allowed set.
- **Caching:** react-query `staleTime` keyed on `(q, scope)`; 250 ms client debounce.

## `<EmployeePicker>` component (canonical)

Single component, prop-driven variants:

| Prop | Purpose |
|------|---------|
| `value` / `onChange` | id (single) or id[] (multi) |
| `multiple` | single vs multi-select (chips) |
| `scope` | `all` \| `department` \| `manager` \| `myteam` |
| `excludeSelf` / `excludeIds` | e.g. cannot pick self as own manager |
| `departmentId` | cascade scoping (replaces `DepartmentEmployeeSelector`) |
| `required` / `error` / `disabled` | form integration |

- Renders avatar + name + employee_id; secondary line department · designation.
- Async server search, keyboard-first, virtualized, "recent" shortcut, empty/loading/error states.
- Styled to Radix Themes tokens for visual consistency.
- `DepartmentEmployeeSelector` becomes a thin wrapper over `<EmployeePicker scope="department">` (retired as standalone logic; kept only as a convenience wrapper if any caller needs the department dropdown + employee dropdown pair).

## Main `/employees` list upgrade

- Any-column sort (name, employee_id, department, designation, type, status, created_at), asc/desc, stable secondary sort on `name` — replaces hard-coded `created_at desc`.
- Preset segment chips (All / Active / My Team / On Probation / Deleted / By Department …) — no new DB table; each is a canned filter+sort combination.
- Active-filter chips + "Clear all"; URL-persisted state (shareable, back-button-safe).
- Facet filters show counts. Keep existing bulk actions; add column visibility + density + export.

## Migration — retire the fragmentation

- Replace the 21 client-side `allUsers`/`allManagers` loads with `<EmployeePicker>`.
- Retire `react-select` + `primereact` for people-selection; standardize on the one component.
- Delete duplicated in-memory filter helpers (`getSafeName`, string-coercion dept filters).
- Each migrated form must submit an identical payload (regression-guarded).

## Sequencing (independently shippable)

- **A.** `EmployeeDirectoryQuery` service + `ScopeResolver` + `/directory/search` endpoint (refactor `paginateEmployees` to delegate — no behavior change).
- **B.** `<EmployeePicker>` component on react-aria ComboBox.
- **C.** Migrate the 21 form/dropdown usages onto `<EmployeePicker>`; retire the extra select libs.
- **D.** `/employees` list upgrade (column sort, preset segments, active-filter chips, URL persistence, column visibility/density/export).

## Testing / done-bar

- **PHPUnit:** `ScopeResolver` (admin vs manager vs dept-head); ranking order; facet counts; `/directory/search` authorization + scope-narrowing (a manager passing `scope=all` cannot widen); `excludeIds`/`excludeSelf` honored.
- **Frontend:** `<EmployeePicker>` async search, keyboard nav, multi-select, exclude-self, empty/error states; list URL-persistence + column sort round-trips.
- **Regression:** every one of the 21 migrated forms still submits the same payload as before migration.

## Out of scope (explicit)

- User-defined saved views persisted to DB (preset segments only for now).
- Changes to the employee CRUD/edit forms beyond swapping their people-selectors.
- Non-people dropdowns (department/designation/type selects) except where a people-picker is involved.
