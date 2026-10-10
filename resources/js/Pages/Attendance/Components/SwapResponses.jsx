import React from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import { Badge, Button, Card, Icon } from '@/Components/Cyber';

/**
 * Employee-side peer-consent inbox: swaps where the current user is the counterparty and must accept or decline
 * before the request goes to a manager for final approval. A Cyber card; renders nothing when nothing is waiting.
 */
export default function SwapResponses() {
    const qc = useQueryClient();
    const { data } = useQuery({
        queryKey: ['swaps', 'awaiting-me'],
        queryFn: () => requestJson('get', '/attendance/swaps/awaiting-me'),
    });
    const swaps = data?.swaps || [];

    const respond = useMutation({
        mutationFn: ({ id, decision }) => requestJson('post', `/attendance/swaps/${id}/respond`, { data: { decision } }),
        onSuccess: (_d, v) => {
            showToast.success(v.decision === 'accept' ? 'Swap accepted — sent to your manager for approval.' : 'Swap declined.');
            qc.invalidateQueries({ queryKey: ['swaps', 'awaiting-me'] });
            qc.invalidateQueries({ queryKey: ['swaps'] });
        },
        onError: (err) => showToast.error(err?.message || 'Failed to respond to swap.'),
    });

    if (swaps.length === 0) return null;

    return (
        <Card id="employee:swap-responses" title="Swaps awaiting your response" flush actions={<Badge color="warning">{swaps.length}</Badge>}>
            <ul className="dl-list">
                {swaps.map((s) => (
                    <li key={s.id} className="dl-list__row">
                        <div className="cy-row">
                            <div>
                                <span className="cy-row__title">{s.requester?.name || `#${s.requester_id}`}</span>
                                <span className="cy-row__sub">Their date {s.requester_date} · your date {s.counterparty_date || '—'}{s.reason ? ` · ${s.reason}` : ''}</span>
                            </div>
                            <span className="cy-row__actions">
                                <Button size="sm" disabled={respond.isPending} onClick={() => respond.mutate({ id: s.id, decision: 'accept' })}><Icon name="check2-circle" /> Accept</Button>
                                <Button size="sm" variant="outline" color="secondary" disabled={respond.isPending} onClick={() => respond.mutate({ id: s.id, decision: 'decline' })}><Icon name="x-lg" /> Decline</Button>
                            </span>
                        </div>
                    </li>
                ))}
            </ul>
        </Card>
    );
}
