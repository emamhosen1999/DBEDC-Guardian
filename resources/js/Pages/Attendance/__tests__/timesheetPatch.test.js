import { describe, it, expect } from 'vitest';
import { patchTimesheetPunch } from '../timesheetPatch';

/**
 * Phase C: time-correction must optimistically patch the edited punch IN PLACE
 * inside the daily-timesheet cache, instead of refetching the whole list.
 * These cover the pure patch the optimistic mutation applies.
 */
describe('patchTimesheetPunch', () => {
  const baseData = () => ({
    total: 2,
    last_page: 1,
    attendances: [
      {
        id: 10,
        user: { id: 100, name: 'Alice' },
        punches: [
          { id: 1, punch_in: '2026-06-28 09:00:00', punch_out: '2026-06-28 17:00:00' },
          { id: 2, punch_in: '2026-06-28 18:00:00', punch_out: null },
        ],
      },
      {
        id: 11,
        user: { id: 101, name: 'Bob' },
        punches: [
          { id: 3, punch_in: '2026-06-28 10:00:00', punch_out: '2026-06-28 19:00:00' },
        ],
      },
    ],
  });

  it('patches punch_in on the targeted punch only', () => {
    const out = patchTimesheetPunch(baseData(), {
      attendanceId: 1,
      data: { punchin: '2026-06-28 09:30:00' },
    });
    expect(out.attendances[0].punches[0].punch_in).toBe('2026-06-28 09:30:00');
    // punch_out and sibling punches untouched
    expect(out.attendances[0].punches[0].punch_out).toBe('2026-06-28 17:00:00');
    expect(out.attendances[0].punches[1].punch_in).toBe('2026-06-28 18:00:00');
    // other attendance row untouched
    expect(out.attendances[1].punches[0].punch_in).toBe('2026-06-28 10:00:00');
  });

  it('patches punch_out (punchout key maps to punch_out)', () => {
    const out = patchTimesheetPunch(baseData(), {
      attendanceId: 2,
      data: { punchout: '2026-06-28 20:00:00' },
    });
    expect(out.attendances[0].punches[1].punch_out).toBe('2026-06-28 20:00:00');
    expect(out.attendances[0].punches[1].punch_in).toBe('2026-06-28 18:00:00');
  });

  it('returns data unchanged when the punch id is not present', () => {
    const input = baseData();
    const out = patchTimesheetPunch(input, { attendanceId: 999, data: { punchin: 'x' } });
    expect(out).toEqual(input);
  });

  it('is a no-op for empty / shapeless data', () => {
    expect(patchTimesheetPunch(undefined, { attendanceId: 1, data: { punchin: 'x' } })).toBeUndefined();
    expect(patchTimesheetPunch({}, { attendanceId: 1, data: { punchin: 'x' } })).toEqual({});
  });

  it('does not mutate the original object (immutability)', () => {
    const input = baseData();
    const snapshot = JSON.parse(JSON.stringify(input));
    patchTimesheetPunch(input, { attendanceId: 1, data: { punchin: '2026-06-28 09:30:00' } });
    expect(input).toEqual(snapshot);
  });
});
