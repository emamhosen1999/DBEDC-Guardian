import React from 'react';
import { Box, Flex, Table, Button, Badge, Text } from '@radix-ui/themes';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';

const statusColor = { provisional: 'amber', accepted: 'green', rejected: 'red' };

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

    const handleReject = (id) => {
        const reason = window.prompt('Reason for rejection:');
        if (!reason || !reason.trim()) return;
        reject.mutate({ id, reason: reason.trim() });
    };

    const exceptions = data || [];

    return (
        <Box mt="5">
            <Text size="3" weight="bold">Punch Exceptions</Text>
            <Table.Root variant="surface" mt="2">
                <Table.Header>
                    <Table.Row>
                        <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Punch-in</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Punch-out</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                        <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                    </Table.Row>
                </Table.Header>
                <Table.Body>
                    {isLoading && (
                        <Table.Row>
                            <Table.Cell colSpan={7}>
                                <Text color="gray" size="2">Loading…</Text>
                            </Table.Cell>
                        </Table.Row>
                    )}
                    {!isLoading && exceptions.length === 0 && (
                        <Table.Row>
                            <Table.Cell colSpan={7}>
                                <Text color="gray" size="2">No pending punch exceptions.</Text>
                            </Table.Cell>
                        </Table.Row>
                    )}
                    {exceptions.map(e => (
                        <Table.Row key={e.id}>
                            <Table.Cell>{e.user?.name || `#${e.user_id}`}</Table.Cell>
                            <Table.Cell>{e.date}</Table.Cell>
                            <Table.Cell>{e.punchin || '—'}</Table.Cell>
                            <Table.Cell>{e.punchout || '—'}</Table.Cell>
                            <Table.Cell style={{ maxWidth: 200, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                {e.policy_exception_reason || '—'}
                            </Table.Cell>
                            <Table.Cell>
                                <Badge color={statusColor[e.policy_status] || 'gray'}>{e.policy_status}</Badge>
                            </Table.Cell>
                            <Table.Cell>
                                {e.needs_approval && (
                                    <Flex gap="2">
                                        <Button size="1" color="green" loading={approve.isPending} onClick={() => approve.mutate(e.id)}>
                                            Approve
                                        </Button>
                                        <Button size="1" color="red" variant="soft" loading={reject.isPending} onClick={() => handleReject(e.id)}>
                                            Reject
                                        </Button>
                                    </Flex>
                                )}
                            </Table.Cell>
                        </Table.Row>
                    ))}
                </Table.Body>
            </Table.Root>
        </Box>
    );
}
