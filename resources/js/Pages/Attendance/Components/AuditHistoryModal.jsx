import React from 'react';
import { Dialog, Table, Text, Badge, Code } from '@radix-ui/themes';
import { useQuery } from '@tanstack/react-query';
import { requestJson } from '@/api/client';

export default function AuditHistoryModal({ open, onOpenChange, attendanceId }) {
    const { data, isLoading } = useQuery({
        queryKey: ['audit', attendanceId],
        queryFn: () => requestJson('get', `/attendance/${attendanceId}/audit`),
        enabled: open && !!attendanceId,
    });
    const logs = data?.logs || [];

    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Content maxWidth="640px">
                <Dialog.Title>Audit History</Dialog.Title>
                {isLoading ? <Text>Loading…</Text> : (
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>When</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Action</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>By</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Change</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {logs.map(l => (
                                <Table.Row key={l.id}>
                                    <Table.Cell><Text size="1">{l.created_at ? new Date(l.created_at).toLocaleString() : ''}</Text></Table.Cell>
                                    <Table.Cell><Badge>{l.action}</Badge></Table.Cell>
                                    <Table.Cell>{l.actor?.name || '—'}</Table.Cell>
                                    <Table.Cell>{l.reason || '—'}</Table.Cell>
                                    <Table.Cell><Code size="1">{JSON.stringify(l.before)} → {JSON.stringify(l.after)}</Code></Table.Cell>
                                </Table.Row>
                            ))}
                            {logs.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={5}>
                                        <Text color="gray" size="2">No history.</Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                )}
            </Dialog.Content>
        </Dialog.Root>
    );
}
