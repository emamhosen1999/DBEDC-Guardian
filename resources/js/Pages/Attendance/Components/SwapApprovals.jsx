import React from 'react';
import { Box, Flex, Table, Button, Badge, Text } from '@radix-ui/themes';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';

const statusColor = { pending: 'amber', approved: 'green', rejected: 'red', cancelled: 'gray' };
const cpColor = { pending: 'amber', accepted: 'green', declined: 'red' };
const cpLabel = (s) => s.counterparty_status === 'pending' ? 'awaiting counterparty'
    : s.counterparty_status === 'accepted' ? 'counterparty accepted'
    : s.counterparty_status === 'declined' ? 'counterparty declined'
    : (s.counterparty_id ? '—' : 'no counterparty');

export default function SwapApprovals({ status = 'pending' }) {
    const qc = useQueryClient();
    const { data } = useQuery({ queryKey: ['swaps'], queryFn: () => requestJson('get', '/attendance/swaps') });

    const act = useMutation({
        mutationFn: ({ id, decision }) => requestJson('post', `/attendance/swaps/${id}/${decision}`),
        onSuccess: () => {
            qc.invalidateQueries({ queryKey: ['swaps'] });
            qc.invalidateQueries({ queryKey: ['roster'] });
        },
    });

    const allSwaps = data?.swaps || [];
    // Admin acts only on swaps past the peer-consent stage. Hide peer-pending ones from the
    // 'pending' queue (they're the counterparty's concern); 'all' still shows them for visibility.
    const byStatus = status && status !== 'all' ? allSwaps.filter(s => s.status === status) : allSwaps;
    const swaps = status === 'pending' ? byStatus.filter(s => s.counterparty_status !== 'pending') : byStatus;
    const emptyLabel = status && status !== 'all' ? `${status} ` : '';
    const canAct = (s) => s.status === 'pending' && s.counterparty_status !== 'pending';

    return (
        <Box mt="5">
            <Text size="3" weight="bold">Swap Requests</Text>
            <Table.Root variant="surface" mt="2">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Requester</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Requester date</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Counterparty</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Counterparty status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {swaps.map(s => (
                        <Table.Row key={s.id}>
                            <Table.Cell>{s.requester?.name || `#${s.requester_id}`}</Table.Cell>
                            <Table.Cell>{s.requester_date}</Table.Cell>
                            <Table.Cell>{s.counterparty?.name || (s.counterparty_id ? `#${s.counterparty_id}` : '—')}{s.counterparty_date ? ` (${s.counterparty_date})` : ''}</Table.Cell>
                            <Table.Cell><Badge color={cpColor[s.counterparty_status] || 'gray'} variant="soft">{cpLabel(s)}</Badge></Table.Cell>
                            <Table.Cell><Badge color={statusColor[s.status] || 'gray'}>{s.status}</Badge></Table.Cell>
                            <Table.Cell>
                                {canAct(s) && (
                                    <Flex gap="2">
                                        <Button size="1" color="green" loading={act.isPending} onClick={() => act.mutate({ id: s.id, decision: 'approve' })}>Approve</Button>
                                        <Button size="1" color="red" variant="soft" loading={act.isPending} onClick={() => act.mutate({ id: s.id, decision: 'reject' })}>Reject</Button>
                                    </Flex>
                                )}
                            </Table.Cell>
                        </Table.Row>
                    ))}
                    {swaps.length === 0 && (
                        <Table.Row><Table.Cell colSpan={6}><Text color="gray" size="2">No {emptyLabel}swap requests.</Text></Table.Cell></Table.Row>
                    )}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}
