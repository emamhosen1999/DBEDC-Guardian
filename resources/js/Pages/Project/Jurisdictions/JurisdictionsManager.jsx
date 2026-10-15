import React, { useCallback, useMemo, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PageHeader from '@/Components/PageHeader';
import { Alert, Badge, Button, Card, DateInput, Field, Icon, Select, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import { formatChainage } from '@/Components/Cyber/Map/geo.js';
import { useQueryFilters } from '@/Hooks/useQueryFilters';

import { CorridorTree } from './JurisdictionTeamTree.jsx';
import JurisdictionDetail from './JurisdictionDetail.jsx';
import { DeleteJurisdictionDialog, JurisdictionFormDialog, KINDS, MergeDialog, RestoreDialog, RetireDialog, SplitDialog, TeamDialog, TransferDialog, kindLabel } from './JurisdictionDialogs.jsx';
import JurisdictionMapCard from './JurisdictionMapCard.jsx';
import { PERIODS, bandColour, chainageRange, formatDay, getJson, lengthKm, overviewParams, todayDhaka } from './jurisdictionUtils.js';

const DEFAULTS = { ju_q: '', ju_kind: 'all', ju_sel: '', ju_dtab: 'team', ju_period: '365d', ju_asof: '', ju_from: '', ju_to: '' };

/**
 * Jurisdictions (project phases) of the expressway, historical: every view takes an as-of date and shows the boundaries and
 * the team valid on it. Home of the jurisdiction map, list, detail with team tree and history, coverage and the printable map.
 * State (selected jurisdiction, period, as-of date, search) lives in the query string under a `ju_` prefix.
 */
export default function JurisdictionsManager({ employees = [] }) {
    const f = useQueryFilters({ mode: 'client', debounceKeys: ['ju_q'], defaults: DEFAULTS });
    const qc = useQueryClient();
    const v = f.values;
    const asOf = v.ju_asof || '';
    const params = useMemo(() => overviewParams({ asOf, period: v.ju_period, from: v.ju_from, to: v.ju_to }), [asOf, v.ju_period, v.ju_from, v.ju_to]);
    const overview = useQuery({
        queryKey: ['jurisdictions', 'overview', params],
        queryFn: () => getJson(route('jurisdictions.overview'), params),
        placeholderData: keepPreviousData,
        staleTime: 20_000,
    });
    const data = overview.data;
    const abilities = data?.abilities ?? { create: false, update: false, delete: false, team: false, correct_history: false };

    const [dialog, setDialog] = useState(null); // { kind, ... }
    const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ['jurisdictions'] }), [qc]);

    const search = (f.draft.ju_q ?? '').trim().toLowerCase();
    const rows = useMemo(() => (data?.jurisdictions ?? []).filter((j) => (v.ju_kind === 'all' || j.kind === v.ju_kind) && (!search || `${j.code} ${j.name} ${j.in_charge?.name ?? ''}`.toLowerCase().includes(search))), [data, search, v.ju_kind]);
    const sections = useMemo(() => (data?.jurisdictions ?? []).filter((j) => j.kind === 'section'), [data]);
    const structures = useMemo(() => (data?.map?.layers ?? []).find((l) => l.key === 'corridor.structures')?.features ?? [], [data]);
    const selected = rows.find((j) => String(j.id) === String(v.ju_sel)) ?? rows[0] ?? null;
    const select = useCallback((id) => f.set('ju_sel', String(id)), [f]);

    const chips = data ? [
        { value: data.summary.count, label: 'Jurisdictions', tone: 'default' },
        { value: `${data.summary.coverage_pct}%`, label: 'Corridor covered', tone: data.summary.coverage_pct >= 100 ? 'success' : 'warning' },
        { value: data.summary.gaps + data.summary.overlaps, label: 'Section gaps and overlaps', tone: data.summary.gaps + data.summary.overlaps === 0 ? 'success' : 'danger', title: data.summary.gaps ? 'Part of the corridor belongs to no section: daily works there cannot be attributed.' : undefined },
        { value: data.summary.unassigned_incharge, label: 'Without in-charge', tone: data.summary.unassigned_incharge === 0 ? 'success' : 'danger' },
        { value: data.summary.works.toLocaleString('en-GB'), label: `Works, ${data.period.label.toLowerCase()}`, tone: 'default' },
    ] : [];

    const printHref = useMemo(() => route('jurisdictions.map.print', params), [params]);
    const asOfLabel = data ? (data.is_today ? 'As of today' : `As of ${formatDay(data.as_of)}`) : '';
    const today = todayDhaka();

    return (
        <ErrorBoundary>
            <div className="jr-tab">
                <PageHeader
                    title="Project" muted="Jurisdictions" upper chips={chips}
                    actions={abilities.create ? <Button onClick={() => setDialog({ kind: 'form' })}><Icon name="plus-lg" /> Add jurisdiction</Button> : null}
                />
                <Toolbar label="Jurisdiction filters">
                    <ToolbarGroup>
                        <Field icon="search" label="Search jurisdictions" type="search" value={f.draft.ju_q} onChange={(e) => f.setDraft('ju_q', e.target.value)} />
                        <label className="cy-tool"><span className="visually-hidden">As of date</span><Icon name="calendar-event" />
                            <DateInput label="As of date" size="sm" value={asOf || today} onChange={(val) => f.set('ju_asof', !val || val === today ? '' : val)} />
                        </label>
                        <Select label="Kind" value={v.ju_kind} onChange={(val) => f.set('ju_kind', val)} options={[{ value: 'all', label: 'All kinds' }, ...KINDS.map((k) => ({ value: k.value, label: `${k.label}s` }))]} />
                        <Select label="Period" value={v.ju_period} onChange={(val) => f.set('ju_period', val)} options={PERIODS} />
                        {v.ju_period === 'custom' && (
                            <>
                                <DateInput label="Period from" size="sm" value={v.ju_from} onChange={(val) => f.set('ju_from', val)} />
                                <DateInput label="Period to" size="sm" value={v.ju_to} onChange={(val) => f.set('ju_to', val)} />
                            </>
                        )}
                    </ToolbarGroup>
                    <ToolbarGroup end>
                        {asOf && <Button size="sm" variant="outline" color="secondary" onClick={() => f.set('ju_asof', '')}>Back to today</Button>}
                        <Button as="a" size="sm" variant="outline" color="secondary" href={printHref} target="_blank" rel="noopener"><Icon name="file-earmark-pdf" /> Print map</Button>
                    </ToolbarGroup>
                </Toolbar>

                {data && !data.is_today && (
                    <div className="jr-asof-banner" role="status">
                        <Badge soft color="warning"><Icon name="clock-history" /> {formatDay(data.as_of)}</Badge>
                        <span>Boundaries, in-charges and teams as they were on this date. Figures are for {data.period.label.toLowerCase()}.</span>
                    </div>
                )}

                {data && data.summary.works === 0 && data.summary.latest_work_date && v.ju_period !== 'all' && (
                    <Alert tone="info" title={`No daily works are dated in the ${data.period.label.toLowerCase()}.`}>
                        <span>The latest work is dated {formatDay(data.summary.latest_work_date)}. </span>
                        {v.ju_period !== '365d' && <Button size="sm" variant="outline" color="secondary" onClick={() => f.set('ju_period', '365d')}>Show last 12 months</Button>}{' '}
                        <Button size="sm" variant="outline" color="secondary" onClick={() => f.set('ju_period', 'all')}>Show all time</Button>
                    </Alert>
                )}

                {overview.isError && !data && (
                    <Alert tone="danger" title="Jurisdictions could not be loaded. ">
                        <Button size="sm" variant="outline" color="secondary" onClick={() => overview.refetch()}>Retry</Button>
                    </Alert>
                )}
                {overview.isLoading && <p className="jr-empty" role="status">Loading jurisdictions…</p>}
                {overview.isError && data && <Alert tone="warning">Showing the last loaded figures; the refresh failed. <Button size="sm" variant="outline" color="secondary" onClick={() => overview.refetch()}>Retry</Button></Alert>}

                {data && (
                    <>
                        <div className="dl-row"><div className="dl-col">
                            <JurisdictionMapCard map={data.map} coverage={data.coverage} onSelectJurisdiction={select} asOfLabel={asOfLabel} />
                        </div></div>

                        <div className="dl-row">
                            <div className="dl-col dl-col--4">
                                <Card id="jurisdictions:list" title="Jurisdictions" flush footer={<span className="cy-fresh">{rows.length} of {data.jurisdictions.length}, in chainage order</span>}>
                                    {rows.length === 0 ? (
                                        <p className="jr-empty" role="status">{data.jurisdictions.length === 0 ? 'No jurisdiction is defined for this date.' : 'No jurisdiction matches your search.'}</p>
                                    ) : (
                                        <ul className="jr-list">
                                            {rows.map((j) => (
                                                <li key={j.id}>
                                                    <button type="button" className="jr-list__row" aria-current={selected?.id === j.id} onClick={() => select(j.id)}>
                                                        <span className="jr-swatch" style={{ '--jr-c': bandColour(j.colour_token) }} aria-hidden="true" />
                                                        <span className="jr-list__main">
                                                            <strong>{j.code} {j.name}</strong>
                                                            <small>{kindLabel(j.kind)}{j.subtype ? `, ${j.subtype.replace('_', ' ')}` : ''}{j.start_m !== null ? `, ${chainageRange(j)}, ${lengthKm(j.length_m)}` : ', polygon'}</small>
                                                            <small>{j.in_charge ? `${j.in_charge.name}${j.in_charge.designation ? `, ${j.in_charge.designation}` : ''}` : 'No in-charge'}</small>
                                                        </span>
                                                        {j.kind === 'section' ? <Badge soft color={j.stats.completion_rate >= 80 ? 'success' : 'secondary'}>{j.stats.works.toLocaleString('en-GB')} works</Badge> : <Badge soft color="info">{kindLabel(j.kind)}</Badge>}
                                                    </button>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </Card>
                            </div>
                            <div className="dl-col dl-col--8">
                                {selected ? (
                                    <JurisdictionDetail
                                        jurisdiction={selected} period={data.period} isToday={data.is_today} abilities={abilities} tab={v.ju_dtab} onTab={(t) => f.set('ju_dtab', t)}
                                        onEdit={() => setDialog({ kind: 'form', jurisdiction: selected })}
                                        onDelete={() => setDialog({ kind: 'delete', jurisdiction: selected })}
                                        onSplit={() => setDialog({ kind: 'split', jurisdiction: selected })}
                                        onMerge={() => setDialog({ kind: 'merge', jurisdiction: selected })}
                                        onRetire={() => setDialog({ kind: 'retire', jurisdiction: selected })}
                                        onTeam={(mode, member) => setDialog({ kind: 'team', mode, jurisdiction: selected, member })}
                                        onTransfer={(handoverId) => setDialog({ kind: 'transfer', jurisdiction: selected, handoverId })}
                                    />
                                ) : (
                                    <Card id="jurisdictions:detail" title="Jurisdiction"><p className="jr-empty" role="status">Choose a jurisdiction to see its team, figures and history.</p></Card>
                                )}
                            </div>
                        </div>

                        <div className="dl-row">
                            <div className="dl-col dl-col--6">
                                <Card id="jurisdictions:coverage" title="Coverage" flush footer={<span className="cy-fresh">{formatChainage(data.coverage.corridor.start_m)} to {formatChainage(data.coverage.corridor.end_m)}, {(data.coverage.corridor.length_m / 1000).toFixed(1)} km</span>}>
                                    <div className="jr-coverage" role="img" aria-label={`Corridor coverage ${data.coverage.coverage_pct} percent, ${data.coverage.gaps.length} gaps, ${data.coverage.overlaps.length} overlaps`}>
                                        {coverageBar(data)}
                                    </div>
                                    <div className="jr-detail__section">
                                        {data.coverage.gaps.length === 0 && data.coverage.overlaps.length === 0 ? (
                                            <p className="cy-formtext" role="status">No gaps and no overlaps: the jurisdictions cover the corridor exactly once.</p>
                                        ) : (
                                            <ul className="jr-suggest">
                                                {data.coverage.gaps.map((g) => <li key={`g${g.start_m}`}><Badge soft color="warning">Gap</Badge> {formatChainage(g.start_m)} to {formatChainage(g.end_m)} ({lengthKm(g.length_m)}) belongs to no jurisdiction</li>)}
                                                {data.coverage.overlaps.map((o) => <li key={`o${o.start_m}`}><Badge soft color="danger">Overlap</Badge> {formatChainage(o.start_m)} to {formatChainage(o.end_m)} ({lengthKm(o.length_m)}) is claimed twice</li>)}
                                            </ul>
                                        )}
                                        <p className="cy-formtext">By kind: {data.summary.sections} sections covering {data.coverage.coverage_pct}% of the corridor; {data.coverage_by_kind.facility.count} facilities; {data.coverage_by_kind.area.count} areas. Facilities and areas are not expected to cover the road.</p>
                                        {data.summary.unplaced > 0 && <p className="cy-formtext">{data.summary.unplaced.toLocaleString('en-GB')} works in this period have no jurisdiction on their date (unreadable chainage, or outside every jurisdiction).</p>}
                                    </div>
                                </Card>
                            </div>
                            <div className="dl-col dl-col--6">
                                <Card id="jurisdictions:tree" title="Corridor, jurisdictions and teams" flush>
                                    <div className="jr-detail__section">
                                        <CorridorTree jurisdictions={data.jurisdictions.filter((j) => j.kind === 'section')} coverage={data.coverage} periodLabel={data.period.label} selectedId={selected?.id} onSelect={select} />
                                    </div>
                                </Card>
                            </div>
                        </div>
                    </>
                )}

                {data && data.archived.length > 0 && (
                    <div className="dl-row"><div className="dl-col">
                        <Card id="jurisdictions:archived" title="Retired and deleted" flush footer={<span className="cy-fresh">Not valid on {formatDay(data.as_of)}; their history stays and shows when you look at an earlier date</span>}>
                            <ul className="jr-list">
                                {data.archived.map((a) => (
                                    <li key={a.id} className="jr-list__row" style={{ cursor: 'default' }}>
                                        <span className="jr-list__main"><strong>{a.code} {a.name}</strong><small>{kindLabel(a.kind)}, {a.state === 'deleted' ? 'deleted' : a.state === 'planned' ? `starts ${formatDay(a.starts_on)}` : `retired${a.retired_on ? ` after ${formatDay(a.retired_on)}` : ''}`}</small></span>
                                        {abilities.restore && a.state !== 'planned' && <Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ kind: 'restore', archived: a })}>Restore</Button>}
                                    </li>
                                ))}
                            </ul>
                        </Card>
                    </div></div>
                )}

                {dialog?.kind === 'form' && <JurisdictionFormDialog jurisdiction={dialog.jurisdiction} employees={employees} abilities={abilities} structures={structures} onClose={() => setDialog(null)} onSaved={refresh} />}
                {dialog?.kind === 'delete' && <DeleteJurisdictionDialog jurisdiction={dialog.jurisdiction} onClose={() => setDialog(null)} onDeleted={refresh} />}
                {dialog?.kind === 'split' && <SplitDialog jurisdiction={dialog.jurisdiction} employees={employees} abilities={abilities} onClose={() => setDialog(null)} onDone={refresh} />}
                {dialog?.kind === 'merge' && <MergeDialog jurisdiction={dialog.jurisdiction} sections={sections} employees={employees} abilities={abilities} onClose={() => setDialog(null)} onDone={refresh} />}
                {dialog?.kind === 'retire' && <RetireDialog jurisdiction={dialog.jurisdiction} abilities={abilities} onClose={() => setDialog(null)} onDone={refresh} />}
                {dialog?.kind === 'restore' && <RestoreDialog archived={dialog.archived} employees={employees} abilities={abilities} onClose={() => setDialog(null)} onDone={refresh} />}
                {dialog?.kind === 'team' && <TeamDialog mode={dialog.mode} jurisdiction={dialog.jurisdiction} member={dialog.member} employees={employees} abilities={abilities} onClose={() => setDialog(null)} onChanged={refresh} />}
                {dialog?.kind === 'transfer' && <TransferDialog jurisdiction={dialog.jurisdiction} handoverId={dialog.handoverId} onClose={() => setDialog(null)} onDone={refresh} />}
            </div>
        </ErrorBoundary>
    );
}

/** The corridor as a strip: each jurisdiction in its colour, gaps hatched, overlaps red. */
function coverageBar(data) {
    const { start_m: from, length_m: total } = data.coverage.corridor;
    const pct = (m) => `${(m / total) * 100}%`;
    const parts = [
        ...data.jurisdictions.filter((j) => j.kind === 'section').map((j) => ({ key: `j${j.id}`, s: j.start_m, e: j.end_m, style: { '--jr-c': bandColour(j.colour_token) }, title: `${j.code} ${j.name}` })),
        ...data.coverage.gaps.map((g) => ({ key: `g${g.start_m}`, s: g.start_m, e: g.end_m, gap: true, title: 'Gap' })),
        ...data.coverage.overlaps.map((o) => ({ key: `o${o.start_m}`, s: o.start_m, e: o.end_m, overlap: true, title: 'Overlap' })),
    ];
    return parts.map((p) => (
        <span key={p.key} title={`${p.title}: ${formatChainage(p.s)} to ${formatChainage(p.e)}`} data-gap={p.gap || undefined} data-overlap={p.overlap || undefined}
            style={{ ...(p.style ?? {}), position: 'absolute', left: pct(p.s - from), width: pct(p.e - p.s + 1), opacity: p.overlap ? 0.8 : 1 }} />
    ));
}
