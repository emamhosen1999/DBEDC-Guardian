/**
 * Pure display decision for a coverage cell (assigned vs required).
 * Presentational only — no React, no side effects.
 * cell: { required: number|null, assigned: number, status: string|null }
 */
export function resolveCoverageCellDisplay(cell) {
  const assigned = Number(cell?.assigned ?? 0);
  const assignedLabel = Number.isInteger(assigned) ? String(assigned) : String(assigned);
  const required = cell?.required ?? null;

  const color =
    cell?.status === 'understaffed' ? 'var(--red-9)'
    : cell?.status === 'met' ? 'var(--green-9)'
    : cell?.status === 'overstaffed' ? 'var(--amber-9)'
    : 'var(--gray-6)';

  return {
    label: `${assignedLabel}/${required ?? '–'}`,
    color,
    status: cell?.status ?? 'untracked',
  };
}
