/**
 * Pure helpers for the multi-shift roster-cell popover (RosterCellPopover)
 * and the resulting cell-update mutation (RosterTab). Presentational/data
 * logic only — no React, no side effects.
 *
 * Contract (RosterController@updateCell): `shift_ids: number[]` (max 3,
 * distinct; empty array = OFF). Legacy scalar `shift_id` is still accepted
 * and kept in sync as `shift_ids[0] ?? null` for back-compat.
 */

const MAX_SHIFTS = 3;

/**
 * Toggle a shift id in/out of a multi-select. "Off" is implicit (empty
 * selection) — the popover's Off control just clears the whole list rather
 * than toggling a member, so it is naturally exclusive with any shift pick.
 * The max cap is enforced silently: picks past the cap are ignored instead
 * of erroring.
 */
export function toggleShiftId(selected = [], shiftId, max = MAX_SHIFTS) {
  if (selected.includes(shiftId)) {
    return selected.filter(id => id !== shiftId);
  }
  if (selected.length >= max) return selected;
  return [...selected, shiftId];
}

/**
 * Derive the popover's initial multi-select from a roster cell: prefers the
 * new `shifts` array (all shifts rostered that user+date) and falls back to
 * the legacy single `code` when `shifts` is absent (older cached data / a
 * cell that hasn't gone through the new endpoint yet).
 */
export function deriveSelectedShiftIds(cell, shifts = []) {
  if (!cell) return [];
  if (Array.isArray(cell.shifts) && cell.shifts.length > 0) {
    return cell.shifts.map(s => s.id);
  }
  if (cell.code && !cell.off) {
    const found = shifts.find(s => s.code === cell.code);
    return found ? [found.id] : [];
  }
  return [];
}

/**
 * Build the optimistic roster-cell patch for a shift_ids write: resolves
 * each id against the shifts catalog, keeps legacy `code`/`color` = the
 * first (primary) shift for back-compat, and carries the full multi-shift
 * list under `shifts`.
 */
export function buildOptimisticCell(shiftIds = [], shifts = [], workLocationId = null) {
  const resolved = shiftIds.map(id => shifts.find(s => s.id === id)).filter(Boolean);
  const primary = resolved[0] || null;
  return {
    code: primary ? primary.code : null,
    color: primary ? primary.color : null,
    off: resolved.length === 0,
    shifts: resolved.map(s => ({ id: s.id, code: s.code, color: s.color })),
    work_location_id: workLocationId ?? null,
  };
}
