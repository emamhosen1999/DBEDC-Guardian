import React, { useMemo } from 'react';
import { Link } from '@inertiajs/react';
import CyberChart from './CyberChart.jsx';
import { sparklineOptions, toneColor } from './chartTheme.js';
import Icon from './Icon.jsx';

/**
 * Cyber stat tile: caption, value, change versus the previous period and a sparkline (analytics.html / index.html).
 *
 * value     the figure (string or number)
 * delta     { text: '+3 vs yesterday', direction: 'up'|'down'|'flat', good: 'up'|'down' } - the sign says which way,
 *           `good` says whether that way is an improvement (colours it). Omit when there is no previous period.
 * spark     array of numbers (a real series) or null/[] to omit the sparkline
 * sparkLabel accessible description of the series, e.g. "Present, last 14 days"
 */
export default function KpiTile({ label, value, tone = 'neutral', delta, spark, sparkLabel, href, hint }) {
    const hasSpark = Array.isArray(spark) && spark.length > 1;
    const options = useMemo(() => (t) => sparklineOptions(t, spark ?? [], { color: toneColor(t, tone === 'neutral' ? 'theme' : tone), name: label }), [spark, tone, label]);
    const dir = delta?.direction;
    const arrow = dir === 'up' ? 'arrow-up-right' : dir === 'down' ? 'arrow-down-right' : null;
    const verdict = !delta || dir === 'flat' ? 'flat' : delta.good === dir ? 'good' : 'bad';

    const body = (
        <>
            <span className="cy-stat__label">{label}</span>
            <span className="cy-stat__value" data-tone={tone}>{value ?? '—'}</span>
            {delta && (
                <span className="cy-kpi__delta" data-verdict={verdict}>
                    {arrow && <Icon name={arrow} />} {delta.text}
                </span>
            )}
            {hint && <span className="cy-kpi__hint">{hint}</span>}
            {hasSpark && <div className="cy-kpi__spark"><CyberChart options={options} label={sparkLabel ?? `${label} trend`} height={20} table={null} /></div>}
        </>
    );
    return <div className="dl-tile cy-kpi">{href ? <Link href={href} className="cy-stat">{body}</Link> : <span className="cy-stat">{body}</span>}</div>;
}
