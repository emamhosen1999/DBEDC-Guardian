import { describe, it, expect } from 'vitest';
import { resolveRosterCellDisplay } from '../rosterCellDisplay';

describe('resolveRosterCellDisplay', () => {
  it('holiday with an approved leave shows the holiday block', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 1, session: null, status: 'approved' } };
    const out = resolveRosterCellDisplay(cell, 'Eid');
    expect(out.kind).toBe('holiday');
    expect(out.tooltip).toContain('Eid');
  });

  it('holiday with a rostered shift keeps the shift chip and carries the holiday', () => {
    const cell = { code: 'NGT', color: '#123', off: false };
    const out = resolveRosterCellDisplay(cell, 'Eid');
    expect(out.kind).toBe('shift');
    expect(out.label).toBe('NGT');
    expect(out.holiday).toBe('Eid');
    expect(out.tooltip).toContain('Eid');
  });

  it('holiday with no assignment shows the holiday block', () => {
    const out = resolveRosterCellDisplay({ off: true }, 'Eid');
    expect(out.kind).toBe('holiday');
    expect(out.holiday).toBe('Eid');
  });

  it('approved full-day leave supersedes the shift', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 1, session: null, status: 'approved' } };
    const out = resolveRosterCellDisplay(cell, null);
    expect(out.kind).toBe('leave');
    expect(out.label).toBe('AL');
    expect(out.tooltip).toContain('leave');
  });

  it('approved half-day leave keeps the worked shift color', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 0.5, session: 'second_half', status: 'approved' } };
    const out = resolveRosterCellDisplay(cell, null);
    expect(out.kind).toBe('leave-half');
    expect(out.color).toBe('#123');
    expect(out.session).toBe('second_half');
  });

  it('pending leave is a hint over the shift, not a takeover', () => {
    const cell = { code: 'D', color: '#123', off: false, leave: { type: 'AL', fraction: 1, session: null, status: 'pending' } };
    const out = resolveRosterCellDisplay(cell, null);
    expect(out.kind).toBe('pending');
    expect(out.label).toBe('D');
  });

  it('plain shift and off render normally', () => {
    expect(resolveRosterCellDisplay({ code: 'N', color: '#0af', off: false }, null).kind).toBe('shift');
    expect(resolveRosterCellDisplay({ off: true }, null).kind).toBe('off');
    expect(resolveRosterCellDisplay(undefined, null).kind).toBe('off');
  });
});
