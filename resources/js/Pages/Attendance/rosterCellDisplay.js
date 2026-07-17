/**
 * Pure decision for how a roster cell renders, applying the overlay precedence:
 *   approved leave > pending-leave hint > scheduled shift (holiday-aware) > holiday > off.
 * A 24/7 operation still works holidays: a rostered shift on a holiday keeps its
 * chip (with the holiday carried in `holiday` + tooltip for the amber tint);
 * the full-width Holiday block only appears when nobody is scheduled.
 * Presentational only — no React, no side effects.
 *
 * cell: { code, color, off, leave?: { type, fraction, session, status } }
 */
export function resolveRosterCellDisplay(cell, holidayTitle) {
  if (holidayTitle) {
    const assignedOnHoliday = cell && !cell.off && !(cell.leave && cell.leave.status === 'approved');
    if (assignedOnHoliday) {
      return {
        kind: 'shift',
        label: cell.code || '·',
        color: cell.color || null,
        tooltip: `${cell.code || 'Assigned'} — works on holiday (${holidayTitle})`,
        leaveType: null,
        session: null,
        holiday: holidayTitle,
      };
    }
    return { kind: 'holiday', label: '', color: null, tooltip: `Holiday — ${holidayTitle}`, leaveType: null, session: null, holiday: holidayTitle };
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
