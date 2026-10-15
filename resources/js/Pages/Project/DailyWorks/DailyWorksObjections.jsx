import React, { useState } from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';

import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import PageHeader from '@/Components/PageHeader';
import { Alert, Badge, Button, Card, DateInput, Field, FormField, Icon, Pagination, Select, Textarea, Toolbar, ToolbarGroup } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { useClampPage, useQueryFilters } from '@/Hooks/useQueryFilters';
import { showToast } from '@/utils/toastUtils';

import { errorsOf, formatDay, formatMoment, getJson, postJson } from './dwUtils.js';

const DEFAULTS = { ob_q: '', ob_status: '', ob_cat: '', ob_ch: '', ob_from: '', ob_to: '', ob_page: 1, ob_pp: 20 };
const TONE = { draft: 'secondary', submitted: 'warning', under_review: 'warning', resolved: 'success', rejected: 'secondary' };

function ResolveDialog({ objection, mode, onClose, onDone }) {
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const go = async () => {
        setBusy(true); setError(null);
        try {
            await postJson(route(mode === 'resolve' ? 'objections.resolve' : 'objections.reject', objection.id), { resolution_notes: notes, lock_version: objection.lock_version });
            showToast.success(mode === 'resolve' ? 'Objection resolved' : 'Objection rejected'); onDone();
        } catch (e) { const r = errorsOf(e); setError(r.fields.resolution_notes || r.message); } finally { setBusy(false); }
    };
    return (
        <Dialog title={`${mode === 'resolve' ? 'Resolve' : 'Reject'} objection`} onClose={onClose}>
            <div className="cy-modal__body">
                {error && <Alert tone="danger">{error}</Alert>}
                <p><strong>{objection.title}</strong></p>
                <FormField label="Resolution notes" htmlFor="dw-ob-n" required hint="Kept with the objection. Say what was decided and why."><Textarea id="dw-ob-n" rows={4} value={notes} onChange={(e) => setNotes(e.target.value)} /></FormField>
            </div>
            <div className="cy-modal__foot"><Button variant="outline" color="secondary" onClick={onClose}>Cancel</Button><Button loading={busy} disabled={!notes.trim()} onClick={go}>{mode === 'resolve' ? 'Resolve' : 'Reject'}</Button></div>
        </Dialog>
    );
}

/** Objections register: every objection with its chainage, linked RFIs and lifecycle, filtered on the server. */
export default function DailyWorksObjections({ onOpenWork }) {
    const f = useQueryFilters({ mode: 'client', debounceKeys: ['ob_q', 'ob_ch'], defaults: DEFAULTS, pageKey: 'ob_page' });
    const qc = useQueryClient();
    const v = f.values;
    const [dialog, setDialog] = useState(null);
    const [busyId, setBusyId] = useState(null);
    const [error, setError] = useState(null);
    const params = Object.fromEntries(Object.entries({ q: v.ob_q, status: v.ob_status, category: v.ob_cat, chainage: v.ob_ch, date_from: v.ob_from, date_to: v.ob_to, page: v.ob_page, per_page: v.ob_pp }).filter(([, x]) => x !== '' && x != null));
    const q = useQuery({ queryKey: ['daily-works', 'objections', params], queryFn: () => getJson(route('dailyWorks.objectionsRegister'), params), placeholderData: keepPreviousData, staleTime: 15_000 });
    const d = q.data;
    useClampPage(f, d && d.meta.total > 0 ? d.meta.last_page : undefined, 'ob_page');
    const refresh = () => qc.invalidateQueries({ queryKey: ['daily-works'] });
    const labels = d?.labels ?? { status: {}, category: {} };

    const move = async (o, routeName, ok) => {
        setBusyId(o.id); setError(null);
        try { await postJson(route(routeName, o.id), { lock_version: o.lock_version }); showToast.success(ok); refresh(); } catch (e) { setError(errorsOf(e).message); } finally { setBusyId(null); }
    };
    const chips = d ? [{ value: d.meta.total.toLocaleString('en-GB'), label: 'Objections', tone: 'default' }, { value: (d.counts.submitted ?? 0) + (d.counts.under_review ?? 0), label: 'Awaiting review', tone: (d.counts.submitted ?? 0) + (d.counts.under_review ?? 0) ? 'warning' : 'success' }, { value: d.counts.resolved ?? 0, label: 'Resolved', tone: 'success' }] : [];
    const filtered = !!(v.ob_q || v.ob_status || v.ob_cat || v.ob_ch || v.ob_from || v.ob_to);

    return (
        <ErrorBoundary>
            <div className="dw-tab">
                <PageHeader title="Daily Works" muted="Objections" upper chips={chips} />
                <Toolbar label="Objection filters">
                    <ToolbarGroup>
                        <Field icon="search" label="Search title, reason or RFI number" type="search" value={f.draft.ob_q} onChange={(e) => f.setDraft('ob_q', e.target.value)} />
                        <Select label="Status" value={v.ob_status} onChange={(val) => f.set('ob_status', val)} options={[{ value: '', label: 'All statuses' }, ...Object.entries(labels.status).map(([value, label]) => ({ value, label }))]} />
                        <Select label="Category" value={v.ob_cat} onChange={(val) => f.set('ob_cat', val)} options={[{ value: '', label: 'All categories' }, ...Object.entries(labels.category).map(([value, label]) => ({ value, label }))]} />
                        <Field icon="geo-alt" label="Chainage, e.g. K35+897" type="search" value={f.draft.ob_ch} onChange={(e) => f.setDraft('ob_ch', e.target.value)} />
                        <DateInput label="Raised from" size="sm" value={v.ob_from} onChange={(val) => f.set('ob_from', val)} />
                        <DateInput label="Raised to" size="sm" value={v.ob_to} onChange={(val) => f.set('ob_to', val)} />
                    </ToolbarGroup>
                    <ToolbarGroup end><Button size="sm" variant="outline" color="secondary" onClick={refresh}><Icon name="arrow-repeat" className={q.isFetching ? 'cy-spin' : ''} /> Refresh</Button></ToolbarGroup>
                </Toolbar>
                {error && <Alert tone="danger" onDismiss={() => setError(null)}>{error}</Alert>}
                {q.isError && <Alert tone="danger" title="Objections could not be loaded">{q.error?.response?.data?.message ?? 'Try again in a moment.'}</Alert>}
                <Card flush>
                    {!d && q.isPending && <p className="dw-state" role="status">Loading objections…</p>}
                    {d && d.data.length === 0 && <p className="dw-state" role="status"><strong>{filtered ? 'No objection matches these filters.' : 'No objections have been raised yet.'}</strong> {filtered && <Button size="sm" onClick={() => f.setMany({ ob_q: '', ob_status: '', ob_cat: '', ob_ch: '', ob_from: '', ob_to: '' })}>Clear filters</Button>}</p>}
                    {d && d.data.length > 0 && (
                        <ul className="dw-objlist" aria-label="Objections" aria-busy={q.isFetching || undefined}>
                            {d.data.map((o) => (
                                <li key={o.id} className="dw-obj">
                                    <div className="dw-obj__head">
                                        <strong>{o.title}</strong>
                                        <Badge soft color={TONE[o.status] ?? 'secondary'}>{labels.status[o.status] ?? o.status}</Badge>
                                        {o.category && <Badge soft color="secondary">{o.category_label}</Badge>}
                                    </div>
                                    <p className="dw-muted">Raised {formatMoment(o.created_at)} by {o.created_by ?? 'unknown'}{o.chainages.length > 0 && <> at {o.chainages.map((c) => c.text).join(', ')}</>}</p>
                                    {o.reason && <p>{o.reason}</p>}
                                    <p>
                                        <Icon name="link-45deg" /> {o.works_count === 0 ? 'Not linked to an RFI.' : <>Linked to {o.works_count} RFI{o.works_count > 1 ? 's' : ''}: </>}
                                        {o.works.map((w) => <button key={w.id} type="button" className="dw-link dw-gap" onClick={() => onOpenWork(w.id)}>{w.number}</button>)}
                                        {o.works_count > o.works.length && <span className="dw-muted"> and {o.works_count - o.works.length} more</span>}
                                    </p>
                                    {o.resolution_notes && <p className="dw-reason">Decision{o.resolved_at ? ` on ${formatDay(String(o.resolved_at).slice(0, 10))}` : ''}: {o.resolution_notes}</p>}
                                    <div className="dw-actions">
                                        {o.can.submit && o.status === 'draft' && <Button size="sm" loading={busyId === o.id} onClick={() => move(o, 'objections.submit', 'Submitted for review')}>Submit for review</Button>}
                                        {o.can.review && o.status === 'submitted' && <Button size="sm" loading={busyId === o.id} onClick={() => move(o, 'objections.review', 'Review started')}>Start review</Button>}
                                        {o.can.review && ['submitted', 'under_review'].includes(o.status) && (<><Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ o, mode: 'resolve' })}>Resolve</Button><Button size="sm" variant="outline" color="secondary" onClick={() => setDialog({ o, mode: 'reject' })}>Reject</Button></>)}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                    {d && d.meta.total > 0 && <Pagination pagination={{ currentPage: d.meta.page, perPage: d.meta.per_page, total: d.meta.total }} loading={q.isFetching} perPageOptions={[10, 20, 50, 100]} label="Objection pages" onPageChange={(p) => f.setPage(p)} onRowsPerPageChange={(n) => f.setMany({ ob_pp: n, ob_page: 1 })} />}
                </Card>
                {dialog && <ResolveDialog objection={dialog.o} mode={dialog.mode} onClose={() => setDialog(null)} onDone={() => { setDialog(null); refresh(); }} />}
            </div>
        </ErrorBoundary>
    );
}
