# Shift Swap & Cover Redesign — Design

**Date:** 2026-06-22
**Scope:** `shift_swap_requests` schema + `ShiftSwapController` + `RosterService::applySwap` + swap routes + the web `SwapRequestForm`. (Mobile app is a separate repo — see Out of Scope.)
**Roadmap:** corrects the shift-swap feature listed as "done" — its roster-rewrite semantics are wrong for cross-date swaps and its counterparty/date rules are unenforced. Relates to Phase B #10 (approval hardening) but is a correctness fix, not new workflow.

---

## 1. Problem

The current swap feature conflates two half-built models and is semantically wrong for the main use case:

- The request form takes **free-text dates** and an **optional counterparty** ([SwapRequestForm.jsx](../../../resources/js/Forms/SwapRequestForm.jsx)); the backend only requires `requester_date` ([ShiftSwapController::store](../../../app/Http/Controllers/HRM/ShiftSwapController.php)).
- **`applySwap`** ([RosterService.php:86](../../../app/Services/Attendance/RosterService.php)), for a named counterparty + date, only rewrites the **two named cells** and merely **exchanges their shift IDs** — so the requester stays rostered on their own date. For "I can't work July 1," nothing useful happens (the requester still works July 1, possibly with a different shift type). It is only correct for a same-day shift-type exchange.
- A counterparty selected **without** a date silently falls through to a one-sided branch that drops the requester's shift and never touches the consenting counterparty.
- The counterparty picker lists **every employee in every department**, unfiltered ([AttendanceController.php:60](../../../app/Http/Controllers/AttendanceController.php)), with no eligibility or availability check.

## 2. Goal

Implement the standard, roster-driven model used by mainstream WFM systems (Deputy, When I Work, Sling, Quinyx):

- The atomic operation is a **cover leg**: a specific coworker takes over a specific rostered shift. A **swap** is two reciprocal legs. **Reciprocity** ("they cover the reverse later") is just a second, independent cover request — not modeled as one record.
- Shifts are **selected from real rosters**, never typed. Partners are **eligible same-department coworkers** who are actually **free** on the relevant date. Peer **consent** then manager **approval** (both already exist) gate the change. On approval the roster is rewritten **correctly**.

## 3. Data model

Add to `shift_swap_requests` (new migration; run on prod):

- `type` — `enum('swap','cover')`, NOT NULL, default `'swap'`, after `requester_date`.

Field semantics (existing columns, clarified):

| column | swap | cover |
|---|---|---|
| `requester_id`, `requester_date` | the shift the requester gives up (required) | same |
| `counterparty_id` | the coworker taking it (**required**) | same |
| `counterparty_date` | the counterparty's shift the requester takes back (**required**) | **must be null** |
| `counterparty_status` | `pending` → peer consent stage (both types) | same |
| `requested_shift_id` | **retired** — no longer read or written (left nullable for back-compat) | same |
| `status`, `approved_by`, `approval_chain` | unchanged | unchanged |

The old "no counterparty / open give-away" path is **removed** — `counterparty_id` is now always required, so `counterparty_status` is always `pending` (never null) on create.

## 4. Validation (store)

`POST /attendance/swaps` validates and rejects (422) unless:

- `type` in `swap,cover`.
- `requester_date` is a date AND resolves to a **working** roster day for the requester (`RosterService` resolves a non-null shift). A day off can't be given up.
- `counterparty_id` exists, **≠ requester**, and has the **same `department_id`** as the requester.
- The counterparty is **free** on `requester_date` — their resolved schedule that day is off/non-working (no double-booking).
- If `type=swap`: `counterparty_date` required; resolves to a **working** roster day for the counterparty; AND the requester is **free** on `counterparty_date`.
- If `type=cover`: `counterparty_date` must be absent/null.

Errors are field-scoped so the form can surface them.

## 5. Roster rewrite — `applySwap` (corrected)

Runs in the existing approve transaction, using `resolveScheduledShiftId` + `writeSwapDay` (source `swap`, `locked=true`):

**Cover** (counterparty takes the requester's shift; requester gets nothing back):
```
reqShift = shift(requester, requester_date)
writeSwapDay(requester,    requester_date, null)       // requester now off
writeSwapDay(counterparty, requester_date, reqShift)   // counterparty works it
```

**Swap** (trade two specific shifts — the 4-cell exchange):
```
reqShift   = shift(requester,    requester_date)
cpShift     = shift(counterparty, counterparty_date)
writeSwapDay(requester,    requester_date,    null)     // requester off their date
writeSwapDay(counterparty, requester_date,    reqShift) // counterparty covers it
writeSwapDay(counterparty, counterparty_date, null)     // counterparty off their date
writeSwapDay(requester,    counterparty_date, cpShift)  // requester takes it
```

For the Zidan example (give up Jul 1 Day, take Saif's Jul 3 Day): afterwards `(Zidan,Jul1)=off`, `(Saif,Jul1)=Day`, `(Saif,Jul3)=off`, `(Zidan,Jul3)=Day`. Zidan is genuinely off July 1.

## 6. Endpoints

- `POST /attendance/swaps` (existing) — store, with §4 validation; always sets `counterparty_status='pending'`.
- `GET /attendance/swaps/eligible?date=YYYY-MM-DD` (**new**, employee-scoped) — same-department coworkers (excluding self) who are **free** on `date`; returns `[{id,name}]` for the partner picker.
- `GET /attendance/swaps/counterparty-roster?counterparty_id=&from=&to=` (**new**, employee-scoped) — the counterparty's **working** roster days in range, for the "their shift" picker; **guarded**: the counterparty must share the requester's department (else 403). Narrow, swap-purpose exposure of a same-team coworker's upcoming shifts.
- `respond` / `awaitingMe` / `approve` / `reject` — unchanged; `approve` now calls the corrected `applySwap`.

Permissions match the existing swaps.store route gate (`attendance.own.view`); admin approve/reject stay on `attendance.settings`.

## 7. Web frontend (`SwapRequestForm.jsx`)

Rewritten as roster-driven:

- **Mode** segmented control: **Swap** | **Cover**.
- **Your shift to give up** — `Select` populated from the signed-in user's `my-roster` working days in the next ~60 days (label: `Tue Jul 1 — Day 08:00–20:00`). Selecting it sets `requester_date`.
- **Coworker** — `Select` from `GET swaps/eligible?date=<requester_date>` (same dept, free that day). Disabled until a date is chosen.
- **Their shift you'll take** — *(Swap only)* `Select` from `GET swaps/counterparty-roster?counterparty_id=…` working days. Sets `counterparty_date`.
- **Reason** (optional). Submit posts `{ type, requester_date, counterparty_id, counterparty_date|null, reason }`. Submit disabled until the mode's required fields are set.

This needs a **frontend rebuild** (`npx vite build`) and the new **migration** run on prod before it's live there.

## 8. Out of scope

- **Open-shift pool / marketplace** (offer a shift to anyone, claim from a board) — removed for now; possible future.
- **Mobile app** (`dbedc-mobile-app`, separate repo): its My Requests / swap UI must be updated to the new `type` + roster-driven shape and the two new endpoints. **Logged as a cross-repo follow-up**, not done here.
- **Rest/overtime/qualification rules** beyond simple free/busy (e.g., min rest between shifts, max weekly hours, skill match beyond department) — future policy work.
- **Multi-level approval / SLA escalation** — Phase B #10.
- Pure "I want the day off, no cover" → that's a leave request / manager roster-off, not a swap; unchanged.

## 9. Testing

PHPUnit class-style, sqlite `:memory:` + `RefreshDatabase`. Build on the existing `SwapCounterpartyConsentTest` / `RosterApplySwapTest` / `SwapRejectApiTest`.

- **Store validation:** rejects cross-department counterparty; rejects self; rejects a counterparty already working `requester_date`; rejects a non-working `requester_date`; `swap` without `counterparty_date`; `cover` with a `counterparty_date`. Accepts the valid swap and valid cover.
- **`applySwap` cover:** asserts the 2-cell outcome (requester off, counterparty on, both `source=swap, locked`).
- **`applySwap` swap:** asserts the **4-cell** outcome (the bug fix) for the Zidan/Saif scenario.
- **Eligible endpoint:** returns only same-dept, free coworkers; excludes self and busy/other-dept users.
- **Counterparty-roster endpoint:** returns the partner's working days; 403 for a cross-department target.
- **Flow regression:** consent (`respond` accept/decline) → `approve` applies the corrected rewrite; `reject` leaves rosters untouched. Existing swap tests stay green.

Verify live by HTTP status via Playwright fetch at `https://aero-enterprise-suite.test`; read-only on prod.
