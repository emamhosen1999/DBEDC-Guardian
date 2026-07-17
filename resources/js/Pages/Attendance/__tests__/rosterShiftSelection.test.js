import { describe, it, expect } from 'vitest';
import { toggleShiftId, deriveSelectedShiftIds, buildOptimisticCell } from '../rosterShiftSelection';

describe('toggleShiftId', () => {
  it('adds an unselected shift', () => {
    expect(toggleShiftId([], 1)).toEqual([1]);
    expect(toggleShiftId([1], 2)).toEqual([1, 2]);
  });

  it('removes an already-selected shift', () => {
    expect(toggleShiftId([1, 2], 1)).toEqual([2]);
  });

  it('silently ignores picks past the max cap (default 3)', () => {
    expect(toggleShiftId([1, 2, 3], 4)).toEqual([1, 2, 3]);
  });

  it('respects a custom max', () => {
    expect(toggleShiftId([1], 2, 1)).toEqual([1]);
  });
});

describe('deriveSelectedShiftIds', () => {
  const shifts = [{ id: 10, code: 'D' }, { id: 11, code: 'N' }];

  it('prefers the shifts array when present', () => {
    const cell = { code: 'D', shifts: [{ id: 10, code: 'D' }, { id: 11, code: 'N' }] };
    expect(deriveSelectedShiftIds(cell, shifts)).toEqual([10, 11]);
  });

  it('falls back to legacy code lookup when shifts is absent', () => {
    const cell = { code: 'N', off: false };
    expect(deriveSelectedShiftIds(cell, shifts)).toEqual([11]);
  });

  it('returns [] for an off cell', () => {
    expect(deriveSelectedShiftIds({ off: true }, shifts)).toEqual([]);
  });

  it('returns [] for a missing cell', () => {
    expect(deriveSelectedShiftIds(undefined, shifts)).toEqual([]);
    expect(deriveSelectedShiftIds(null, shifts)).toEqual([]);
  });

  it('returns [] when the legacy code has no catalog match', () => {
    expect(deriveSelectedShiftIds({ code: 'ZZZ', off: false }, shifts)).toEqual([]);
  });
});

describe('buildOptimisticCell', () => {
  const shifts = [
    { id: 10, code: 'D', color: '#111' },
    { id: 11, code: 'N', color: '#222' },
  ];

  it('empty shiftIds => off cell', () => {
    const cell = buildOptimisticCell([], shifts, null);
    expect(cell.off).toBe(true);
    expect(cell.code).toBeNull();
    expect(cell.shifts).toEqual([]);
  });

  it('single shift => legacy code/color mirrors the primary shift', () => {
    const cell = buildOptimisticCell([10], shifts, null);
    expect(cell.off).toBe(false);
    expect(cell.code).toBe('D');
    expect(cell.color).toBe('#111');
    expect(cell.shifts).toEqual([{ id: 10, code: 'D', color: '#111' }]);
  });

  it('multiple shifts => legacy fields mirror the first, all carried in shifts', () => {
    const cell = buildOptimisticCell([11, 10], shifts, null);
    expect(cell.code).toBe('N');
    expect(cell.color).toBe('#222');
    expect(cell.shifts).toEqual([
      { id: 11, code: 'N', color: '#222' },
      { id: 10, code: 'D', color: '#111' },
    ]);
  });

  it('carries the work location id through', () => {
    expect(buildOptimisticCell([10], shifts, 7).work_location_id).toBe(7);
  });

  it('ignores unknown ids', () => {
    const cell = buildOptimisticCell([999], shifts, null);
    expect(cell.off).toBe(true);
    expect(cell.shifts).toEqual([]);
  });
});
