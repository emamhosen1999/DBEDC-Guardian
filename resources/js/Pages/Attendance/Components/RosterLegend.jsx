import React from 'react';

/**
 * Shared shift legend for the roster views (grid + per-employee).
 * Maps each shift's colour to its code + name, plus Off / Holiday markers,
 * so the colour coding is readable in every view.
 */
export default function RosterLegend({ shifts = [], showHoliday = false }) {
    if (!shifts.length) return null;
    return (
        <div className="cy-legendrow" role="group" aria-label="Roster legend">
            {shifts.map(s => (
                <span key={s.id} className="cy-legendrow__item">
                    <span className="cy-swatch" style={{ background: s.color || 'var(--accent-9)' }} aria-hidden="true" />
                    <b>{s.code}</b> {s.name}
                </span>
            ))}
            <span className="cy-legendrow__item"><span className="cy-swatch cy-swatch--off" aria-hidden="true" />Off</span>
            {showHoliday && <span className="cy-legendrow__item"><span className="cy-swatch" style={{ background: 'var(--amber-9)' }} aria-hidden="true" />Holiday</span>}
        </div>
    );
}
