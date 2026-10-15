import React, { lazy, Suspense, useCallback, useEffect, useMemo, useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PageHeader from '@/Components/PageHeader';
import { Alert, Badge, Button, Card, Field, Icon, IconButton, Pagination, Select, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { useClampPage, useQueryFilters } from '@/Hooks/useQueryFilters';
import { useRealtimeSignals } from '@/api/useRealtimeSignals';

import { FilterChips, FilterPanel } from './RegisterFilters.jsx';
import { WorksCards, WorksTable } from './RegisterTable.jsx';
import { COLUMNS, DEFAULTS, activeFilterCount, getJson, loadColumns, loadDensity, quietMessage, registerParams, saveColumns, saveDensity } from './dwUtils.js';
import { formatDay } from '../Jurisdictions/jurisdictionUtils.js';

const WorkDetailDialog = lazy(() => import('./WorkDetailDialog.jsx'));
const WorkFormDialog = lazy(() => import('./WorkFormDialog.jsx'));
const ImportWizard = lazy(() => import('./ImportWizard.jsx'));
const ExportDialog = lazy(() => import('./ExportDialog.jsx'));
const BulkDialog = lazy(() => import('./BulkDialog.jsx'));

/**
 * The works register: every filter, the sort, the page and the open work live in the query string (prefix dw_), so a link
 * reproduces the view. Rows come from the scoped register API; nothing is filtered or sorted in the browser.
 */
export default function DailyWorksRegister({ authId, jurisdictions = [], shellCan = {} }) {
    const f = useQueryFilters({ mode: 'client', debounceKeys: ['dw_q'], defaults: DEFAULTS, pageKey: 'dw_page' });
    const qc = useQueryClient();
    const v = f.values;
    const isPhone = useMediaQuery('(max-width: 640px)');
    const [panel, setPanel] = useState(false);
    const [columns, setColumns] = useState(loadColumns);
    const [density, setDensity] = useState(loadDensity);
    const [chooser, setChooser] = useState(false);
    const [selected, setSelected] = useState(() => new Set());
    const [dialog, setDialog] = useState(null); // { kind: 'form'|'import'|'export'|'bulk', ... }
    const [conflict, setConflict] = useState(null);

    const params = useMemo(() => registerParams(v), [v]);
    const register = useQuery({
        queryKey: ['daily-works', 'register', params],
        queryFn: () => getJson(route('dailyWorks.register'), params),
        placeholderData: keepPreviousData,
        staleTime: 15_000,
    });
    const data = register.data;
    const can = data?.can ?? {};
    const rows = data?.data ?? [];
    const meta = data?.meta;
    useClampPage(f, meta && meta.total > 0 ? meta.last_page : undefined, 'dw_page');

    const needPeople = panel || dialog?.kind === 'form' || dialog?.kind === 'bulk' || !!v.dw_inc || !!v.dw_asg;
    const people = useQuery({ queryKey: ['daily-works', 'people'], queryFn: () => getJson(route('dailyWorks.people')).then((r) => r.people), enabled: needPeople && (can.assign || can.reassign || can.create), staleTime: 300_000 });
    const peopleList = people.data ?? [];

    const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ['daily-works'] }), [qc]);
    useRealtimeSignals({ path: 'dailywork/all', selfActorId: authId ?? null, onSignal: refresh });
    useEffect(() => { setSelected(new Set()); }, [params]);

    const openId = v.dw_open ? Number(v.dw_open) : null;
    const openWork = useCallback((w) => f.commit({ dw_open: String(w.id) }, { resetPage: false, replace: false }), [f]);
    const closeWork = useCallback(() => f.commit({ dw_open: '' }, { resetPage: false }), [f]);

    const onSort = (key) => f.setMany(v.dw_sort === key ? { dw_dir: v.dw_dir === 'asc' ? 'desc' : 'asc' } : { dw_sort: key, dw_dir: key === 'date' || key === 'updated' || key === 'completion' ? 'desc' : 'asc' });
    const toggleColumn = (key) => { const next = columns.includes(key) ? columns.filter((k) => k !== key) : COLUMNS.filter((c) => columns.includes(c.key) || c.key === key).map((c) => c.key); if (next.length) { setColumns(next); saveColumns(next); } };
    const pickDensity = (d) => { setDensity(d); saveDensity(d); };
    const onSelect = (id, on) => setSelected((prev) => { const n = new Set(prev); if (on) n.add(id); else n.delete(id); return n; });
    const onSelectAll = (on) => setSelected(on ? new Set(rows.map((r) => r.id)) : new Set());
    const selectedRows = rows.filter((r) => selected.has(r.id));

    const filterCount = activeFilterCount(v);
    const chips = meta ? [
        { value: meta.total.toLocaleString('en-GB'), label: filterCount ? 'Works found' : 'Works', tone: 'default' },
        ...(v.dw_quality === '' && data?.counts?.unplaced ? [{ value: data.counts.unplaced, label: 'Not placed', tone: 'warning' }] : []),
    ] : [];

    const errored = register.isError;
    const empty = data && rows.length === 0;
    const actions = (
        <>
            {(can.create ?? shellCan.create) && <Button onClick={() => setDialog({ kind: 'form' })}><Icon name="plus-lg" /> Add work</Button>}
            {(can.import ?? shellCan.import) && <Button variant="outline" color="secondary" onClick={() => setDialog({ kind: 'import' })}><Icon name="download" className="dw-flip" /> Import</Button>}
        </>
    );

    return (
        <ErrorBoundary>
            <div className="dw-tab">
                <PageHeader title="Daily" muted="Works" upper chips={chips} actions={actions} />
                <Toolbar label="Register filters">
                    <ToolbarGroup>
                        <Field icon="search" label="Search RFI, location, description" type="search" value={f.draft.dw_q} onChange={(e) => f.setDraft('dw_q', e.target.value)} />
                        <Select label="Status" value={v.dw_status.includes(',') ? '' : v.dw_status} onChange={(val) => f.set('dw_status', val)} options={[{ value: '', label: v.dw_status.includes(',') ? 'Several statuses' : 'All statuses' }, ...['new', 'in-progress', 'pending', 'emergency', 'resubmission', 'rejected', 'completed'].map((s) => ({ value: s, label: s === 'in-progress' ? 'In progress' : s[0].toUpperCase() + s.slice(1) }))]} />
                        <Button size="sm" variant="outline" color={filterCount ? 'theme' : 'secondary'} aria-expanded={panel} aria-controls="dw-filter-panel" onClick={() => setPanel((p) => !p)}><Icon name="funnel" /> Filters{filterCount ? ` (${filterCount})` : ''}</Button>
                    </ToolbarGroup>
                    <ToolbarGroup end>
                        <div className="dw-menu">
                            <Button size="sm" variant="outline" color="secondary" aria-expanded={chooser} aria-haspopup="true" onClick={() => setChooser((c) => !c)}><Icon name="table" /> View</Button>
                            {chooser && (
                                <div className="dw-menu__pop" role="group" aria-label="Columns and density">
                                    <strong>Columns</strong>
                                    {COLUMNS.map((c) => (
                                        <label key={c.key} className="dw-menu__row"><input type="checkbox" checked={columns.includes(c.key)} onChange={() => toggleColumn(c.key)} /> {c.label}</label>
                                    ))}
                                    <strong>Density</strong>
                                    <label className="dw-menu__row"><input type="radio" name="dw-density" checked={density === 'comfortable'} onChange={() => pickDensity('comfortable')} /> Comfortable</label>
                                    <label className="dw-menu__row"><input type="radio" name="dw-density" checked={density === 'compact'} onChange={() => pickDensity('compact')} /> Compact</label>
                                    <Button size="sm" onClick={() => setChooser(false)}>Done</Button>
                                </div>
                            )}
                        </div>
                        {(can.export ?? shellCan.export) && <Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ kind: 'export' })}><Icon name="file-earmark-excel" /> Export</Button>}
                        <IconButton icon="arrow-repeat" label="Refresh the register" spin={register.isFetching} onClick={refresh} />
                    </ToolbarGroup>
                </Toolbar>

                {panel && <div id="dw-filter-panel"><FilterPanel values={v} set={f.set} setMany={f.setMany} jurisdictions={jurisdictions} people={peopleList} canSeeTrashed={!!can.restore} onClose={() => setPanel(false)} /></div>}
                <FilterChips values={v} setMany={f.setMany} jurisdictions={jurisdictions} people={peopleList} />

                {conflict && <Alert tone="warning" title="This work changed while you were editing" onDismiss={() => setConflict(null)}>{conflict} The register has been refreshed with the current values.</Alert>}

                {selected.size > 0 && (
                    <div className="dw-bulkbar" role="region" aria-label="Bulk actions">
                        <strong>{selected.size} selected</strong>
                        {can.bulk && !v.dw_trash && (
                            <>
                                {can.status && <Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ kind: 'bulk', action: 'status' })}>Change status</Button>}
                                {can.reassign && <Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ kind: 'bulk', action: 'incharge' })}>Change in-charge</Button>}
                                {can.status && <Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ kind: 'bulk', action: 'completion' })}>Set completion date</Button>}
                                {can.delete && <Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ kind: 'bulk', action: 'delete' })}><Icon name="trash" /> Delete</Button>}
                            </>
                        )}
                        {!can.bulk && <span className="dw-muted">Bulk actions need the bulk permission.</span>}
                        <Button size="sm" variant="outline" color="secondary" onClick={() => setSelected(new Set())}>Clear selection</Button>
                    </div>
                )}

                <Card flush>
                    {errored && <div className="dw-state"><Alert tone="danger" title="The register could not be loaded">{register.error?.response?.data?.message ?? 'Check your connection and try again.'} <Button size="sm" onClick={() => register.refetch()}>Try again</Button></Alert></div>}
                    {!data && register.isPending && <p className="dw-state" role="status">Loading the register…</p>}
                    {empty && (
                        <div className="dw-state" role="status">
                            <p><strong>{quietMessage(data.empty?.scoped_total, data.empty?.latest_work_date)}</strong></p>
                            {filterCount > 0 && <Button size="sm" onClick={() => f.setMany({ dw_status: '', dw_type: '', dw_side: '', dw_inc: '', dw_asg: '', dw_jur: '', dw_from: '', dw_to: '', dw_cf: '', dw_ct: '', dw_obj: '', dw_quality: '', dw_trash: '', dw_q: '' })}>Clear all filters</Button>}
                            {data.empty?.latest_work_date && <Button size="sm" variant="outline" color="secondary" onClick={() => f.setMany({ dw_from: '', dw_to: '' })}>Show every date</Button>}
                        </div>
                    )}
                    {rows.length > 0 && (isPhone
                        ? <WorksCards rows={rows} selected={selected} onSelect={onSelect} onOpen={openWork} busy={register.isFetching} />
                        : <WorksTable rows={rows} columns={columns} sort={v.dw_sort} dir={v.dw_dir} onSort={onSort} selected={selected} onSelect={onSelect} onSelectAll={onSelectAll} onOpen={openWork} density={density} busy={register.isFetching} />)}
                    {meta && meta.total > 0 && <Pagination pagination={{ currentPage: meta.page, perPage: meta.per_page, total: meta.total }} loading={register.isFetching} perPageOptions={[10, 25, 50, 100]} label="Register pages" onPageChange={(p) => f.setPage(p)} onRowsPerPageChange={(n) => f.setMany({ dw_pp: n, dw_page: 1 })} />}
                </Card>

                <Suspense fallback={null}>
                    {openId && <WorkDetailDialog id={openId} can={can} people={peopleList} onClose={closeWork} onChanged={refresh} onEdit={(w) => { closeWork(); setDialog({ kind: 'form', work: w }); }} onConflict={(m) => { setConflict(m); refresh(); }} />}
                    {dialog?.kind === 'form' && <WorkFormDialog work={dialog.work} people={peopleList} can={can} onClose={() => setDialog(null)} onSaved={() => { setDialog(null); refresh(); }} onConflict={(m) => { setDialog(null); setConflict(m); refresh(); }} />}
                    {dialog?.kind === 'import' && <ImportWizard can={can} onClose={() => setDialog(null)} onDone={refresh} onFilterBatch={(id) => { setDialog(null); f.setMany({ dw_q: '', dw_from: '', dw_to: '', dw_status: '', dw_quality: '' }); }} />}
                    {dialog?.kind === 'export' && <ExportDialog filters={params} onClose={() => setDialog(null)} />}
                    {dialog?.kind === 'bulk' && <BulkDialog action={dialog.action} works={selectedRows} people={peopleList} onClose={() => setDialog(null)} onDone={() => { setDialog(null); setSelected(new Set()); refresh(); }} />}
                </Suspense>
            </div>
        </ErrorBoundary>
    );
}
