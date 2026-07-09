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

const formatDate = (dateStr) => {
    if (!dateStr) return '—';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
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
                        <Table.ColumnHeaderCell>Shift to Give</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Counterparty</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Shift to Take</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Timeline History</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {swaps.map(s => (
                        <Table.Row key={s.id}>
                            {/* Requester Info */}
                            <Table.Cell>
                                <Flex direction="column">
                                    <Text weight="semibold">{s.requester?.name || `#${s.requester_id}`}</Text>
                                    {s.reason && <Text size="1" color="gray" italic mt="1">"{s.reason}"</Text>}
                                </Flex>
                            </Table.Cell>
                            
                            {/* Requester Shift / Date */}
                            <Table.Cell>
                                <Flex direction="column">
                                    <Text size="2">{formatDate(s.requester_date)}</Text>
                                    <Badge color="blue" variant="soft" style={{ alignSelf: 'flex-start' }} mt="1">
                                        {s.requester_shift_code || 'OFF'}
                                    </Badge>
                                </Flex>
                            </Table.Cell>

                            {/* Type */}
                            <Table.Cell>
                                <Badge color={s.type === 'swap' ? 'purple' : 'orange'} variant="solid">
                                    {s.type.toUpperCase()}
                                </Badge>
                            </Table.Cell>

                            {/* Counterparty Info */}
                            <Table.Cell>
                                <Text weight="semibold">
                                    {s.counterparty?.name || (s.counterparty_id ? `#${s.counterparty_id}` : '—')}
                                </Text>
                            </Table.Cell>

                            {/* Counterparty Shift / Date */}
                            <Table.Cell>
                                {s.counterparty_id ? (
                                    <Flex direction="column">
                                        <Text size="2">{s.counterparty_date ? formatDate(s.counterparty_date) : formatDate(s.requester_date)}</Text>
                                        <Badge color="blue" variant="soft" style={{ alignSelf: 'flex-start' }} mt="1">
                                            {s.counterparty_shift_code || 'OFF'}
                                        </Badge>
                                    </Flex>
                                ) : '—'}
                            </Table.Cell>

                            {/* Timeline History */}
                            <Table.Cell style={{ verticalAlign: 'middle' }}>
                                <Flex direction="column" gap="1">
                                    {getTimelineEvents(s.approval_chain).map((event, idx) => (
                                        <Text key={idx} size="1" color="gray">
                                            • <strong>{event.label}</strong> at {event.time}
                                        </Text>
                                    ))}
                                    {(!s.approval_chain || s.approval_chain.length === 0) && (
                                        <Text size="1" color="gray" italic>—</Text>
                                    )}
                                </Flex>
                            </Table.Cell>

                            {/* Statuses */}
                            <Table.Cell>
                                <Flex direction="column" gap="1" style={{ alignItems: 'flex-start' }}>
                                    <Badge color={statusColor[s.status] || 'gray'}>
                                        {s.status.toUpperCase()}
                                    </Badge>
                                    <Badge color={cpColor[s.counterparty_status] || 'gray'} variant="outline" size="1">
                                        {cpLabel(s)}
                                    </Badge>
                                </Flex>
                            </Table.Cell>

                            {/* Actions */}
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
                        <Table.Row><Table.Cell colSpan={8}><Text color="gray" size="2">No {emptyLabel}swap requests.</Text></Table.Cell></Table.Row>
                    )}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}
