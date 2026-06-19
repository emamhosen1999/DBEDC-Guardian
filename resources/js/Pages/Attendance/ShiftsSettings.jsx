import React, { useState } from 'react';
import { Box, Flex, Table, Button, Badge, Text, IconButton } from '@radix-ui/themes';
import { Pencil1Icon, TrashIcon, PlusIcon, LayersIcon } from '@radix-ui/react-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import ShiftForm from '@/Forms/ShiftForm';

export default function ShiftsSettings() {
    const qc = useQueryClient();
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
    });

    const shifts = data?.shifts || [];

    const refresh = () => qc.invalidateQueries({ queryKey: ['shifts'] });

    const remove = async (s) => {
        if (!confirm(`Delete shift "${s.name}"? This cannot be undone.`)) return;
        try {
            await requestJson('delete', `/attendance/shifts/${s.id}`);
            showToast.success('Shift deleted.');
            refresh();
        } catch (err) {
            showToast.error(err?.message || 'Failed to delete shift.');
        }
    };

    return (
        <Box mt="6">
            <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                <Flex align="center" gap="2">
                    <LayersIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                    <Text size="4" weight="bold">Shifts</Text>
                </Flex>
                <Button size="2" onClick={() => { setEditing(null); setOpen(true); }}>
                    <PlusIcon /> Add shift
                </Button>
            </Flex>

            {isLoading ? (
                <Text size="2" color="gray">Loading shifts…</Text>
            ) : shifts.length === 0 ? (
                <Flex direction="column" align="center" py="5" gap="2">
                    <Text size="2" color="gray">No shifts yet.</Text>
                    <Text size="1" color="gray">Click Add shift above to create one.</Text>
                </Flex>
            ) : (
                <Table.Root size="2" variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Name</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Code</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Window</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {shifts.map(s => (
                            <Table.Row key={s.id}>
                                <Table.Cell>
                                    <Text size="2" weight="medium">{s.name}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Badge style={{ background: s.color || undefined, color: s.color ? '#fff' : undefined }}>
                                        {s.code}
                                    </Badge>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2" color="gray">
                                        {s.start_time}–{s.end_time}{s.crosses_midnight ? ' (+1)' : ''}
                                    </Text>
                                </Table.Cell>
                                <Table.Cell>
                                    {s.is_active ? <Badge color="green">Active</Badge> : <Badge color="gray">Inactive</Badge>}
                                </Table.Cell>
                                <Table.Cell>
                                    <Flex gap="1" justify="end">
                                        <IconButton size="1" variant="ghost" color="blue" onClick={() => { setEditing(s); setOpen(true); }}>
                                            <Pencil1Icon />
                                        </IconButton>
                                        <IconButton size="1" variant="ghost" color="red" onClick={() => remove(s)}>
                                            <TrashIcon />
                                        </IconButton>
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            )}

            <ShiftForm open={open} onOpenChange={setOpen} initial={editing} onSaved={refresh} />
        </Box>
    );
}
