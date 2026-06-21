# Global DateTimePicker Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build one reusable `<DateTimePicker>` component (single date, date range, datetime, datetime range, time) and replace every native date/time input across the app with it.

**Architecture:** A headless `react-aria-components` calendar/time engine + `@internationalized/date` (already installed) provides behavior and a11y; a Radix `Popover` + Radix Themes tokens provide the shell and styling. All `@internationalized/date` object conversion is isolated in a pure, unit-tested `format.js`; consumers only ever see native-format strings (single modes) or `{ start, end }` string pairs (range modes).

**Tech Stack:** React 18, Inertia, Radix UI Themes, `react-aria-components` (new), `@internationalized/date`, `lucide-react`, Vitest.

## Global Constraints

- **No HeroUI, NextUI, Tailwind, react-day-picker, or flatpickr.** Only `react-aria-components` is added.
- **UI library is Radix UI Themes only.** Style exclusively with Radix CSS tokens (`--accent-9`, `--gray-*`, radius vars) — no hardcoded colors.
- **Value contract = native-input-compatible strings:** `date`→`YYYY-MM-DD`, `time`→`HH:mm` (or `HH:mm:ss` when `granularity="second"`), `datetime`→`YYYY-MM-DDTHH:mm`. Range modes emit `{ start, end }` of those strings. Empty = `''` (single) / `{ start:'', end:'' }` (range).
- **Default `hourCycle` = 24.**
- **Icons from `lucide-react`:** `CalendarDays`, `Clock`, `ChevronLeft`, `ChevronRight`, `X`.
- **Import alias:** `@` → `resources/js`.
- **Dev/verify workflow:** run `npm run dev`; test at `https://aero-enterprise-suite.test`. NEVER run `npm run build` (it auto-commits/pushes).
- **Tests:** Vitest, pure-function style (`import { describe, it, expect } from 'vitest'`). No React Testing Library — component behavior is verified by running the app.

---

## File Structure

```
resources/js/Components/DateTimePicker/
  format.js            // PURE string <-> @internationalized/date helpers (unit-tested)
  format.test.js       // Vitest unit tests for format.js
  presets.js           // range preset definitions (pure)
  presets.test.js      // Vitest unit tests for presets.js
  CalendarPanel.jsx    // Calendar / RangeCalendar + month nav + preset rail
  TimePanel.jsx        // TimeField wrapper
  DateTimePicker.jsx   // public API + mode router + popover + string<->object glue
  datetimepicker.css   // Radix-token styling for react-aria internals
  index.js             // re-export DateTimePicker
```

Migration touches 39 files (reconciled list in Tasks 6–10).

---

### Task 1: Add dependency + pure conversion layer (`format.js`)

**Files:**
- Modify: `package.json` (add `react-aria-components` dependency)
- Create: `resources/js/Components/DateTimePicker/format.js`
- Test: `resources/js/Components/DateTimePicker/format.test.js`

**Interfaces:**
- Consumes: `@internationalized/date` (`parseDate`, `parseDateTime`, `parseTime`).
- Produces:
  - `strToDate(str) => CalendarDate | null`
  - `strToDateTime(str) => CalendarDateTime | null`
  - `strToTime(str) => Time | null`
  - `dateToStr(CalendarDate|null) => string`
  - `dateTimeToStr(CalendarDateTime|null, granularity='minute') => string`
  - `timeToStr(Time|null, granularity='minute') => string`
  - `strRangeToDateRange(startStr, endStr) => { start: CalendarDate, end: CalendarDate } | null`
  - `strRangeToDateTimeRange(startStr, endStr) => { start: CalendarDateTime, end: CalendarDateTime } | null`

- [ ] **Step 1: Add the dependency**

```bash
npm install react-aria-components@^1.5.0
```

Expected: `package.json` `dependencies` gains `"react-aria-components"`; install succeeds.

- [ ] **Step 2: Write the failing test**

Create `resources/js/Components/DateTimePicker/format.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { parseDate, parseDateTime, parseTime } from '@internationalized/date';
import {
  strToDate, strToDateTime, strToTime,
  dateToStr, dateTimeToStr, timeToStr,
  strRangeToDateRange, strRangeToDateTimeRange,
} from './format.js';

describe('format: string -> object', () => {
  it('parses a valid date', () => {
    const d = strToDate('2026-06-21');
    expect(d.year).toBe(2026); expect(d.month).toBe(6); expect(d.day).toBe(21);
  });
  it('returns null for empty/invalid date', () => {
    expect(strToDate('')).toBeNull();
    expect(strToDate('not-a-date')).toBeNull();
  });
  it('parses a valid datetime', () => {
    const dt = strToDateTime('2026-06-21T13:30');
    expect(dt.hour).toBe(13); expect(dt.minute).toBe(30);
  });
  it('parses a valid time', () => {
    const t = strToTime('09:05');
    expect(t.hour).toBe(9); expect(t.minute).toBe(5);
  });
  it('returns null for invalid time', () => {
    expect(strToTime('')).toBeNull();
    expect(strToTime('99:99x')).toBeNull();
  });
});

describe('format: object -> string', () => {
  it('formats a date', () => {
    expect(dateToStr(parseDate('2026-06-21'))).toBe('2026-06-21');
    expect(dateToStr(parseDate('0007-01-09'))).toBe('0007-01-09');
  });
  it('returns empty string for null', () => {
    expect(dateToStr(null)).toBe('');
    expect(timeToStr(null)).toBe('');
    expect(dateTimeToStr(null)).toBe('');
  });
  it('formats time at minute granularity (default)', () => {
    expect(timeToStr(parseTime('09:05:07'))).toBe('09:05');
  });
  it('formats time at second granularity', () => {
    expect(timeToStr(parseTime('09:05:07'), 'second')).toBe('09:05:07');
  });
  it('formats datetime at minute granularity', () => {
    expect(dateTimeToStr(parseDateTime('2026-06-21T13:30:45'))).toBe('2026-06-21T13:30');
  });
  it('formats datetime at second granularity', () => {
    expect(dateTimeToStr(parseDateTime('2026-06-21T13:30:45'), 'second')).toBe('2026-06-21T13:30:45');
  });
});

describe('format: range helpers', () => {
  it('builds a date range when both ends present', () => {
    const r = strRangeToDateRange('2026-06-01', '2026-06-21');
    expect(r.start.day).toBe(1); expect(r.end.day).toBe(21);
  });
  it('returns null when either end is missing/invalid', () => {
    expect(strRangeToDateRange('2026-06-01', '')).toBeNull();
    expect(strRangeToDateRange('', '2026-06-21')).toBeNull();
  });
  it('builds a datetime range when both ends present', () => {
    const r = strRangeToDateTimeRange('2026-06-01T08:00', '2026-06-01T17:30');
    expect(r.start.hour).toBe(8); expect(r.end.minute).toBe(30);
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npx vitest run resources/js/Components/DateTimePicker/format.test.js`
Expected: FAIL — `Failed to resolve import "./format.js"`.

- [ ] **Step 4: Write minimal implementation**

Create `resources/js/Components/DateTimePicker/format.js`:

```js
import { parseDate, parseDateTime, parseTime } from '@internationalized/date';

const pad = (n, len = 2) => String(n).padStart(len, '0');

// ---- string -> @internationalized/date object (null when empty/invalid) ----
export function strToDate(str) {
  if (!str) return null;
  try { return parseDate(str); } catch { return null; }
}
export function strToDateTime(str) {
  if (!str) return null;
  try { return parseDateTime(str); } catch { return null; }
}
export function strToTime(str) {
  if (!str) return null;
  try { return parseTime(str); } catch { return null; }
}

// ---- @internationalized/date object -> string (empty string when null) ----
export function dateToStr(d) {
  if (!d) return '';
  return `${pad(d.year, 4)}-${pad(d.month)}-${pad(d.day)}`;
}
export function timeToStr(t, granularity = 'minute') {
  if (!t) return '';
  const base = `${pad(t.hour)}:${pad(t.minute)}`;
  return granularity === 'second' ? `${base}:${pad(t.second)}` : base;
}
export function dateTimeToStr(dt, granularity = 'minute') {
  if (!dt) return '';
  const datePart = `${pad(dt.year, 4)}-${pad(dt.month)}-${pad(dt.day)}`;
  const timePart = granularity === 'second'
    ? `${pad(dt.hour)}:${pad(dt.minute)}:${pad(dt.second)}`
    : `${pad(dt.hour)}:${pad(dt.minute)}`;
  return `${datePart}T${timePart}`;
}

// ---- range builders for react-aria RangeCalendar (need both ends or null) ----
export function strRangeToDateRange(startStr, endStr) {
  const start = strToDate(startStr);
  const end = strToDate(endStr);
  return (start && end) ? { start, end } : null;
}
export function strRangeToDateTimeRange(startStr, endStr) {
  const start = strToDateTime(startStr);
  const end = strToDateTime(endStr);
  return (start && end) ? { start, end } : null;
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx vitest run resources/js/Components/DateTimePicker/format.test.js`
Expected: PASS (all assertions).

- [ ] **Step 6: Commit**

```bash
git add package.json package-lock.json resources/js/Components/DateTimePicker/format.js resources/js/Components/DateTimePicker/format.test.js
git commit -m "feat(datetime-picker): add react-aria-components dep + pure format helpers"
```

---

### Task 2: Range presets (`presets.js`)

**Files:**
- Create: `resources/js/Components/DateTimePicker/presets.js`
- Test: `resources/js/Components/DateTimePicker/presets.test.js`

**Interfaces:**
- Consumes: `@internationalized/date` (`CalendarDate`), `format.js` (`dateToStr`).
- Produces: `getDatePresets(today: CalendarDate) => Array<{ key, label, range: { start: string, end: string } }>` where range strings are `YYYY-MM-DD`.

- [ ] **Step 1: Write the failing test**

Create `resources/js/Components/DateTimePicker/presets.test.js`:

```js
import { describe, it, expect } from 'vitest';
import { parseDate } from '@internationalized/date';
import { getDatePresets } from './presets.js';

describe('getDatePresets', () => {
  const today = parseDate('2026-06-21'); // a Sunday

  it('returns the expected preset keys in order', () => {
    const keys = getDatePresets(today).map(p => p.key);
    expect(keys).toEqual([
      'today', 'yesterday', 'last7', 'last30',
      'thisMonth', 'lastMonth', 'thisYear',
    ]);
  });

  it('today is a single-day range', () => {
    const p = getDatePresets(today).find(p => p.key === 'today');
    expect(p.range).toEqual({ start: '2026-06-21', end: '2026-06-21' });
  });

  it('yesterday is the prior single day', () => {
    const p = getDatePresets(today).find(p => p.key === 'yesterday');
    expect(p.range).toEqual({ start: '2026-06-20', end: '2026-06-20' });
  });

  it('last7 spans the 7 days ending today (inclusive)', () => {
    const p = getDatePresets(today).find(p => p.key === 'last7');
    expect(p.range).toEqual({ start: '2026-06-15', end: '2026-06-21' });
  });

  it('last30 spans the 30 days ending today (inclusive)', () => {
    const p = getDatePresets(today).find(p => p.key === 'last30');
    expect(p.range).toEqual({ start: '2026-05-23', end: '2026-06-21' });
  });

  it('thisMonth spans the calendar month', () => {
    const p = getDatePresets(today).find(p => p.key === 'thisMonth');
    expect(p.range).toEqual({ start: '2026-06-01', end: '2026-06-30' });
  });

  it('lastMonth spans the prior calendar month', () => {
    const p = getDatePresets(today).find(p => p.key === 'lastMonth');
    expect(p.range).toEqual({ start: '2026-05-01', end: '2026-05-31' });
  });

  it('thisYear spans the calendar year', () => {
    const p = getDatePresets(today).find(p => p.key === 'thisYear');
    expect(p.range).toEqual({ start: '2026-01-01', end: '2026-12-31' });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run resources/js/Components/DateTimePicker/presets.test.js`
Expected: FAIL — `Failed to resolve import "./presets.js"`.

- [ ] **Step 3: Write minimal implementation**

Create `resources/js/Components/DateTimePicker/presets.js`:

```js
import { dateToStr } from './format.js';

// `today` is an @internationalized/date CalendarDate.
// CalendarDate supports .add()/.subtract() (durations) and .set().
export function getDatePresets(today) {
  const startOfMonth = today.set({ day: 1 });
  const endOfMonth = startOfMonth.add({ months: 1 }).subtract({ days: 1 });
  const startOfLastMonth = startOfMonth.subtract({ months: 1 });
  const endOfLastMonth = startOfMonth.subtract({ days: 1 });
  const startOfYear = today.set({ month: 1, day: 1 });
  const endOfYear = today.set({ month: 12, day: 31 });
  const yesterday = today.subtract({ days: 1 });

  const range = (start, end) => ({ start: dateToStr(start), end: dateToStr(end) });

  return [
    { key: 'today', label: 'Today', range: range(today, today) },
    { key: 'yesterday', label: 'Yesterday', range: range(yesterday, yesterday) },
    { key: 'last7', label: 'Last 7 days', range: range(today.subtract({ days: 6 }), today) },
    { key: 'last30', label: 'Last 30 days', range: range(today.subtract({ days: 29 }), today) },
    { key: 'thisMonth', label: 'This month', range: range(startOfMonth, endOfMonth) },
    { key: 'lastMonth', label: 'Last month', range: range(startOfLastMonth, endOfLastMonth) },
    { key: 'thisYear', label: 'This year', range: range(startOfYear, endOfYear) },
  ];
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run resources/js/Components/DateTimePicker/presets.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/DateTimePicker/presets.js resources/js/Components/DateTimePicker/presets.test.js
git commit -m "feat(datetime-picker): add range presets"
```

---

### Task 3: Calendar panel + styling (`CalendarPanel.jsx`, `datetimepicker.css`)

**Files:**
- Create: `resources/js/Components/DateTimePicker/CalendarPanel.jsx`
- Create: `resources/js/Components/DateTimePicker/datetimepicker.css`

**Interfaces:**
- Consumes: `react-aria-components` (`Calendar`, `RangeCalendar`, `CalendarGrid`, `CalendarCell`, `CalendarGridHeader`, `CalendarHeaderCell`, `CalendarGridBody`, `Heading`, `Button`), `lucide-react`, `presets.js` (`getDatePresets`), `@internationalized/date` (`today`, `getLocalTimeZone`), `format.js` (`dateToStr`).
- Produces:
  - `<CalendarPanel range={false} value={CalendarDate|null} onChange={fn} minValue maxValue isDateUnavailable />`
  - `<CalendarPanel range={true} value={{start,end}|null} onChange={fn} showPresets onPreset={(rangeStrings)=>void} ... />`

This task has no automated test (visual component, no RTL in repo); it is verified live in Task 5 when wired into the public component.

- [ ] **Step 1: Write the styling**

Create `resources/js/Components/DateTimePicker/datetimepicker.css`:

```css
/* Radix-token styling for react-aria-components calendar/time internals */
.dtp-panel { display: flex; gap: var(--space-3); padding: var(--space-3); }
.dtp-calendar { width: max-content; }

.dtp-calendar header {
  display: flex; align-items: center; justify-content: space-between;
  margin-bottom: var(--space-2);
}
.dtp-calendar header h2 { font-size: var(--font-size-2); font-weight: 500; }

.dtp-nav-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 28px; height: 28px; border: 0; background: transparent;
  border-radius: var(--radius-2); color: var(--gray-11); cursor: pointer;
}
.dtp-nav-btn[data-hovered] { background: var(--gray-4); }
.dtp-nav-btn[data-disabled] { color: var(--gray-7); cursor: default; }

.dtp-grid { border-collapse: collapse; }
.dtp-grid th {
  font-size: var(--font-size-1); font-weight: 500; color: var(--gray-10);
  padding: var(--space-1);
}
.dtp-cell {
  width: 34px; height: 34px; text-align: center; vertical-align: middle;
  font-size: var(--font-size-2); border-radius: var(--radius-2);
  cursor: pointer; outline: none; color: var(--gray-12);
}
.dtp-cell[data-outside-month] { display: none; }
.dtp-cell[data-hovered] { background: var(--accent-4); }
.dtp-cell[data-selected] { background: var(--accent-9); color: var(--accent-contrast); }
.dtp-cell[data-selection-start],
.dtp-cell[data-selection-end] { background: var(--accent-9); color: var(--accent-contrast); }
.dtp-cell[data-disabled],
.dtp-cell[data-unavailable] { color: var(--gray-7); cursor: default; text-decoration: line-through; }
.dtp-cell[data-focus-visible] { box-shadow: inset 0 0 0 2px var(--accent-8); }

/* range middle days */
.dtp-grid[data-range] .dtp-cell[data-selected]:not([data-selection-start]):not([data-selection-end]) {
  background: var(--accent-4); color: var(--gray-12); border-radius: 0;
}

.dtp-presets {
  display: flex; flex-direction: column; gap: 2px;
  border-left: 1px solid var(--gray-5); padding-left: var(--space-3);
}
.dtp-preset-btn {
  text-align: left; border: 0; background: transparent; cursor: pointer;
  padding: var(--space-1) var(--space-2); border-radius: var(--radius-2);
  font-size: var(--font-size-2); color: var(--gray-12); white-space: nowrap;
}
.dtp-preset-btn:hover { background: var(--gray-4); }

/* time field segments */
.dtp-timefield {
  display: inline-flex; align-items: center; gap: var(--space-1);
  border: 1px solid var(--gray-7); border-radius: var(--radius-2);
  padding: 2px var(--space-2); background: var(--color-surface);
}
.dtp-timefield[data-focus-within] { border-color: var(--accent-8); }
.dtp-segment {
  padding: 0 2px; border-radius: var(--radius-1); outline: none;
  font-variant-numeric: tabular-nums; color: var(--gray-12);
}
.dtp-segment[data-placeholder] { color: var(--gray-9); }
.dtp-segment[data-focused] { background: var(--accent-9); color: var(--accent-contrast); }
```

- [ ] **Step 2: Write the component**

Create `resources/js/Components/DateTimePicker/CalendarPanel.jsx`:

```jsx
import {
  Calendar, RangeCalendar, CalendarGrid, CalendarCell,
  CalendarGridHeader, CalendarHeaderCell, CalendarGridBody,
  Heading, Button,
} from 'react-aria-components';
import { today, getLocalTimeZone } from '@internationalized/date';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { getDatePresets } from './presets.js';
import './datetimepicker.css';

function CalendarBody({ range }) {
  return (
    <>
      <header>
        <Button slot="previous" className="dtp-nav-btn" aria-label="Previous month">
          <ChevronLeft size={16} />
        </Button>
        <Heading />
        <Button slot="next" className="dtp-nav-btn" aria-label="Next month">
          <ChevronRight size={16} />
        </Button>
      </header>
      <CalendarGrid className="dtp-grid" data-range={range || undefined}>
        <CalendarGridHeader>
          {(day) => <CalendarHeaderCell>{day}</CalendarHeaderCell>}
        </CalendarGridHeader>
        <CalendarGridBody>
          {(date) => <CalendarCell date={date} className="dtp-cell" />}
        </CalendarGridBody>
      </CalendarGrid>
    </>
  );
}

export default function CalendarPanel({
  range = false,
  value,
  onChange,
  minValue,
  maxValue,
  isDateUnavailable,
  showPresets = false,
  onPreset,
}) {
  const presets = range && showPresets
    ? getDatePresets(today(getLocalTimeZone()))
    : null;

  return (
    <div className="dtp-panel">
      {range ? (
        <RangeCalendar
          className="dtp-calendar"
          aria-label="Date range"
          value={value}
          onChange={onChange}
          minValue={minValue}
          maxValue={maxValue}
          isDateUnavailable={isDateUnavailable}
        >
          <CalendarBody range />
        </RangeCalendar>
      ) : (
        <Calendar
          className="dtp-calendar"
          aria-label="Date"
          value={value}
          onChange={onChange}
          minValue={minValue}
          maxValue={maxValue}
          isDateUnavailable={isDateUnavailable}
        >
          <CalendarBody />
        </Calendar>
      )}

      {presets && (
        <div className="dtp-presets">
          {presets.map((p) => (
            <button
              key={p.key}
              type="button"
              className="dtp-preset-btn"
              onClick={() => onPreset(p.range)}
            >
              {p.label}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 3: Commit**

```bash
git add resources/js/Components/DateTimePicker/CalendarPanel.jsx resources/js/Components/DateTimePicker/datetimepicker.css
git commit -m "feat(datetime-picker): calendar/range panel + Radix-token styling"
```

---

### Task 4: Time panel (`TimePanel.jsx`)

**Files:**
- Create: `resources/js/Components/DateTimePicker/TimePanel.jsx`

**Interfaces:**
- Consumes: `react-aria-components` (`TimeField`, `DateInput`, `DateSegment`), `datetimepicker.css`.
- Produces: `<TimePanel value={Time|null} onChange={fn} hourCycle={24} granularity="minute" aria-label />`

This task is verified live in Task 5.

- [ ] **Step 1: Write the component**

Create `resources/js/Components/DateTimePicker/TimePanel.jsx`:

```jsx
import { TimeField, DateInput, DateSegment } from 'react-aria-components';
import './datetimepicker.css';

export default function TimePanel({
  value,
  onChange,
  hourCycle = 24,
  granularity = 'minute',
  'aria-label': ariaLabel = 'Time',
}) {
  return (
    <TimeField
      value={value}
      onChange={onChange}
      hourCycle={hourCycle}
      granularity={granularity}
      aria-label={ariaLabel}
    >
      <DateInput className="dtp-timefield">
        {(segment) => <DateSegment segment={segment} className="dtp-segment" />}
      </DateInput>
    </TimeField>
  );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/Components/DateTimePicker/TimePanel.jsx
git commit -m "feat(datetime-picker): time panel"
```

---

### Task 5: Public component (`DateTimePicker.jsx`, `index.js`) + live verification

**Files:**
- Create: `resources/js/Components/DateTimePicker/DateTimePicker.jsx`
- Create: `resources/js/Components/DateTimePicker/index.js`

**Interfaces:**
- Consumes: `@radix-ui/themes` (`Popover`, `TextField`, `Button`, `Flex`, `Text`), `lucide-react`, `format.js` (all helpers), `CalendarPanel`, `TimePanel`, `@internationalized/date` (`Time`, `toCalendarDateTime`, `toTime`).
- Produces (public API): `<DateTimePicker mode value onChange min max disabledDates hourCycle granularity presets clearable label error size placeholder disabled readOnly />`. Default export.
- `mode ∈ { 'date', 'dateRange', 'datetime', 'datetimeRange', 'time' }`.

- [ ] **Step 1: Write the component**

Create `resources/js/Components/DateTimePicker/DateTimePicker.jsx`:

```jsx
import { useState } from 'react';
import { Popover, TextField, Button, Flex, Text } from '@radix-ui/themes';
import { CalendarDateTime, Time } from '@internationalized/date';
import { CalendarDays, Clock, X } from 'lucide-react';
import {
  strToDate, strToDateTime, strToTime,
  dateToStr, dateTimeToStr, timeToStr,
  strRangeToDateRange, strRangeToDateTimeRange,
} from './format.js';
import CalendarPanel from './CalendarPanel.jsx';
import TimePanel from './TimePanel.jsx';

const RANGE_MODES = new Set(['dateRange', 'datetimeRange']);
const TIME_MODES = new Set(['datetime', 'datetimeRange', 'time']);
const DATE_MODES = new Set(['date', 'dateRange', 'datetime', 'datetimeRange']);

// Merge a CalendarDate (date part) with a Time into a CalendarDateTime string.
function mergeDateTime(calDate, time, granularity) {
  if (!calDate) return '';
  const t = time ?? new Time(0, 0);
  const dt = new CalendarDateTime(calDate.year, calDate.month, calDate.day, t.hour, t.minute, t.second);
  return dateTimeToStr(dt, granularity);
}

function displayText(mode, value, granularity) {
  if (RANGE_MODES.has(mode)) {
    const { start, end } = value || {};
    if (!start && !end) return '';
    return `${start || '…'} → ${end || '…'}`;
  }
  return value || '';
}

export default function DateTimePicker({
  mode = 'date',
  value,
  onChange,
  min,
  max,
  disabledDates,
  hourCycle = 24,
  granularity = 'minute',
  presets = true,
  clearable = true,
  label,
  error,
  size = '2',
  placeholder = 'Select…',
  disabled = false,
  readOnly = false,
}) {
  const [open, setOpen] = useState(false);
  const isRange = RANGE_MODES.has(mode);
  const hasTime = TIME_MODES.has(mode);
  const hasDate = DATE_MODES.has(mode);

  const minValue = strToDate(min);
  const maxValue = strToDate(max);
  const isDateUnavailable = typeof disabledDates === 'function'
    ? (date) => disabledDates(dateToStr(date))
    : Array.isArray(disabledDates)
      ? (date) => disabledDates.includes(dateToStr(date))
      : undefined;

  // ----- value -> react-aria objects -----
  const calValue = (() => {
    if (mode === 'time') return strToTime(value);
    if (mode === 'date') return strToDate(value);
    if (mode === 'datetime') {
      return strToDateTime(value); // CalendarDateTime works directly in Calendar
    }
    if (mode === 'dateRange') return strRangeToDateRange(value?.start, value?.end);
    if (mode === 'datetimeRange') return strRangeToDateTimeRange(value?.start, value?.end);
    return null;
  })();

  // ----- handlers -----
  const handleSingleDate = (calDate) => {
    if (mode === 'date') {
      onChange(dateToStr(calDate));
    } else if (mode === 'datetime') {
      const existing = strToDateTime(value);
      const t = existing ? new Time(existing.hour, existing.minute, existing.second) : null;
      onChange(mergeDateTime(calDate, t, granularity));
    }
  };

  const handleRange = (range) => {
    // range.start / range.end are CalendarDate or CalendarDateTime
    if (mode === 'dateRange') {
      onChange({ start: dateToStr(range.start), end: dateToStr(range.end) });
    } else {
      const sExisting = strToDateTime(value?.start);
      const eExisting = strToDateTime(value?.end);
      const sTime = sExisting ? new Time(sExisting.hour, sExisting.minute, sExisting.second) : null;
      const eTime = eExisting ? new Time(eExisting.hour, eExisting.minute, eExisting.second) : null;
      onChange({
        start: mergeDateTime(range.start, sTime, granularity),
        end: mergeDateTime(range.end, eTime, granularity),
      });
    }
  };

  const handlePreset = (rangeStrings) => {
    onChange(rangeStrings);
    setOpen(false);
  };

  const handleTimeOnly = (time) => onChange(timeToStr(time, granularity));

  const handleDateTimeTimeChange = (which, time) => {
    if (mode === 'datetime') {
      const d = strToDate(value); // date part from current value
      const baseDate = d || strToDateTime(value);
      onChange(mergeDateTime(baseDate, time, granularity));
    } else {
      // datetimeRange: which is 'start' | 'end'
      const cur = value?.[which];
      const d = strToDateTime(cur);
      onChange({ ...value, [which]: mergeDateTime(d, time, granularity) });
    }
  };

  const clear = (e) => {
    e.stopPropagation();
    onChange(isRange ? { start: '', end: '' } : '');
  };

  const text = displayText(mode, value, granularity);
  const showClear = clearable && !disabled && !readOnly &&
    (isRange ? (value?.start || value?.end) : value);

  return (
    <div>
      {label && <Text size="2" weight="medium" mb="1" as="div">{label}</Text>}
      <Popover.Root open={open} onOpenChange={setOpen}>
        <Popover.Trigger disabled={disabled || readOnly}>
          <TextField.Root
            size={size}
            value={text}
            placeholder={placeholder}
            readOnly
            disabled={disabled}
            style={{ cursor: disabled ? 'default' : 'pointer' }}
            color={error ? 'red' : undefined}
          >
            <TextField.Slot>{hasDate ? <CalendarDays size={16} /> : <Clock size={16} />}</TextField.Slot>
            {showClear && (
              <TextField.Slot side="right" onClick={clear} style={{ cursor: 'pointer' }}>
                <X size={14} />
              </TextField.Slot>
            )}
          </TextField.Root>
        </Popover.Trigger>

        <Popover.Content>
          {hasDate && (
            <CalendarPanel
              range={isRange}
              value={calValue}
              onChange={isRange ? handleRange : handleSingleDate}
              minValue={minValue}
              maxValue={maxValue}
              isDateUnavailable={isDateUnavailable}
              showPresets={isRange && presets}
              onPreset={handlePreset}
            />
          )}

          {mode === 'datetime' && (
            <Flex gap="2" align="center" px="3" pb="3">
              <Text size="2">Time</Text>
              <TimePanel
                value={(() => { const dt = strToDateTime(value); return dt ? new Time(dt.hour, dt.minute, dt.second) : null; })()}
                onChange={(t) => handleDateTimeTimeChange('start', t)}
                hourCycle={hourCycle}
                granularity={granularity}
              />
            </Flex>
          )}

          {mode === 'datetimeRange' && (
            <Flex gap="3" align="center" px="3" pb="3">
              <Flex gap="2" align="center">
                <Text size="2">Start</Text>
                <TimePanel
                  value={(() => { const dt = strToDateTime(value?.start); return dt ? new Time(dt.hour, dt.minute, dt.second) : null; })()}
                  onChange={(t) => handleDateTimeTimeChange('start', t)}
                  hourCycle={hourCycle} granularity={granularity} aria-label="Start time"
                />
              </Flex>
              <Flex gap="2" align="center">
                <Text size="2">End</Text>
                <TimePanel
                  value={(() => { const dt = strToDateTime(value?.end); return dt ? new Time(dt.hour, dt.minute, dt.second) : null; })()}
                  onChange={(t) => handleDateTimeTimeChange('end', t)}
                  hourCycle={hourCycle} granularity={granularity} aria-label="End time"
                />
              </Flex>
            </Flex>
          )}

          {mode === 'time' && (
            <div style={{ padding: 'var(--space-3)' }}>
              <TimePanel
                value={strToTime(value)}
                onChange={handleTimeOnly}
                hourCycle={hourCycle}
                granularity={granularity}
              />
            </div>
          )}
        </Popover.Content>
      </Popover.Root>
      {error && <Text size="1" color="red" as="div" mt="1">{error}</Text>}
    </div>
  );
}
```

- [ ] **Step 2: Add the barrel export**

Create `resources/js/Components/DateTimePicker/index.js`:

```js
export { default } from './DateTimePicker.jsx';
export { default as DateTimePicker } from './DateTimePicker.jsx';
```

- [ ] **Step 3: Re-run the unit suites (regression)**

Run: `npx vitest run resources/js/Components/DateTimePicker/`
Expected: PASS (format + presets suites still green).

- [ ] **Step 4: Live verification harness**

Temporarily mount all five modes on an existing dev-visible page to exercise them. Edit `resources/js/Forms/LeaveForm.jsx` and, just inside the form's top `Box`, add a throwaway block (remove after verifying):

```jsx
import DateTimePicker from '@/Components/DateTimePicker';
// ...inside render, temporarily:
{/* TEMP DTP SMOKE TEST — remove */}
<Flex direction="column" gap="3" mb="4">
  <DateTimePicker mode="date" value={data.fromDate} onChange={v => setData('fromDate', v)} label="date" />
  <DateTimePicker mode="dateRange" value={{ start: data.fromDate, end: data.toDate }} onChange={({start,end}) => { setData('fromDate', start); setData('toDate', end); }} label="dateRange" />
  <DateTimePicker mode="datetime" value={''} onChange={() => {}} label="datetime" />
  <DateTimePicker mode="datetimeRange" value={{ start: '', end: '' }} onChange={() => {}} label="datetimeRange" />
  <DateTimePicker mode="time" value={''} onChange={() => {}} label="time" />
</Flex>
```

Start the dev server (`npm run dev`) and open the Leave form at `https://aero-enterprise-suite.test`. Verify, for each mode:
- Trigger shows calendar/clock icon; click opens a Radix-styled popover.
- Single date selects and closes-to-string; range highlights start→end; presets fill the range; datetime shows time field(s); time-only edits segments.
- Dark mode + accent color match the rest of the app.
- Clear (X) empties the value.

Then **revert the temporary block** (`git checkout resources/js/Forms/LeaveForm.jsx`).

- [ ] **Step 5: Commit**

```bash
git add resources/js/Components/DateTimePicker/DateTimePicker.jsx resources/js/Components/DateTimePicker/index.js
git commit -m "feat(datetime-picker): public DateTimePicker component (all 5 modes)"
```

---

### Task 6: Migrate Leave / Holiday

**Files:**
- Modify: `resources/js/Forms/LeaveForm.jsx` (from/to date pair → `dateRange`)
- Modify: `resources/js/Forms/HolidayForm.jsx` (from/to date pair → `dateRange`)

**Interfaces:**
- Consumes: `@/Components/DateTimePicker` (default export).

- [ ] **Step 1: Migrate LeaveForm**

In `resources/js/Forms/LeaveForm.jsx`, add the import near the other imports:

```jsx
import DateTimePicker from '@/Components/DateTimePicker';
```

Replace the two `From`/`To` `Box` blocks (the `TextField.Root type="date"` for `fromDate`/`toDate`) with one range control:

```jsx
<Box style={{ gridColumn: '1 / -1' }}>
  <DateTimePicker
    mode="dateRange"
    label="Leave Period"
    value={{ start: data.fromDate, end: data.toDate }}
    onChange={({ start, end }) => { setData('fromDate', start); setData('toDate', end); }}
  />
</Box>
```

- [ ] **Step 2: Migrate HolidayForm**

In `resources/js/Forms/HolidayForm.jsx`, add the import, then replace the two `from_date`/`to_date` `TextField.Root type="date"` blocks with:

```jsx
<Box style={{ gridColumn: '1 / -1' }}>
  <DateTimePicker
    mode="dateRange"
    label="Holiday Period"
    value={{ start: formData.from_date, end: formData.to_date }}
    onChange={({ start, end }) => { setFormData(f => ({ ...f, from_date: start, to_date: end })); }}
    error={err('fromDate') || err('toDate')}
  />
</Box>
```

(Confirm the exact state setter name in the file — `setFormData`/`setData` — and match it. Keep the existing `err(...)` helper.)

- [ ] **Step 3: Verify live**

With `npm run dev` running, open the Leave form and the Holiday form at `https://aero-enterprise-suite.test`. For each: pick a range, submit, confirm the request payload still sends `fromDate`/`toDate` (or `from_date`/`to_date`) as `YYYY-MM-DD` and the record saves. Confirm validation errors still render.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Forms/LeaveForm.jsx resources/js/Forms/HolidayForm.jsx
git commit -m "feat(datetime-picker): migrate Leave + Holiday forms to DateTimePicker"
```

---

### Task 7: Migrate Attendance group

**Files (modify each; replace native date/time inputs):**
- `resources/js/Forms/OvertimeRequestForm.jsx`
- `resources/js/Forms/RegularizationForm.jsx`
- `resources/js/Forms/SwapRequestForm.jsx`
- `resources/js/Forms/ShiftAssignmentForm.jsx`
- `resources/js/Forms/ShiftForm.jsx`
- `resources/js/Forms/MarkAsPresentForm.jsx`
- `resources/js/Forms/BulkMarkAsPresentForm.jsx`
- `resources/js/Forms/PolicyForm.jsx`
- `resources/js/Pages/Attendance/Components/PoliciesManager.jsx`
- `resources/js/Pages/Attendance/SettingsTab.jsx`
- `resources/js/Pages/Attendance/DailyTimesheetTab.jsx`
- `resources/js/Components/AttendanceTimePicker.jsx`
- `resources/js/Components/AdminUnified/BiometricPanel.jsx`

**Interfaces:**
- Consumes: `@/Components/DateTimePicker`.

**Per-input mapping rule (apply consistently):**
- `type="date"` single field → `mode="date"`, `value={X}`, `onChange={v => setX(v)}`.
- `type="time"` field → `mode="time"`.
- `type="datetime-local"` field → `mode="datetime"`.
- A from/to pair of two `type="date"` fields bound to two keys → one `mode="dateRange"` with `value={{ start, end }}` and `onChange={({start,end}) => { setStart(start); setEnd(end); }}`.

- [ ] **Step 1: Migrate each file**

For each file above: add `import DateTimePicker from '@/Components/DateTimePicker';`, then replace every `TextField.Root type="date|time|datetime-local"` (and any `react-datepicker`/native equivalent) with the matching `DateTimePicker` per the mapping rule, preserving the existing state key names, `setData`/setter calls, labels, and `err(...)` error wiring. Keep surrounding layout (`Box`, `Text` label) — move the label into the picker's `label` prop where a sibling `<Text>` label exists, otherwise keep the existing label and omit `label`.

Note for `AttendanceTimePicker.jsx`: it is itself a time wrapper — replace its internal native `type="time"` with `<DateTimePicker mode="time" ... />`, keeping its existing external props/signature intact so its consumers don't change.

- [ ] **Step 2: Verify live**

With `npm run dev` running, open each affected screen at `https://aero-enterprise-suite.test` (Overtime request, Regularization, Swap request, Shift assignment, Shift, Mark/Bulk-mark present, Attendance Settings/Policies/Daily timesheet, Biometric panel). For each: set the date/time, submit, confirm the payload format is unchanged (`YYYY-MM-DD`, `HH:mm`, or `YYYY-MM-DDTHH:mm`) and the record saves.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Forms/OvertimeRequestForm.jsx resources/js/Forms/RegularizationForm.jsx resources/js/Forms/SwapRequestForm.jsx resources/js/Forms/ShiftAssignmentForm.jsx resources/js/Forms/ShiftForm.jsx resources/js/Forms/MarkAsPresentForm.jsx resources/js/Forms/BulkMarkAsPresentForm.jsx resources/js/Forms/PolicyForm.jsx resources/js/Pages/Attendance/Components/PoliciesManager.jsx resources/js/Pages/Attendance/SettingsTab.jsx resources/js/Pages/Attendance/DailyTimesheetTab.jsx resources/js/Components/AttendanceTimePicker.jsx resources/js/Components/AdminUnified/BiometricPanel.jsx
git commit -m "feat(datetime-picker): migrate Attendance forms/pages to DateTimePicker"
```

---

### Task 8: Migrate DailyWork group

**Files (modify each):**
- `resources/js/Forms/DailyWorkForm.jsx`
- `resources/js/Forms/EnhancedDailyWorkForm.jsx`
- `resources/js/Forms/EnhancedDailyWorksExportForm.jsx`
- `resources/js/Pages/Project/DailyWorksUnified.jsx`
- `resources/js/Tables/DailyWorksTable.jsx`
- `resources/js/Components/DailyWork/BulkCompletionDateModal.jsx`
- `resources/js/Components/DailyWork/BulkSubmitModal.jsx`
- `resources/js/Components/DailyWork/BulkResponseStatusModal.jsx`

**Interfaces:**
- Consumes: `@/Components/DateTimePicker`.

Apply the same per-input mapping rule from Task 7. Note: export/filter screens (`EnhancedDailyWorksExportForm`, `DailyWorksUnified`, `DailyWorksTable`) with a from/to filter → use `mode="dateRange"` (presets on, which is the default).

- [ ] **Step 1: Migrate each file**

For each file: add the import; replace native date/time inputs with `DateTimePicker` per the mapping rule, preserving state keys, setters, labels, and error wiring. For from/to filter pairs use `mode="dateRange"`.

- [ ] **Step 2: Verify live**

With `npm run dev` running, open Daily Works (unified page, table filters), the add/edit Daily Work forms, the export form, and the three bulk modals at `https://aero-enterprise-suite.test`. Confirm date selection/filtering still works and payload formats are unchanged.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Forms/DailyWorkForm.jsx resources/js/Forms/EnhancedDailyWorkForm.jsx resources/js/Forms/EnhancedDailyWorksExportForm.jsx resources/js/Pages/Project/DailyWorksUnified.jsx resources/js/Tables/DailyWorksTable.jsx resources/js/Components/DailyWork/BulkCompletionDateModal.jsx resources/js/Components/DailyWork/BulkSubmitModal.jsx resources/js/Components/DailyWork/BulkResponseStatusModal.jsx
git commit -m "feat(datetime-picker): migrate DailyWork forms/pages to DateTimePicker"
```

---

### Task 9: Migrate PettyCash group

**Files (modify each):**
- `resources/js/Forms/PettyCashExpenseForm.jsx`
- `resources/js/Forms/PettyCashLoanForm.jsx`
- `resources/js/Forms/PettyCashRepaymentForm.jsx`
- `resources/js/Forms/PettyCashReimbursementForm.jsx`

**Interfaces:**
- Consumes: `@/Components/DateTimePicker`.

Apply the per-input mapping rule from Task 7 (these are predominantly single `mode="date"` fields, e.g. expense/loan/repayment dates).

- [ ] **Step 1: Migrate each file**

For each file: add the import; replace native date inputs with `DateTimePicker mode="date"` (or `dateRange` for any from/to pair), preserving state keys, setters, labels, and error wiring.

- [ ] **Step 2: Verify live**

With `npm run dev` running, open each Petty Cash form at `https://aero-enterprise-suite.test`. Set the date, submit, confirm `YYYY-MM-DD` payload and successful save.

- [ ] **Step 3: Commit**

```bash
git add resources/js/Forms/PettyCashExpenseForm.jsx resources/js/Forms/PettyCashLoanForm.jsx resources/js/Forms/PettyCashRepaymentForm.jsx resources/js/Forms/PettyCashReimbursementForm.jsx
git commit -m "feat(datetime-picker): migrate PettyCash forms to DateTimePicker"
```

---

### Task 10: Migrate Profile / People / Org / Settings group

**Files (modify each):**
- `resources/js/Forms/ProfileForm.jsx`
- `resources/js/Forms/PersonalInformationForm.jsx`
- `resources/js/Forms/FamilyMemberForm.jsx`
- `resources/js/Components/EmployeeFormModal.jsx`
- `resources/js/Forms/AddUserForm.jsx`
- `resources/js/Forms/AddEditUserFormRadix.jsx`
- `resources/js/Forms/AddEditTrainingForm.jsx`
- `resources/js/Forms/TrainingForm.jsx`
- `resources/js/Forms/AddEditJobForm.jsx`
- `resources/js/Forms/DepartmentForm.jsx`
- `resources/js/Pages/Organization/Components/DepartmentForm.jsx`
- `resources/js/Pages/Settings/RequestLogs.jsx`

**Interfaces:**
- Consumes: `@/Components/DateTimePicker`.

Apply the per-input mapping rule from Task 7. Note: `RequestLogs.jsx` is a log filter with a from/to date pair → `mode="dateRange"` (presets default on). Date-of-birth / join-date / training-date fields → `mode="date"`.

- [ ] **Step 1: Migrate each file**

For each file: add the import; replace native date inputs with the matching `DateTimePicker` mode per the mapping rule, preserving state keys, setters, labels, and error wiring.

- [ ] **Step 2: Verify live**

With `npm run dev` running, open Profile, Personal Information, Family Member, Employee modal, Add/Edit User (both forms), Add/Edit Training + Training form, Add/Edit Job, both Department forms, and Settings → Request Logs at `https://aero-enterprise-suite.test`. Confirm each date control works and payload formats are unchanged.

- [ ] **Step 3: Final sweep check**

Run a fresh grep to confirm no native date/time inputs remain:

Run: `npx rg -l "type=\"date\"|type=\"time\"|type=\"datetime-local\"" resources/js`
Expected: no results (empty output). If any file remains, migrate it with the same rule and re-run.

- [ ] **Step 4: Commit**

```bash
git add resources/js/Forms/ProfileForm.jsx resources/js/Forms/PersonalInformationForm.jsx resources/js/Forms/FamilyMemberForm.jsx resources/js/Components/EmployeeFormModal.jsx resources/js/Forms/AddUserForm.jsx resources/js/Forms/AddEditUserFormRadix.jsx resources/js/Forms/AddEditTrainingForm.jsx resources/js/Forms/TrainingForm.jsx resources/js/Forms/AddEditJobForm.jsx resources/js/Forms/DepartmentForm.jsx resources/js/Pages/Organization/Components/DepartmentForm.jsx resources/js/Pages/Settings/RequestLogs.jsx
git commit -m "feat(datetime-picker): migrate Profile/People/Org/Settings to DateTimePicker"
```

---

## Self-Review Notes

- **Spec coverage:** Engine (Task 1, 3, 4), Radix shell/theming (Task 3 CSS, Task 5), all five modes (Task 5), value contract incl. ranges (Task 1 format + Task 5 glue), presets (Task 2 + Task 3/5), drop-in string migration (Tasks 6–10), full sweep verification (Task 10 Step 3). Testing centered on pure logic per the no-RTL toolchain constraint; component behavior verified live.
- **Type consistency:** `format.js` names (`strToDate`, `dateToStr`, `strRangeToDateRange`, …) are used verbatim in Tasks 3 & 5. `CalendarPanel` props (`range`, `value`, `onChange`, `showPresets`, `onPreset`) match between Task 3 and Task 5. `TimePanel` props (`value`, `onChange`, `hourCycle`, `granularity`) match between Task 4 and Task 5.
- **Open verification point:** exact range-pair vs single-field decisions per form (Tasks 7–10) are resolved per-file during migration by reading the existing state keys; the mapping rule is explicit so the choice is mechanical.
