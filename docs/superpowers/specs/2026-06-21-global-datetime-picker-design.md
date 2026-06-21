# Global `<DateTimePicker>` — Design Spec

**Date:** 2026-06-21
**Status:** Approved (brainstorming) — pending implementation plan
**Author:** Emam Hosen + Claude

## Summary

Build one reusable, globally-applied date/time picker component for the Aero
Enterprise Suite frontend. It must support single date, date range, date+time,
date+time range, and time-only selection through a single `mode` prop, match the
existing Radix UI Themes look and dark mode, and replace the ~34 native
`<input type="date">` usages currently scattered across the app.

## Goals

- A single `<DateTimePicker>` component covering all date/time input needs.
- HeroUI-grade UX (accessibility, keyboard navigation, i18n) **without** HeroUI
  or Tailwind — the app deliberately migrated off HeroUI to Radix UI Themes.
- Drop-in friendly: emit native-compatible string values so existing form
  wiring (`setData(key, value)`) changes mechanically, not structurally.
- Apply everywhere: migrate all current native date inputs, phased and verified.

## Non-Goals

- Re-introducing HeroUI, NextUI, or Tailwind.
- A general form framework or validation layer (forms keep their current
  Inertia / precognition / zod wiring).
- Backend/API changes — the emitted value formats match what the backend
  already receives from native inputs.

## Decisions (from brainstorming)

| Decision | Choice | Rationale |
|----------|--------|-----------|
| UI library | Radix UI Themes only (no HeroUI/Tailwind) | App already migrated off HeroUI; avoids two theming systems. |
| Calendar engine | `react-aria-components` (headless) + `@internationalized/date` | Same engine HeroUI uses; best a11y/i18n; `@internationalized/date` already installed. |
| Modes | Full set: `date`, `dateRange`, `datetime`, `datetimeRange`, `time` | One component, one API, covers every current and future case. |
| Rollout | Build + migrate all ~34 inputs, phased & verified | Consistent result across the app. |
| Range presets | On by default for range modes | ERP filters/reports benefit from quick ranges. |
| Value contract | Native-compatible strings by default | Mechanical migration from native inputs. |

## Architecture

### Dependencies

- **New runtime dependency:** `react-aria-components` (pairs with the
  `@internationalized/date` 3.5.6 already in `package.json`).
- **Reused:** `@internationalized/date`, `lucide-react` (icons),
  `@radix-ui/themes` (Popover, TextField, Button, Text, theming tokens).
- **Not added:** HeroUI, NextUI, Tailwind, react-day-picker, flatpickr.

### Theming & shell

- Radix `Popover` provides the trigger anchor and floating panel.
- The visible field reuses Radix `TextField`/`Button` styling.
- All calendar/time cells styled with Radix CSS custom properties
  (`--accent-9`, `--gray-*`, radius tokens) so light/dark mode and accent color
  follow the global theme automatically.

### Icons (lucide-react)

`CalendarDays` (date trigger), `Clock` (time trigger), `ChevronLeft` /
`ChevronRight` (month navigation), `X` (clear).

## Component structure (isolated, testable units)

```
resources/js/Components/DateTimePicker/
  DateTimePicker.jsx   // public API + mode router; owns string<->object conversion
  CalendarPanel.jsx    // Calendar / RangeCalendar + month navigation + preset rail
  TimePanel.jsx        // TimeField (hourCycle, minute/second granularity)
  presets.js           // range preset definitions
  format.js            // PURE parse/format helpers (string <-> @internationalized) — unit-tested
  datetimepicker.css   // Radix-token styling
  index.js             // re-export
```

Each unit has one purpose:
- `format.js` — pure conversion between native strings and
  `@internationalized/date` objects (`CalendarDate`, `CalendarDateTime`,
  `Time`). No React, fully unit-testable.
- `DateTimePicker.jsx` — public component; routes by `mode`, manages popover
  open state, converts value in/out via `format.js`.
- `CalendarPanel.jsx` — renders `Calendar` or `RangeCalendar`, month nav, and
  the preset rail (range modes).
- `TimePanel.jsx` — renders `TimeField` for `time`/`datetime*` modes.
- `presets.js` — range preset list (computed against "today").

## Public API

```jsx
<DateTimePicker
  mode="date|dateRange|datetime|datetimeRange|time"
  value={…}            // string for single modes; { start, end } for range modes
  onChange={…}         // mirrors value shape
  min={…} max={…}      // bounds (native-format strings)
  disabledDates={…}    // predicate (date) => boolean, or array of strings
  hourCycle={24}       // 12 | 24 — time/datetime modes
  granularity="minute" // "minute" | "second"
  presets={true}       // range modes only; default on
  clearable
  label error
  size="2"             // Radix size scale
  placeholder
  disabled readOnly
/>
```

### Value contract

Default emitted/accepted formats (native-input compatible):

| Mode | Value type | Format |
|------|-----------|--------|
| `date` | string | `YYYY-MM-DD` |
| `time` | string | `HH:mm` (or `HH:mm:ss` when `granularity="second"`) |
| `datetime` | string | `YYYY-MM-DDTHH:mm` |
| `dateRange` | `{ start, end }` strings | each `YYYY-MM-DD` |
| `datetimeRange` | `{ start, end }` strings | each `YYYY-MM-DDTHH:mm` |

Empty/unset value is `''` for single modes and `{ start: '', end: '' }` for
range modes.

### Migration patterns

Single date (mechanical replacement):

```jsx
// before
<TextField.Root type="date" value={data.fromDate}
  onChange={e => setData('fromDate', e.target.value)} />
// after
<DateTimePicker mode="date" value={data.fromDate}
  onChange={v => setData('fromDate', v)} />
```

Two-key from/to pair → single range control:

```jsx
<DateTimePicker
  mode="dateRange"
  value={{ start: data.fromDate, end: data.toDate }}
  onChange={({ start, end }) => { setData('fromDate', start); setData('toDate', end); }}
/>
```

## UX

- Field displays the formatted value with a calendar or clock icon; clicking
  opens the Radix Popover with the relevant panel.
- `dateRange` / `datetimeRange`: two-month `RangeCalendar` with hover-range
  preview, plus a preset rail: Today, Yesterday, Last 7 days, Last 30 days,
  This month, Last month, This year.
- `datetime` / `datetimeRange`: calendar with an inline time panel.
- `time`: time panel only.
- Full keyboard support, focus trap, ARIA labels, locale/RTL handling via
  `@internationalized/date` and `react-aria-components`.

## Rollout (phased & verified)

Build component + tests first, then sweep all ~34 native date inputs grouped by
area, verifying each group renders and submits in the existing format before
proceeding:

1. **Leave / Holiday** — `LeaveForm`, `HolidayForm`.
2. **Attendance** — `OvertimeRequestForm`, `RegularizationForm`,
   `SwapRequestForm`, `ShiftAssignmentForm`, `MarkAsPresentForm`,
   `BulkMarkAsPresentForm`, `PoliciesManager`, `PolicyForm`,
   `DailyTimesheetTab`.
3. **DailyWork** — `DailyWorkForm`, `EnhancedDailyWorkForm`,
   `EnhancedDailyWorksExportForm`, `DailyWorksUnified`, `DailyWorksTable`,
   `BulkCompletionDateModal`, `BulkSubmitModal`, `BulkResponseStatusModal`.
4. **PettyCash** — `PettyCashExpenseForm`, `PettyCashLoanForm`,
   `PettyCashRepaymentForm`, `PettyCashReimbursementForm`.
5. **Profile / People / Org** — `ProfileForm`, `PersonalInformationForm`,
   `FamilyMemberForm`, `EmployeeFormModal`, `AddUserForm`, `AddEditUserFormRadix`,
   `AddEditTrainingForm`, `AddEditJobForm`, `DepartmentForm`
   (Forms + Organization), `RequestLogs`.

(Exact file list reconciled during planning via a fresh grep for
`type="date"|type="time"|type="datetime-local"`.)

## Testing

- **Unit (`format.js`):** every mode round-trips; invalid/empty input; bounds
  (`min`/`max`); 12/24 hour cycle; second granularity.
- **Component render tests:** per mode — open popover, select a value/range/time,
  assert the emitted string/object matches the contract; clear behavior;
  disabled/readOnly.
- Run under the existing Vitest setup (sqlite test env unaffected; this is
  frontend-only).

## Risks & mitigations

- **`@internationalized/date` object model differs from JS `Date`.** Contained
  entirely in `format.js`; consumers only ever see strings/`{start,end}`.
- **Styling drift from Radix.** Use Radix CSS tokens exclusively; no hardcoded
  colors.
- **Large migration surface.** Phased, per-group verification; value contract
  keeps each change mechanical.
