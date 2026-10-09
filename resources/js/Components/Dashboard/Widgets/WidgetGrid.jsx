import React, { useCallback, useEffect, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
import axios from 'axios';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { Button, Card, Icon, KpiTile, StatTile } from '@/Components/Cyber';
import ChartPanel from './ChartPanel.jsx';

/**
 * Renders the permission-driven widget registry (App\Services\Dashboard\WidgetRegistry).
 * One payload contract serves both dashboards and the mobile app:
 *   { dashboard, generated_at, refresh_seconds, sections: [{key,label}], widgets: [{key,title,section,type,href,data,error}] }
 * Inertia delivers it as the deferred prop `widgets`; this hook then keeps it fresh by
 * polling GET /dashboard/widgets?section=… at the server's refresh_seconds hint.
 */
export function useWidgetPayload(section) {
    const initial = usePage().props.widgets;
    const [payload, setPayload] = useState(initial ?? null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (initial) setPayload(initial);
    }, [initial]);

    const load = useCallback(async () => {
        try {
            const { data } = await axios.get(route('dashboard.widgets'), { params: { section } });
            setPayload(data);
            setFailed(false);
        } catch {
            setFailed(true);
        }
    }, [section]);

    const refreshMs = Math.max(60, payload?.refresh_seconds ?? 120) * 1000;
    useEffect(() => {
        if (!payload) return undefined;
        const tick = () => { if (document.visibilityState === 'visible') load(); };
        const timer = setInterval(tick, refreshMs);
        return () => clearInterval(timer);
    }, [payload === null, refreshMs, load]); // eslint-disable-line react-hooks/exhaustive-deps

    return { payload, failed, reload: load };
}

export const widgetByKey = (payload, key) => payload?.widgets?.find((w) => w.key === key && !w.error)?.data ?? null;
export const statValue = (payload, widgetKey, statKey) =>
    widgetByKey(payload, widgetKey)?.stats?.find((s) => s.key === statKey)?.value ?? null;

const TONE_CLASS = { good: 'cy-text-good', warn: 'cy-text-warn', crit: 'cy-text-crit', info: 'cy-text-info' };

function Stats({ data }) {
    const stats = data?.stats ?? [];
    if (!stats.length) return data?.charts?.length ? null : <Empty />;
    return <div className="dl-tiles">{stats.map((s) => <StatTile key={s.key} label={s.label} value={s.value} tone={s.tone} href={s.href} />)}</div>;
}

function RowLink({ href, children }) {
    return href ? <Link href={href} className="cy-stat">{children}</Link> : <>{children}</>;
}

/** One entitlement ring: the arc is the share USED, the centre the days left; pure SVG so a card can hold several. */
function Ring({ row }) {
    const radius = 15.9155; // circumference 100
    const used = row.total > 0 ? Math.max(0, Math.min(100, (row.used / row.total) * 100)) : 0;
    const tone = row.remaining <= 0 && row.total > 0 ? 'crit' : used >= 80 ? 'warn' : 'theme';
    const body = (
        <>
            <svg className="cy-ring" viewBox="0 0 36 36" role="img" aria-label={`${row.label}: ${row.used} of ${row.total} ${row.unit} used, ${row.remaining} left`}>
                <circle className="cy-ring__track" cx="18" cy="18" r={radius} />
                <circle className="cy-ring__arc" data-tone={tone} cx="18" cy="18" r={radius} strokeDasharray={`${used} ${100 - used}`} transform="rotate(-90 18 18)" />
                <text x="18" y="20.5" textAnchor="middle" className="cy-ring__value">{row.remaining}</text>
            </svg>
            <span className="cy-ring__label" title={row.label}>{row.label}</span>
            <span className="cy-ring__meta">{row.used} / {row.total} {row.unit} used</span>
        </>
    );
    return <li className="cy-ring-cell">{row.href ? <Link href={row.href} className="cy-stat cy-ring-link">{body}</Link> : <span className="cy-ring-link">{body}</span>}</li>;
}

function Rows({ data }) {
    const rows = data?.rows ?? [];
    if (!rows.length) return <Empty />;
    return <ul className="cy-rings" aria-label="Entitlements">{rows.map((r) => <Ring key={r.key} row={r} />)}</ul>;
}

function Items({ data }) {
    const items = data?.items ?? [];
    if (!items.length) return <Empty />;
    return (
        <ul className="dl-list">
            {items.map((it) => (
                <li key={it.key} className="dl-list__row">
                    <RowLink href={it.href}>
                        <div className="cy-row">
                            <div style={{ minWidth: 0 }}>
                                <span className={`cy-row__title ${TONE_CLASS[it.tone] ?? ''}`}>{it.title}</span>
                                {it.subtitle && <span className="cy-row__sub">{it.subtitle}</span>}
                            </div>
                            {it.meta && <span className="cy-row__meta">{it.meta}</span>}
                        </div>
                    </RowLink>
                </li>
            ))}
        </ul>
    );
}

function Empty() {
    return <div className="dl-empty"><span className="cy-row__sub">Nothing to show.</span></div>;
}

function Headline({ data }) {
    if (!data?.headline) return null;
    return (
        <div className="dl-list__row cy-headline">
            <span className={`cy-row__title ${TONE_CLASS[data.tone] ?? ''}`}>{data.headline}</span>
        </div>
    );
}

const clock = (iso) => {
    const d = iso ? new Date(iso) : null;
    return d && !Number.isNaN(d.getTime()) ? d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' }) : null;
};

/** Freshness line every card carries: when its numbers were computed (and, for registers, when they last changed). */
function Freshness({ widget }) {
    const when = clock(widget.as_of);
    const extra = widget.data?.freshness;
    if (!when && !extra) return null;
    return <span className="cy-fresh">{extra ? `${extra} · ` : ''}{when ? `updated ${when}` : ''}</span>;
}

/** One registry widget as a Cyber card: figures, then its charts, then a freshness footer. */
export function WidgetCard({ widget, onRetry }) {
    const { data, error, type } = widget;
    const charts = data?.charts ?? [];
    return (
        <ErrorBoundary>
            <Card
                id={`widget:${widget.key}`}
                title={widget.title}
                sub={data?.scope?.label}
                flush
                footer={<Freshness widget={widget} />}
                actions={widget.href ? <Button as={Link} href={widget.href} variant="outline" size="sm">Open <Icon name="arrow-up-right" /></Button> : null}
            >
                {error ? (
                    <div className="dl-empty">
                        <p className="cy-row__sub">{error}</p>
                        {onRetry && <Button variant="outline" size="sm" onClick={onRetry}><Icon name="arrow-clockwise" /> Retry</Button>}
                    </div>
                ) : (
                    <>
                        <Headline data={data} />
                        {type === 'progress' ? <Rows data={data} /> : type === 'list' ? <Items data={data} /> : <Stats data={data} />}
                        {type !== 'list' && type !== 'progress' && data?.items?.length ? <Items data={data} /> : null}
                        {charts.length > 0 && <div className="cy-chartgrid">{charts.map((spec) => <ChartPanel key={spec.key} spec={spec} />)}</div>}
                    </>
                )}
            </Card>
        </ErrorBoundary>
    );
}

/** Tiles per row at the current width: all of them at 1440+, then 4, 3 or 2 (the strip never spills sideways). */
function tilesPerRow(width, count) {
    if (width >= 1440) return Math.min(count, 7);
    if (width >= 1024) return Math.min(count, 4);
    if (width >= 576) return Math.min(count, 4);
    return Math.min(count, 2);
}

/** The strip is a 420-column grid (420 is divisible by 1 to 7), so a row of any 1-7 tiles splits into exactly equal parts. */
export const STRIP_COLUMNS = 420;

/**
 * Split n tiles over rows of at most `perRow`, balanced (7 over 4 gives 4 + 3), and give each tile a column span so every
 * row is filled edge to edge with equal tiles.
 */
export function kpiRows(n, perRow) {
    const rows = Math.ceil(n / perRow);
    const base = Math.floor(n / rows);
    const extra = n % rows;
    const spans = [];
    for (let r = 0; r < rows; r += 1) {
        const m = base + (r < extra ? 1 : 0);
        for (let i = 0; i < m; i += 1) spans.push(STRIP_COLUMNS / m);
    }
    return spans;
}

function useViewportWidth() {
    const [width, setWidth] = React.useState(typeof window === 'undefined' ? 1440 : window.innerWidth);
    useEffect(() => {
        const onResize = () => setWidth(window.innerWidth);
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);
    return width;
}

/** The KPI tiles widgets contribute to the top strip (kpis with strip !== false), in widget priority order. */
export function KpiStrip({ payload }) {
    const width = useViewportWidth();
    const kpis = (payload?.widgets ?? []).filter((w) => !w.error && w.type !== 'command').flatMap((w) => (Array.isArray(w.data?.kpis) ? w.data.kpis : []).filter((k) => k.strip !== false));
    if (!kpis.length) return null;
    const spans = kpiRows(kpis.length, tilesPerRow(width, kpis.length));
    return (
        <div className="cy-kpi-strip" role="list" aria-label="Key figures">
            {kpis.map((k, i) => (
                <div key={k.key} role="listitem" className="cy-kpi-strip__cell" style={{ gridColumn: `span ${spans[i]}` }}>
                    <KpiTile label={k.label} value={k.value} tone={k.tone} delta={k.delta} spark={k.spark} sparkLabel={k.spark_label} hint={k.hint} href={k.href} />
                </div>
            ))}
        </div>
    );
}

/**
 * Pack widgets (already in priority order) into rows of exactly twelve columns: a widget keeps its preferred span when
 * it fits the row, otherwise the row is closed and the remaining columns go to the widgets already in it, so no row is
 * ever left short and no cell is empty.
 *
 * @param {Array<{span: number}>} widgets
 * @returns {Array<Array<{widget: object, span: number}>>}
 */
export function packRows(widgets) {
    const rows = [];
    let row = [];
    let used = 0;
    const close = () => {
        if (!row.length) return;
        const spare = 12 - used;
        if (spare > 0) {
            // Hand the spare columns out one 2-column step at a time, widest cell last so wide charts grow first.
            let i = row.length - 1;
            for (let left = spare; left > 0; left -= Math.min(left, 2)) {
                row[i].span += Math.min(left, 2);
                i = i === 0 ? row.length - 1 : i - 1;
            }
        }
        rows.push(row);
        row = [];
        used = 0;
    };
    widgets.forEach((widget) => {
        const span = [4, 6, 8, 12].includes(widget.span) ? widget.span : 4;
        if (used + span > 12) close();
        row.push({ widget, span });
        used += span;
        if (used === 12) close();
    });
    close();
    return rows;
}

/** Registry widgets in priority order as gutter-less Cyber rows. `skip` omits keys rendered elsewhere. */
export function WidgetGrid({ payload, skip = [], onRetry }) {
    if (!payload) {
        return (
            <div className="dl-page" aria-busy="true" aria-live="polite">
                <div className="dl-empty"><span className="cy-row__sub">Loading widgets…</span></div>
            </div>
        );
    }
    const widgets = payload.widgets.filter((w) => !skip.includes(w.key) && w.type !== 'command');
    const rows = packRows(widgets);
    return (
        <div className="dl-page">
            {rows.map((row) => (
                <div key={row.map((c) => c.widget.key).join('+')} className="dl-row">
                    {row.map(({ widget, span }) => (
                        <div key={widget.key} className={`dl-col dl-col--${span}`}>
                            <WidgetCard widget={widget} onRetry={onRetry} />
                        </div>
                    ))}
                </div>
            ))}
        </div>
    );
}
