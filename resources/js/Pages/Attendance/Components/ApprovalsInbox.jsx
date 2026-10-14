import React, { useState } from 'react';
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { Badge, Button, Card, Checkbox, Pagination, Segmented, Tabs } from '@/Components/Cyber';
import { panelId, tabId } from '@/Components/Cyber/Tabs';
import RejectReasonDialog from './RejectReasonDialog';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import PunchExceptions from './PunchExceptions';
import SwapApprovals from './SwapApprovals';

const statusColor = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'secondary' };
const TABS_ID = 'attendance-approvals';

function formatMinutes(mins) {
    if (!mins) return '—';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export default function ApprovalsInbox() {
    const qc = useQueryClient();

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
    const confirmReject = (reason) => {
        const target = rejecting;
        setRejecting(null);
        if (!target) return;
        (target.kind === 'reg' ? regReject : otReject).mutate({ id: target.id, reason });
    };

    /* per-row comp-off checkbox state */
    const [compOff, setCompOff] = useState({});
    const toggleCompOff = (id) => setCompOff(prev => ({ ...prev, [id]: !prev[id] }));

    const regs = regData?.requests || [];
    const ots  = otData?.requests  || [];

    // Slice for local pagination
    const paginatedRegs = regs.slice((regPage - 1) * itemsPerPage, regPage * itemsPerPage);
    const paginatedOts = ots.slice((otPage - 1) * itemsPerPage, otPage * itemsPerPage);

    const subTabs = [
        { key: 'regularizations', label: 'Regularizations', ...(regs.length > 0 ? { count: regs.length, tone: 'danger' } : {}) },
        { key: 'overtime', label: 'Overtime', ...(ots.length > 0 ? { count: ots.length, tone: 'danger' } : {}) },
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
                                <div className="cy-dt" tabIndex={0} role="region" aria-label="Overtime requests, scrollable">
                                    <table className="cy-table">
                                        <thead>
                                            <tr>
                                                <th scope="col">Requester</th><th scope="col">Date</th><th scope="col">Duration</th><th scope="col">Reason</th>
                                                <th scope="col">Grant comp-off</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {otLoading && emptyRow(7, 'Loading…')}
                                            {!otLoading && ots.length === 0 && emptyRow(7, `No ${emptyLabel}overtime requests.`)}
                                            {paginatedOts.map(o => (
                                                <tr key={o.id}>
                                                    <td><b>{o.user?.name || `#${o.user_id}`}</b></td>
                                                    <td className="cy-nowrap">{o.date}</td>
                                                    <td>{formatMinutes(o.requested_minutes)}</td>
                                                    <td className="cy-wrap">{o.reason || '—'}</td>
                                                    <td>
                                                        {o.status === 'pending' ? (
                                                            <Checkbox checked={!!compOff[o.id]} onCheckedChange={() => toggleCompOff(o.id)} label="Grant comp-off" />
                                                        ) : (
                                                            o.grant_comp_off ? <Badge soft color="success">Granted</Badge> : '—'
                                                        )}
                                                    </td>
                                                    <td><Badge soft color={statusColor[o.status] || 'secondary'}>{o.status}</Badge></td>
                                                    <td>{approvalActions(o.status === 'pending', () => otApprove.mutate({ id: o.id, grant_comp_off: !!compOff[o.id] }), () => handleOtReject(o.id), otApprove.isPending, otReject.isPending, o.user?.name || `#${o.user_id}`)}</td>
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

                        {activeSub === 'swaps' && <div {...panel('swaps')}><SwapApprovals status={statusFilter} /></div>}
                        {activeSub === 'exceptions' && <div {...panel('exceptions')}><PunchExceptions /></div>}
                    </Card>
                </div>
            </div>
            {rejecting && (
                <RejectReasonDialog
                    title={rejecting.kind === 'reg' ? 'Reject regularization' : 'Reject overtime request'}
                    onClose={() => setRejecting(null)}
                    onConfirm={confirmReject}
                />
            )}
        </div>
    );
}
