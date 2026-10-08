import React from 'react';
import { Link } from '@inertiajs/react';

/** One figure inside a flush Card's .dl-tiles grid: caption above, value below, optional drill-down link. */
export default function StatTile({ label, value, tone = 'neutral', href }) {
    const body = (
        <>
            <span className="cy-stat__label">{label}</span>
            <span className="cy-stat__value" data-tone={tone}>{value ?? '—'}</span>
        </>
    );
    return <div className="dl-tile">{href ? <Link href={href} className="cy-stat">{body}</Link> : <span className="cy-stat">{body}</span>}</div>;
}
