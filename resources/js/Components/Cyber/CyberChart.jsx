import React, { useEffect, useId, useMemo, useRef, useState } from 'react';
import { baseOptions, cyberTokens, mergeOptions } from './chartTheme.js';

/**
 * The ONE place Guardian renders a chart. ApexCharts (Cyber's chart library, vendored under
 * public/vendor/cyber/apexcharts, version pinned by the query string) is loaded lazily, the first time a
 * chart mounts, so pages without charts never download it.
 *
 *   <CyberChart label="Attendance, last 30 days" options={(t) => ({ chart: { type: 'area', height: 260 }, series: [...] })} />
 *
 * options  Apex options, or a function of the resolved token colours. Merged over Cyber's defaults (chartTheme.js).
 * label    accessible summary (role="img"). Always give the numbers' gist, e.g. "Present 112 of 130 today".
 * table    optional { columns: string[], rows: any[][] } for the "View data" table; derived from series and
 *          categories / labels when omitted; `false` removes the table and its toggle.
 * empty    when set, the chart is replaced by this honest empty state (never a fabricated series).
 *
 * Accessibility: the chart is an image with a text alternative, "View data" toggles a real table with the same
 * numbers, animation is off under prefers-reduced-motion, colours come from the tokens (AA in dark and light).
 */
const SRC = '/vendor/cyber/apexcharts/apexcharts.min.js?v=5.10.3';
let loading = null;

export function loadApexCharts() {
    if (typeof window === 'undefined') return Promise.reject(new Error('no window'));
    if (window.ApexCharts) return Promise.resolve(window.ApexCharts);
    loading ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SRC;
        script.async = true;
        script.onload = () => resolve(window.ApexCharts);
        script.onerror = () => { loading = null; reject(new Error('ApexCharts failed to load')); };
        document.head.appendChild(script);
    });
    return loading;
}

const prefersReducedMotion = () => typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;

/** Re-render when the design / theme switches (class or data attribute on <html>). */
function useThemeVersion() {
    const [version, setVersion] = useState(0);
    useEffect(() => {
        const observer = new MutationObserver(() => setVersion((v) => v + 1));
        observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class', 'data-design', 'data-accent', 'style'] });
        return () => observer.disconnect();
    }, []);
    return version;
}

export function deriveTable(opts) {
    const series = opts.series ?? [];
    if (Array.isArray(opts.labels) && series.length && typeof series[0] === 'number') {
        return { columns: ['Item', 'Value'], rows: opts.labels.map((l, i) => [l, series[i]]) };
    }
    const categories = opts.xaxis?.categories ?? [];
    const named = series.filter((s) => s && Array.isArray(s.data));
    if (!named.length) return null;
    return { columns: ['', ...named.map((s) => s.name ?? 'Value')], rows: named[0].data.map((_, i) => [categories[i] ?? i + 1, ...named.map((s) => s.data[i])]) };
}

export function hasData(opts) {
    const series = opts.series ?? [];
    if (!series.length) return false;
    if (typeof series[0] === 'number') return series.some((v) => v !== null && v !== undefined);
    return series.some((s) => Array.isArray(s?.data) && s.data.some((v) => v !== null && v !== undefined));
}

export default function CyberChart({ options, label, table, empty, height, className = '' }) {
    const host = useRef(null);
    const chart = useRef(null);
    const [failed, setFailed] = useState(false);
    const [showData, setShowData] = useState(false);
    const themeVersion = useThemeVersion();
    const tableId = useId();

    const resolved = useMemo(() => {
        const t = cyberTokens();
        const own = typeof options === 'function' ? options(t) : options;
        const merged = mergeOptions(baseOptions(t, { animate: !prefersReducedMotion() }), own);
        if (height) merged.chart = { ...merged.chart, height };
        return merged;
        // themeVersion re-resolves the token colours after a theme switch
    }, [options, height, themeVersion]); // eslint-disable-line react-hooks/exhaustive-deps

    const isEmpty = empty != null || !hasData(resolved);

    useEffect(() => {
        if (isEmpty || !host.current) return undefined;
        let cancelled = false;
        loadApexCharts().then((ApexCharts) => {
            if (cancelled || !host.current) return;
            chart.current?.destroy();
            chart.current = new ApexCharts(host.current, resolved);
            chart.current.render();
        }).catch(() => { if (!cancelled) setFailed(true); });

        return () => { cancelled = true; chart.current?.destroy(); chart.current = null; };
    }, [resolved, isEmpty]);

    if (isEmpty) {
        return <div className={`cy-chart cy-chart--empty ${className}`.trim()} role="status">{empty ?? 'No data for this period.'}</div>;
    }

    // table={false} switches the data table off (a sparkline sits inside a tile that already prints the figure).
    const data = table === false ? null : (table ?? deriveTable(resolved));
    return (
        <div className={`cy-chart ${className}`.trim()}>
            <div ref={host} role="img" aria-label={label} className="cy-chart__canvas" style={{ minHeight: resolved.chart?.height }} />
            {failed && <p className="cy-chart__error" role="alert">The chart could not be loaded. The data is available below.</p>}
            {data && (
                <>
                    <button type="button" className="cy-chart__toggle" aria-expanded={showData || failed} aria-controls={tableId} onClick={() => setShowData((s) => !s)}>
                        {showData || failed ? 'Hide data' : 'View data'}
                    </button>
                    {(showData || failed) && (
                        <div className="cy-chart__table" id={tableId}>
                            <table className="cy-table">
                                <caption className="visually-hidden">{label}</caption>
                                <thead><tr>{data.columns.map((c, i) => <th key={i} scope="col">{c}</th>)}</tr></thead>
                                <tbody>{data.rows.map((row, i) => <tr key={i}>{row.map((cell, j) => (j === 0 ? <th key={j} scope="row">{cell}</th> : <td key={j}>{cell ?? '—'}</td>))}</tr>)}</tbody>
                            </table>
                        </div>
                    )}
                </>
            )}
        </div>
    );
}
