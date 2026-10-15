import React, { useMemo } from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PageHeader from '@/Components/PageHeader';
import { Alert, Badge, Button, Card, CyberChart, DateInput, Icon, Select, StatStrip, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import { useQueryFilters } from '@/Hooks/useQueryFilters';

import { formatDay, getJson, quietMessage, statusMeta } from './dwUtils.js';

const PERIOD_OPTIONS = [
    { value: '30d', label: 'Last 30 days' }, { value: '90d', label: 'Last 90 days' }, { value: '365d', label: 'Last 12 months' }, { value: 'all', label: 'All time' }, { value: 'custom', label: 'Custom range' },
];
const DEFAULTS = { sm_period: '365d', sm_from: '', sm_to: '', sm_jur: '', sm_type: '' };
const pct = (v) => (v == null ? undefined : `${v}%`);

function TrendChart({ trend }) {
    const options = useMemo(() => (t) => ({
        chart: { type: 'bar', height: 260, stacked: false },
        series: [{ name: 'Works', data: trend.points.map((p) => p.works) }, { name: 'Completed', data: trend.points.map((p) => p.completed) }],
        xaxis: { categories: trend.points.map((p) => p.date), tickAmount: 8, labels: { rotate: 0, hideOverlappingLabels: true } },
        colors: [t.theme, t.success],
        dataLabels: { enabled: false },
        legend: { show: true, position: 'top' },
    }), [trend]);
    const table = useMemo(() => ({ columns: [trend.grain === 'month' ? 'Month' : 'Date', 'Works', 'Completed'], rows: trend.points.map((p) => [p.date, p.works, p.completed]) }), [trend]);
    return <CyberChart options={options} table={table} label={`Works and completed works per ${trend.grain}, ${trend.points.length} points`} empty={trend.points.length ? undefined : 'No works in this period.'} />;
}

function BarList({ rows, label, keyName = 'key' }) {
    const max = Math.max(1, ...rows.map((r) => r.count ?? r.works));
    return (
        <ul className="dw-bars" aria-label={label}>
            {rows.map((r) => {
                const n = r.count ?? r.works;
                return <li key={r[keyName] ?? r.id ?? 'none'}><span className="dw-bars__label">{r.label ?? r.name ?? r.code ?? statusMeta(r.key).label}</span><span className="dw-bars__track" aria-hidden="true"><span style={{ width: `${(n / max) * 100}%` }} /></span><span className="cy-num">{n.toLocaleString('en-GB')}</span></li>;
            })}
        </ul>
    );
}

/** Summary tab: database aggregates over your scope. The default period is 12 months; a period with nothing says when the latest record is. */
export default function DailyWorksSummary({ jurisdictions = [] }) {
    const f = useQueryFilters({ mode: 'client', defaults: DEFAULTS });
    const v = f.values;
    const params = useMemo(() => {
        const p = { period: v.sm_period === 'custom' ? 'all' : v.sm_period };
        if (v.sm_period === 'custom') { if (v.sm_from) p.date_from = v.sm_from; if (v.sm_to) p.date_to = v.sm_to; }
        if (v.sm_jur) p.jurisdiction = v.sm_jur;
        if (v.sm_type) p.type = v.sm_type;
        return p;
    }, [v]);
    const q = useQuery({ queryKey: ['daily-works', 'summary', params], queryFn: () => getJson(route('dailyWorks.summaryData'), params), placeholderData: keepPreviousData, staleTime: 30_000 });
    const d = q.data;
    const t = d?.totals;

    const strip = t ? [
        { key: 'works', label: 'Works', value: t.works.toLocaleString('en-GB') },
        { key: 'completed', label: 'Completed', value: t.completed.toLocaleString('en-GB'), hint: pct(t.completion_rate) && `${pct(t.completion_rate)} of works`, tone: 'success' },
        { key: 'open', label: 'Open', value: t.open.toLocaleString('en-GB') },
        { key: 'pass', label: 'Inspection pass rate', value: pct(t.pass_rate), hint: `${t.passed} passed, ${t.failed} failed` },
        { key: 'ftp', label: 'First-time pass', value: pct(t.first_time_pass_rate), hint: 'Passed with no resubmission' },
        { key: 'resub', label: 'Resubmitted', value: t.resubmitted.toLocaleString('en-GB') },
        { key: 'obj', label: 'With active objections', value: t.with_objections.toLocaleString('en-GB'), tone: t.with_objections ? 'danger' : undefined },
    ] : undefined;
    const quiet = d?.empty;

    return (
        <ErrorBoundary>
            <div className="dw-tab">
                <PageHeader title="Daily" muted="Works summary" upper chips={t ? [{ value: t.works.toLocaleString('en-GB'), label: 'In period', tone: 'default' }, ...(t.unplaced ? [{ value: t.unplaced, label: 'Not placed', tone: 'warning' }] : [])] : []} />
                <Toolbar label="Summary filters">
                    <ToolbarGroup>
                        <Select label="Period" value={v.sm_period} onChange={(val) => f.set('sm_period', val)} options={PERIOD_OPTIONS} />
                        {v.sm_period === 'custom' && (<><DateInput label="From" size="sm" value={v.sm_from} onChange={(val) => f.set('sm_from', val)} /><DateInput label="To" size="sm" value={v.sm_to} onChange={(val) => f.set('sm_to', val)} /></>)}
                        <Select label="Jurisdiction" value={v.sm_jur} onChange={(val) => f.set('sm_jur', val)} options={[{ value: '', label: 'All jurisdictions' }, ...jurisdictions.map((j) => ({ value: String(j.id), label: j.code }))]} />
                        <Select label="Type" value={v.sm_type} onChange={(val) => f.set('sm_type', val)} options={[{ value: '', label: 'All types' }, ...['Embankment', 'Structure', 'Pavement'].map((x) => ({ value: x, label: x }))]} />
                    </ToolbarGroup>
                    <ToolbarGroup end><Button size="sm" variant="outline" color="secondary" onClick={() => q.refetch()}><Icon name="arrow-repeat" className={q.isFetching ? 'cy-spin' : ''} /> Refresh</Button></ToolbarGroup>
                </Toolbar>
                {q.isError && <Alert tone="danger" title="The summary could not be loaded">Try again in a moment. <Button size="sm" onClick={() => q.refetch()}>Retry</Button></Alert>}
                {!d && q.isPending && <p className="dw-state" role="status">Loading the summary…</p>}
                {quiet && (
                    <Alert tone="info" title="No works in this period">
                        {quietMessage(quiet.scoped_total, quiet.latest_work_date)}{' '}
                        {quiet.latest_work_date && <Button size="sm" onClick={() => f.setMany({ sm_period: 'all', sm_from: '', sm_to: '' })}>Show all time</Button>}
                    </Alert>
                )}
                {d && !quiet && (
                    <>
                        <Card title="Key figures" flush footer={<span className="cy-fresh">{d.period.first ? `Records from ${formatDay(d.period.first)} to ${formatDay(d.period.last)}` : ''}</span>}><StatStrip items={strip} label="Daily works key figures" busy={q.isFetching} /></Card>
                        <Card title={`Works per ${d.trend.grain}`} flush><div className="cy-chartgrid"><div className="cy-chartpanel"><TrendChart trend={d.trend} /></div></div></Card>
                        <div className="dw-grid2">
                            <Card title="By status"><BarList rows={d.by_status} label="Works by status" /></Card>
                            <Card title="By type"><BarList rows={d.by_type} label="Works by type" /></Card>
                            <Card title="By jurisdiction" footer={t.unattributed ? <Badge soft color="warning">{t.unattributed} not attributed</Badge> : null}><BarList rows={d.by_jurisdiction.map((r) => ({ ...r, label: r.code ?? 'Not attributed' }))} label="Works by jurisdiction" keyName="id" /></Card>
                            <Card title="By in-charge (top 15)"><BarList rows={d.by_incharge.map((r) => ({ ...r, label: r.name }))} label="Works by in-charge" keyName="id" /></Card>
                        </div>
                        <Card title="Turnaround">
                            {d.turnaround.avg_days == null ? <p role="status">No completed works with a completion time in this period.</p> : (
                                <p>On average <strong>{d.turnaround.avg_days} days</strong> from RFI to completion, across {d.turnaround.samples.toLocaleString('en-GB')} completed works.{d.turnaround.fallback_samples > 0 && <span className="dw-muted"> {d.turnaround.fallback_samples.toLocaleString('en-GB')} older records have no submission date, so their work date is used.</span>}</p>
                            )}
                        </Card>
                    </>
                )}
            </div>
        </ErrorBoundary>
    );
}
