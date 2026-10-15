import React, { useCallback, useId, useState } from 'react';
import axios from 'axios';
import { useQuery } from '@tanstack/react-query';

import { Alert, Badge, Button, FormField, Icon, SelectInput, Tabs, Textarea, Timeline } from '@/Components/Cyber';
import Dialog from '@/Components/Cyber/Map/Dialog.jsx';
import { showToast } from '@/utils/toastUtils';

import { StatusBadge } from './RegisterTable.jsx';
import { RESULTS, errorsOf, formatDay, formatMoment, getJson, postJson, statusMeta } from './dwUtils.js';

const SOURCE = { web: 'the web app', mobile: 'the mobile app', api: 'the API', import: 'an Excel import', console: 'a system task' };
const EVENT_ICON = { created: 'plus-lg', updated: 'pencil', status_changed: 'arrow-left-right', deleted: 'trash', restored: 'arrow-counterclockwise', resubmitted: 'arrow-repeat' };

function history(h) {
    if (!h) return [];
    return h.revisions.map((r) => {
        const tone = r.event === 'deleted' ? 'danger' : r.event === 'restored' ? 'info' : r.tags.includes('status_changed') ? 'warning' : r.event === 'created' ? 'success' : 'secondary';
        const label = r.event === 'created' ? 'Created' : r.event === 'deleted' ? 'Deleted' : r.event === 'restored' ? 'Restored' : r.tags.includes('resubmitted') ? 'Resubmitted' : r.tags.includes('status_changed') ? 'Status changed' : 'Edited';
        return {
            id: r.id, date: formatMoment(r.at), title: `${label}, revision ${r.revision_no}`, tone, icon: EVENT_ICON[r.event] ?? 'pencil',
            badge: <Badge soft color="secondary">{r.by?.name ?? 'System'} via {SOURCE[r.source] ?? r.source}</Badge>,
            detail: (
                <>
                    {r.reason && <p className="dw-reason">Reason: {r.reason}</p>}
                    {r.import_batch_id && <p className="dw-muted">Import batch #{r.import_batch_id}</p>}
                    {r.changes.length > 0 && (
                        <ul className="dw-changes">
                            {r.changes.map((c) => <li key={c.field}><strong>{c.label}:</strong> <span className="dw-was">{c.before ?? 'empty'}</span> <Icon name="chevron-right" /> <span className="dw-now">{c.after ?? 'empty'}</span></li>)}
                        </ul>
                    )}
                </>
            ),
        };
    });
}

function Facts({ w, asOf, turnaround }) {
    const rows = [
        ['RFI date', formatDay(w.date)], ['Type', w.type], ['Location', w.location], ['Road side', w.side], ['Layer or quantity', w.qty_layer], ['Planned time', w.planned_time],
        ['In-charge', w.incharge_name ?? w.incharge], ['Assigned to', w.assigned_name ?? w.assigned], ['Completed at', w.completion_time ? formatMoment(w.completion_time) : null],
        ['RFI submitted', w.rfi_submission_date ? formatDay(w.rfi_submission_date) : null], ['RFI response', w.rfi_response_status ? `${w.rfi_response_status}${w.rfi_response_date ? `, ${formatDay(w.rfi_response_date)}` : ''}` : null],
        ['Resubmissions', w.resubmission_count || null],
    ].filter(([, val]) => val != null && val !== '');
    return (
        <>
            <dl className="dw-facts">{rows.map(([k, val]) => <div key={k}><dt>{k}</dt><dd>{val}</dd></div>)}</dl>
            <h3 className="dw-h3">Where it sits</h3>
            <p>
                {asOf.jurisdiction ? <><Badge soft color="secondary">{asOf.jurisdiction.code}</Badge> {asOf.jurisdiction.name}, as on {formatDay(asOf.date)}.</> : 'This work is not attributed to a jurisdiction. Correct the location to place it.'}
                {w.start_m == null && ' The location could not be read as a chainage.'}
            </p>
            {asOf.in_charge_then && asOf.in_charge_then !== w.incharge && <p className="dw-muted">The section in-charge on that date was employee {asOf.in_charge_then}; the work is recorded under {w.incharge_name ?? w.incharge}.</p>}
            {turnaround.days != null && <p>Turnaround <strong>{turnaround.days} days</strong> <span className="dw-muted">({turnaround.basis})</span></p>}
            {w.inspection_details && <><h3 className="dw-h3">Inspection details</h3><p className="dw-pre">{w.inspection_details}</p></>}
            {w.description && <><h3 className="dw-h3">Description</h3><p className="dw-pre">{w.description}</p></>}
        </>
    );
}

/** One work: facts, status moves, inspection note, documents, objections and the full history. */
export default function WorkDetailDialog({ id, can, people, onClose, onChanged, onEdit, onConflict }) {
    const labelled = useId();
    const [tab, setTab] = useState('details');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(null);
    const [move, setMove] = useState(null); // { to, result, reason }
    const [note, setNote] = useState(null);
    const detail = useQuery({ queryKey: ['daily-works', 'detail', id], queryFn: () => getJson(route('dailyWorks.detail', id)), staleTime: 5_000 });
    const d = detail.data;
    const w = d?.work;

    const act = useCallback(async (fn, okMessage) => {
        setBusy(true); setError(null);
        try {
            await fn();
            if (okMessage) showToast.success(okMessage);
            await detail.refetch();
            onChanged();
        } catch (e) {
            const err = errorsOf(e);
            if (err.status === 409) { onConflict(err.message); await detail.refetch(); } else setError(err.fields.reason || err.fields.to || err.fields.inspection_result || err.message);
        } finally { setBusy(false); }
    }, [detail, onChanged, onConflict]);

    const transition = () => act(() => postJson(route('dailyWorks.transition', id), { to: move.to, inspection_result: move.to === 'completed' ? move.result : undefined, reason: move.reason || undefined, lock_version: w.lock_version }).then(() => setMove(null)), `Status changed to ${statusMeta(move.to).label}`);
    const saveNote = () => act(() => postJson(route('dailyWorks.updateInspectionDetails'), { id, inspection_details: note, lock_version: w.lock_version }).then(() => setNote(null)), 'Inspection details saved');
    const remove = () => { if (window.confirm(`Delete RFI ${w.number}? It moves to deleted works and can be restored.`)) act(() => axios.delete(route('dailyWorks.delete'), { data: { id, lock_version: w.lock_version } }).then(onClose), 'Work deleted'); };
    const restore = () => act(() => postJson(route('dailyWorks.restore', id), {}), 'Work restored');
    const upload = (e) => {
        const files = [...e.target.files]; if (!files.length) return;
        const form = new FormData(); files.forEach((f) => form.append('files[]', f));
        act(() => axios.post(route('dailyWorks.rfiFiles.upload', id), form), 'Files uploaded'); e.target.value = '';
    };
    const dropFile = (m) => { if (window.confirm(`Remove ${m.name}?`)) act(() => axios.delete(route('dailyWorks.rfiFiles.delete', [id, m.id])), 'File removed'); };

    const tabs = [{ key: 'details', label: 'Details' }, { key: 'files', label: 'Documents', count: d?.files?.length }, { key: 'objections', label: 'Objections', count: d?.objections?.length, tone: d?.objections?.some((o) => ['submitted', 'under_review', 'draft'].includes(o.status)) ? 'danger' : undefined }, ...(can.history ? [{ key: 'history', label: 'History' }] : [])];

    return (
        <Dialog title={w ? `RFI ${w.number}` : 'Daily work'} onClose={onClose} className="cy-modal cy-modal--wide dw-detail" labelledBy={labelled}>
            <div className="cy-modal__body">
                {detail.isPending && <p role="status">Loading the work…</p>}
                {detail.isError && <Alert tone="danger" title="This work could not be loaded">{detail.error?.response?.status === 403 ? 'It is outside your scope.' : 'Try again in a moment.'}</Alert>}
                {w && (
                    <>
                        <div className="dw-detail__head">
                            <StatusBadge status={w.status} result={w.inspection_result} />
                            {w.deleted_at && <Badge soft color="danger">Deleted</Badge>}
                            {w.active_objections > 0 && <Badge soft color="danger">{w.active_objections} active objection{w.active_objections > 1 ? 's' : ''}</Badge>}
                            <span className="dw-spacer" />
                            {w.can?.update && !w.deleted_at && <Button size="sm" variant="outline" color="secondary" onClick={() => onEdit(w)}><Icon name="pencil" /> Edit</Button>}
                            {w.can?.delete && !w.deleted_at && <Button size="sm" variant="outline" color="secondary" onClick={remove} disabled={busy}><Icon name="trash" /> Delete</Button>}
                            {w.can?.restore && w.deleted_at && <Button size="sm" onClick={restore} disabled={busy}><Icon name="arrow-counterclockwise" /> Restore</Button>}
                            <Button as="a" size="sm" variant="outline" color="secondary" href={route('dailyWorks.report.inspection', { date: w.date, jurisdiction: w.jurisdiction?.id })} target="_blank" rel="noopener"><Icon name="file-earmark-pdf" /> Day report</Button>
                        </div>
                        {error && <Alert tone="danger" onDismiss={() => setError(null)}>{error}</Alert>}

                        {w.transitions.length > 0 && !move && (
                            <div className="dw-moves" role="group" aria-label="Change status">
                                <span className="dw-muted">Move to</span>
                                {w.transitions.map((t) => <Button key={t} size="sm" variant="outline" color="secondary" disabled={busy} onClick={() => setMove({ to: t, result: 'pass', reason: '' })}>{statusMeta(t).label}</Button>)}
                            </div>
                        )}
                        {move && (
                            <div className="dw-movebox">
                                <strong>Move to {statusMeta(move.to).label}</strong>
                                {move.to === 'completed' && <FormField label="Inspection result" htmlFor="dw-mv-res" required><SelectInput id="dw-mv-res" size="sm" value={move.result} onChange={(val) => setMove({ ...move, result: val })} options={Object.entries(RESULTS).map(([value, m]) => ({ value, label: m.label }))} /></FormField>}
                                <FormField label="Reason" htmlFor="dw-mv-why" hint="Recorded in the history. Required when rejecting or re-opening."><Textarea id="dw-mv-why" rows={2} value={move.reason} onChange={(e) => setMove({ ...move, reason: e.target.value })} /></FormField>
                                <div className="dw-actions"><Button size="sm" loading={busy} onClick={transition}>Confirm</Button><Button size="sm" variant="outline" color="secondary" onClick={() => setMove(null)}>Cancel</Button></div>
                            </div>
                        )}

                        <Tabs tabs={tabs} value={tab} onChange={setTab} idPrefix="dw-d" label="Work sections" className="cy-tabs--scroll" />
                        <div role="tabpanel" id={`dw-d-panel-${tab}`} aria-labelledby={`dw-d-tab-${tab}`} className="dw-panel">
                            {tab === 'details' && (
                                <>
                                    <Facts w={w} asOf={d.as_of} turnaround={d.turnaround} />
                                    {w.can?.update && !w.deleted_at && can.inspection && (
                                        note === null
                                            ? <Button size="sm" variant="outline" color="secondary" onClick={() => setNote(w.inspection_details ?? '')}><Icon name="pencil" /> Edit inspection details</Button>
                                            : <div className="dw-movebox"><FormField label="Inspection details" htmlFor="dw-note"><Textarea id="dw-note" rows={4} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} /></FormField><div className="dw-actions"><Button size="sm" loading={busy} onClick={saveNote}>Save</Button><Button size="sm" variant="outline" color="secondary" onClick={() => setNote(null)}>Cancel</Button></div></div>
                                    )}
                                </>
                            )}
                            {tab === 'files' && (
                                <>
                                    {d.files.length === 0 ? <p role="status">No documents are attached to this RFI.</p> : (
                                        <ul className="dw-files">
                                            {d.files.map((m) => (
                                                <li key={m.id}><Icon name={m.mime_type === 'application/pdf' ? 'file-earmark-pdf' : 'image'} /> <a href={route('dailyWorks.rfiFiles.download', [id, m.id])}>{m.name}</a> <span className="dw-muted">{Math.max(1, Math.round((m.size ?? 0) / 1024))} KB</span>
                                                    {can.files && !w.deleted_at && <Button size="sm" variant="outline" color="secondary" onClick={() => dropFile(m)} aria-label={`Remove ${m.name}`}><Icon name="trash" /></Button>}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    {can.files && !w.deleted_at && <label className="cy-btn cy-btn--outline-secondary cy-btn--sm dw-upload"><Icon name="download" className="dw-flip" /> Add documents<input type="file" multiple accept="image/*,application/pdf" onChange={upload} className="visually-hidden" /></label>}
                                </>
                            )}
                            {tab === 'objections' && (d.objections.length === 0 ? <p role="status">No objections are linked to this RFI.</p> : (
                                <ul className="dw-files">{d.objections.map((o) => <li key={o.id}><strong>{o.title}</strong> <Badge soft color={['resolved'].includes(o.status) ? 'success' : o.status === 'rejected' ? 'secondary' : 'danger'}>{String(o.status).replace('_', ' ')}</Badge> <span className="dw-muted">{o.category}, linked {o.attached_at ? formatDay(String(o.attached_at).slice(0, 10)) : ''}</span></li>)}</ul>
                            ))}
                            {tab === 'history' && d.history && (
                                <>
                                    {!d.history.complete && <Alert tone="info" title="History starts later than this work">{d.history.since ? `Changes have been recorded since ${formatDay(d.history.since)}. What happened to this work before then was not recorded and is not guessed.` : 'No changes have been recorded yet.'}</Alert>}
                                    <Timeline label="History of this work" events={history(d.history)} empty="No changes have been recorded for this work yet." />
                                </>
                            )}
                        </div>
                    </>
                )}
            </div>
        </Dialog>
    );
}
