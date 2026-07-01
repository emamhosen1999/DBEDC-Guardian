# Roster 10/10 — Overarching Design (Expressway, demand-driven)

**Date:** 2026-07-01
**Status:** Approved (brainstorming). Overarching design for a phased initiative; each phase gets its own implementation plan. **Phase 1 (Roster Leave/Holiday Overlay) is fully specced below and ready for a plan.**
**Source:** Roster audit of 2026-07-01 (this session) + architectural decisions confirmed with the owner.
**Related specs (reconciled, not duplicated):**
- `docs/superpowers/specs/2026-06-23-attendance-leaves-holidays-10of10-design.md` — leave/holiday correctness on the **attendance/reporting** surfaces (engine precedence + `worked_on_leave` DONE; leave fractions/half-day/`HolidayService::forRange` specced). **This roster initiative reuses those semantics; it does not re-implement them.**
- `docs/superpowers/specs/2026-06-28-expressway-monitoring-center-design.md` — the `Monitoring` domain with **topology** (routes → sections → directions → lanes, chainage; toll plazas). **Phase 2 (Post/Coverage) anchors "posts" to this existing topology instead of a parallel location model.**

---

## Context — what we are

An expressway development company running a **24/7, demand-driven, multi-post** operation: a Monitoring Center (control room that can never be unmanned), a Toll team (plazas/lanes staffed per shift), a Field Patrol team (highway divided into zones/sections, each needing coverage round the clock), and an O&M team (mostly day shifts + on-call).

The current roster answers *"what shift is this person on?"* A 10/10 operation-grade roster must answer *"is every post covered on every shift, and by whom?"* — the difference between a scheduling grid and a demand-driven roster.

## Current strengths (do not rebuild)

The scheduling **engine** is already strong: effective-dated shift assignments with scope precedence (user > designation > department > org), rotation patterns with `anchor_date` phasing, materialized `roster_days` with `source` tracking + `locked`, optimistic-concurrency (`expected_updated_at` → 409), a real swap/cover engine, and realtime cross-client signals. The gaps are in the **product** layer around it.

## Roadmap (6 phases, dependency-ordered)

1. **Leave/Holiday overlay on the roster planning grid** — *this spec, fully detailed below.* Correctness: the admin roster grid is leave-blind. Also produces the leave-overlay primitive Phase 2 coverage needs. Lowest effort, highest correctness payoff; must land first so coverage counts are accurate.
2. **Post + Coverage model** — the backbone: define **posts** (control room, each toll plaza, each patrol zone) **anchored to the existing `Monitoring` topology**, required headcount per shift/day, and under/over-staffing indicators on the grid.
3. **Draft → Publish workflow** — build a draft, publish once (fires coverage + compliance checks, then notifies affected staff). Kills per-edit notification spam.
4. **Compliance warnings** — rest-between-shifts, max weekly hours, consecutive days, overlaps; surfaced (warn, not hard-block) at save/publish.
5. **Bulk editing UX** — copy-previous-week, drag-to-fill, multi-select, paste-across-days (operates on the *draft* from Phase 3).
6. **Availability / unavailability** — employee-declared, feeds generation.

Each phase ships independently behind existing attendance/roster role gates; migrations run on dev MySQL `dbedc_guardian` and are noted for prod.

---

# Phase 1 — Roster Leave/Holiday Overlay (DETAILED)

## Goal

Make the **roster planning surface** (admin `RosterTab`/`RosterCalendar` + the employee `myRoster` view + the `/api/v1` mobile roster) leave- and holiday-aware, so a planner never sees a normal scheduled shift for someone who is actually on approved leave or a company holiday. This is a **read-only overlay** — no change to the attendance engine, the schedule resolver, or leave-day counting.

## Non-goals (Phase 1)

- No change to `AttendanceStatusService`, `RosterScheduleResolver`, `LeaveDayCalculator`, or any attendance/reporting surface (those already integrate leave/holidays per the 2026-06-23 design).
- No materializing leave into `roster_days` (leave stays authoritative in `leaves`; it can be cancelled/edited).
- No coverage counting yet (Phase 2 consumes this overlay).
- No hourly/partial leave beyond the existing half-day (AM/PM session) granularity.

## Key finding that scopes this phase

- **Attendance is already leave-aware.** `AttendanceStatusService::resolve` accepts `leaveFraction`/`leaveSession`, classifies `ON_LEAVE`, and flags `worked_on_leave`/`half_day_leave_unworked`; `AttendanceQueryService` already checks approved leave. So the attendance path needs nothing.
- **The resolver must stay leave-unaware.** `LeaveDayCalculator` uses `ScheduleResolver` only for weekly-off detection. If the resolver became leave-aware, a leave would count itself as non-working (0 days). Therefore the overlay lives **only in the roster read/display path**, never in `resolveShift`/`RosterScheduleResolver`.

## Architecture — read-only overlay

A new overlay service merges two authoritative, already-existing sources onto each roster cell at read time:

- **Approved leave** from the `leaves` table (`status = 'approved'`, honoring `is_half_day` + `half_day_session`).
- **Holidays** from the existing `HolidayService::forRange` (active-only, `annual_fixed` recurrence expansion — already built in the 2026-06-23 Phase 1).

The overlay **visually supersedes** the scheduled shift on the grid; the underlying `roster_days` rows are untouched.

### Components & interfaces

1. **`RosterOverlayService`** (`app/Services/Attendance/RosterOverlayService.php`)
   - `forRange(array $userIds, string $from, string $to): array`
   - Returns a nested map: `[userId => [ 'Y-m-d' => ['leave' => ?array, 'holiday' => ?array] ]]` where
     - `leave` = `['type' => <leave_type code/name>, 'fraction' => 1.0|0.5, 'session' => 'first_half'|'second_half'|null, 'status' => 'approved'|'pending']`
     - `holiday` = `['name' => string]` (holiday is org-wide, so keyed by date, applied to every user).
   - **One** leave query for all users in range (`whereIn('user_id', …)->where('status','approved')` + a second light query for `pending`), grouped in PHP; **one** `HolidayService::forRange` call. No per-cell queries (no N+1).
   - `pending` leave is included as a separate, lower-emphasis signal (planning hint), not merged into `approved`.

2. **`RosterController::index` + `RosterController::myRoster`**
   - After `formatRoster`, call `RosterOverlayService::forRange` for the same users/range and merge a `leave` and org-level `holidays` structure into the JSON response. Cell shape gains an optional `leave` key; the response gains a top-level `holidays => ['Y-m-d' => name]` map (org-wide, sent once rather than per cell).
   - `myRoster` scopes `userIds` to the requesting user only (unchanged scoping).

3. **Mobile `/api/v1` roster** (`MobileAttendanceRequestController::myRoster`)
   - Same overlay merged into the `{success, data}` envelope so the mobile ESS schedule shows leave/holiday consistently.

4. **`RosterCalendar.jsx`** (presentational)
   - **Holiday:** day-header column and every cell for that date get a distinct holiday tint; tooltip shows the holiday name.
   - **Approved leave (full):** cell rendered in amber with the leave-type code (e.g. `AL`, `SL`); tooltip `On leave — <type>`.
   - **Approved leave (half):** split cell — worked-half shows the scheduled shift color/code, leave-half amber; tooltip names the session (AM/PM) and type.
   - **Pending leave:** faint dotted outline over the scheduled shift (planning hint only).
   - Precedence on a cell: **holiday > approved leave > scheduled shift > off**. (Matches the engine's no-punch precedence `holiday > weekly-off > leave > absent`.)

5. **Cell interaction (`RosterTab.jsx`)** — *warn, don't block.* Clicking a leave/holiday cell still opens `RosterCellPopover`, but the popover shows a banner ("On approved leave — <type>" / "Company holiday — <name>"). Ops can still override (assign a shift) deliberately; no hard block. Consistent with the warn-not-block philosophy chosen for Phase 4 compliance.

### Data flow

```
RosterController::index/myRoster
  ├─ RosterDay::with(shift,user) ...            → formatRoster()  (unchanged)
  ├─ RosterOverlayService::forRange(userIds,…)
  │     ├─ leaves where status=approved (+ pending, separate)   [1 query each]
  │     └─ HolidayService::forRange(from,to)                    [1 call]
  └─ merge → JSON { roster: {…, days:{…, leave?}}, holidays:{date:name} }
        → RosterCalendar renders overlay (holiday > leave > shift > off)
```

## Edge cases (locked)

- **Leave overlapping a weekly-off / holiday:** holiday and off-day out-rank leave on the display (a rest day is never "consumed" by leave), mirroring the engine.
- **Leave spanning a month boundary:** the range query already clips to `[from,to]`; only in-range dates overlay.
- **Cancelled/rejected leave:** never shown (overlay reads `approved` + `pending` only; because it reads live, a later cancellation simply stops overlaying — no stale materialized rows).
- **Half-day session vs shift:** the split cell shows the worked half using the resolved shift; if there is no scheduled shift that day, the whole cell renders as half-day leave (no phantom shift).
- **Multiple approved leaves on one date** (should not occur, but): first by `from_date`/id wins; overlay is display-only so this cannot corrupt data.
- **Performance:** overlay adds a bounded, constant number of queries per roster load regardless of employee/day count.

## Testing strategy (Phase 1)

Backend (PHPUnit, sqlite `:memory:` + `RefreshDatabase`):
- `RosterOverlayService::forRange` returns approved leave (full + half w/ session) and holidays for the range; excludes rejected/cancelled; includes pending separately.
- `RosterController::index` payload merges `leave` onto the correct user/date cells and `holidays` at top level; `department_id` filter still applies.
- `myRoster` overlay is scoped to the requester only.
- Holiday out-ranks leave; leave on a weekly-off does not overlay as leave.
- No N+1: query count is constant across N users / M days (assert via query log or count).
- Regression: attendance/report suites unchanged (proves the engine/resolver were not touched).

Frontend (vitest):
- `RosterCalendar` renders holiday tint, full-leave amber cell, half-leave split cell, and pending dotted hint given a crafted `roster`+`holidays` prop.
- Clicking a leave cell opens the popover with the warning banner (warn-not-block).

Mobile parity: `/api/v1` roster returns the same overlay shape (covered by an API feature test).

## Rollout

- No new tables in Phase 1 (pure read overlay). One consolidated `public/build` at the end (frontend changed).
- Runs on dev at `https://aero-enterprise-suite.test`; no prod migration needed for this phase.
- Ships behind the existing roster/attendance role gates.
