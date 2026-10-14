import React, { useState } from 'react';
import { Badge, Button } from '@/Components/Cyber';
import RejectReasonDialog from './RejectReasonDialog';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const statusColor = { provisional: 'warning', accepted: 'success', rejected: 'danger' };

export default function PunchExceptions() {
    const qc = useQueryClient();
    const { data, isLoading } = useQuery({
        queryKey: ['punch-exceptions'],
        queryFn: () => requestJson('get', '/attendance/punch-exceptions/pending'),
    });

    const invalidate = () => {
        qc.invalidateQueries({ queryKey: ['punch-exceptions'] });
        qc.invalidateQueries({ queryKey: ['roster'] });
    };

    const approve = useMutation({
        mutationFn: (id) => requestJson('post', `/attendance/punch-exceptions/${id}/approve`),
        onSuccess: invalidate,
        onError: (err) => showToast.error(err?.message || 'Action failed'),
    });

    const reject = useMutation({
        mutationFn: ({ id, reason }) => requestJson('post', `/attendance/punch-exceptions/${id}/reject`, { data: { reason } }),
        onSuccess: invalidate,
        onError: (err) => showToast.error(err?.message || 'Action failed'),
    });

    /* The id being rejected while the reason dialog is open. */
    const [rejectingId, setRejectingId] = useState(null);
    const handleReject = (id) => setRejectingId(id);

    const exceptions = data || [];
    const emptyRow = (text) => (
        <tr><td colSpan={7} className="cy-cell--empty"><div className="cy-empty cy-empty--flat"><p className="cy-empty__text">{text}</p></div></td></tr>
    );

    return (
        <>
            <div className="cy-dt" tabIndex={0} role="region" aria-label="Punch exceptions, scrollable">
                <table className="cy-table">
                    <thead>
                        <tr>
                            <th scope="col">Employee</th><th scope="col">Date</th><th scope="col">Punch-in</th><th scope="col">Punch-out</th>
                            <th scope="col">Reason</th><th scope="col">Status</th><th scope="col"><span className="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        {isLoading && emptyRow('Loading…')}
                        {!isLoading && exceptions.length === 0 && emptyRow('No pending punch exceptions.')}
                        {exceptions.map(e => (
                            <tr key={e.id}>
                                <td>{e.user?.name || `#${e.user_id}`}</td>
                                <td className="cy-nowrap">{e.date}</td>
                                <td>{e.punchin || '—'}</td>
                                <td>{e.punchout || '—'}</td>
                                <td className="cy-wrap">{e.policy_exception_reason || '—'}</td>
                                <td><Badge soft color={statusColor[e.policy_status] || 'secondary'}>{e.policy_status}</Badge></td>
                                <td>
                                    {e.needs_approval && (
                                        <span className="cy-actions">
                                            <Button size="sm" color="success" loading={approve.isPending} onClick={() => approve.mutate(e.id)} aria-label={`Approve punch exception of ${e.user?.name || `#${e.user_id}`}`}>Approve</Button>
                                            <Button size="sm" color="danger" variant="outline" loading={reject.isPending} onClick={() => handleReject(e.id)} aria-label={`Reject punch exception of ${e.user?.name || `#${e.user_id}`}`}>Reject</Button>
                                        </span>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {rejectingId !== null && (
                <RejectReasonDialog
                    title="Reject punch exception"
                    onClose={() => setRejectingId(null)}
                    onConfirm={(reason) => { const id = rejectingId; setRejectingId(null); reject.mutate({ id, reason }); }}
                />
            )}
        </>
    );
}
