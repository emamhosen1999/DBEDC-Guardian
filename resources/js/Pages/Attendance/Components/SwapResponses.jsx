import { Panel } from '@/Components/ui/Panel';
import React from 'react';
import { Flex, Text, Table, Button, Badge } from '@radix-ui/themes';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

/**
 * Employee-side peer-consent inbox: swaps where the current user is the counterparty
 * and must Accept/Decline before the request goes to a manager/admin for final approval.
 * Renders nothing when there is nothing awaiting the user's response.
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
        <Panel tinted>
            <Flex align="center" gap="2" mb="3">
                <Text size="3" weight="bold">Swap requests awaiting your response</Text>
                <Badge color="amber" variant="soft">{swaps.length}</Badge>
            </Flex>
            <Table.Root variant="surface">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Requester</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Their date</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Your date</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {swaps.map(s => (
                        <Table.Row key={s.id}>
                            <Table.Cell>{s.requester?.name || `#${s.requester_id}`}</Table.Cell>
                            <Table.Cell>{s.requester_date}</Table.Cell>
                            <Table.Cell>{s.counterparty_date || '—'}</Table.Cell>
                            <Table.Cell style={{ maxWidth: 220, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>{s.reason || '—'}</Table.Cell>
                            <Table.Cell>
                                <Flex gap="2">
                                    <Button size="1" color="green" loading={respond.isPending} onClick={() => respond.mutate({ id: s.id, decision: 'accept' })}>Accept</Button>
                                    <Button size="1" color="red" variant="soft" loading={respond.isPending} onClick={() => respond.mutate({ id: s.id, decision: 'decline' })}>Decline</Button>
                                </Flex>
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Panel>
    );
}
