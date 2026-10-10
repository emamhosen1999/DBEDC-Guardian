import React, { useEffect, useState } from 'react';
import KpiTile from './KpiTile.jsx';

/* The strip is a 420-column grid (divisible by 1 to 7), so a row of any 1-7 tiles splits into exactly equal parts. */
const COLUMNS = 420;

/** Tiles per row at this width: all of them on desktop, then 3 and 2, so the strip never spills sideways. */
export const tilesPerRow = (width, count) => (width >= 1024 ? Math.min(count, 7) : width >= 576 ? Math.min(count, 3) : Math.min(count, 2));

/** n tiles over rows of at most `perRow`, balanced (5 over 3 gives 3 + 2); each tile's column span fills its row edge to edge. */
export function spansFor(n, perRow) {
    const rows = Math.ceil(n / perRow);
    const base = Math.floor(n / rows);
    const extra = n % rows;
    const spans = [];
    for (let r = 0; r < rows; r += 1) {
        const m = base + (r < extra ? 1 : 0);
        for (let i = 0; i < m; i += 1) spans.push(COLUMNS / m);
    }
    return spans;
}

function useViewportWidth() {
    const [width, setWidth] = useState(typeof window === 'undefined' ? 1440 : window.innerWidth);
    useEffect(() => {
        const onResize = () => setWidth(window.innerWidth);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);
    return width;
}

/**
 * Cyber stat row (index.html / widgets.html): equal tiles edge to edge, caption above, figure below. One section of a
 * flush Card body. items = [{ key, label, value, tone, hint }]; a value of undefined shows a dash (still loading).
 */
export default function StatStrip({ items, label = 'Key figures', busy = false }) {
    const width = useViewportWidth();
    if (!items?.length) return null;
    const spans = spansFor(items.length, tilesPerRow(width, items.length));
    return (
        <div className="cy-kpi-strip cy-kpi-strip--compact" role="list" aria-label={label} aria-busy={busy || undefined}>
            {items.map((item, i) => (
                <div key={item.key} role="listitem" className="cy-kpi-strip__cell" style={{ gridColumn: `span ${spans[i]}` }}>
                    <KpiTile label={item.label} value={item.value} tone={item.tone} hint={item.hint} />
                </div>
            ))}
        </div>
    );
}
