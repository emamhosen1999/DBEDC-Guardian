# Roster, Upcoming-Shift & Avatar Refactor — Design

**Date:** 2026-07-14
**Repos:** `DBEDC-Guardian` (Laravel 11 + React 18 + Inertia + Radix), `dbedc-mobile-app` (Expo / React Native + Tamagui)
**Status:** Approved — ready for planning

---

## Problem

Four defects, all rooted in logic that was written twice (once for web, once for the mobile API) and drifted:

1. **Upcoming shifts are date-bound.** The 12-hour window is only honoured when the selected date happens to be *today*; on any other date the list silently falls back to "that date's working shifts". The rule lives in two controllers and drifts between them.
2. **Shift lists are unsorted.** Employees come back in database order, so a 16-24 employee can appear above an 00-08 one. There is no shift-start ordering anywhere.
3. **Avatars are missing or copy-pasted.** `EmployeeAvatar` is duplicated in four mobile approval screens, and absent from every list, picker, roster and dashboard. Several API endpoints do not return an image at all.
4. **The mobile roster is not the web roster.** Web has a 24-hour-segment grid; mobile has a week list (`my-roster`) and a dot-calendar (`team-roster`). The mobile API has no multi-employee roster, no holidays, and no shifts catalog, so this is a backend gap, not just a UI gap.

## Non-goals

- No change to how rosters are generated, swapped, or approved.
- No change to punch/attendance recording logic.
- No redesign of the web roster — the web grid is the reference implementation; mobile ports *to* it.

---

## A. Upcoming-shift semantics — one shared service

### Current state

`AttendanceController::getAbsentUsersForDate()` (web, ~line 585) and `Api/V1/AttendanceController::absentUsersForDate()` (mobile) each contain their own copy of the window logic. Both branch on `$parsedDate->isToday()`.

### Design

Extract a single `App\Services\Attendance\UpcomingShiftService` with:

```php
public function forDate(CarbonInterface $date, Collection $users): Collection
```

Both controllers call it. It returns the users who have an upcoming shift, each decorated with `shift_code`, `shift_name`, `shift_color`, `shift_start`, `shift_end`, `shift_start_time`, `shift_start_minutes` (sort key), and `profile_image_url`.

Rules — the same in both shells:

| Selected date | Upcoming list |
|---|---|
| **Today** | Shifts starting in `[now, now + 12h]`. The window crosses midnight naturally, so at 22:00 tomorrow's 00-08 shift appears. This is the "regardless of date" behaviour. |
| **Future** | Every working shift on that date — the whole day is ahead. Sorted by start time. |
| **Past** | Nothing. The section/tab is **hidden**, not rendered empty. Nothing is upcoming in the past. |

A user in the Upcoming list is excluded from Absent (already the case) — a 16-24 employee viewed at 09:00 must not read as absent.

### Payload contract

The absent-users endpoint (both shells) returns:

```json
{
  "absent_users": [...],
  "off_users": [...],
  "upcoming_users": [...],
  "upcoming_visible": true
}
```

`upcoming_visible: false` on past dates. Consumers (`AbsentSidebar.jsx`, mobile `team-attendance.js`) hide the Upcoming tab and drop it from the count totals when false.

---

## B. Shift-start ordering

Ordering key: **shift start clock time ascending** (00-08 → 08-16 → 16-24), employee name as tiebreaker. A midnight-crossing shift sorts by its *start* clock time, not its end.

Sorting is done in the **backend** so both shells inherit it. Applies to:

- Upcoming, Present, Absent, and Off user lists (web + mobile API).
- The shifts catalog (`GET /attendance/shifts` and the new mobile `GET /api/v1/attendance/shifts`) — this one endpoint feeds the roster legend, the roster cell popover, and the swap forms, so ordering it once orders all three.

Users with no resolvable shift sort last, by name.

Roster grid **employee rows stay alphabetical** — the grid is a month view, so there is no single "the shift" to sort a row by.

---

## C. Avatars

### Shared primitive

New `components/ui/Avatar.js` in the mobile app: image → initials fallback → person icon. Props: `user`, `size`, `baseUrl`. It resolves the image via the existing `src/auth/profileImage.js` helper (`profile_image_url` then `profile_image`).

It replaces the four duplicated `EmployeeAvatar` functions in `leave-approvals.js`, `overtime-approvals.js`, `regularization-approvals.js`, `swap-approvals.js`, the inline avatar in `team-attendance.js`, and the hand-rolled avatar markup inside `PunchStatusCardMobile.js`.

### Where avatars appear (mobile)

- Team attendance rows (present / absent / upcoming / off).
- All four approval screens.
- The team-roster member picker and the roster grid's employee name column.
- The swap-request coworker picker.
- The dashboard hero (section E).

### Backend plumbing

`profile_image_url` is already an appended accessor on `User` (media-library backed), so most endpoints already carry it. The gaps to close:

- `GET /api/v1/manager/team-members` — currently `->get(['id', 'name', 'employee_id'])`, which strips the accessor. Add the image.
- The roster payload (`RosterController::formatRoster()`) — add `profile_image_url` per user row.
- Swap-eligible and counterparty-roster lists.

### Web

Because the roster payload is shared, the **web** roster grid's name cell gets the real avatar too, with the current initials circle as the fallback. No other web surface changes.

---

## D. Mobile roster = the web roster

### Reference implementation (web, unchanged)

- `RosterTab.jsx` — toolbar (month nav, employee search, department filter), Grid/Per-employee toggle, legend, generate/refresh.
- `RosterCalendar.jsx` — the grid: sticky employee name column, one column per day, each day cell a 24-column CSS grid; the shift chip spans `[startHour, endHour)`; midnight-crossing shifts render as two chips (`start→24` and `0→end`); holidays and full-day leave render as full-width cells; half-day leave shades half the cell.
- `RosterEmployeeView.jsx` — per-employee month calendar, one shift chip per day.
- `RosterLegend.jsx` — colour → code + name chips, plus Off and Holiday markers.

Chip geometry comes from the **shifts catalog** (`start_time`, `end_time`, `crosses_midnight`), not from the roster payload — the roster cell only carries `code` / `color` / `off`.

### Mobile target

`app/(tabs)/team-roster.js` is rebuilt as a port of `RosterTab`:

- **Toolbar** — month nav (‹ ›) and employee search. **No department filter**: the web gets departments from an Inertia prop and the mobile API has no equivalent endpoint; a manager's roster is already scoped to their team, so the filter would be near-empty. The `department_id` parameter still exists on the endpoint for future use.
- **Legend** — the same colour/code/name chips, plus Off and Holiday, rendered from the shifts actually present in view.
- **Grid view (default)** — horizontally scrollable. Sticky left column = avatar + name. One column per day of the month. Each day cell is a 24-segment hour grid with the shift chip positioned in its real hour range, midnight-crossing shifts split into two chips — identical geometry to `RosterCalendar`.
- **Per-employee view** — month calendar for one selected employee, one chip per day, toggled by a `SegmentedTabs` control.

A month is ~30 columns wide, so "identical to web" means the grid **side-scrolls** on a phone; it does not shrink to fit. This is accepted.

`app/(tabs)/my-roster.js` keeps its week layout but adopts the same chip and legend vocabulary, so the two roster screens speak one visual language.

### New mobile API (this is the real work)

- `GET /api/v1/attendance/roster?from&to&department_id` — multi-user roster rows + `holidays` map + leave overlay. **Team-scoped**: a manager sees only `resolveTeamMemberIds()`; a non-manager sees only themselves. Mirrors the shape of the web `/attendance/roster` so the ported components need no data reshaping.
- `GET /api/v1/attendance/shifts` — the shifts catalog with `code`, `name`, `color`, `start_time`, `end_time`, `crosses_midnight`, ordered by start time (section B). Without this the mobile grid cannot position a chip.

Both reuse the existing `RosterService` / `RosterOverlayService` — no new query logic.

The mobile roster grid is **read-only** in this pass. Cell editing (the web's `RosterCellPopover`) is out of scope.

---

## E. Mobile dashboard employee hero

### Current state

`components/dashboard/DashboardView.js` renders a text welcome block ("Good morning, X" + date + last-sync), then an inline `PunchSection` (PulseDot + big clock readout + punch button). **There is no avatar on the dashboard.** The avatar-with-pulse-ring lives in `PunchStatusCardMobile.js`, which is only used on the separate `punch.js` screen.

### Design

New `components/dashboard/EmployeeHeroCard.js`, placed at the top of the dashboard. It **absorbs the welcome text block** (name/date/last-sync move into it). Layout, top to bottom, centered:

1. **Avatar** — circular, width = 50% of screen width (`Dimensions.get('window').width * 0.5`), via the shared `<Avatar>` primitive.
2. **Name** — display heading.
3. **Employee ID**.
4. **Designation · Department**.
5. **Today's shift** — colour dot + code + `08:00 – 16:00`; falls back to "Off today" / "No shift assigned".
6. **Punch state** — the live status line (on the clock since HH:MM / not on the clock / on leave).

`PunchSection` below it is **left untouched**. `punch.js` keeps its avatar but swaps its hand-rolled markup for the shared `<Avatar>` primitive so there is one implementation.

### Data

Today's shift is not currently on any mobile dashboard payload. Add a `today_shift` object (`code`, `name`, `color`, `start`, `end`, `crosses_midnight`, `off`) to `GET /api/v1/attendance/today`, resolved through the existing `RosterService::resolveShift()`. Designation and department come from the existing `UserResource`.

---

## Testing

**Guardian (PHPUnit):**
- `UpcomingShiftService` — today (in-window / out-of-window / crosses-midnight-into-tomorrow), future date, past date returns empty + `upcoming_visible: false`.
- Ordering — a fixture with 16-24, 00-08, 08-16 employees comes back 00-08, 08-16, 16-24.
- `GET /api/v1/attendance/roster` — manager sees team only; non-manager sees self only; a manager cannot read a non-team member.
- `GET /api/v1/attendance/shifts` — ordered by start time.
- Regression: existing `RosterApiTest`, `MyRosterScopingTest`, `RosterPayloadTest` still pass.

**Mobile:** no test harness exists in this repo (documented debt). Verification is by running the app against a seeded Guardian and checking each surface listed in C, D, E.

---

## Sequencing

1. **Backend, Guardian** — `UpcomingShiftService` + ordering + `profile_image_url` plumbing + the two new mobile endpoints + `today_shift`. Tests here.
2. **Web** — `AbsentSidebar` consumes `upcoming_visible`; roster name cell shows the avatar.
3. **Mobile primitives** — `<Avatar>`, shift-chip and legend components shared by both roster screens.
4. **Mobile screens** — avatars everywhere (C), `team-roster` rebuild (D), `my-roster` chip/legend alignment (D), dashboard hero (E).

Step 1 must land first: steps 3 and 4 cannot be verified without the new endpoints.
