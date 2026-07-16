import { describe, it, expect } from 'vitest';
import {
  normalizeViolations,
  violationsFromResult,
  hasBlockingViolation,
  groupViolationsByEmployee,
  keyEmployeesById,
} from '../complianceViolations';

const V1 = { date: '2026-07-10', rule: 'min_rest', message: 'Less than 11h rest between shifts.', severity: 'warning', details: {} };
const V2 = { date: '2026-07-11', rule: 'max_span', message: 'Exceeds 13h span in 24h.', severity: 'error', details: {} };

describe('normalizeViolations', () => {
  it('returns [] for null/undefined', () => {
    expect(normalizeViolations(null)).toEqual([]);
    expect(normalizeViolations(undefined)).toEqual([]);
  });

  it('flattens a flat array (RosterController@updateCell shape) with userId: null', () => {
    const out = normalizeViolations([V1, V2]);
    expect(out).toEqual([{ userId: null, ...V1 }, { userId: null, ...V2 }]);
  });

  it('flattens a keyed-by-user_id object (generate/assignment/swap shape)', () => {
    const out = normalizeViolations({ 5: [V1], 9: [V2] });
    expect(out).toEqual([{ userId: 5, ...V1 }, { userId: 9, ...V2 }]);
  });
});

describe('violationsFromResult', () => {
  it('reads compliance_violations off a plain success payload', () => {
    const out = violationsFromResult({ message: 'ok', compliance_violations: [V1] });
    expect(out).toEqual([{ userId: null, ...V1 }]);
  });

  it('reads compliance_violations off a caught ApiError (422 response.data)', () => {
    const err = { status: 422, response: { data: { compliance_violations: { 3: [V2] } } } };
    const out = violationsFromResult(err);
    expect(out).toEqual([{ userId: 3, ...V2 }]);
  });

  it('returns [] when neither shape is present', () => {
    expect(violationsFromResult({ message: 'ok' })).toEqual([]);
    expect(violationsFromResult({ status: 500 })).toEqual([]);
  });
});

describe('hasBlockingViolation', () => {
  it('true when any entry is severity=error', () => {
    expect(hasBlockingViolation([{ severity: 'warning' }, { severity: 'error' }])).toBe(true);
  });
  it('false when all entries are warnings', () => {
    expect(hasBlockingViolation([{ severity: 'warning' }])).toBe(false);
  });
  it('false for an empty list', () => {
    expect(hasBlockingViolation([])).toBe(false);
  });
});

describe('groupViolationsByEmployee', () => {
  it('groups by userId and resolves names from the lookup', () => {
    const violations = normalizeViolations({ 5: [V1, V2] });
    const groups = groupViolationsByEmployee(violations, { 5: { name: 'Ada Lovelace' } });
    expect(groups).toHaveLength(1);
    expect(groups[0].name).toBe('Ada Lovelace');
    expect(groups[0].violations).toHaveLength(2);
  });

  it('falls back to #id when the employee is not in the lookup', () => {
    const violations = normalizeViolations({ 7: [V1] });
    const groups = groupViolationsByEmployee(violations, {});
    expect(groups[0].name).toBe('#7');
  });

  it('separates multiple users into separate groups', () => {
    const violations = normalizeViolations({ 1: [V1], 2: [V2] });
    const groups = groupViolationsByEmployee(violations, { 1: { name: 'A' }, 2: { name: 'B' } });
    expect(groups.map(g => g.name).sort()).toEqual(['A', 'B']);
  });
});

describe('keyEmployeesById', () => {
  it('builds an id -> employee lookup', () => {
    const lookup = keyEmployeesById([{ id: 1, name: 'A' }, { id: 2, name: 'B' }]);
    expect(lookup[1].name).toBe('A');
    expect(lookup[2].name).toBe('B');
  });

  it('handles an empty list', () => {
    expect(keyEmployeesById([])).toEqual({});
  });
});
