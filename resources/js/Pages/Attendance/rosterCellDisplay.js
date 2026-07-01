/**
 * Pure decision for how a roster cell renders, applying the overlay precedence:
 *   holiday > approved leave > pending-leave hint > scheduled shift > off.
 * Presentational only — no React, no side effects.
 *
 * cell: { code, color, off, leave?: { type, fraction, session, status } }
 */
export function resolveRosterCellDisplay(cell, holidayTitle) {
  if (holidayTitle) {
    return { kind: 'holiday', label: '', color: null, tooltip: `Holiday — ${holidayTitle}`, leaveType: null, session: null };
  }

  const leave = cell?.leave;
  if (leave && leave.status === 'approved') {
    if (leave.fraction === 0.5) {
      const sessionLabel = leave.session === 'first_half' ? 'AM' : leave.session === 'second_half' ? 'PM' : null;
      return {
        kind: 'leave-half',
        label: cell.code || leave.type,
        color: cell.color || null,
        tooltip: sessionLabel ? `Half-day leave (${sessionLabel}) — ${leave.type}` : `Half-day leave — ${leave.type}`,
        leaveType: leave.type,
        session: leave.session,
      };
    }
    return { kind: 'leave', label: leave.type, color: null, tooltip: `On leave — ${leave.type}`, leaveType: leave.type, session: null };
  }

  const assigned = cell && !cell.off;
  if (leave && leave.status === 'pending' && assigned) {
    return { kind: 'pending', label: cell.code || '·', color: cell.color || null, tooltip: `${cell.code || 'Assigned'} — pending leave`, leaveType: leave.type, session: null };
  }
  if (leave && leave.status === 'pending') {
    return { kind: 'pending', label: '', color: null, tooltip: `Pending leave — ${leave.type}`, leaveType: leave.type, session: null };
  }

  if (assigned) {
    return { kind: 'shift', label: cell.code || '·', color: cell.color || null, tooltip: cell.code || 'Assigned', leaveType: null, session: null };
  }
  if (cell?.off) {
    return { kind: 'off', label: '', color: null, tooltip: 'Off', leaveType: null, session: null };
  }
  return { kind: 'off', label: '', color: null, tooltip: 'No assignment', leaveType: null, session: null };
}
