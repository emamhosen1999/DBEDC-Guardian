/**
 * Pure layout helpers for roster-grid shift chips (RosterCalendar.jsx).
 * Presentational only — no React, no side effects.
 */

/**
 * A rostered day cell additionally carries `worked: true | false | null`
 * (contract: RosterController roster grid payload) — true = duty actually
 * punched, false = past rostered duty with no attendance, null/undefined =
 * future date or no shift. Maps that tri-state to a chip status-dot state.
 */
export function resolveWorkedDotState(worked) {
  if (worked === true) return 'worked';
  if (worked === false) return 'missed';
  return null;
}

/**
 * Given same-day shift intervals (hour-based, half-open [start, end)),
 * flag which ones overlap with at least one sibling and assign an
 * alternating lane (0/1) so overlapping chips can be rendered at half
 * height, stacked top/bottom, instead of silently painting over each other.
 * Non-overlapping intervals get `lane: null` (render at full height,
 * unchanged from the single-shift look).
 *
 * intervals: [{ start, end }] — order is preserved in the output.
 */
export function packShiftIntervals(intervals = []) {
  const overlaps = (a, b) => a.start < b.end && b.start < a.end;

  const flagged = intervals.map((iv, i) => ({
    ...iv,
    overlapping: intervals.some((other, j) => j !== i && overlaps(iv, other)),
  }));

  let seen = 0;
  return flagged.map((iv) => {
    if (!iv.overlapping) return { ...iv, lane: null };
    const lane = seen % 2;
    seen += 1;
    return { ...iv, lane };
  });
}
