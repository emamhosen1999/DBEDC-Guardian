import React, { useState } from 'react';
import { Box, Flex, Table, Button, IconButton, Text, Badge } from '@radix-ui/themes';
import { PlusIcon, TrashIcon } from '@radix-ui/react-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import ShiftAssignmentForm from '@/Forms/ShiftAssignmentForm';

export default function AssignmentManager({ employees = [], departments = [], designations = [] }) {
    const qc = useQueryClient();
    const [open, setOpen] = useState(false);

    const { data, isLoading } = useQuery({
        queryKey: ['shift-assignments'],
        queryFn: () => requestJson('get', '/attendance/shift-assignments'),
    });

    const assignments = data?.assignments || [];

    const refresh = () => qc.invalidateQueries({ queryKey: ['shift-assignments'] });

    const scopeLabel = (a) => {
        if (a.scope_type === 'org') return 'Organization';
        const list = a.scope_type === 'department'
            ? departments
            : a.scope_type === 'designation'
                ? designations
                : employees;
        const found = list.find(x => Number(x.id) === Number(a.scope_id));
        const name = found ? (found.name || found.title) : `#${a.scope_id}`;
        const label = a.scope_type.charAt(0).toUpperCase() + a.scope_type.slice(1);
        return `${label}: ${name}`;
    };

    const remove = async (id) => {
        if (!confirm('Delete this assignment?')) return;
        try {
            await requestJson('delete', `/attendance/shift-assignments/${id}`);
            showToast.success('Assignment deleted.');
            refresh();
        } catch (e) {
            showToast.error(e?.message || 'Failed to delete assignment.');
        }
    };

    return (
        <Box mt="5">
            <Flex justify="between" align="center" mb="3">
                <Text size="3" weight="bold">Shift Assignments</Text>
                <Button size="2" onClick={() => setOpen(true)}>
                    <PlusIcon /> Assign shift
                </Button>
            </Flex>

            {isLoading ? (
                <Text size="2" color="gray">Loading assignments…</Text>
            ) : (
                <Table.Root size="2" variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Scope</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Shift</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Effective</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Priority</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}></Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {assignments.length === 0 ? (
                            <Table.Row>
                                <Table.Cell colSpan={5}>
                                    <Text color="gray" size="2">No assignments yet.</Text>
                                </Table.Cell>
                            </Table.Row>
                        ) : assignments.map(a => (
                            <Table.Row key={a.id}>
                                <Table.Cell>
                                    <Text size="2">{scopeLabel(a)}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    {a.shift
                                        ? <Badge>{a.shift.code}</Badge>
                                        : a.rotation_pattern
                                            ? <Text size="2" color="gray">Pattern: {a.rotation_pattern.name}</Text>
                                            : <Text size="2" color="gray">—</Text>
                                    }
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2" color="gray">
                                        {a.effective_from} → {a.effective_to || '∞'}
                                    </Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Text size="2">{a.priority ?? 0}</Text>
                                </Table.Cell>
                                <Table.Cell>
                                    <Flex justify="end">
                                        <IconButton size="1" variant="ghost" color="red" onClick={() => remove(a.id)}>
                                            <TrashIcon />
                                        </IconButton>
                                    </Flex>
                                </Table.Cell>
                            </Table.Row>
                        ))}
                    </Table.Body>
                </Table.Root>
            )}

            <ShiftAssignmentForm
                open={open}
                onOpenChange={setOpen}
                onSaved={refresh}
                employees={employees}
                departments={departments}
                designations={designations}
            />
        </Box>
    );
}
