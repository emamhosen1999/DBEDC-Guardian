# Roster Phase 2 — Post Coverage (Design)

**Date:** 2026-07-01
**Status:** Approved (brainstorming). Second phase of the roster 10/10 initiative; gets its own implementation plan.
**Source:** Phase 2 brainstorming of 2026-07-01 + owner decisions (all "recommended / industry-standard 10/10").
**Related:**
- `docs/superpowers/specs/2026-07-01-roster-10of10-design.md` — the 6-phase roadmap; this is Phase 2.
- Phase 1 (MERGED, commit 2689e0c9): `RosterOverlayService` (read-only leave/holiday overlay) — **reused here for leave-aware coverage counting.**
- `docs/superpowers/specs/2026-06-28-expressway-monitoring-center-design.md` — the `Monitoring` topology (routes/sections/plazas) is **NOT built** (design only); Phase 2 deliberately does not depend on it.

---

## Context & anchor decision

A "post" (the thing that must be staffed) is anchored to the **existing `WorkLocation`** — sites that already carry geofence/attendance rules and to which every user is assigned via `users.work_location_id`. For an expressway, the Control Room, each Toll Plaza, and each Patrol Base are work locations. This needs no new location model and no Monitoring-domain prerequisite.

Coverage answers: **for a (location, shift, date), are enough of the right people actually rostered there?** — turning the roster from a scheduling grid into a demand-driven, coverage-aware roster.

## Owner decisions (locked)

| Decision | Choice |
|---|---|
| Coverage anchor | Existing `WorkLocation` (a post = a work location). |
| Granularity | Location × Shift headcount, with an **optional** role (Designation) split. |
| Per-day deployment | Yes — add `roster_days.work_location_id` (per-day post; null → user's home location). |
| Requirement definition | Recurring rules (per shift, optional weekday) + per-date overrides. |
| Holidays | **Not** subtracted from coverage — the expressway runs through holidays; posts still need staffing. |
| Access control | Roles only (`$user->hasRole(...)`), per app-wide standard. |

---

## 1. Data model (2 new tables + 1 column)

### `coverage_requirements`
One row per requirement. Columns:
- `id`
- `work_location_id` — FK `work_locations`, cascade on delete.
- `shift_id` — FK `shifts`, cascade on delete.
- `designation_id` — **nullable** FK `designations`, null on delete. `null` = total headcount for the post/shift; set = a role-specific requirement.
- `required_headcount` — unsigned int.
- `weekday` — nullable tinyint (0=Sunday … 6=Saturday, matching PHP `date('w')`/Carbon `dayOfWeek`). `null` = applies to all days.
- `date` — nullable date. Set = a specific-date override.
- `is_active` — boolean, default true.
- `timestamps`.

**Constraints/indexes:** index `(work_location_id, shift_id, date)` and `(work_location_id, shift_id, weekday)` for resolution queries.

**Resolution precedence** — for a given (location, shift, role, date `D`) pick exactly ONE effective active row:
1. `date == D` (specific override) — highest.
2. else matching `weekday == dayOfWeek(D)` with `date IS NULL`.
3. else the all-days row (`weekday IS NULL AND date IS NULL`).
If none match, that (location, shift, role) has **no requirement** on D (not zero — simply untracked).

**Total vs role rows are independent constraints.** A `designation_id = null` row governs the total headcount; each `designation_id = X` row governs that role's headcount. Coverage evaluates each independently and reports each. Role rows are NOT required to sum to the total.

### `roster_days.work_location_id`
- Nullable FK `work_locations`, null on delete. Added to the existing `roster_days` table.
- The **effective post** for a roster day = `roster_days.work_location_id ?? user.work_location_id`.
- Existing rows/logic unaffected when null (backward compatible).

### Models
- New `App\Models\HRM\CoverageRequirement` (fillable + casts + `workLocation()`, `shift()`, `designation()` relations).
- Extend `App\Models\HRM\RosterDay`: add `work_location_id` to `$fillable` and a `workLocation()` relation.

---

## 2. Coverage computation — `App\Services\Attendance\CoverageService`

`forRange(string $from, string $to, ?array $locationIds = null): array`

For each (location, shift, date) in range, compute:
- **required** — resolved from `coverage_requirements` per the precedence above. Produces a total requirement (role=null) and, where defined, per-role requirements.
- **assigned** — count of `roster_days` where `date` = D, `shift_id` IS NOT NULL, and effective location (`work_location_id ?? user.work_location_id`) = L, **reduced by approved leave** from `RosterOverlayService`: a full-day approved leave subtracts 1.0; a half-day subtracts 0.5. (Pending leave does NOT reduce assigned — only approved.) For a role requirement, assigned counts only users whose `designation_id` matches.
- **status** — `understaffed` (assigned < required), `met` (assigned == required), `overstaffed` (assigned > required). Fractional assigned (from half-day leave) compares numerically.

**Holidays are not subtracted.** **Query efficiency:** bounded, batched queries (one requirements query for the range, one roster_days+users query, one leave-overlay call) — no per-cell/per-day queries (assert with a query-count test).

Return shape (per the contract the plan will pin exactly):
```
[
  '<Y-m-d>' => [
    '<work_location_id>' => [
      '<shift_id>' => [
        'total' => ['required'=>int|null, 'assigned'=>float, 'status'=>string|null],
        'roles' => [ '<designation_id>' => ['required'=>int, 'assigned'=>float, 'status'=>string], ... ],
      ],
    ],
  ],
]
```
`required=null`/`status=null` for total when no requirement is defined for that cell (untracked, not a violation).

---

## 3. UI surfaces

All gated roles-only (`$user->hasRole(...)`), following existing roster page patterns (Radix Themes, React Query v5, `requestJson`).

### 3a. Coverage requirements admin
- CRUD screen to define standing rules (location, shift, optional role, headcount, optional weekday) and per-date overrides.
- Endpoints under the roster/attendance admin group: `GET/POST/PUT/DELETE /attendance/coverage-requirements`.
- Optimistic-concurrency not required here (low-write config), but validate FK existence + `required_headcount >= 0` + weekday range + date format.

### 3b. Coverage indicators on the roster
- A coverage panel/strip on the roster page: matrix of **Location × Shift** for the visible range (or a selected date), each cell showing `assigned / required` with color (red understaffed, green met, amber overstaffed, neutral untracked).
- An "understaffed posts" callout listing the (date, location, shift, role) gaps for the range so a planner can act.
- Data via `GET /attendance/coverage?from&to[&department_id|location_id]` returning the `CoverageService::forRange` payload.

### 3c. Post picker in the cell popover
- Extend `RosterCellPopover` so that when assigning a shift the planner may also choose the **post** (work location) for that day; defaults to the employee's home location. Persists to `roster_days.work_location_id` via the existing `updateCell` path (extended to accept `work_location_id`).

---

## 4. Reuse & boundaries

- **Reuses:** `RosterOverlayService` (leave), `WorkLocation`, `Shift`, `Designation`, `User.work_location_id`. No new location model; no Monitoring dependency.
- **Read-mostly**; the only writes are coverage-requirement CRUD and `roster_days.work_location_id` (through the existing `updateCell`).
- **Does not touch** `AttendanceStatusService`, `RosterScheduleResolver`, `LeaveDayCalculator` — coverage is a read-side aggregation, keeping the attendance engine untouched (same discipline as Phase 1).

## 5. Testing strategy

Backend (PHPUnit, sqlite `:memory:` + `RefreshDatabase`):
- Requirement resolution precedence: date-override > weekday > all-days; no-match = untracked.
- `CoverageService`: assigned counts effective location (`roster_day.work_location_id` override AND home-location fallback); approved full-day leave subtracts 1, half-day 0.5, pending does not; holidays do NOT reduce; role requirements count only matching designation; understaffed/met/overstaffed classification.
- Query-count bound (constant across N locations/days).
- Coverage + requirements endpoints: roles-only gating (allowed/denied); validation rejects bad headcount/weekday/date.
- `updateCell` persists `work_location_id` and leaves existing behavior intact.

Frontend (Vitest, pure-function pattern — no `@testing-library/react`):
- A pure `resolveCoverageCellDisplay(cell)` (assigned/required → color/label/status) unit-tested like `rosterCellDisplay`.

## 6. Rollout

- **Migrations run on dev MySQL `dbedc_guardian`** (not only sqlite) or live pages 500 — two new tables + one column.
- One consolidated `public/build` at the end (frontend changed) — done via the owner's normal flow; do NOT run `npm run build` in-session.
- Ships behind existing roster/attendance role gates; additive, no breaking changes to Phase 1 payloads.

## 7. Non-goals (Phase 2)

- No auto-scheduling / gap auto-fill (planner acts on indicators manually).
- No labor cost/budgeting.
- No open-shift pool / bidding.
- No Monitoring topology / chainage / zones-as-first-class (WorkLocation is the post unit).
- No change to attendance computation.
