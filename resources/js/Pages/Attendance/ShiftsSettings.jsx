import React, { useState } from 'react';
import { Box, Flex, Table, Button, Badge, Text, IconButton, Tabs } from '@radix-ui/themes';
import { Pencil1Icon, TrashIcon, PlusIcon, LayersIcon, SymbolIcon } from '@radix-ui/react-icons';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { usePage } from '@inertiajs/react';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import ShiftForm from '@/Forms/ShiftForm';
import RotationPatternForm from '@/Forms/RotationPatternForm';
import AssignmentManager from '@/Pages/Attendance/Components/AssignmentManager';
import TablePagination from '@/Components/TablePagination.jsx';

export default function ShiftsSettings() {
    const { auth, employees = [], departments = [], designations = [] } = usePage().props;
    const isGlobalUser = auth?.isSuperAdmin || auth?.roles?.includes('Super Administrator') || auth?.roles?.includes('Administrator') || auth?.roles?.includes('HR Manager') || auth?.permissions?.includes('attendance.settings');
    const qc = useQueryClient();
    const [activeSubTab, setActiveSubTab] = useState('shifts');
    const [editing, setEditing] = useState(null);
    const [open, setOpen] = useState(false);
    const [patternOpen, setPatternOpen] = useState(false);
    const [editingPattern, setEditingPattern] = useState(null);

    // Pagination states
    const [shiftsPage, setShiftsPage] = useState(1);
    const [shiftsPerPage, setShiftsPerPage] = useState(20);
    const [patternsPage, setPatternsPage] = useState(1);
    const [patternsPerPage, setPatternsPerPage] = useState(20);

    const { data, isLoading } = useQuery({
        queryKey: ['shifts'],
        queryFn: () => requestJson('get', '/attendance/shifts'),
    });

    const shifts = data?.shifts || [];

    const { data: patternsData, isLoading: patternsLoading } = useQuery({
        queryKey: ['rotation-patterns'],
        queryFn: () => requestJson('get', '/attendance/rotation-patterns'),
    });

    const patterns = patternsData?.patterns || [];

    const refresh = () => {
        qc.invalidateQueries({ queryKey: ['shifts'] });
        qc.invalidateQueries({ queryKey: ['rotation-patterns'] });
    };

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

    const removePattern = async (p) => {
        if (!confirm(`Delete rotation pattern "${p.name}"? This cannot be undone.`)) return;
        try {
            await requestJson('delete', `/attendance/rotation-patterns/${p.id}`);
            showToast.success('Rotation pattern deleted.');
            refresh();
        } catch (err) {
            showToast.error(err?.message || 'Failed to delete rotation pattern.');
        }
    };

    const formatSequence = (definition) => {
        if (!definition || !Array.isArray(definition)) return null;
        return definition.map((day, idx) => {
            const isLast = idx === definition.length - 1;
            if (day === null || day === undefined) {
                return (
                    <Flex key={idx} align="center" style={{ display: 'inline-flex' }}>
                        <Badge color="gray">Off</Badge>
                        {!isLast && <Text size="1" color="gray" mx="1">→</Text>}
                    </Flex>
                );
            }
            const shiftObj = shifts.find(s => s.id === day);
            return (
                <Flex key={idx} align="center" style={{ display: 'inline-flex' }}>
                    <Badge style={{ background: shiftObj?.color || undefined, color: shiftObj?.color ? '#fff' : undefined }}>
                        {shiftObj ? shiftObj.code : `Shift #${day}`}
                    </Badge>
                    {!isLast && <Text size="1" color="gray" mx="1">→</Text>}
                </Flex>
            );
        });
    };

    const paginatedShifts = shifts.slice((shiftsPage - 1) * shiftsPerPage, shiftsPage * shiftsPerPage);
    const paginatedPatterns = patterns.slice((patternsPage - 1) * patternsPerPage, patternsPage * patternsPerPage);

    return (
        <Box mt="4">
            <Tabs.Root value={activeSubTab} onValueChange={setActiveSubTab}>
                <Tabs.List size="2" mb="4">
                    <Tabs.Trigger value="shifts">Shifts</Tabs.Trigger>
                    <Tabs.Trigger value="patterns">Rotation Patterns</Tabs.Trigger>
                    <Tabs.Trigger value="assignments">Shift Assignments</Tabs.Trigger>
                </Tabs.List>

                {/* Shifts Sub-tab */}
                <Tabs.Content value="shifts">
                    <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                        <Flex align="center" gap="2">
                            <LayersIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                            <Text size="3" weight="bold">Manage Shifts</Text>
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
                        <Box>
                            <Table.Root size="2" variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Name</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Code</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Window</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                        {isGlobalUser && <Table.ColumnHeaderCell>Created By</Table.ColumnHeaderCell>}
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {paginatedShifts.map(s => (
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
                                            {isGlobalUser && (
                                                <Table.Cell>
                                                    <Text size="2" color="gray">{s.creator?.name || 'System'}</Text>
                                                </Table.Cell>
                                            )}
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
                            
                            {shifts.length > 0 && (
                                <Box mt="4">
                                    <TablePagination
                                        pagination={{ currentPage: shiftsPage, perPage: shiftsPerPage, total: shifts.length }}
                                        onPageChange={setShiftsPage}
                                        onRowsPerPageChange={(v) => { setShiftsPerPage(v); setShiftsPage(1); }}
                                    />
                                </Box>
                            )}
                        </Box>
                    )}
                </Tabs.Content>

                {/* Rotation Patterns Sub-tab */}
                <Tabs.Content value="patterns">
                    <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                        <Flex align="center" gap="2">
                            <SymbolIcon style={{ color: 'var(--accent-9)', width: 18, height: 18 }} />
                            <Text size="3" weight="bold">Rotation Patterns</Text>
                        </Flex>
                        <Button size="2" onClick={() => { setEditingPattern(null); setPatternOpen(true); }}>
                            <PlusIcon /> Add pattern
                        </Button>
                    </Flex>

                    {patternsLoading ? (
                        <Text size="2" color="gray">Loading rotation patterns…</Text>
                    ) : patterns.length === 0 ? (
                        <Flex direction="column" align="center" py="5" gap="2" style={{ border: '1px dashed var(--gray-6)', borderRadius: 'var(--radius-3)' }}>
                            <Text size="2" color="gray">No rotation patterns yet.</Text>
                            <Text size="1" color="gray">Click Add pattern above to create one.</Text>
                        </Flex>
                    ) : (
                        <Box>
                            <Table.Root size="2" variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Name</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Code</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Cycle Length</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Sequence Preview</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                        {isGlobalUser && <Table.ColumnHeaderCell>Created By</Table.ColumnHeaderCell>}
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Actions</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {paginatedPatterns.map(p => (
                                        <Table.Row key={p.id}>
                                            <Table.Cell>
                                                <Text size="2" weight="medium">{p.name}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color="blue">{p.code}</Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2">{p.cycle_length_days} days</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Flex gap="1" wrap="wrap" align="center">
                                                    {formatSequence(p.definition)}
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {p.is_active ? <Badge color="green">Active</Badge> : <Badge color="gray">Inactive</Badge>}
                                            </Table.Cell>
                                            {isGlobalUser && (
                                                <Table.Cell>
                                                    <Text size="2" color="gray">{p.creator?.name || 'System'}</Text>
                                                </Table.Cell>
                                            )}
                                            <Table.Cell>
                                                <Flex gap="1" justify="end">
                                                    <IconButton size="1" variant="ghost" color="blue" onClick={() => { setEditingPattern(p); setPatternOpen(true); }}>
                                                        <Pencil1Icon />
                                                    </IconButton>
                                                    <IconButton size="1" variant="ghost" color="red" onClick={() => removePattern(p)}>
                                                        <TrashIcon />
                                                    </IconButton>
                                                </Flex>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>

                            {patterns.length > 0 && (
                                <Box mt="4">
                                    <TablePagination
                                        pagination={{ currentPage: patternsPage, perPage: patternsPerPage, total: patterns.length }}
                                        onPageChange={setPatternsPage}
                                        onRowsPerPageChange={(v) => { setPatternsPerPage(v); setPatternsPage(1); }}
                                    />
                                </Box>
                            )}
                        </Box>
                    )}
                </Tabs.Content>

                {/* Assignments Sub-tab */}
                <Tabs.Content value="assignments">
                    <AssignmentManager
                        employees={employees}
                        departments={departments}
                        designations={designations}
                    />
                </Tabs.Content>
            </Tabs.Root>

            <ShiftForm open={open} onOpenChange={setOpen} initial={editing} onSaved={refresh} />
            <RotationPatternForm open={patternOpen} onOpenChange={setPatternOpen} initial={editingPattern} onSaved={refresh} />
        </Box>
    );
}
