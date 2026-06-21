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
