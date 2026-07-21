import React, { useState } from 'react';
import { Box, Flex, Table, Button, Badge, Text, Callout, IconButton } from '@radix-ui/themes';
import { ExclamationTriangleIcon, Cross2Icon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import { violationsFromResult, groupViolationsByEmployee, keyEmployeesById } from '../complianceViolations';

const statusColor = { pending: 'amber', approved: 'green', rejected: 'red', cancelled: 'gray' };
const cpColor = { pending: 'amber', accepted: 'green', declined: 'red' };
const cpLabel = (s) => s.counterparty_status === 'pending' ? 'awaiting counterparty'
    : s.counterparty_status === 'accepted' ? 'counterparty accepted'
    : s.counterparty_status === 'declined' ? 'counterparty declined'
    : (s.counterparty_id ? '—' : 'no counterparty');

const formatDate = (dateStr) => {
    if (!dateStr) return '—';
    const normalized = /^\d{4}-\d{2}-\d{2}$/.test(dateStr) ? `${dateStr}T00:00:00` : dateStr;
    const date = new Date(normalized);
    return date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' });
};

// One plain-language sentence describing the trade, mirroring the mobile
// swap cards word-for-word. Reads the snapshot shift codes (persisted at
// request time), so it stays correct after an approval rewrites the roster.
const swapSummary = (s) => {
    const cp = s.counterparty?.name || (s.counterparty_id ? `#${s.counterparty_id}` : 'a teammate');
    const reqShift = s.requester_shift_code || 'OFF';
    const cpShift = s.counterparty_shift_code || 'OFF';
    const reqDate = formatDate(s.requester_date);
    const cpDate = formatDate(s.counterparty_date);
    if (s.type === 'cover') return `Gives up the ${reqShift} shift on ${reqDate} — ${cp} covers it, taking no shift in return.`;
    if (s.type === 'pickup') return `Picks up the ${cpShift} shift on ${cpDate} from ${cp}, giving up nothing in return.`;
    return `Gives up the ${reqShift} shift on ${reqDate} to ${cp}, and takes the ${cpShift} shift from them on ${cpDate} in return.`;
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
    // Dismissible working-time compliance banner for the most recently approved swap:
    // { swapId, blocked, groups } — blocked=true means the 422 rejected it (not applied).
    const [actionViolations, setActionViolations] = useState(null);

    const allSwaps = data?.swaps || [];

    const act = useMutation({
        mutationFn: ({ id, decision }) => requestJson('post', `/attendance/swaps/${id}/${decision}`),
        onSuccess: (result, variables) => {
            qc.invalidateQueries({ queryKey: ['swaps'] });
            qc.invalidateQueries({ queryKey: ['roster'] });

            if (variables.decision !== 'approve') return;
            const swap = allSwaps.find(s => s.id === variables.id);
            const employeesById = keyEmployeesById([swap?.requester, swap?.counterparty].filter(Boolean));
            const violations = violationsFromResult(result);
            setActionViolations(violations.length > 0
                ? { swapId: variables.id, blocked: false, groups: groupViolationsByEmployee(violations, employeesById) }
                : null);
        },
        onError: (err, variables) => {
            if (variables.decision === 'approve' && err?.status === 422) {
                const violations = violationsFromResult(err);
                if (violations.length > 0) {
                    const swap = allSwaps.find(s => s.id === variables.id);
                    const employeesById = keyEmployeesById([swap?.requester, swap?.counterparty].filter(Boolean));
                    setActionViolations({ swapId: variables.id, blocked: true, groups: groupViolationsByEmployee(violations, employeesById) });
                    showToast.error(err?.message || 'This swap violates working-time compliance rules and was not applied.');
                    return;
                }
            }
            showToast.error(err?.message || 'Failed to update swap request.');
        },
    });
    // Admin acts only on swaps past the peer-consent stage. Hide peer-pending ones from the
    // 'pending' queue (they're the counterparty's concern); 'all' still shows them for visibility.
    const byStatus = status && status !== 'all' ? allSwaps.filter(s => s.status === status) : allSwaps;
    const swaps = status === 'pending' ? byStatus.filter(s => s.counterparty_status !== 'pending') : byStatus;
    const emptyLabel = status && status !== 'all' ? `${status} ` : '';
    const canAct = (s) => s.status === 'pending' && s.counterparty_status !== 'pending';

    return (
        <Box mt="5">
            <Text size="3" weight="bold">Swap Requests</Text>

            {actionViolations && (
                <Callout.Root color={actionViolations.blocked ? 'red' : 'amber'} size="1" mt="2">
                    <Callout.Icon><ExclamationTriangleIcon /></Callout.Icon>
                    <Flex justify="between" align="start" gap="3" width="100%">
                        <Box>
                            <Text size="2" weight="medium" as="div" mb="1">
                                {actionViolations.blocked
                                    ? 'Blocked by working-time rules — swap was not applied:'
                                    : 'Swap approved — compliance warnings for review:'}
                            </Text>
                            <Flex direction="column" gap="1">
                                {actionViolations.groups.flatMap((g) => g.violations.map((v, i) => (
                                    <Text key={`${g.userId}-${i}`} as="div" size="1" color="gray">
                                        <Text weight="medium">{g.name}</Text>: {v.date} — {v.message}
                                    </Text>
                                )))}
                            </Flex>
                        </Box>
                        <IconButton size="1" variant="ghost" color="gray" onClick={() => setActionViolations(null)} aria-label="Dismiss">
                            <Cross2Icon />
                        </IconButton>
                    </Flex>
                </Callout.Root>
            )}

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
                                <Flex direction="column" style={{ minWidth: 220 }}>
                                    <Text weight="semibold">{s.requester?.name || `#${s.requester_id}`}</Text>
                                    <Text size="1" color="gray" mt="1">{swapSummary(s)}</Text>
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
                                        <Button size="1" color="green" loading={act.isPending && act.variables?.id === s.id && act.variables?.decision === 'approve'} onClick={() => act.mutate({ id: s.id, decision: 'approve' })}>Approve</Button>
                                        <Button size="1" color="red" variant="soft" loading={act.isPending && act.variables?.id === s.id && act.variables?.decision === 'reject'} onClick={() => act.mutate({ id: s.id, decision: 'reject' })}>Reject</Button>
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
