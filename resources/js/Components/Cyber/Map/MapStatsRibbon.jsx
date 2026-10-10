import React from 'react';

/** Cyber stat cells in one row across the top of the map (two per row on a phone): caption above, figure below. */
export default function MapStatsRibbon({ items }) {
    const shown = items.filter((i) => i && i.value !== null && i.value !== undefined);
    if (!shown.length) return null;
    return (
        <div className="cy-ribbon" style={{ '--n': shown.length }} role="list" aria-label="Map summary">
            {shown.map((i) => (
                <div key={i.key ?? i.label} className="cy-ribbon__cell" role="listitem" title={i.title}>
                    <span className="cy-stat__label">{i.label}</span>
                    <span className="cy-stat__value" data-tone={i.tone ?? 'neutral'}>{i.value}</span>
                </div>
            ))}
        </div>
    );
}
