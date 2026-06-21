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
