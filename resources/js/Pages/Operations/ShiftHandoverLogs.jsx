import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, TextField, Dialog, Select, Separator, TextArea } from '@radix-ui/themes';
import {
    ClipboardDocumentCheckIcon,
    PlusIcon,
    ShieldCheckIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function ShiftHandoverLogs({ auth, shiftLogs, activeMetrics }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.shift.manage') || auth?.roles?.includes('Super Administrator');
    const [openModal, setOpenModal] = useState(false);
    const [shiftDate, setShiftDate] = useState(new Date().toISOString().split('T')[0]);
    const [shiftType, setShiftType] = useState('morning');
    const [weather, setWeather] = useState('clear');
    const [notes, setNotes] = useState('');
    const [exceptions, setExceptions] = useState('');

    const logs = shiftLogs?.data || [];

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post('/om/shift-logs', {
            shift_date: shiftDate,
            shift_type: shiftType,
            weather_condition: weather,
            handover_notes: notes,
            equipment_exceptions: exceptions,
        }, {
            onSuccess: () => {
                setOpenModal(false);
            },
            onError: showOperationMutationErrors,
        });
    };

    const handleAcknowledge = (log) => {
        router.post(`/om/shift-logs/${log.id}/acknowledge`, {
            lock_version: log.lock_version,
        }, {
            onError: showOperationMutationErrors,
        });
    };

    const metrics = activeMetrics || {
        open_incidents_count: 0,
        active_lane_closures_count: 0,
        cctv_offline_count: 0,
        wim_offline_count: 0,
    };

    const statItems = [
        { key: 'incidents', title: 'Open Incidents Carried', value: metrics.open_incidents_count, color: 'amber', icon: <ExclamationTriangleIcon /> },
        { key: 'closures', title: 'Active Lane Closures', value: metrics.active_lane_closures_count, color: 'blue', icon: <ClockIcon /> },
        { key: 'its_status', title: 'Offline ITS Hardware', value: `${(metrics.cctv_offline_count || 0) + (metrics.wim_offline_count || 0)} Devices`, color: 'green', icon: <CheckCircleIcon /> },
    ];

    return (
        <App auth={auth}>
            <Head title="Digital Shift Handover Logs" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--amber-a3)', borderRadius: 12, border: '1px solid var(--amber-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ClipboardDocumentCheckIcon style={{ width: 22, height: 22, color: 'var(--amber-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Digital Shift Logbook & Handover Protocol
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            TMC & O&M Shift Handovers: Carry-Over Incidents, Safety Checklists & Dual Operator Digital Signoff
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && <Button color="amber" onClick={() => setOpenModal(true)} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                    <PlusIcon width={16} height={16} /> Submit Shift Handover
                                </Button>}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '3' }} mb="4" />

                        {/* Shift Handover Table */}
                        <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Table.Root size="2" style={{ minWidth: 920, width: '100%' }}>
                                <Table.Header style={{
                                    position: 'sticky',
                                    top: 0,
                                    zIndex: 2,
                                    background: 'var(--aero-surface, var(--color-background))',
                                    backdropFilter: 'blur(8px)',
                                    boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                }}>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>Shift Code</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>Shift Date</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 100 }}>Type</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 150 }}>Outgoing Operator</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 100 }}>Weather</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 100 }}>Open Inc.</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 220 }}>Handover Notes</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 140 }}>Dual Signoff</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {logs.map((log) => (
                                        <Table.Row key={log.id} align="center">
                                            <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>{log.shift_code || `SHF-${log.id}`}</Table.Cell>
                                            <Table.Cell><Text size="2">{log.shift_date}</Text></Table.Cell>
                                            <Table.Cell>
                                                <Badge color={log.shift_type === 'morning' ? 'amber' : log.shift_type === 'evening' ? 'blue' : 'indigo'} variant="soft">
                                                    {log.shift_type?.toUpperCase()}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell><Text weight="bold">{log.operator?.name || 'Duty Operator'}</Text></Table.Cell>
                                            <Table.Cell>
                                                <Badge color={log.weather_condition === 'rain' ? 'blue' : 'gray'} variant="outline">
                                                    {log.weather_condition?.toUpperCase() || 'CLEAR'}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell style={{ fontVariantNumeric: 'tabular-nums' }}>{log.open_incidents_count}</Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" style={{ display: 'block', maxWidth: 220, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {log.handover_notes}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'right' }}>
                                                {log.is_acknowledged ? (
                                                    <Badge color="green" variant="soft">
                                                        <CheckCircleIcon width={12} height={12} /> Acknowledged
                                                    </Badge>
                                                ) : canManage ? (
                                                    <Button size="1" color="amber" onClick={() => handleAcknowledge(log)}>
                                                        Sign & Accept
                                                    </Button>
                                                ) : null}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            {/* Shift Handover Modal */}
            <Dialog.Root open={canManage && openModal} onOpenChange={setOpenModal}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Submit Shift Handover Log</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Transfer operational command, active tickets, and hardware exceptions to the incoming supervisor.
                    </Dialog.Description>
                    <form onSubmit={handleSubmit}>
                        <Flex direction="column" gap="3">
                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Shift Date</Text>
                                    <TextField.Root type="date" value={shiftDate} onChange={(e) => setShiftDate(e.target.value)} required />
                                </label>
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Shift Type</Text>
                                    <Select.Root value={shiftType} onValueChange={setShiftType}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="morning">Morning (06:00 - 14:00)</Select.Item>
                                            <Select.Item value="evening">Evening (14:00 - 22:00)</Select.Item>
                                            <Select.Item value="night">Night (22:00 - 06:00)</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Weather Condition</Text>
                                <Select.Root value={weather} onValueChange={setWeather}>
                                    <Select.Trigger style={{ width: '100%' }} />
                                    <Select.Content>
                                        <Select.Item value="clear">Clear Skies / Normal</Select.Item>
                                        <Select.Item value="rain">Heavy Rainfall / Wet Pavement</Select.Item>
                                        <Select.Item value="heavy_fog">Dense Fog / Low Visibility</Select.Item>
                                        <Select.Item value="storm_high_winds">Storm / High Winds</Select.Item>
                                    </Select.Content>
                                </Select.Root>
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Operational Handover Notes</Text>
                                <TextArea value={notes} onChange={(e) => setNotes(e.target.value)} required />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Equipment Exceptions / Outages</Text>
                                <TextField.Root value={exceptions} onChange={(e) => setExceptions(e.target.value)} />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenModal(false)}>Cancel</Button>
                                <Button type="submit" color="amber">Submit Shift Handover</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
