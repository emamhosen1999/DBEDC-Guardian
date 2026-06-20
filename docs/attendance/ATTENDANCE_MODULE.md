# Attendance Module — Full Documentation

**App:** Aero-Enterprise-Suite (DBEDC Guardian) · Laravel 11 + Inertia v2 + React 18 (Radix Themes) · MySQL · Timezone Asia/Dhaka.
**Status:** Phase 0 (foundation) + Phase 1 (shifts & rostering) + completion pass — **shipped & live**. Phase 2 (workflows) — planned, see §13.

---

## 1. What this module does

The attendance module captures when employees work, decides what each work‑day *means* (present / late / absent / off / half‑day / overtime…), and lets admins run shift rosters for 24×7 operations. Everything that interprets a work‑day flows through **one engine**, so the daily view, monthly view, and stats always agree.

Three pillars:

1. **Capture** — record punches (web, mobile, biometric devices) with pluggable location/identity validation.
2. **Resolve** — for any employee+date, figure out which **shift** they were scheduled for (fixed time, rotating pattern, manual override, or the global default), then compute the day's status from their punches against that shift.
3. **Operate** — admins define shifts, build rosters (incl. rotating night shifts), handle swaps, correct records (audited), and read trustworthy stats; employees see their own attendance and upcoming roster and can request swaps.

---

## 2. Core concepts & glossary

| Term | Meaning |
|---|---|
| **Punch** | A clock‑in or clock‑out event. Stored as a full `DATETIME` (UTC‑safe), so night shifts spanning midnight compute correctly. One `attendances` row = one punch pair (in + optional out); multiple rows per day allowed. |
| **Attendance Setting** | The single global config row: office hours, break, grace thresholds, weekend days, auto‑punch‑out. Acts as the **default shift** when no roster/shift applies. |
| **Attendance Type** | A punch‑validation method (Geo‑polygon, WiFi/IP, Route‑waypoint, QR, Biometric) assigned per employee; controls *how* a punch is allowed. |
| **Shift** | A named working window: start/end time, crosses‑midnight flag, grace in/out, break, and full‑day / half‑day / min‑present minute thresholds. |
| **Rotation Pattern** | An ordered cycle (e.g. 7‑day) of shifts/off‑days. Phase = `(date − anchor) mod cycle_length`. |
| **Assignment** | An **effective‑dated** binding of a shift *or* a rotation pattern to a scope: a user, designation, department, or the whole org, with a priority. |
| **Roster Day** | The materialized truth for one employee+date: which shift (or off). Sources: `pattern` (generated), `manual` (override), `swap`, `rule`. Can be `locked`. |
| **Swap Request** | An employee‑initiated shift swap between two people/dates; on approval it rewrites both parties' roster days. |
| **Schedule (`ShiftSchedule`)** | The resolved working window + thresholds for one employee‑day that the status engine consumes. |
| **`DayAttendance`** | The engine's output: status + worked/late/early‑leave/overtime minutes + first‑in/last‑out + flags. |
| **Audit log** | An immutable record of every admin change to attendance (who, before→after, reason, IP). |

**Statuses:** `present`, `late`, `half_day`, `short`, `absent`, `on_leave`, `holiday`, `weekend`, `day_off`.

---

## 3. Architecture & data flow

### 3.1 The one‑engine design

```
                         ┌─────────────────────────┐
   punches (datetimes) ─►│  AttendanceStatusService │─► DayAttendance
   ShiftSchedule ───────►│   (pure, no DB access)   │   {status, worked_minutes,
   isHoliday / isOnLeave►│                          │    late_minutes, ot_minutes,
                         └─────────────────────────┘    early_leave_minutes,
                                    ▲                    first_in, last_out, flags}
                                    │ ShiftSchedule
                         ┌──────────┴───────────┐
                         │  ScheduleResolver     │  (interface)
                         │  └ RosterScheduleResolver (Phase 1, bound)
                         │       ├─ roster_days (manual/swap) ─┐
                         │       ├─ assignment → shift/rotation │─► Shift.toSchedule(date)
                         │       └─ (no coverage) ─────────────┴─► DefaultScheduleResolver
                         │                                          (from Attendance Setting)
                         └───────────────────────┘
```

- **`AttendanceStatusService`** is pure PHP/Carbon — it never touches the DB, so it's deterministic and fully unit‑tested. It receives the day's punches + a `ShiftSchedule` and returns a `DayAttendance`.
- **`ScheduleResolver`** (interface) decides the schedule for an employee+date. The bound implementation is **`RosterScheduleResolver`**, which:
  1. checks `roster_days` for a `manual`/`swap` override → uses that shift (or off);
  2. else resolves an effective‑dated **assignment** (precedence below) → fixed shift or rotation‑pattern phase;
  3. else, if the employee has **no roster coverage at all**, falls back to **`DefaultScheduleResolver`** (the global office‑hours settings) — preserving classic behavior for non‑shift staff.
- Every surface (Daily Timesheet late count, Monthly stats, Monthly calendar grid, employee page) reads from this same chain, so numbers never diverge.

### 3.2 Shift resolution precedence

```
manual roster override / approved swap   (roster_days.source in manual|swap)
   ▼ else
user assignment  ▸ designation assignment ▸ department assignment ▸ org assignment
   ▼ else (no roster row AND no assignment)
global default schedule (Attendance Setting)
```

A rotation‑pattern assignment computes the day's shift by phase: `definition[(date − anchor_date) mod cycle_length_days]`; a `"off"` entry (or null shift) = an off day (never "absent").

### 3.3 How a day's status is decided (engine logic)

1. **No punches:** `holiday` > `on_leave` > `weekend`/`day_off` (non‑working shift) > `absent` (a working day with no punch).
2. **Has punches:** worked minutes = Σ(punchout − punchin) across complete pairs (authoritative datetimes — no midnight heuristics). Then:
   - `short` if worked < `min_present_minutes` (when configured),
   - else `half_day` if worked < `half_day_minutes` (when configured),
   - else `late` if first‑in > shift start + `grace_in_minutes`,
   - else `present`.
   - `overtime` minutes = worked − `full_day_minutes` (when configured); `early_leave` minutes if last‑out is before shift end − `grace_out`.
   - Open punch (no out) ⇒ `is_complete=false`, flag `missing_punch_out`.

A `0` threshold disables that rule, which keeps the **default schedule** producing classic present/absent/late behavior; the default now derives `full_day_minutes`/`half_day_minutes` from office hours so overtime/half‑day also work for non‑shift staff.

### 3.4 Data model (tables)

| Table | Purpose |
|---|---|
| `attendances` | Punch rows: `user_id, date, punchin (datetime), punchout (datetime), *_location, symbol`. Index `(user_id,date)`. |
| `attendance_settings` | Single global config (default shift). |
| `attendance_types` (+ `employee_attendance_types`, `attendance_type_biometric_device`) | Validation methods + per‑employee/device assignment. |
| `attendance_audit_logs` | Immutable audit of admin mutations: `actor_id, attendance_id, action, before(json), after(json), reason, ip, created_at`. |
| `shifts` | Shift definitions. |
| `shift_rotation_patterns` | `cycle_length_days, definition(json: shift_id ints or "off")`. |
| `shift_assignments` | Effective‑dated `scope_type/scope_id`, `shift_id` XOR `rotation_pattern_id`, `anchor_date, effective_from/to, priority`. |
| `roster_days` | Materialized per `(user_id,date)` (unique): `shift_id (null=off), source, locked, note`. |
| `shift_swap_requests` | Swap requests + `approval_chain, status`. |

---

## 4. Capturing attendance (punching)

- **Channels:** web punch (`POST /attendance/punch`), mobile API (`/api/v1/attendance/punch`), and biometric devices via ZKTeco ADMS push (`/iclock/*`).
- **Validation types** (assigned per employee, multi‑config, `any`/`all` mode):
  - **Geo‑polygon** — must be inside a drawn area.
  - **WiFi/IP** — must be on an allowed network/IP.
  - **Route‑waypoint** — must be near a route point (field staff).
  - **QR** — scan a generated QR (with expiry).
  - **Biometric** — fingerprint/face via device (managed from the Biometric Devices admin panel).
- **Safeguards:** punch‑in is wrapped in a DB transaction with a row lock; a **dedupe guard** rejects a rapid duplicate punch within 30 s (HTTP 429); photos captured for polygon/route punches.
- **Server time is authoritative** for punch timestamps.

---

## 5. Shifts & rostering (Phase 1)

### 5.1 Shifts
A shift defines: `name, code, type(fixed|flexible|open), start_time, end_time, crosses_midnight, break_minutes, grace_in_minutes, grace_out_minutes, full_day_minutes, half_day_minutes, min_present_minutes, color, is_active`. A **night shift** sets `crosses_midnight=true` (e.g. 20:00→08:00) and the engine computes hours across midnight correctly.

### 5.2 Rotation patterns
An ordered cycle, e.g. a 7‑day pattern `[Day, Day, Night, Night, off, off, Day]`. Anchored to a date; the phase advances each day and wraps at `cycle_length_days`.

### 5.3 Assignments (effective‑dated)
Bind a shift **or** a rotation pattern to a scope (user / designation / department / org) with `anchor_date`, `effective_from`, optional `effective_to`, and `priority`. Overlapping date ranges for the same scope are rejected. Exactly one of shift/pattern must be set.

### 5.4 Roster generation
`Generate roster(user_ids, from, to)` materializes `roster_days` from the resolved shift for each user‑day (`source=pattern`). It is **idempotent** and **never overwrites** `manual`, `swap`, or `locked` rows. Regenerating after assignment changes is safe.

### 5.5 Overrides & swaps
- **Manual override:** set a single cell to any shift or off (`source=manual, locked=true`) — survives regeneration.
- **Swaps:** an employee requests a swap; on **approval** the system rewrites both parties' roster days (`source=swap`); on rejection nothing changes. Approve/reject are guarded to act only on `pending` requests.

---

## 6. The admin app — `/attendance`

A single page with tabs (Settings/Approvals tabs appear based on permissions):

### 6.1 Daily Timesheet
- Date picker, employee search, department filter, pagination, Excel/PDF export.
- Stat cards: **Present / Absent / Late Arrivals / On Leave** (all engine‑derived; "Late" honors each employee's resolved shift start + grace, not a fixed 9 AM).
- Per‑row punch in/out, work hours, punch count, and actions: **correct** time, **delete**, **mark present**, and **History** (opens the audit trail for that record).
- Team Locations map (today's punch locations).

### 6.2 Monthly Calendar
- Employees × days grid with status symbols; weekend shading from configured `weekend_days`; leave/holiday columns. A rostered **off‑day shows "Day Off," not "Absent."** Export to Excel/PDF.

### 6.3 Roster *(new)*
- Month grid of employees × days showing shift **codes/colors** (Day/Night) with off gaps, weekday labels, light grid lines.
- **Generate roster** for the visible month.
- **Click a cell → shift picker popover** to assign a shift or clear (manual override).
- **Swap Requests** panel: approve / reject pending swaps (approving refreshes the grid).

### 6.4 Settings *(permission: `attendance.settings`)*
- **General:** office start/end, break, late‑mark‑after, early‑leave‑before, overtime‑after, weekend days, auto‑punch‑out.
- **Attendance Types:** create/edit/delete validation types and their configs (polygons, IPs, routes, QR); biometric is read‑only (managed from Biometric Devices).
- **Shifts:** create/edit/delete shifts.
- **Rotation patterns:** build a cycle of shifts/off.
- **Shift Assignments:** assign a shift/pattern to a user/dept/designation/org, effective‑dated; list + delete.

### 6.5 Approvals *(Phase 2 — planned)*
Manager inbox for regularization + overtime requests (see §13).

---

## 7. The employee app — `/attendance-employee`

- **My Attendance:** monthly stats (Present / Absent / Late Arrivals / On Leave) for the employee, plus their own punch records.
- **My Roster:** upcoming shifts for the signed‑in user (scoped to them only — never other employees').
- **Request swap:** pick a date, an optional counterparty (by name), and a reason → submitted for approval.

---

## 8. Permissions

| Permission | Grants |
|---|---|
| `attendance.own.view` | Employee: own attendance, my‑roster, request swap. |
| `attendance.own.punch` | Punch in/out. |
| `attendance.view` | Admin read: timesheet, monthly, stats, **audit history**. |
| `attendance.manage` | Mark/bulk‑mark present; (Phase 2) approvals. |
| `attendance.correct` | Correct/add/delete/status a record (audited). |
| `attendance.settings` | Settings, types, **shifts, rotation patterns, assignments, roster generate/cell, swaps approve/reject**. |

All admin write endpoints are gated; employees can never read other employees' rosters or the approval queues.

---

## 9. Audit trail

Every admin mutation (correct, add, delete, status change, mark‑present, regularization apply) writes an immutable `attendance_audit_logs` row with the **actor**, **before→after** JSON, **reason**, and **IP**. View a record's full history via the **History** action on the Daily Timesheet (`GET /attendance/{id}/audit`). Audit rows are never updated or deleted.

---

## 10. End‑to‑end example flows

### 10.1 Set up a 24×7 rotating roster (what we did on production)
1. **Settings → Shifts:** create *Day* (08:00–20:00) and *Night* (20:00–08:00, crosses midnight).
2. **Settings → Rotation patterns:** create one 7‑day pattern per employee (e.g. `off,Day,Day,Night,Night,Night,off`).
3. **Settings → Shift Assignments:** assign each pattern to its employee, `anchor_date` on a Monday, `effective_from` = start date.
4. **Roster tab → Generate roster** for the date range → all days materialize.
5. Adjust any single day via the cell popover; handle swaps in the Swap Requests panel.
   *(Because it's pattern‑based, generating later dates keeps the rotation rolling — no re‑import.)*

### 10.2 Fix a forgotten punch‑out (admin)
Daily Timesheet → find the record → **correct** → set the punch‑out time + reason → save. The engine recomputes hours; an audit row records the change; **History** shows before→after.

### 10.3 Approve a shift swap
Employee submits a swap on `/attendance-employee`. Admin: Roster tab → **Swap Requests** → **Approve** → both employees' roster days are rewritten and the grid refreshes. (Reject leaves rosters unchanged.)

---

## 11. Endpoint reference (web)

```
# Capture / read
POST   /attendance/punch
GET    /attendance/daily-overview                 (stats: present/absent/late/on_leave)
GET    /attendances-admin-paginate                (monthly calendar data)
GET    /admin/daily-timesheet                      (daily rows)
GET    /attendance/monthly-stats | /my-monthly-stats
GET    /attendance/{id}/audit                      (record history)

# Corrections (attendance.correct)
POST   /attendance/{id}/correct
POST   /attendance/add
DELETE /attendance/{id}
PATCH  /attendance/{id}/status
POST   /attendance/mark-as-present | /bulk-mark-as-present   (attendance.manage)

# Shifts / rotation / assignments (attendance.settings)
GET|POST       /attendance/shifts          PUT|DELETE /attendance/shifts/{id}
POST           /attendance/rotation-patterns
GET|POST|DELETE /attendance/shift-assignments[/{id}]

# Roster (attendance.settings)
GET    /attendance/roster?from=&to=&department_id=
POST   /attendance/roster/generate         (user_ids[], from, to)
PUT    /attendance/roster/cell             (user_id, date, shift_id|null)

# Swaps
GET    /attendance/swaps                    (attendance.settings)
POST   /attendance/swaps/{id}/approve|reject(attendance.settings)
POST   /attendance/swaps                    (attendance.own.view — employee)
GET    /attendance/my-roster                (attendance.own.view — scoped to self)
```

---

## 12. Settings reference

| Setting | Meaning |
|---|---|
| `office_start_time` / `office_end_time` | Default working window (also the default shift). |
| `break_time_duration` | Minutes deducted; default schedule's `full_day_minutes = window − break`. |
| `late_mark_after` | Grace‑in minutes after start before "late." |
| `early_leave_before` | Grace‑out minutes before end. |
| `overtime_after` | (Threshold knob; OT in the engine is worked − full_day_minutes.) |
| `weekend_days` | Array of weekday names treated as non‑working. |
| `auto_punch_out` (+ time) | Auto‑close open punches. |

---

## 13. Roadmap — what's done vs planned

| Phase | Scope | Status |
|---|---|---|
| **0 — Foundation** | Datetime punches, the status engine, schedule resolver, enforced audit, dedupe | ✅ Shipped |
| **1 — Shifts & rostering** | Shifts (incl. night), rotation patterns, effective‑dated assignments, materialized roster + overrides + swaps, shift‑aware status | ✅ Shipped |
| **Completion pass** | Assignment UI, swap approvals, roster cell assign, audit viewer, monthly stats on the engine | ✅ Shipped |
| **2 — Workflows** | Engine detection of **out‑of‑schedule work** (off‑day / unscheduled / outside‑window flags) + **off‑day overtime**; regularization requests; overtime requests; comp‑off (TOIL); multi‑level approvals; approvals inbox surfaces flagged exception days | 📝 Planned — `docs/superpowers/plans/2026-06-20-attendance-phase-2-workflows.md` |
| **3 — Policy engine** | Per‑dept/designation/employee effective‑dated rules, grace tiers, and the **punch‑strictness toggle** (warn‑but‑allow [default] / flag‑and‑require‑approval / restrict‑to‑shift‑window) | 🔜 Future |
| **4 — Analytics & payroll** | Scorecards, trends, muster, payroll‑ready exports | 🔜 Future |
| **5 — Notifications, anti‑spoof, mobile** | Alerts/escalations, mock‑GPS/device‑binding/face‑match, offline sync | 🔜 Future |

---

## 14. Operational / developer notes

- **Tests:** PHPUnit class‑style on sqlite `:memory:`. The status engine is pure and fully unit‑tested; services/controllers have feature tests. (`php artisan test --filter=Attendance`.)
- **Migrations:** after adding any migration, run `php artisan migrate` on the live DB — the sqlite test suite won't catch a missing prod table.
- **Inertia props** that are Eloquent models with appended accessors must be mapped to plain arrays before rendering (avoids a lazy‑loading 500 / N+1).
- **Frontend:** build with `npx vite build` (never `npm run build`, which auto‑commits). The frontend client is `requestJson(method, url, {params|data})`.
- **Source of truth:** all day interpretation goes through `AttendanceStatusService` + `ScheduleResolver` — extend these (not ad‑hoc calculations) for new behavior.
