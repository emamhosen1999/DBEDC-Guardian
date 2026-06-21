import React, { useState } from 'react';
import { Box, Flex, Table, Button, Badge, Text, TextField } from '@radix-ui/themes';
import { PlusIcon } from '@radix-ui/react-icons';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { requestJson } from '@/api/client';
import { showToast } from '@/utils/toastUtils';
import PolicyForm from '@/Forms/PolicyForm';
import DateTimePicker from '@/Components/DateTimePicker';

const statusColor = { draft: 'gray', active: 'green', archived: 'amber' };

const scopeLabel = (p) => {
    if (p.scope_type === 'org') return 'Organization';
    const label = p.scope_type.charAt(0).toUpperCase() + p.scope_type.slice(1);
    return `${label} #${p.scope_id}`;
};

export default function PoliciesManager() {
    const qc = useQueryClient();
    const [formOpen, setFormOpen] = useState(false);
    const [editingPolicy, setEditingPolicy] = useState(null);
    const [simRange, setSimRange] = useState({ from: '', to: '' });
    const [simPolicy, setSimPolicy] = useState(null);
    const [simResult, setSimResult] = useState(null);
    const [simError, setSimError] = useState('');

    const { data, isLoading } = useQuery({
        queryKey: ['policies'],
        queryFn: () => requestJson('get', '/attendance/policies'),
    });

    const policies = data?.policies || [];

    const refresh = () => qc.invalidateQueries({ queryKey: ['policies'] });

    const activate = useMutation({
        mutationFn: (id) => requestJson('post', `/attendance/policies/${id}/activate`),
        onSuccess: () => {
            showToast.success('Policy activated.');
            refresh();
        },
        onError: (e) => showToast.error(e?.message || 'Failed to activate policy.'),
    });

    const simulate = useMutation({
        mutationFn: (payload) => requestJson('post', '/attendance/policies/simulate', { data: payload }),
        onSuccess: (res) => { setSimResult(res); setSimError(''); },
        onError: (e) => { setSimError(e?.message || 'Failed to simulate policy.'); setSimResult(null); },
    });

    const openNew = () => { setEditingPolicy(null); setFormOpen(true); };
    const openEdit = (p) => { setEditingPolicy(p); setFormOpen(true); };

    const runSimulation = (p) => {
        if (!simRange.from || !simRange.to) {
            showToast.error('Pick a from/to date range first.');
            return;
        }
        setSimPolicy(p.id);
        setSimResult(null);
        simulate.mutate({
            from: simRange.from,
            to: simRange.to,
            name: p.name,
            scope_type: p.scope_type,
            scope_id: p.scope_id,
            effective_from: p.effective_from,
            effective_to: p.effective_to,
            punch_strictness: p.punch_strictness,
            outside_window_minutes: p.outside_window_minutes,
            grace_tiers: p.grace_tiers,
            rounding: p.rounding,
        });
    };

    return (
        <Box mt="5">
            <Flex justify="between" align="center" mb="3">
                <Text size="3" weight="bold">Attendance Policies</Text>
                <Button size="2" onClick={openNew}>
                    <PlusIcon /> New policy
                </Button>
            </Flex>

            <Flex gap="3" align="end" mb="3" wrap="wrap">
                <Box>
                    <DateTimePicker
                        mode="dateRange"
                        label="Preview from → Preview to"
                        value={{ start: simRange.from, end: simRange.to }}
                        onChange={({ start, end }) => setSimRange({ from: start, to: end })}
                    />
                </Box>
                <Text size="1" color="gray">Pick a date range, then use "Preview impact" on a policy row below.</Text>
            </Flex>

            {isLoading ? (
                <Text size="2" color="gray">Loading policies…</Text>
            ) : (
                <Table.Root size="2" variant="surface">
                    <Table.Header>
                        <Table.Row>
                            <Table.ColumnHeaderCell>Name</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Scope</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Strictness</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Version</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell>Effective</Table.ColumnHeaderCell>
                            <Table.ColumnHeaderCell></Table.ColumnHeaderCell>
                        </Table.Row>
                    </Table.Header>
                    <Table.Body>
                        {policies.length === 0 && (
                            <Table.Row>
                                <Table.Cell colSpan={7}>
                                    <Text color="gray" size="2">No policies yet.</Text>
                                </Table.Cell>
                            </Table.Row>
                        )}
                        {policies.map(p => (
                            <React.Fragment key={p.id}>
                                <Table.Row>
                                    <Table.Cell><Text size="2">{p.name}</Text></Table.Cell>
                                    <Table.Cell><Text size="2" color="gray">{scopeLabel(p)}</Text></Table.Cell>
                                    <Table.Cell><Badge variant="soft">{p.punch_strictness}</Badge></Table.Cell>
                                    <Table.Cell><Badge color={statusColor[p.status] || 'gray'}>{p.status}</Badge></Table.Cell>
                                    <Table.Cell><Text size="2">v{p.version}</Text></Table.Cell>
                                    <Table.Cell>
                                        <Text size="2" color="gray">{p.effective_from} → {p.effective_to || '∞'}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex gap="2" justify="end">
                                            {p.status === 'draft' && (
                                                <Button size="1" variant="soft" onClick={() => openEdit(p)}>Edit</Button>
                                            )}
                                            {p.status !== 'active' && (
                                                <Button size="1" color="green" loading={activate.isPending} onClick={() => activate.mutate(p.id)}>
                                                    Activate
                                                </Button>
                                            )}
                                            <Button size="1" variant="soft" color="blue" loading={simulate.isPending && simPolicy === p.id} onClick={() => runSimulation(p)}>
                                                Preview impact
                                            </Button>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                                {simPolicy === p.id && (simResult || simError) && (
                                    <Table.Row>
                                        <Table.Cell colSpan={7}>
                                            {simError ? (
                                                <Text size="2" color="red">{simError}</Text>
                                            ) : (
                                                <Box>
                                                    <Text size="2" weight="medium">
                                                        {simResult.changed}/{simResult.days} day-statuses would change
                                                    </Text>
                                                    {simResult.samples?.length > 0 && (
                                                        <Box mt="2">
                                                            <Table.Root size="1" variant="ghost">
                                                                <Table.Header>
                                                                    <Table.Row>
                                                                        <Table.ColumnHeaderCell>User</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>Before</Table.ColumnHeaderCell>
                                                                        <Table.ColumnHeaderCell>After</Table.ColumnHeaderCell>
                                                                    </Table.Row>
                                                                </Table.Header>
                                                                <Table.Body>
                                                                    {simResult.samples.slice(0, 5).map((s, i) => (
                                                                        <Table.Row key={i}>
                                                                            <Table.Cell>#{s.user_id}</Table.Cell>
                                                                            <Table.Cell>{s.date}</Table.Cell>
                                                                            <Table.Cell><Badge variant="soft" color="gray">{s.before_status}</Badge></Table.Cell>
                                                                            <Table.Cell><Badge variant="soft">{s.after_status}</Badge></Table.Cell>
                                                                        </Table.Row>
                                                                    ))}
                                                                </Table.Body>
                                                            </Table.Root>
                                                        </Box>
                                                    )}
                                                </Box>
                                            )}
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                            </React.Fragment>
                        ))}
                    </Table.Body>
                </Table.Root>
            )}

            <PolicyForm
                open={formOpen}
                onOpenChange={setFormOpen}
                onSaved={refresh}
                policy={editingPolicy}
            />
        </Box>
    );
}
