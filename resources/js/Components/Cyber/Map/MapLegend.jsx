import React, { useId, useState } from 'react';

import Icon from '../Icon.jsx';
import { LayerSwatch, PERSON_KEY, STATUS_KEY } from './symbology.jsx';

/**
 * Map legend (bottom left, collapsible): every symbol on the map explained - each visible layer with its own badge
 * and count, grouped, then the status rings, the people rings, clusters, the corridor line and the districts.
 */
export default function MapLegend({ layers, groups, people = false, defaultOpen = true }) {
    const [open, setOpen] = useState(defaultOpen);
    const id = useId();
    const order = Object.keys(groups ?? {});
    const shown = layers.filter((l) => l.count > 0);

    return (
        <div className="cy-legend" data-open={open}>
            <button type="button" className="cy-map__btn cy-legend__toggle" aria-expanded={open} aria-controls={id} onClick={() => setOpen((o) => !o)}>
                <Icon name="list-check" /> Legend
            </button>
            {open && (
                <div className="cy-legend__panel" id={id} role="region" aria-label="Map legend">
                    {order.map((g) => {
                        const rows = shown.filter((l) => l.group === g);
                        if (!rows.length) return null;
                        return (
                            <div key={g} className="cy-legend__group">
                                <div className="cy-legend__head">{groups[g]}</div>
                                {rows.map((l) => (
                                    <div key={l.key} className="cy-legend__row">
                                        <LayerSwatch layer={l} />
                                        <span className="cy-legend__name">{l.label}</span>
                                        <span className="cy-legend__count">{l.count.toLocaleString('en-GB')}</span>
                                    </div>
                                ))}
                            </div>
                        );
                    })}
                    <div className="cy-legend__group">
                        <div className="cy-legend__head">Symbols</div>
                        {STATUS_KEY.map((s) => (
                            <div key={s.tone} className="cy-legend__row">
                                <svg className="cy-map__swatch" width="18" height="18" viewBox="-9 -9 18 18" aria-hidden="true"><circle className="cy-map__ring" data-tone={s.tone} r="6.5" /><circle className="cy-map__swatch-dot" r="3.5" /></svg>
                                <span className="cy-legend__name">{s.label}</span>
                            </div>
                        ))}
                        {people && PERSON_KEY.map((p) => (
                            <div key={p.status} className="cy-legend__row">
                                <svg className="cy-map__swatch" width="18" height="18" viewBox="-9 -9 18 18" aria-hidden="true"><circle className="cy-map__avatar-bg" r="7" /><circle className="cy-map__avatar-ring" data-status={p.status} r="7" /></svg>
                                <span className="cy-legend__name">{p.label}</span>
                            </div>
                        ))}
                        <div className="cy-legend__row">
                            <svg className="cy-map__swatch" width="18" height="18" viewBox="-9 -9 18 18" aria-hidden="true"><circle className="cy-map__cluster" r="8" /><text className="cy-map__cluster-n" dy="0.35em" textAnchor="middle">5</text></svg>
                            <span className="cy-legend__name">Several records here: click to zoom in</span>
                        </div>
                        <div className="cy-legend__row">
                            <svg className="cy-map__swatch" width="18" height="18" viewBox="-9 -9 18 18" aria-hidden="true"><line className="cy-map__swatch-corridor" x1="-8" y1="0" x2="8" y2="0" /><line className="cy-map__tick" x1="0" x2="0" y1="-4" y2="4" /></svg>
                            <span className="cy-legend__name">Expressway centreline; ticks every 1 km</span>
                        </div>
                        <div className="cy-legend__row">
                            <svg className="cy-map__swatch" width="18" height="18" viewBox="-9 -9 18 18" aria-hidden="true"><rect className="cy-map__region" x="-7" y="-6" width="14" height="12" /></svg>
                            <span className="cy-legend__name">District (Gazipur, Dhaka, Narayanganj)</span>
                        </div>
                    </div>
                </div>
            )}
        </div>
    );
}
