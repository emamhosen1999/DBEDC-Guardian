import { describe, it, expect } from 'vitest';
import { resolveWorkedDotState, packShiftIntervals } from '../rosterChipLayout';

describe('resolveWorkedDotState', () => {
  it('worked=true maps to the "worked" dot', () => {
    expect(resolveWorkedDotState(true)).toBe('worked');
  });
  it('worked=false maps to the "missed" dot', () => {
    expect(resolveWorkedDotState(false)).toBe('missed');
  });
  it('null/undefined (future date or no shift) has no dot', () => {
    expect(resolveWorkedDotState(null)).toBeNull();
    expect(resolveWorkedDotState(undefined)).toBeNull();
  });
});

describe('packShiftIntervals', () => {
  it('non-overlapping intervals get lane: null (render full height, unchanged)', () => {
    const out = packShiftIntervals([{ start: 6, end: 14 }, { start: 14, end: 22 }]);
    expect(out[0].overlapping).toBe(false);
    expect(out[0].lane).toBeNull();
    expect(out[1].overlapping).toBe(false);
    expect(out[1].lane).toBeNull();
  });

  it('overlapping intervals are flagged and assigned alternating lanes', () => {
    const out = packShiftIntervals([{ start: 8, end: 16 }, { start: 10, end: 18 }]);
    expect(out[0].overlapping).toBe(true);
    expect(out[1].overlapping).toBe(true);
    expect(out[0].lane).toBe(0);
    expect(out[1].lane).toBe(1);
  });

  it('identical windows are treated as overlapping', () => {
    const out = packShiftIntervals([{ start: 9, end: 17 }, { start: 9, end: 17 }]);
    expect(out[0].overlapping).toBe(true);
    expect(out[1].overlapping).toBe(true);
    expect(out[0].lane).not.toBe(out[1].lane);
  });

  it('touching-but-not-overlapping (end === start) intervals do not overlap', () => {
    const out = packShiftIntervals([{ start: 6, end: 14 }, { start: 14, end: 22 }]);
    expect(out.every(iv => !iv.overlapping)).toBe(true);
  });

  it('empty input returns empty output', () => {
    expect(packShiftIntervals([])).toEqual([]);
  });
});
