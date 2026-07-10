import React, { useState } from 'react';
import { Box, Flex, Table, Button, Badge, Text, Checkbox, SegmentedControl, Tabs } from '@radix-ui/themes';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import PunchExceptions from './PunchExceptions';
import SwapApprovals from './SwapApprovals';
import TablePagination from '@/Components/TablePagination.jsx';

const statusColor = { pending: 'amber', approved: 'green', rejected: 'red', cancelled: 'gray' };

function formatMinutes(mins) {
    if (!mins) return '—';
    const h = Math.floor(mins / 60);
    const m = mins % 60;
    return h > 0 ? `${h}h ${m}m` : `${m}m`;
}

export default function ApprovalsInbox() {
    const qc = useQueryClient();

    /* Shared status filter across the inbox (Pending / Approved / Rejected / All). */
    const [statusFilter, setStatusFilter] = useState('pending');
    const [subTab, setSubTab] = useState('regularizations');
    const emptyLabel = statusFilter === 'all' ? '' : `${statusFilter} `;

    // Pagination states
    const [regPage, setRegPage] = useState(1);
    const [otPage, setOtPage] = useState(1);
    const itemsPerPage = 10;

    /* ── Regularizations ───────────────────────────────────── */
    const { data: regData, isLoading: regLoading } = useQuery({
        queryKey: ['regularizations', statusFilter],
        queryFn: () => requestJson('get', '/attendance/regularizations/pending', { params: { status: statusFilter } }),
    });

    const regApprove = useMutation({
        mutationFn: ({ id }) =>
            requestJson('post', `/attendance/regularizations/${id}/approve`, { data: {} }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['regularizations'] }),
    });

    const regReject = useMutation({
        mutationFn: ({ id, reason }) =>
            requestJson('post', `/attendance/regularizations/${id}/reject`, { data: { reason } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['regularizations'] }),
    });

    const handleRegReject = (id) => {
        const reason = window.prompt('Reason for rejection:');
        if (!reason || !reason.trim()) return;
        regReject.mutate({ id, reason: reason.trim() });
    };

    /* ── Overtime ──────────────────────────────────────────── */
    const { data: otData, isLoading: otLoading } = useQuery({
        queryKey: ['overtime', statusFilter],
        queryFn: () => requestJson('get', '/attendance/overtime/pending', { params: { status: statusFilter } }),
    });

    const otApprove = useMutation({
        mutationFn: ({ id, grant_comp_off }) =>
            requestJson('post', `/attendance/overtime/${id}/approve`, { data: { grant_comp_off } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['overtime'] }),
    });

    const otReject = useMutation({
        mutationFn: ({ id, reason }) =>
            requestJson('post', `/attendance/overtime/${id}/reject`, { data: { reason } }),
        onSuccess: () => qc.invalidateQueries({ queryKey: ['overtime'] }),
    });

    const handleOtReject = (id) => {
        const reason = window.prompt('Reason for rejection:');
        if (!reason || !reason.trim()) return;
        otReject.mutate({ id, reason: reason.trim() });
    };

    /* per-row comp-off checkbox state */
    const [compOff, setCompOff] = useState({});
    const toggleCompOff = (id) => setCompOff(prev => ({ ...prev, [id]: !prev[id] }));

    const regs = regData?.requests || [];
    const ots  = otData?.requests  || [];

    // Slice for local pagination
    const paginatedRegs = regs.slice((regPage - 1) * itemsPerPage, regPage * itemsPerPage);
    const paginatedOts = ots.slice((otPage - 1) * itemsPerPage, otPage * itemsPerPage);

    // Reset pages on filter change
    React.useEffect(() => {
        setRegPage(1);
        setOtPage(1);
    }, [statusFilter]);

    /* ── render ───────────────────────────────────────────── */
    return (
        <Box>
            {/* ── Status filter ───────────────────────────── */}
            <Flex align="center" justify="between" gap="3" mb="4" wrap="wrap">
                <Flex align="center" gap="3">
                    <Text size="2" weight="medium" color="gray">Status</Text>
                    <SegmentedControl.Root value={statusFilter} onValueChange={setStatusFilter} size="1">
                        <SegmentedControl.Item value="pending">Pending</SegmentedControl.Item>
                        <SegmentedControl.Item value="approved">Approved</SegmentedControl.Item>
                        <SegmentedControl.Item value="rejected">Rejected</SegmentedControl.Item>
                        <SegmentedControl.Item value="all">All</SegmentedControl.Item>
                    </SegmentedControl.Root>
                </Flex>
            </Flex>

            {/* ── Sub-tabs ────────────────────────────────── */}
            <Tabs.Root value={subTab} onValueChange={setSubTab}>
                <Tabs.List style={{ marginBottom: 'var(--space-3)' }}>
                    <Tabs.Trigger value="regularizations">
                        Regularizations
                        {regs.length > 0 && (
                            <Badge color="red" variant="solid" size="1" style={{ marginLeft: 6 }}>
                                {regs.length}
                            </Badge>
                        )}
                    </Tabs.Trigger>
                    <Tabs.Trigger value="overtime">
                        Overtime
                        {ots.length > 0 && (
                            <Badge color="red" variant="solid" size="1" style={{ marginLeft: 6 }}>
                                {ots.length}
                            </Badge>
                        )}
                    </Tabs.Trigger>
                    <Tabs.Trigger value="swaps">Swap Requests</Tabs.Trigger>
                    <Tabs.Trigger value="exceptions">Punch Exceptions</Tabs.Trigger>
                </Tabs.List>

                {/* Regularizations Tab */}
                <Tabs.Content value="regularizations">
                    <Box style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden' }}>
                        <Table.Root size="2" variant="ghost">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Requester</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Punch-in</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Punch-out</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {regLoading && (
                                    <Table.Row>
                                        <Table.Cell colSpan={8}>
                                            <Text color="gray" size="2">Loading…</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                                {!regLoading && regs.length === 0 && (
                                    <Table.Row>
                                        <Table.Cell colSpan={8}>
                                            <Text color="gray" size="2">No {emptyLabel}regularization requests.</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                                {paginatedRegs.map(r => (
                                    <Table.Row key={r.id}>
                                        <Table.Cell><Text weight="medium">{r.user?.name || `#${r.user_id}`}</Text></Table.Cell>
                                        <Table.Cell>{r.date}</Table.Cell>
                                        <Table.Cell><Badge color="gray" variant="soft">{r.type || '—'}</Badge></Table.Cell>
                                        <Table.Cell>{r.requested_punchin || '—'}</Table.Cell>
                                        <Table.Cell>{r.requested_punchout || '—'}</Table.Cell>
                                        <Table.Cell style={{ maxWidth: 200, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                            {r.reason || '—'}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge color={statusColor[r.status] || 'gray'}>{r.status}</Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            {r.status === 'pending' && (
                                                <Flex gap="2">
                                                    <Button
                                                        size="1"
                                                        color="green"
                                                        loading={regApprove.isPending}
                                                        onClick={() => regApprove.mutate({ id: r.id })}
                                                    >
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        size="1"
                                                        color="red"
                                                        variant="soft"
                                                        loading={regReject.isPending}
                                                        onClick={() => handleRegReject(r.id)}
                                                    >
                                                        Reject
                                                    </Button>
                                                </Flex>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                        {regs.length > itemsPerPage && (
                            <Box p="2" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                                <TablePagination
                                    pagination={{ currentPage: regPage, perPage: itemsPerPage, total: regs.length }}
                                    onPageChange={setRegPage}
                                    onRowsPerPageChange={() => {}}
                                    loading={regLoading}
                                />
                            </Box>
                        )}
                    </Box>
                </Tabs.Content>

                {/* Overtime Tab */}
                <Tabs.Content value="overtime">
                    <Box style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-3)', overflow: 'hidden' }}>
                        <Table.Root size="2" variant="ghost">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Requester</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Duration</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Reason</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Grant Comp-off</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {otLoading && (
                                    <Table.Row>
                                        <Table.Cell colSpan={7}>
                                            <Text color="gray" size="2">Loading…</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                                {!otLoading && ots.length === 0 && (
                                    <Table.Row>
                                        <Table.Cell colSpan={7}>
                                            <Text color="gray" size="2">No {emptyLabel}overtime requests.</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                                {paginatedOts.map(o => (
                                    <Table.Row key={o.id}>
                                        <Table.Cell><Text weight="medium">{o.user?.name || `#${o.user_id}`}</Text></Table.Cell>
                                        <Table.Cell>{o.date}</Table.Cell>
                                        <Table.Cell>{formatMinutes(o.requested_minutes)}</Table.Cell>
                                        <Table.Cell style={{ maxWidth: 200, whiteSpace: 'pre-wrap', wordBreak: 'break-word' }}>
                                            {o.reason || '—'}
                                        </Table.Cell>
                                        <Table.Cell>
                                            {o.status === 'pending' ? (
                                                <Flex align="center" gap="2">
                                                    <Checkbox
                                                        checked={!!compOff[o.id]}
                                                        onCheckedChange={() => toggleCompOff(o.id)}
                                                    />
                                                    <Text size="2">Grant comp-off</Text>
                                                </Flex>
                                            ) : (
                                                o.grant_comp_off ? <Badge color="green">Granted</Badge> : '—'
                                            )}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge color={statusColor[o.status] || 'gray'}>{o.status}</Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            {o.status === 'pending' && (
                                                <Flex gap="2">
                                                    <Button
                                                        size="1"
                                                        color="green"
                                                        loading={otApprove.isPending}
                                                        onClick={() => otApprove.mutate({ id: o.id, grant_comp_off: !!compOff[o.id] })}
                                                    >
                                                        Approve
                                                    </Button>
                                                    <Button
                                                        size="1"
                                                        color="red"
                                                        variant="soft"
                                                        loading={otReject.isPending}
                                                        onClick={() => handleOtReject(o.id)}
                                                    >
                                                        Reject
                                                    </Button>
                                                </Flex>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                        {ots.length > itemsPerPage && (
                            <Box p="2" style={{ borderTop: '1px solid var(--gray-a4)' }}>
                                <TablePagination
                                    pagination={{ currentPage: otPage, perPage: itemsPerPage, total: ots.length }}
                                    onPageChange={setOtPage}
                                    onRowsPerPageChange={() => {}}
                                    loading={otLoading}
                                />
                            </Box>
                        )}
                    </Box>
                </Tabs.Content>

                {/* Swap Requests Tab */}
                <Tabs.Content value="swaps">
                    <SwapApprovals status={statusFilter} />
                </Tabs.Content>

                {/* Punch Exceptions Tab */}
                <Tabs.Content value="exceptions">
                    <PunchExceptions />
                </Tabs.Content>
            </Tabs.Root>
        </Box>
    );
}
