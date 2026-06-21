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
