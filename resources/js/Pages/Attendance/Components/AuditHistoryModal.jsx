import React from 'react';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { Badge } from '@/Components/Cyber';
import { Dialog } from '@/Components/Cyber/Map';

/** Audit trail of one attendance record: when, what, who, why, and the before / after values. */
export default function AuditHistoryModal({ open, onOpenChange, attendanceId }) {
    const { data, isLoading } = useQuery({
        queryKey: ['audit', attendanceId],
        queryFn: () => requestJson('get', `/attendance/${attendanceId}/audit`),
        enabled: open && !!attendanceId,
    });
    const logs = data?.logs || [];
    if (!open) return null;

    return (
        <Dialog title="Audit history" onClose={() => onOpenChange(false)}>
            {isLoading ? (
                <div className="cy-empty" role="status"><p className="cy-empty__text">Loading…</p></div>
            ) : (
                <div className="cy-dt">
                    <table className="cy-table">
                        <thead>
                            <tr><th scope="col">When</th><th scope="col">Action</th><th scope="col">By</th><th scope="col">Reason</th><th scope="col">Change</th></tr>
                        </thead>
                        <tbody>
                            {logs.map((l) => (
                                <tr key={l.id}>
                                    <td className="cy-nowrap">{l.created_at ? new Date(l.created_at).toLocaleString() : ''}</td>
                                    <td><Badge color="secondary">{l.action}</Badge></td>
                                    <td>{l.actor?.name || '—'}</td>
                                    <td>{l.reason || '—'}</td>
                                    <td><code>{JSON.stringify(l.before)} → {JSON.stringify(l.after)}</code></td>
                                </tr>
                            ))}
                            {logs.length === 0 && (
                                <tr><td colSpan={5} className="cy-muted">No history.</td></tr>
                            )}
                        </tbody>
                    </table>
                </div>
            )}
        </Dialog>
    );
}
