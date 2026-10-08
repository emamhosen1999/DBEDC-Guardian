import React, { useMemo } from 'react';
import { CyberChart, toneColor } from '@/Components/Cyber';

/**
 * Renders one declarative chart spec from the widget registry (see DashboardWidget::chart()) with ApexCharts options
 * copied from Cyber's dashboard demo (chartTheme.js). Kinds: area | line | bar | donut | radial | heatstrip.
 * Colours come from tones, so a tone reads the same in every chart, in dark and light.
 */
const HEIGHT = { bar: 200, area: 200, line: 190, donut: 200, radial: 170 };

const fmt = (unit) => (value) => (value === null || value === undefined ? '—' : `${typeof value === 'number' ? Number(value.toLocaleString('en-US', { maximumFractionDigits: 1 })) : value}${unit && unit !== '%' ? ` ${unit}` : unit === '%' ? '%' : ''}`);

/* Category axes (horizontal bars) pass strings through; numeric axes show integers or one decimal. */
const axisNumber = (v) => (typeof v === 'number' ? (Number.isInteger(v) ? v : v.toFixed(1)) : v);

function optionsFor(spec, t) {
    const colors = (spec.series ?? []).map((s) => toneColor(t, s.tone ?? 'theme'));
    const unit = fmt(spec.unit);
    const stacked = Boolean(spec.stacked);
    const categories = spec.categories ?? [];
    const common = { chart: { height: HEIGHT[spec.kind] ?? 220 } };

    switch (spec.kind) {
        case 'bar':
            return {
                ...common,
                chart: { ...common.chart, type: 'bar', stacked },
                colors,
                series: spec.series,
                plotOptions: { bar: { horizontal: Boolean(spec.horizontal), columnWidth: stacked ? '70%' : '60%', barHeight: '60%' } },
                fill: { type: 'solid', opacity: 0.85 },
                stroke: { width: 0 },
                dataLabels: { enabled: false },
                xaxis: { categories, tickAmount: categories.length > 12 ? 8 : undefined, labels: { rotate: 0, hideOverlappingLabels: true } },
                yaxis: { labels: { formatter: axisNumber }, min: 0 },
                tooltip: { shared: !spec.horizontal, intersect: false, y: { formatter: (v, o) => (spec.detail && o ? spec.detail[o.dataPointIndex] ?? unit(v) : unit(v)) } },
                legend: { show: (spec.series?.length ?? 0) > 1 },
            };
        case 'area':
        case 'line':
            return {
                ...common,
                chart: { ...common.chart, type: spec.kind },
                colors,
                series: spec.series,
                stroke: { width: spec.kind === 'area' ? 2 : 2, curve: 'smooth' },
                markers: { size: spec.kind === 'line' ? 3 : 0, strokeWidth: 0 },
                fill: spec.kind === 'area' ? undefined : { type: 'solid', opacity: 1 },
                dataLabels: { enabled: false },
                xaxis: { categories, tickAmount: categories.length > 12 ? 7 : undefined, labels: { rotate: 0, hideOverlappingLabels: true } },
                yaxis: { labels: { formatter: axisNumber } },
                annotations: spec.baseline !== undefined ? { yaxis: [{ y: spec.baseline, borderColor: t.muted, strokeDashArray: 4 }] } : undefined,
                tooltip: { shared: true, intersect: false, y: { formatter: unit } },
                legend: { show: (spec.series?.length ?? 0) > 1 },
            };
        case 'donut': {
            const palette = [t.theme, t.success, t.warning, t.danger, t.info, t.purple, t.muted];
            return {
                ...common,
                chart: { ...common.chart, type: 'donut' },
                labels: spec.labels,
                series: spec.series,
                colors: palette.slice(0, spec.series.length),
                stroke: { width: 1, colors: [t.border] },
                fill: { type: 'solid', opacity: 0.9 },
                dataLabels: { enabled: false },
                plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: 'Total', color: t.fg, fontSize: '10px', formatter: (w) => w.globals.seriesTotals.reduce((a, b) => a + b, 0).toLocaleString('en-US', { maximumFractionDigits: 1 }) }, value: { color: t.fg, fontSize: '17px' } } } } },
                tooltip: { y: { formatter: unit } },
                legend: { position: 'bottom' },
            };
        }
        case 'radial': {
            const value = Math.max(0, Math.min(100, Number(spec.value) || 0));
            const tone = value >= 90 ? 'good' : value >= 70 ? 'warn' : 'crit';
            return {
                ...common,
                chart: { ...common.chart, type: 'radialBar' },
                series: [value],
                labels: [spec.title],
                colors: [toneColor(t, tone)],
                fill: { type: 'solid', opacity: 1 },
                stroke: { lineCap: 'butt' },
                plotOptions: { radialBar: { hollow: { size: '62%' }, track: { background: t.borderSoft, strokeWidth: '100%' }, dataLabels: { name: { show: Boolean(spec.detail), offsetY: 18, color: t.muted, fontSize: '10px', formatter: () => spec.detail ?? '' }, value: { offsetY: spec.detail ? -12 : 4, color: t.fg, fontSize: '25px', formatter: (v) => `${Math.round(v)}%` } } } },
                legend: { show: false },
            };
        }
        default:
            return null;
    }
}

const STATE_LABEL = { present: 'Present', late: 'Late', leave: 'On leave', absent: 'Absent', pending: 'Not yet punched', off: 'Day off', future: 'Upcoming' };

/** Calendar heat strip: one cell per day of the month, coloured by state, each cell labelled for assistive tech. */
function HeatStrip({ spec }) {
    const days = spec.days ?? [];
    return (
        <div className="cy-heat">
            <ul className="cy-heat__grid" role="list" aria-label={spec.summary}>
                {days.map((d) => (
                    <li key={d.date} className="cy-heat__cell" data-state={d.state} title={`${d.date}: ${STATE_LABEL[d.state] ?? d.state}`} aria-label={`${d.date}: ${STATE_LABEL[d.state] ?? d.state}`}>
                        <span aria-hidden="true">{d.day}</span>
                    </li>
                ))}
            </ul>
            <ul className="cy-heat__legend" aria-hidden="true">
                {['present', 'late', 'leave', 'absent', 'off'].map((s) => <li key={s} data-state={s}><i /> {STATE_LABEL[s]}</li>)}
            </ul>
        </div>
    );
}

function Timeline({ spec }) {
    const items = spec.items ?? [];
    if (!items.length) return null;
    return (
        <ul className="dl-list">
            {items.map((it) => (
                <li key={it.key} className="dl-list__row">
                    <div className="cy-row">
                        <span className={`cy-row__title ${it.tone === 'crit' ? 'cy-text-crit' : it.tone === 'warn' ? 'cy-text-warn' : ''}`}>{it.title}</span>
                        {it.meta && <span className="cy-row__meta">{it.meta}</span>}
                    </div>
                </li>
            ))}
        </ul>
    );
}

export default function ChartPanel({ spec }) {
    const options = useMemo(() => (t) => optionsFor(spec, t), [spec]);
    const caption = spec.period?.from && spec.period?.to && spec.period.from !== spec.period.to ? `${spec.period.from} to ${spec.period.to}` : null;

    return (
        <section className="cy-chartpanel" aria-label={spec.title}>
            <h4 className="cy-chartpanel__title">{spec.title}{caption && <small>{caption}</small>}</h4>
            {spec.kind === 'heatstrip' ? (
                spec.empty ? <div className="cy-chart cy-chart--empty" role="status">{spec.empty_message ?? 'No data for this period.'}</div> : <HeatStrip spec={spec} />
            ) : (
                <CyberChart options={options} label={spec.summary} empty={spec.empty ? (spec.empty_message ?? 'No data for this period.') : undefined} />
            )}
            {spec.kind === 'bar' && !spec.empty && <Timeline spec={spec} />}
        </section>
    );
}
