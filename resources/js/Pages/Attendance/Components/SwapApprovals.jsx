import React, { useState } from 'react';
import { Alert, Badge, Button } from '@/Components/Cyber';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import { violationsFromResult, groupViolationsByEmployee, keyEmployeesById } from '../complianceViolations';

const statusColor = { pending: 'warning', approved: 'success', rejected: 'danger', cancelled: 'secondary' };
const cpColor = { pending: 'warning', accepted: 'success', declined: 'danger' };
const cpLabel = (s) => s.counterparty_status === 'pending' ? 'awaiting counterparty'
    : s.counterparty_status === 'accepted' ? 'counterparty accepted'
    : s.counterparty_status === 'declined' ? 'counterparty declined'
    : (s.counterparty_id ? '—' : 'no counterparty');

const formatDate = (dateStr) => {
    if (!dateStr) return '—';
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(dateStr) ? `${dateStr}T00:00:00` : dateStr;
    const date = new Date(normalized);
    return date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
};

// One plain-language sentence describing the trade, mirroring the mobile
// swap cards word-for-word. Reads the snapshot shift codes (persisted at
// request time), so it stays correct after an approval rewrites the roster.
const swapSummary = (s) => {
    const cp = s.counterparty?.name || (s.counterparty_id ? `#${s.counterparty_id}` : 'a teammate');
    const reqShift = s.requester_shift_code || 'OFF';
    const cpShift = s.counterparty_shift_code || 'OFF';
    const reqDate = formatDate(s.requester_date);
    const cpDate = formatDate(s.counterparty_date);
    if (s.type === 'cover') return `Gives up the ${reqShift} shift on ${reqDate} — ${cp} covers it, taking no shift in return.`;
    if (s.type === 'pickup') return `Picks up the ${cpShift} shift on ${cpDate} from ${cp}, giving up nothing in return.`;
    return `Gives up the ${reqShift} shift on ${reqDate} to ${cp}, and takes the ${cpShift} shift from them on ${cpDate} in return.`;
};

const getTimelineEvents = (chain) => {
    if (!chain || !Array.isArray(chain)) return [];
    return chain.map(e => {
        const date = new Date(e.timestamp);
        const timeStr = date.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' });
        const dateStr = date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short' });
        
        let label = '';
        if (e.action === 'requested') label = `Requested by ${e.user_name}`;
        else if (e.action === 'counterparty_accepted') label = `Accepted by ${e.user_name}`;
        else if (e.action === 'counterparty_declined') label = `Declined by ${e.user_name}`;
        else if (e.action === 'manager_approved') label = `Approved by ${e.user_name}`;
        else if (e.action === 'manager_rejected') label = `Rejected by ${e.user_name}`;
        
        return { label, time: `${dateStr} ${timeStr}` };
    });
};

export default function SwapApprovals({ status = 'pending' }) {
    const qc = useQueryClient();
    const { data } = useQuery({ queryKey: ['swaps'], queryFn: () => requestJson('get', '/attendance/swaps') });
    // Dismissible working-time compliance banner for the most recently approved swap:
    // { swapId, blocked, groups } — blocked=true means the 422 rejected it (not applied).
    const [actionViolations, setActionViolations] = useState(null);

    const allSwaps = data?.swaps || [];

    const act = useMutation({
        mutationFn: ({ id, decision }) => requestJson('post', `/attendance/swaps/${id}/${decision}`),
        onSuccess: (result, variables) => {
            qc.invalidateQueries({ queryKey: ['swaps'] });
            qc.invalidateQueries({ queryKey: ['roster'] });

            if (variables.decision !== 'approve') return;
            const swap = allSwaps.find(s => s.id === variables.id);
            const employeesById = keyEmployeesById([swap?.requester, swap?.counterparty].filter(Boolean));
            const violations = violationsFromResult(result);
            setActionViolations(violations.length > 0
                ? { swapId: variables.id, blocked: false, groups: groupViolationsByEmployee(violations, employeesById) }
                : null);
        },
        onError: (err, variables) => {
            if (variables.decision === 'approve' && err?.status === 422) {
                const violations = violationsFromResult(err);
                if (violations.length > 0) {
                    const swap = allSwaps.find(s => s.id === variables.id);
                    const employeesById = keyEmployeesById([swap?.requester, swap?.counterparty].filter(Boolean));
                    setActionViolations({ swapId: variables.id, blocked: true, groups: groupViolationsByEmployee(violations, employeesById) });
                    showToast.error(err?.message || 'This swap violates working-time compliance rules and was not applied.');
                    return;
                }
            }
            showToast.error(err?.message || 'Failed to update swap request.');
        },
    });
    // Admin acts only on swaps past the peer-consent stage. Hide peer-pending ones from the
    // 'pending' queue (they're the counterparty's concern); 'all' still shows them for visibility.
    const byStatus = status && status !== 'all' ? allSwaps.filter(s => s.status === status) : allSwaps;
    const swaps = status === 'pending' ? byStatus.filter(s => s.counterparty_status !== 'pending') : byStatus;
    const emptyLabel = status && status !== 'all' ? `${status} ` : '';
    const canAct = (s) => s.status === 'pending' && s.counterparty_status !== 'pending';

    return (
        <>
            {actionViolations && (
                <div className="cy-card-alert">
                    <Alert
                        tone={actionViolations.blocked ? 'danger' : 'warning'}
                        title={actionViolations.blocked ? 'Blocked by working-time rules - swap was not applied' : 'Swap approved - compliance warnings for review'}
                        onDismiss={() => setActionViolations(null)}
                    >
                        {actionViolations.groups.flatMap((g) => g.violations.map((v, i) => (
                            <div key={`${g.userId}-${i}`}><b>{g.name}</b>: {v.date} - {v.message}</div>
                        )))}
                    </Alert>
                </div>
            )}

            <div className="cy-dt" tabIndex={0} role="region" aria-label="Swap requests, scrollable">
                <table className="cy-table">
                    <thead>
                        <tr>
                            <th scope="col">Requester</th><th scope="col">Shift to give</th><th scope="col">Type</th><th scope="col">Counterparty</th>
                            <th scope="col">Shift to take</th><th scope="col">Timeline history</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {swaps.map(s => (
                            <tr key={s.id}>
                                <td className="cy-swaps__who">
                                    <b>{s.requester?.name || `#${s.requester_id}`}</b>
                                    <span className="cy-sub">{swapSummary(s)}</span>
                                    {s.reason && <span className="cy-sub"><i>"{s.reason}"</i></span>}
                                </td>
                                <td>
                                    {formatDate(s.requester_date)}
                                    <span className="cy-sub"><Badge soft color="info">{s.requester_shift_code || 'OFF'}</Badge></span>
                                </td>
                                <td><Badge color={s.type === 'swap' ? 'theme' : 'warning'}>{s.type.toUpperCase()}</Badge></td>
                                <td><b>{s.counterparty?.name || (s.counterparty_id ? `#${s.counterparty_id}` : '—')}</b></td>
                                <td>
                                    {s.counterparty_id ? (
                                        <>
                                            {s.counterparty_date ? formatDate(s.counterparty_date) : formatDate(s.requester_date)}
                                            <span className="cy-sub"><Badge soft color="info">{s.counterparty_shift_code || 'OFF'}</Badge></span>
                                        </>
                                    ) : '—'}
                                </td>
                                <td>
                                    {getTimelineEvents(s.approval_chain).map((event, idx) => (
                                        <span key={idx} className="cy-sub">• <b>{event.label}</b> at {event.time}</span>
                                    ))}
                                    {(!s.approval_chain || s.approval_chain.length === 0) && <span className="cy-muted">—</span>}
                                </td>
                                <td>
                                    <Badge soft color={statusColor[s.status] || 'secondary'}>{s.status.toUpperCase()}</Badge>
                                    <span className="cy-sub"><Badge outline color={cpColor[s.counterparty_status] || 'secondary'}>{cpLabel(s)}</Badge></span>
                                </td>
                                <td>
                                    {canAct(s) && (
                                        <span className="cy-actions">
                                            <Button size="sm" color="success" loading={act.isPending && act.variables?.id === s.id && act.variables?.decision === 'approve'} onClick={() => act.mutate({ id: s.id, decision: 'approve' })} aria-label={`Approve swap of ${s.requester?.name || `#${s.requester_id}`}`}>Approve</Button>
                                            <Button size="sm" color="danger" variant="outline" loading={act.isPending && act.variables?.id === s.id && act.variables?.decision === 'reject'} onClick={() => act.mutate({ id: s.id, decision: 'reject' })} aria-label={`Reject swap of ${s.requester?.name || `#${s.requester_id}`}`}>Reject</Button>
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                        {swaps.length === 0 && (
                            <tr><td colSpan={8} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><p className="cy-empty__text">No {emptyLabel}swap requests.</p></div></td></tr>
                        )}
                    </tbody>
                </table>
            </div>
        </>
    );
}
