import React from 'react';

/**
 * The layers a viewer may see, grouped, each with its count and an on/off checkbox. Shared by the header menu and the
 * maximized card's layer panel (which adds a search box above it).
 */
export default function LayerList({ layers, groups, hidden, onToggle, onSetAll, query = '' }) {
    const needle = query.trim().toLowerCase();
    const shown = layers.filter((l) => !needle || `${l.label} ${groups[l.group] ?? ''}`.toLowerCase().includes(needle));
    const order = Object.keys(groups);
    return (
        <div>
            <div className="cy-layers__actions">
                <button type="button" onClick={() => onSetAll(true)}>All on</button>
                <button type="button" onClick={() => onSetAll(false)}>All off</button>
            </div>
            {order.map((g) => {
                const rows = shown.filter((l) => l.group === g);
                if (!rows.length) return null;
                return (
                    <div key={g} role="group" aria-label={groups[g]}>
                        <div className="cy-layers__group">{groups[g]}</div>
                        {rows.map((l) => (
                            <label key={l.key} className="cy-layers__row" data-tone={l.tone}>
                                <input type="checkbox" checked={!hidden.has(l.key)} onChange={() => onToggle(l.key)} />
                                <i aria-hidden="true" />
                                <span className="cy-layers__name" title={l.source}>{l.label}</span>
                                <span className="cy-layers__count">{l.count}</span>
                            </label>
                        ))}
                    </div>
                );
            })}
            {shown.length === 0 && <div className="cy-layers__group" style={{ borderBottom: 0 }}>No layer matches.</div>}
        </div>
    );
}
