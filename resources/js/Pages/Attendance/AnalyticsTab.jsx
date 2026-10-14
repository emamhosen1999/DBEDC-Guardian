/**
 * Attendance analytics for one month.
 *
 * Team view (attendance.view) covers everyone in scope and adds department
 * comparison and a watch list; everyone else sees their own month. All figures
 * come from the same engine pass as the monthly stats, so they reconcile with
 * the timesheet and calendar.
 *
 * The month is the Attendance page's own month; scope and department live in
 * the URL under an `aa_` prefix (every Attendance tab stays mounted).
 *
 * Built from Cyber's analytics.html / chart_apex.html: a stat strip, then ApexCharts panels in flush cards (CyberChart).
 * Every CyberChart receives memoised options: it re-draws whenever the options identity changes.
 */
import React, { useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import axios from 'axios';
import dayjs from 'dayjs';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Alert, Card, CyberChart, Field, IconButton, Segmented, StatStrip, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import DepartmentSelect from './Components/DepartmentSelect';

/* ── formatting ─────────────────────────────────────────────────────────── */
const fmt = {
    pct: (v, digits = 1) => (v == null ? '—' : `${Number(v).toFixed(digits)}%`),
    num: (v, digits = 0) => (v == null ? '—' : Number(v).toLocaleString('en-IN', { maximumFractionDigits: digits })),
    date: (iso) => new Date(`${iso}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }),
};
const dayCount = (v) => fmt.num(v, 1);

/* Change versus the previous period: the sign says which way, `good` says which way is an improvement. */
function deltaOf(change, { unit = '', goodWhen = 'up', suffix = '', digits } = {}) {
    if (change == null || Number.isNaN(Number(change))) return null;
    const n = Number(change);
    digits ??= Number.isInteger(n) ? 0 : 1;
    const direction = Math.abs(n) < 0.05 ? 'flat' : n > 0 ? 'up' : 'down';
    const sign = n > 0 ? '+' : n < 0 ? '−' : '';
    return { direction, good: goodWhen, text: `${sign}${Math.abs(n).toFixed(digits)}${unit}${suffix}` };
}

/* Stack order and tones of the daily composition. */
const COMPOSITION = [
    { key: 'on_time', label: 'On time', tone: 'success' },
    { key: 'late', label: 'Late', tone: 'warning' },
    { key: 'leave', label: 'On leave', tone: 'info' },
    { key: 'absent', label: 'Absent', tone: 'danger' },
];

const intTicks = { labels: { formatter: (v) => Math.round(v) } };

export default function AnalyticsTab({ month, onMonthChange, departments = [], canViewTeam = false, isActive = true, isMobile = false }) {
    const f = useQueryFilters({
        mode: 'client',
        debounceKeys: [],
        defaults: { aa_scope: canViewTeam ? 'team' : 'self', aa_dept: '' },
    });
    const scope = canViewTeam && f.values.aa_scope === 'team' ? 'team' : 'self';
    const departmentId = scope === 'team' ? f.values.aa_dept : '';

    const { data, isLoading, isFetching, isError, refetch } = useQuery({
        queryKey: ['attendance-analytics', month, scope, departmentId],
        queryFn: async () => (await axios.get(route('attendance.analytics'), {
            params: { month, scope, department_id: departmentId || undefined },
        })).data.data,
        enabled: isActive && Boolean(month),
        placeholderData: keepPreviousData,
        staleTime: 60_000,
    });

    const toolbar = (
        <Toolbar label="Analytics filters">
            <ToolbarGroup>
                <Field icon="calendar3" type="month" label="Month" value={month} max={dayjs().format('YYYY-MM')} onChange={(e) => e.target.value && onMonthChange?.(e.target.value)} />
                {canViewTeam && (
                    <Segmented
                        label="Whose attendance"
                        value={scope}
                        onChange={(v) => f.set('aa_scope', v)}
                        options={[{ value: 'team', label: 'Team', icon: 'people' }, { value: 'self', label: 'Me', icon: 'person-badge' }]}
                    />
                )}
                {scope === 'team' && (
                    <DepartmentSelect value={departmentId || 'all'} onChange={(v) => f.set('aa_dept', v === 'all' ? '' : v)} departments={departments} allLabel="All departments" />
                )}
            </ToolbarGroup>
            <ToolbarGroup end>
                <IconButton icon="arrow-clockwise" label="Refresh" onClick={() => refetch()} disabled={isFetching} spin={isFetching} />
            </ToolbarGroup>
        </Toolbar>
    );

    return (
        <div className="dl-page" aria-busy={isFetching || undefined}>
            {isLoading ? (
                <div className="dl-row"><div className="dl-col dl-col--12">
                    <Card id="attendance-analytics" title="Analytics" flush>
                        {toolbar}
                        <div className="cy-empty cy-empty--flat" role="status"><p className="cy-empty__text">Loading analytics…</p></div>
                    </Card>
                </div></div>
            ) : isError || !data ? (
                <div className="dl-row"><div className="dl-col dl-col--12">
                    <Card id="attendance-analytics" title="Analytics" flush>
                        {toolbar}
                        <div className="cy-card-alert">
                            <Alert tone="danger" role="alert">
                                Analytics could not be loaded.{' '}
                                <button type="button" className="cy-linkbtn" onClick={() => refetch()}>Try again</button>
                            </Alert>
                        </div>
                    </Card>
                </div></div>
            ) : (
                <Content data={data} isMobile={isMobile} toolbar={toolbar} />
            )}
        </div>
    );
}

function Content({ data, isMobile, toolbar }) {
    const b = data.breakdown;
    const isTeam = data.meta.scope === 'team';
    const working = useMemo(() => b.daily.filter((d) => d.expected > 0 || d.leave > 0), [b.daily]);
    const nothingYet = working.length === 0;

    return (
        <>
            <div className="dl-row">
                <div className="dl-col dl-col--12">
                    <Card id="attendance-analytics" title={`Attendance analytics: ${data.meta.month}`} flush>
                        {toolbar}
                        <Headline data={data} isTeam={isTeam} />
                    </Card>
                </div>
            </div>

            <div className="dl-row">
                <div className="dl-col dl-col--12"><DailyComposition rows={working} isMobile={isMobile} empty={nothingYet} /></div>
            </div>

            <div className="dl-row">
                <div className="dl-col dl-col--6"><DailyRate rows={working} target={b.rate.scheduled} empty={nothingYet} /></div>
                <div className="dl-col dl-col--6"><Punctuality punctuality={b.punctuality} late={data.attendance.lateArrivals} /></div>
            </div>

            <div className="dl-row">
                <div className="dl-col dl-col--6">
                    <WeekdayBars
                        id="attendance-analytics-late-weekday"
                        title="Late arrivals by weekday"
                        rows={b.weekday}
                        dataKey="late"
                        tone="warning"
                        name="Late arrivals"
                        empty={!data.attendance.lateArrivals}
                        emptyText="No late arrivals this month."
                    />
                </div>
                <div className="dl-col dl-col--6">
                    <WeekdayBars
                        id="attendance-analytics-absent-weekday"
                        title="Absences by weekday"
                        rows={b.weekday}
                        dataKey="absent"
                        tone="danger"
                        name="Absent days"
                        empty={!data.attendance.absent}
                        emptyText="No absences this month."
                    />
                </div>
            </div>

            {isTeam && b.departments.length > 1 && (
                <div className="dl-row"><div className="dl-col dl-col--12"><Departments rows={b.departments} /></div></div>
            )}
            {isTeam && b.watchlist && <Watchlist watchlist={b.watchlist} />}
        </>
    );
}

/* ── headline tiles ─────────────────────────────────────────────────────── */

function Headline({ data, isTeam }) {
    const a = data.attendance;
    const h = data.hours;
    const cmp = data.comparison;
    const r = data.breakdown.rate;

    const items = [
        {
            key: 'rate',
            label: 'Attendance rate',
            value: fmt.pct(r.scheduled),
            delta: cmp?.rate != null && r.scheduled != null ? deltaOf(r.scheduled - cmp.rate, { unit: ' pts', goodWhen: 'up', digits: 1, suffix: ` vs ${cmp.month}` }) : null,
            hint: isTeam ? `${data.meta.totalEmployees} employees · ${data.meta.month}` : data.meta.month,
        },
        {
            key: 'present',
            label: 'Present',
            value: `${dayCount(r.present_on_schedule)} days`,
            hint: `of ${dayCount(r.expected_days)} scheduled working days${r.off_schedule_days > 0 ? ` · +${dayCount(r.off_schedule_days)} worked on days off` : ''}`,
        },
        {
            key: 'absent',
            label: 'Absent',
            value: `${dayCount(a.absent)} days`,
            delta: cmp ? deltaOf(a.absent - cmp.absent, { goodWhen: 'down', suffix: ` vs ${cmp.month}` }) : null,
        },
        {
            key: 'late',
            label: 'Late arrivals',
            value: fmt.num(a.lateArrivals),
            delta: cmp ? deltaOf(a.lateArrivals - cmp.lateArrivals, { goodWhen: 'down', digits: 0, suffix: ` vs ${cmp.month}` }) : null,
            hint: a.lateArrivals ? `avg ${fmt.num(data.breakdown.punctuality.average_late_minutes, 0)} min past grace` : 'Everyone on time',
        },
        { key: 'leave', label: 'On leave', value: `${dayCount(a.leaves)} days` },
        {
            key: 'perfect',
            label: 'Perfect attendance',
            value: isTeam ? fmt.num(a.perfectCount) : (a.perfectCount ? 'Yes' : 'Not yet'),
            hint: isTeam ? 'employees with no missed working day' : 'every working day attended',
        },
        { key: 'avg', label: 'Average hours', value: `${fmt.num(h.averageDaily, 1)} h`, hint: `per present day · ${fmt.num(h.totalWork, 0)} h total` },
        { key: 'ot', label: 'Overtime', value: `${fmt.num(h.overtime, 1)} h` },
    ];
    return <StatStrip items={items} label="Attendance figures for the month" />;
}

/* ── daily composition (stacked) ────────────────────────────────────────── */

function DailyComposition({ rows, isMobile, empty }) {
    const options = useMemo(() => (t) => ({
        chart: { type: 'bar', height: 300, stacked: true },
        series: COMPOSITION.map((c) => ({ name: c.label, data: rows.map((r) => r[c.key]) })),
        xaxis: { categories: rows.map((r) => r.label), tickAmount: isMobile ? 8 : undefined, labels: { rotate: 0, hideOverlappingLabels: true } },
        yaxis: { ...intTicks },
        colors: COMPOSITION.map((c) => t[c.tone]),
        fill: { type: 'solid', opacity: 1 },
        stroke: { width: 1, colors: [t.border] },
        dataLabels: { enabled: false },
        plotOptions: { bar: { columnWidth: isMobile ? '80%' : '55%' } },
        tooltip: { y: { formatter: dayCount } },
    }), [rows, isMobile]);
    const table = useMemo(() => ({
        columns: ['Date', ...COMPOSITION.map((c) => c.label), 'Rate'],
        rows: rows.map((r) => [`${fmt.date(r.date)} (${r.weekday})`, ...COMPOSITION.map((c) => dayCount(r[c.key])), fmt.pct(r.rate)]),
    }), [rows]);
    return (
        <Card id="attendance-analytics-daily" title="Daily attendance" flush footer={<span className="cy-fresh">Man-days per working day: on time, late, on leave and absent</span>}>
            <div className="cy-chartgrid"><div className="cy-chartpanel">
                <CyberChart options={options} table={table} label="Daily attendance, man-days per working day by outcome" empty={empty ? 'No working days recorded for this month yet.' : undefined} />
            </div></div>
        </Card>
    );
}

/* ── daily rate line ────────────────────────────────────────────────────── */

function DailyRate({ rows, target, empty }) {
    const points = useMemo(() => rows.filter((r) => r.rate != null), [rows]);
    const low = points.reduce((m, r) => (m == null || r.rate < m.rate ? r : m), null);
    const options = useMemo(() => (t) => ({
        chart: { type: 'area', height: 260 },
        series: [{ name: 'Attendance rate', data: points.map((r) => Number(r.rate)) }],
        xaxis: { categories: points.map((r) => r.label), tickAmount: 8, labels: { rotate: 0, hideOverlappingLabels: true } },
        yaxis: { min: 0, max: 100, tickAmount: 4, labels: { formatter: (v) => `${Math.round(v)}%` } },
        colors: [t.theme],
        legend: { show: false },
        dataLabels: { enabled: false },
        markers: { size: points.length <= 12 ? 3 : 0 },
        annotations: target != null ? { yaxis: [{ y: target, borderColor: t.muted, strokeDashArray: 4, label: { text: `month ${fmt.pct(target, 0)}`, position: 'right', textAnchor: 'end', style: { color: '#000', background: t.muted, fontSize: '10px' } } }] } : {},
        tooltip: { y: { formatter: (v) => fmt.pct(v) } },
    }), [points, target]);
    const table = useMemo(() => ({
        columns: ['Date', 'Rate'],
        rows: points.map((r) => [`${fmt.date(r.date)} (${r.weekday})`, fmt.pct(r.rate)]),
    }), [points]);
    const isEmpty = empty || points.length === 0;
    return (
        <Card id="attendance-analytics-rate" title="Attendance rate by day" flush footer={low ? <span className="cy-fresh">{`Lowest: ${fmt.pct(low.rate)} on ${low.label} (${low.weekday})`}</span> : null}>
            <div className="cy-chartgrid"><div className="cy-chartpanel">
                <CyberChart options={options} table={table} label="Attendance rate for each working day of the month" empty={isEmpty ? 'No data for this period.' : undefined} />
            </div></div>
        </Card>
    );
}

/* ── punctuality distribution ───────────────────────────────────────────── */

function Punctuality({ punctuality, late }) {
    const rows = punctuality.buckets;
    const options = useMemo(() => (t) => ({
        chart: { type: 'bar', height: Math.max(180, rows.length * 38 + 50) },
        series: [{ name: 'Arrivals', data: rows.map((r) => r.count) }],
        xaxis: { categories: rows.map((r) => r.bucket) },
        yaxis: { labels: { style: { colors: t.fg } } },
        colors: [t.warning],
        fill: { type: 'solid', opacity: 0.9 },
        legend: { show: false },
        plotOptions: { bar: { horizontal: true, barHeight: '55%' } },
        dataLabels: { enabled: true, formatter: (v) => `${fmt.num(v)} · ${fmt.pct(late ? (v / late) * 100 : 0, 0)}`, style: { colors: ['#000'] } },
        tooltip: { y: { formatter: (v) => fmt.num(v) } },
    }), [rows, late]);
    const table = useMemo(() => ({ columns: ['Minutes late', 'Arrivals'], rows: rows.map((r) => [r.bucket, r.count]) }), [rows]);
    return (
        <Card id="attendance-analytics-howlate" title="How late" flush
            footer={late ? <span className="cy-fresh">{`${fmt.num(late)} late arrivals · ${fmt.num(punctuality.total_late_minutes)} minutes past grace in total`}</span> : null}>
            <div className="cy-chartgrid"><div className="cy-chartpanel">
                <CyberChart options={options} table={table} label="Late arrivals grouped by minutes late" empty={!late ? 'No late arrivals this month.' : undefined} />
            </div></div>
        </Card>
    );
}

/* ── weekday small multiples ────────────────────────────────────────────── */

// Late and absent are shown as two single-series charts rather than one grouped chart.
function WeekdayBars({ id, title, rows, dataKey, tone, name, empty, emptyText }) {
    const shown = useMemo(() => rows.filter((r) => r.expected > 0), [rows]);
    const options = useMemo(() => (t) => ({
        chart: { type: 'bar', height: 220 },
        series: [{ name, data: shown.map((r) => r[dataKey]) }],
        xaxis: { categories: shown.map((r) => r.day) },
        yaxis: { ...intTicks },
        colors: [t[tone]],
        fill: { type: 'solid', opacity: 0.9 },
        legend: { show: false },
        dataLabels: { enabled: false },
        plotOptions: { bar: { columnWidth: '45%' } },
        tooltip: { y: { formatter: dayCount } },
    }), [shown, dataKey, tone, name]);
    const table = useMemo(() => ({
        columns: ['Day', name, 'Attendance rate'],
        rows: shown.map((r) => [r.day, dayCount(r[dataKey]), fmt.pct(r.rate)]),
    }), [shown, dataKey, name]);
    const isEmpty = empty || shown.length === 0;
    return (
        <Card id={id} title={title} flush>
            <div className="cy-chartgrid"><div className="cy-chartpanel">
                <CyberChart options={options} table={table} label={`${title}, by weekday`} empty={isEmpty ? emptyText ?? 'No data for this period.' : undefined} />
            </div></div>
        </Card>
    );
}

/* ── departments ────────────────────────────────────────────────────────── */

function Departments({ rows }) {
    const options = useMemo(() => (t) => ({
        chart: { type: 'bar', height: Math.max(180, rows.length * 38 + 50) },
        series: [{ name: 'Attendance rate', data: rows.map((d) => Number(d.rate ?? 0)) }],
        xaxis: { categories: rows.map((d) => d.department), max: 100, labels: { formatter: (v) => `${Math.round(v)}%` } },
        yaxis: { labels: { style: { colors: t.fg } } },
        colors: [t.theme],
        fill: { type: 'solid', opacity: 0.9 },
        legend: { show: false },
        plotOptions: { bar: { horizontal: true, barHeight: '55%' } },
        dataLabels: { enabled: true, formatter: (v) => fmt.pct(v), style: { colors: ['#000'] } },
        tooltip: { y: { formatter: (v) => fmt.pct(v) } },
    }), [rows]);
    const table = useMemo(() => ({
        columns: ['Department', 'Staff', 'Rate', 'Absent days', 'Late', 'Late share', 'Leave days'],
        rows: rows.map((d) => [d.department, d.employees, fmt.pct(d.rate), dayCount(d.absent), d.late, fmt.pct(d.late_rate), dayCount(d.leave)]),
    }), [rows]);
    return (
        <Card id="attendance-analytics-departments" title="Attendance by department" flush footer={<span className="cy-fresh">Lowest rate first</span>}>
            <div className="cy-chartgrid"><div className="cy-chartpanel">
                <CyberChart options={options} table={table} label="Attendance rate by department, lowest first" />
            </div></div>
        </Card>
    );
}

/* ── watch list ─────────────────────────────────────────────────────────── */

function Watchlist({ watchlist }) {
    const lists = [
        { key: 'most_late', title: 'Most late arrivals', head: 'Late · minutes', value: (p) => `${fmt.num(p.late)} · ${fmt.num(p.late_minutes)} min` },
        { key: 'most_absent', title: 'Most absent days', head: 'Absent days', value: (p) => dayCount(p.absent) },
        { key: 'lowest_rate', title: 'Lowest attendance rate', head: 'Rate', value: (p) => fmt.pct(p.rate) },
    ];

    return (
        <div className="dl-row">
            {lists.map((l) => {
                const rows = watchlist[l.key] ?? [];
                return (
                    <div className="dl-col dl-col--4" key={l.key}>
                        <Card id={`attendance-analytics-${l.key}`} title={l.title} flush>
                            {rows.length === 0 ? (
                                <div className="cy-empty cy-empty--flat"><p className="cy-empty__text">Nobody to flag this month.</p></div>
                            ) : (
                                <div className="cy-dt" tabIndex={0} role="region" aria-label={`${l.title}, scrollable`}>
                                    <table className="cy-table">
                                        <thead><tr><th scope="col">Employee</th><th scope="col" className="cy-num">{l.head}</th></tr></thead>
                                        <tbody>
                                            {rows.map((r, i) => (
                                                <tr key={`${r.name}-${i}`}>
                                                    <td><b>{r.name}</b><span className="cy-sub">{r.department}</span></td>
                                                    <td className="cy-num">{l.value(r)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </Card>
                    </div>
                );
            })}
        </div>
    );
}
