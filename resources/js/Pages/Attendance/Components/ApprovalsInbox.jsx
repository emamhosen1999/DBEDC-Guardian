import React, { useState } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Button, Card, Pagination, Segmented, Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';
import RejectReasonDialog from './RejectReasonDialog';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import PunchExceptions from './PunchExceptions';
import SwapApprovals from './SwapApprovals';
import { usePage } from '@inertiajs/react';
import AttachmentViewer from '@/Components/Attachments/AttachmentViewer';
import ReasonDialog from '@/Components/Attachments/ReasonDialog';
import { requestReupload } from '@/Components/Attachments/attachmentsApi';
import OvertimeDecisionDialog from './OvertimeDecisionDialog';

const statusColor = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'secondary' };
const TABS_ID = 'attendance-approvals';

function formatMinutes(mins) {
    if (!mins) return '—';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

const COMP = { payment: 'Overtime pay', replacement: 'Replacement leave' };
const TYPE = { rest_day: 'Rest day', holiday: 'Holiday' };

export default function ApprovalsInbox() {
    const qc = useQueryClient();
    const { auth } = usePage().props;
    const can = (p) => !!(auth?.isSuperAdmin || auth?.permissions?.includes(p));

    /* Shared status filter across the inbox (Pending / Approved / Rejected / All). */
    /* Status, sub-tab and both pages live in the URL under an `a_` prefix
       (every Attendance tab stays mounted). Changing the status returns both
       lists to their first page. */
    const f = useQueryFilters({
        mode: 'client',
        pageKey: 'a_page',
        debounceKeys: [],
        defaults: { a_status: 'pending', a_sub: 'regularizations', a_reg: 1, a_ot: 1 },
    });
    const statusFilter = f.values.a_status;
    const setStatusFilter = (v) => f.setMany({ a_status: v, a_reg: 1, a_ot: 1 });
    const subTab = f.values.a_sub;
    const setSubTab = (v) => f.set('a_sub', v);
    const emptyLabel = statusFilter === 'all' ? '' : `${statusFilter} `;

    const regPage = f.values.a_reg;
    const otPage = f.values.a_ot;
    const setRegPage = (p) => f.commit({ a_reg: p }, { resetPage: false, replace: false });
    const setOtPage = (p) => f.commit({ a_ot: p }, { resetPage: false, replace: false });
    const itemsPerPage = 10;

    /* ── Regularizations ───────────────────────────────────── */
    const { data: regData, isLoading: regLoading } = useQuery({
        queryKey: ['regularizations', statusFilter],
        queryFn: () => requestJson('get', '/attendance/regularizations/pending', { params: { status: statusFilter } }),
    });

    const regApprove = useMutation({
        mutationFn: ({ id }) =>
            requestJson('post', `/attendance/regularizations/${id}/approve`, { data: {} }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['regularizations'] }),
    });

    const regReject = useMutation({
        mutationFn: ({ id, reason }) =>
            requestJson('post', `/attendance/regularizations/${id}/reject`, { data: { reason } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['regularizations'] }),
    });

    /* Rejecting asks for a reason in a dialog: { kind: 'reg' | 'ot', id } while it is open. */
    const [rejecting, setRejecting] = useState(null);
    const handleRegReject = (id) => setRejecting({ kind: 'reg', id });

    /* ── Overtime ──────────────────────────────────────────── */
    const { data: otData, isLoading: otLoading } = useQuery({
        queryKey: ['overtime', statusFilter],
        queryFn: () => requestJson('get', '/attendance/overtime/pending', { params: { status: statusFilter } }),
    });

    const otApprove = useMutation({
        mutationFn: ({ id, grant_comp_off }) =>
            requestJson('post', `/attendance/overtime/${id}/approve`, { data: { grant_comp_off } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['overtime'] }),
    });

    const otReject = useMutation({
        mutationFn: ({ id, reason }) =>
            requestJson('post', `/attendance/overtime/${id}/reject`, { data: { reason } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['overtime'] }),
    });

    const handleOtReject = (id) => setRejecting({ kind: 'ot', id });

    /* ── Replacement leave requests ───────────────────────── */
    const { data: repData, isLoading: repLoading } = useQuery({
        queryKey: ['replacement', statusFilter],
        queryFn: () => requestJson('get', '/attendance/replacement/pending', { params: { status: statusFilter } }),
    });
    const repApprove = useMutation({
        mutationFn: ({ id }) => requestJson('post', `/attendance/replacement/${id}/approve`, { data: {} }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['replacement'] }),
    });
    const repReject = useMutation({
        mutationFn: ({ id, reason }) => requestJson('post', `/attendance/replacement/${id}/reject`, { data: { reason } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['replacement'] }),
    });
    const handleRepReject = (id) => setRejecting({ kind: 'rep', id });
    const confirmReject = (reason) => {
        const target = rejecting;
        setRejecting(null);
        if (!target) return;
        ({ reg: regReject, ot: otReject, rep: repReject }[target.kind]).mutate({ id: target.id, reason });
    };

    /* ── Documents, decisions with a claim dialog, and "Request re-upload" ── */
    const [viewing, setViewing] = useState(null);   // { kind: 'ot'|'rep', row }
    const [deciding, setDeciding] = useState(null); // an overtime claim being approved
    const [returning, setReturning] = useState(null); // { kind, id }
    const [returnError, setReturnError] = useState('');
    const refreshAll = () => { qc.invalidateQueries({ queryKey: ['overtime'] }); qc.invalidateQueries({ queryKey: ['replacement'] }); };
    const approveOt = (o) => (o.legacy ? otApprove.mutate({ id: o.id, grant_comp_off: false }) : setDeciding(o));
    const returnMut = useMutation({
        mutationFn: ({ kind, id, reason }) => requestReupload(kind === 'ot' ? 'overtime' : 'replacement', id, reason),
        onSuccess: () => { setReturning(null); setViewing(null); setReturnError(''); refreshAll(); },
        onError: (e) => setReturnError(e.message || 'Could not return the application.'),
    });
    const decideOt = useMutation({
        mutationFn: ({ id, ...data }) => requestJson('post', `/attendance/overtime/${id}/approve`, { data }),
        onSuccess: () => { setDeciding(null); refreshAll(); },
    });
    const returnedBadge = (r) => r.attachments?.returned_for_reupload && <Badge soft color="warning">Returned for re-upload</Badge>;
    const docButton = (kind, r) => (
        <Button size="sm" color="default" variant="outline" disabled={!r.attachments?.files?.length} onClick={() => setViewing({ kind, row: r })} aria-label={`View documents of ${r.user?.name || `#${r.user_id}`}`}>
            View{r.attachments?.files?.filter((f) => !f.superseded).length > 0 ? ` (${r.attachments.files.filter((f) => !f.superseded).length})` : ''}
        </Button>
    );
    const reuploadButton = (kind, r) => r.status === 'pending' && !r.attachments?.returned_for_reupload && can('overtime.request_reupload') && (
        <Button size="sm" color="default" variant="outline" onClick={() => { setReturnError(''); setReturning({ kind, id: r.id }); }}>Request re-upload</Button>
    );

    const regs = regData?.requests || [];
    const ots  = otData?.requests  || [];
    const reps = Array.isArray(repData) ? repData : (repData?.requests || []);

    // Slice for local pagination
    const paginatedRegs = regs.slice((regPage - 1) * itemsPerPage, regPage * itemsPerPage);
    const paginatedOts = ots.slice((otPage - 1) * itemsPerPage, otPage * itemsPerPage);

    const subTabs = [
        { key: 'regularizations', label: 'Regularizations', ...(regs.length > 0 ? { count: regs.length, tone: 'danger' } : {}) },
        { key: 'overtime', label: 'Overtime', ...(ots.length > 0 ? { count: ots.length, tone: 'danger' } : {}) },
        { key: 'replacement', label: 'Replacement leave', ...(reps.length > 0 ? { count: reps.length, tone: 'danger' } : {}) },
        { key: 'swaps', label: 'Swap Requests' },
        { key: 'exceptions', label: 'Punch Exceptions' },
    ];
    const activeSub = subTabs.some(t => t.key === subTab) ? subTab : 'regularizations';
    const panel = (key) => ({ role: 'tabpanel', id: panelId(TABS_ID, key), 'aria-labelledby': tabId(TABS_ID, key) });
    const emptyRow = (cols, text) => (
        <tr><td colSpan={cols} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><p className="cy-empty__text">{text}</p></div></td></tr>
    );
    const approvalActions = (pending, onApprove, onReject, approveBusy, rejectBusy, who) => pending && (
        <span className="cy-actions">
            <Button size="sm" color="success" loading={approveBusy} onClick={onApprove} aria-label={`Approve ${who}`}>Approve</Button>
            <Button size="sm" color="danger" variant="outline" loading={rejectBusy} onClick={onReject} aria-label={`Reject ${who}`}>Reject</Button>
        </span>
    );

    /* ── render ───────────────────────────────────────────── */
    return (
        <div className="dl-page">
            <div className="dl-row">
                <div className="dl-col dl-col--12">
                    <Card id="attendance-approvals" title="Approvals" flush>
                        <div className="cy-toolbar" role="toolbar" aria-label="Approvals filters">
                            <div className="cy-toolbar__group">
                                <Segmented
                                    label="Status"
                                    value={statusFilter}
                                    onChange={setStatusFilter}
                                    options={[{ value: 'pending', label: 'Pending' }, { value: 'approved', label: 'Approved' }, { value: 'rejected', label: 'Rejected' }, { value: 'all', label: 'All' }]}
                                />
                            </div>
                        </div>

                        <Tabs tabs={subTabs} value={activeSub} onChange={setSubTab} idPrefix={TABS_ID} label="Approval types" className="cy-tabs--scroll" />

                        {activeSub === 'regularizations' && (
                            <div {...panel('regularizations')}>
                                <div className="cy-dt" tabIndex={0} role="region" aria-label="Regularization requests, scrollable">
                                    <table className="cy-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Requester</th><th scope="col">Date</th><th scope="col">Type</th><th scope="col">Punch-in</th>
                                                <th scope="col">Punch-out</th><th scope="col">Reason</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {regLoading && emptyRow(8, 'Loading…')}
                                            {!regLoading && regs.length === 0 && emptyRow(8, `No ${emptyLabel}regularization requests.`)}
                                            {paginatedRegs.map(r => (
                                                <tr key={r.id}>
                                                    <td><b>{r.user?.name || `#${r.user_id}`}</b></td>
                                                    <td className="cy-nowrap">{r.date}</td>
                                                    <td><Badge soft color="secondary">{r.type || '—'}</Badge></td>
                                                    <td>{r.requested_punchin || '—'}</td>
                                                    <td>{r.requested_punchout || '—'}</td>
                                                    <td className="cy-wrap">{r.reason || '—'}</td>
                                                    <td><Badge soft color={statusColor[r.status] || 'secondary'}>{r.status}</Badge></td>
                                                    <td>{approvalActions(r.status === 'pending', () => regApprove.mutate({ id: r.id }), () => handleRegReject(r.id), regApprove.isPending, regReject.isPending, r.user?.name || `#${r.user_id}`)}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {regs.length > itemsPerPage && (
                                    <div className="cy-card-foot-inline">
                                        <Pagination pagination={{ currentPage: regPage, perPage: itemsPerPage, total: regs.length }} onPageChange={setRegPage} loading={regLoading} label="Regularization pages" />
                                    </div>
                                )}
                            </div>
                        )}

                        {activeSub === 'overtime' && (
                            <div {...panel('overtime')}>
                                <div className="cy-dt" tabIndex={0} role="region" aria-label="Overtime claims, scrollable">
                                    <table className="cy-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Requester</th><th scope="col">Date</th><th scope="col">Claim</th><th scope="col">Document</th>
                                                <th scope="col">Note</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {otLoading && emptyRow(7, 'Loading…')}
                                            {!otLoading && ots.length === 0 && emptyRow(7, `No ${emptyLabel}overtime claims.`)}
                                            {paginatedOts.map(o => (
                                                <tr key={o.id}>
                                                    <td><b>{o.user?.name || `#${o.user_id}`}</b></td>
                                                    <td className="cy-nowrap">{o.date}</td>
                                                    <td>
                                                        {o.legacy
                                                            ? <><Badge soft color="secondary">legacy (hours)</Badge> {formatMinutes(o.requested_minutes)}</>
                                                            : <>
                                                                <b>{o.day_credit === 1 ? '1 day' : `${o.day_credit} day`}</b> · {TYPE[o.claim_type] ?? o.claim_type} · {COMP[o.compensation] ?? '—'}
                                                                {o.end_time_declared && <div><Badge soft color="warning">No punch-out, end time declared by the employee</Badge></div>}
                                                                {o.credit_adjust_reason && <div className="cy-row__sub">Adjusted from {o.original_day_credit}: {o.credit_adjust_reason}</div>}
                                                            </>}
                                                    </td>
                                                    <td>{o.legacy ? '—' : docButton('ot', o)} {returnedBadge(o)}</td>
                                                    <td className="cy-wrap">{o.reason || '—'}</td>
                                                    <td><Badge soft color={statusColor[o.status] || 'secondary'}>{o.status}</Badge></td>
                                                    <td>
                                                        {o.status === 'pending' && (
                                                            <span className="cy-actions">
                                                                <Button size="sm" color="success" disabled={!!o.attachments?.returned_for_reupload} loading={otApprove.isPending} onClick={() => approveOt(o)} aria-label={`Approve ${o.user?.name || `#${o.user_id}`}`}>Approve</Button>
                                                                <Button size="sm" color="danger" variant="outline" disabled={!!o.attachments?.returned_for_reupload} loading={otReject.isPending} onClick={() => handleOtReject(o.id)} aria-label={`Reject ${o.user?.name || `#${o.user_id}`}`}>Reject</Button>
                                                                {!o.legacy && reuploadButton('ot', o)}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                                {ots.length > itemsPerPage && (
                                    <div className="cy-card-foot-inline">
                                        <Pagination pagination={{ currentPage: otPage, perPage: itemsPerPage, total: ots.length }} onPageChange={setOtPage} loading={otLoading} label="Overtime pages" />
                                    </div>
                                )}
                            </div>
                        )}

                        {activeSub === 'replacement' && (
                            <div {...panel('replacement')}>
                                <div className="cy-dt" tabIndex={0} role="region" aria-label="Replacement leave requests, scrollable">
                                    <table className="cy-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Requester</th><th scope="col">Replacement date</th><th scope="col">Length</th><th scope="col">For rest day worked</th>
                                                <th scope="col">Document</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {repLoading && emptyRow(7, 'Loading…')}
                                            {!repLoading && reps.length === 0 && emptyRow(7, `No ${emptyLabel}replacement leave requests.`)}
                                            {reps.map(r => (
                                                <tr key={r.id}>
                                                    <td><b>{r.user?.name || `#${r.user_id}`}</b></td>
                                                    <td className="cy-nowrap">{String(r.replacement_date).slice(0, 10)}</td>
                                                    <td>{r.days === 1 ? 'Full day' : 'Half day'}</td>
                                                    <td className="cy-wrap">{(r.allocations || []).map(a => a.work_date).join(', ')}</td>
                                                    <td>{docButton('rep', r)} {returnedBadge(r)}</td>
                                                    <td><Badge soft color={statusColor[r.status] || 'secondary'}>{r.status}</Badge></td>
                                                    <td>
                                                        {r.status === 'pending' && (
                                                            <span className="cy-actions">
                                                                <Button size="sm" color="success" disabled={!!r.attachments?.returned_for_reupload} loading={repApprove.isPending} onClick={() => repApprove.mutate({ id: r.id })}>Approve</Button>
                                                                <Button size="sm" color="danger" variant="outline" disabled={!!r.attachments?.returned_for_reupload} onClick={() => handleRepReject(r.id)}>Reject</Button>
                                                                {reuploadButton('rep', r)}
                                                            </span>
                                                        )}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        )}

                        {activeSub === 'swaps' && <div {...panel('swaps')}><SwapApprovals status={statusFilter} /></div>}
                        {activeSub === 'exceptions' && <div {...panel('exceptions')}><PunchExceptions /></div>}
                    </Card>
                </div>
            </div>
            {viewing && (
                <AttachmentViewer
                    title={`Documents of ${viewing.row.user?.name || `#${viewing.row.user_id}`}`}
                    attachments={viewing.row.attachments}
                    onClose={() => setViewing(null)}
                    actions={viewing.row.status === 'pending' && !viewing.row.attachments?.returned_for_reupload && (
                        <>
                            <Button size="sm" color="success" onClick={() => { const v = viewing; setViewing(null); if (v.kind === 'ot') approveOt(v.row); else repApprove.mutate({ id: v.row.id }); }}>Approve</Button>
                            <Button size="sm" color="danger" variant="outline" onClick={() => { const v = viewing; setViewing(null); setRejecting({ kind: v.kind, id: v.row.id }); }}>Reject</Button>
                            {can('overtime.request_reupload') && <Button size="sm" color="default" variant="outline" onClick={() => { setReturnError(''); setReturning({ kind: viewing.kind, id: viewing.row.id }); }}>Request re-upload</Button>}
                        </>
                    )}
                />
            )}
            {deciding && <OvertimeDecisionDialog claim={deciding} busy={decideOt.isPending} onClose={() => setDeciding(null)} onConfirm={(d) => decideOt.mutate({ id: deciding.id, ...d })} />}
            {returning && (
                <ReasonDialog title="Request re-upload" label="What is wrong with the document?" confirmLabel="Return to employee" busy={returnMut.isPending} error={returnError}
                    onClose={() => setReturning(null)} onConfirm={(reason) => returnMut.mutate({ ...returning, reason })} />
            )}
            {rejecting && (
                <RejectReasonDialog
                    title={{ reg: 'Reject regularization', ot: 'Reject overtime claim', rep: 'Reject replacement leave' }[rejecting.kind]}
                    onClose={() => setRejecting(null)}
                    onConfirm={confirmReject}
                />
            )}
        </div>
    );
}
