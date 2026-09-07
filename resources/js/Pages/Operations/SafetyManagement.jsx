import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, TextArea, Switch, Callout } from '@radix-ui/themes';
import { ShieldExclamationIcon, PlusIcon, ExclamationTriangleIcon, HeartIcon, ClockIcon, CheckBadgeIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function SafetyManagement({ auth, incidents, stats, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [showCreate, setShowCreate] = useState(false);
    const [statusModal, setStatusModal] = useState(null);

    // Create form
    const [title, setTitle] = useState('');
    const [incidentType, setIncidentType] = useState('near_miss');
    const [severity, setSeverity] = useState('minor');
    const [location, setLocation] = useState('');
    const [description, setDescription] = useState('');
    const [immediateAction, setImmediateAction] = useState('');
    const [ppeWorn, setPpeWorn] = useState(true);
    const [toolboxDone, setToolboxDone] = useState(false);

    // Status update form
    const [newStatus, setNewStatus] = useState('');
    const [rootCause, setRootCause] = useState('');
    const [correctiveAction, setCorrectiveAction] = useState('');

    const incidentList = incidents?.data || [];

    const handleCreate = (e) => {
        e.preventDefault();
        router.post('/om/safety', {
            title, incident_type: incidentType, severity,
            location: location || null,
            description: description || null,
            immediate_action_taken: immediateAction || null,
            ppe_worn: ppeWorn,
            toolbox_talk_done: toolboxDone,
        }, {
            onSuccess: () => { setShowCreate(false); setTitle(''); setDescription(''); },
            onError: showOperationMutationErrors,
        });
    };

    const handleStatusUpdate = () => {
        router.post(`/om/safety/${statusModal.id}/status`, {
            status: newStatus,
            root_cause: rootCause || null,
            corrective_action: correctiveAction || null,
        }, {
            onSuccess: () => { setStatusModal(null); setRootCause(''); setCorrectiveAction(''); },
            onError: showOperationMutationErrors,
        });
    };

    const severityColor = (s) => ({
        negligible: 'gray', minor: 'blue', moderate: 'amber', major: 'orange', catastrophic: 'red',
    }[s] || 'gray');

    const typeLabel = (t) => t?.replace(/_/g, ' ');

    return (
        <App auth={auth}>
            <Head title="Safety Management" />
            <Box p="5">
                <Flex justify="between" align="center" mb="4">
                    <Box>
                        <Heading size="6" weight="bold">
                            <ShieldExclamationIcon className="inline h-6 w-6 mr-2" />
                            Safety Management
                        </Heading>
                        <Text size="2" color="gray">Incident reports, near-miss logging, PPE compliance, safety KPIs</Text>
                    </Box>
                    {canManage && (
                        <Dialog.Root open={showCreate} onOpenChange={setShowCreate}>
                            <Dialog.Trigger>
                                <Button color="red"><PlusIcon className="h-4 w-4" /> Report Safety Incident</Button>
                            </Dialog.Trigger>
                            <Dialog.Content maxWidth="550px">
                                <Dialog.Title>Report Safety Incident</Dialog.Title>
                                <Dialog.Description size="2" color="gray" mb="3">
                                    Log workplace injuries, near-misses, PPE violations, and environmental incidents
                                </Dialog.Description>
                                <form onSubmit={handleCreate}>
                                    <Flex direction="column" gap="3">
                                        <TextField.Root placeholder="e.g. Worker slipped on wet surface near Ch 14+200" value={title} onChange={e => setTitle(e.target.value)} required />
                                        <Flex gap="3">
                                            <Select.Root value={incidentType} onValueChange={setIncidentType}>
                                                <Select.Trigger placeholder="Incident Type" />
                                                <Select.Content>
                                                    <Select.Item value="near_miss">Near Miss</Select.Item>
                                                    <Select.Item value="workplace_injury">Workplace Injury</Select.Item>
                                                    <Select.Item value="vehicle_incident">Vehicle Incident</Select.Item>
                                                    <Select.Item value="hazardous_material">Hazardous Material</Select.Item>
                                                    <Select.Item value="fall_from_height">Fall from Height</Select.Item>
                                                    <Select.Item value="electrical_hazard">Electrical Hazard</Select.Item>
                                                    <Select.Item value="traffic_zone_breach">Traffic Zone Breach</Select.Item>
                                                    <Select.Item value="ppe_violation">PPE Violation</Select.Item>
                                                    <Select.Item value="environmental_spill">Environmental Spill</Select.Item>
                                                    <Select.Item value="fire_emergency">Fire Emergency</Select.Item>
                                                    <Select.Item value="other">Other</Select.Item>
                                                </Select.Content>
                                            </Select.Root>
                                            <Select.Root value={severity} onValueChange={setSeverity}>
                                                <Select.Trigger placeholder="Severity" />
                                                <Select.Content>
                                                    <Select.Item value="negligible">Negligible</Select.Item>
                                                    <Select.Item value="minor">Minor</Select.Item>
                                                    <Select.Item value="moderate">Moderate</Select.Item>
                                                    <Select.Item value="major">Major</Select.Item>
                                                    <Select.Item value="catastrophic">Catastrophic</Select.Item>
                                                </Select.Content>
                                            </Select.Root>
                                        </Flex>
                                        <TextField.Root placeholder="Location / Chainage" value={location} onChange={e => setLocation(e.target.value)} />
                                        <TextArea placeholder="Describe what happened..." value={description} onChange={e => setDescription(e.target.value)} rows={2} />
                                        <TextArea placeholder="Immediate action taken..." value={immediateAction} onChange={e => setImmediateAction(e.target.value)} rows={2} />
                                        <Flex gap="4">
                                            <Flex align="center" gap="2">
                                                <Switch checked={ppeWorn} onCheckedChange={setPpeWorn} />
                                                <Text size="2">PPE Worn</Text>
                                            </Flex>
                                            <Flex align="center" gap="2">
                                                <Switch checked={toolboxDone} onCheckedChange={setToolboxDone} />
                                                <Text size="2">Toolbox Talk Done</Text>
                                            </Flex>
                                        </Flex>
                                    </Flex>
                                    <Flex justify="end" gap="2" mt="4">
                                        <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                                        <Button type="submit" color="red">Submit Report</Button>
                                    </Flex>
                                </form>
                            </Dialog.Content>
                        </Dialog.Root>
                    )}
                </Flex>

                {/* Safety KPIs */}
                <StatsCards stats={[
                    { label: 'Days Since Last Injury', value: stats?.days_since_last_injury ?? '∞', icon: HeartIcon, color: 'green' },
                    { label: 'Open Incidents', value: stats?.open_incidents ?? 0, icon: ShieldExclamationIcon, color: stats?.open_incidents > 0 ? 'red' : 'green' },
                    { label: 'Near Misses', value: stats?.near_misses ?? 0, icon: ExclamationTriangleIcon, color: 'amber' },
                    { label: 'PPE Violations', value: stats?.ppe_violations ?? 0, icon: ShieldExclamationIcon, color: stats?.ppe_violations > 0 ? 'red' : 'green' },
                    { label: 'Lost Time (hrs)', value: stats?.lost_time_hours ?? 0, icon: ClockIcon, color: 'orange' },
                    { label: 'Toolbox Compliance', value: `${stats?.toolbox_compliance_pct ?? 100}%`, icon: CheckBadgeIcon, color: 'blue' },
                ]} />

                {/* Incidents Table */}
                <Panel mt="4">
                    <Table.Root>
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Safety #</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Title</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Severity</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>PPE</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                {canManage && <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {incidentList.map(inc => (
                                <Table.Row key={inc.id}>
                                    <Table.Cell><Text weight="bold" size="2" color="red">{inc.safety_number}</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{inc.title}</Text></Table.Cell>
                                    <Table.Cell><Badge variant="soft" size="1">{typeLabel(inc.incident_type)}</Badge></Table.Cell>
                                    <Table.Cell><Badge color={severityColor(inc.severity)} size="1">{inc.severity?.toUpperCase()}</Badge></Table.Cell>
                                    <Table.Cell><Text size="2">{inc.location || inc.chainage || '—'}</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{inc.occurred_at ? new Date(inc.occurred_at).toLocaleDateString() : '—'}</Text></Table.Cell>
                                    <Table.Cell><Badge color={inc.ppe_worn ? 'green' : 'red'} size="1">{inc.ppe_worn ? 'YES' : 'NO'}</Badge></Table.Cell>
                                    <Table.Cell><Badge variant="outline" size="1">{inc.status?.replace(/_/g, ' ').toUpperCase()}</Badge></Table.Cell>
                                    {canManage && (
                                        <Table.Cell>
                                            {inc.status !== 'closed' && (
                                                <Button size="1" variant="soft" color="blue" onClick={() => {
                                                    setStatusModal(inc);
                                                    setNewStatus(inc.status === 'reported' ? 'investigating' : inc.status === 'investigating' ? 'corrective_action' : 'closed');
                                                }}>
                                                    {inc.status === 'reported' ? 'Investigate' : inc.status === 'investigating' ? 'Action' : 'Close'}
                                                </Button>
                                            )}
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            ))}
                            {incidentList.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={9}>
                                        <Text align="center" color="gray" size="2" style={{display:'block', padding:'24px'}}>
                                            No safety incidents recorded. Stay safe! 🦺
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>

                {/* Status Update Modal */}
                <Dialog.Root open={!!statusModal} onOpenChange={(open) => !open && setStatusModal(null)}>
                    <Dialog.Content maxWidth="450px">
                        <Dialog.Title>Update {statusModal?.safety_number}</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Moving to: {newStatus?.replace(/_/g, ' ')}
                        </Dialog.Description>
                        <Flex direction="column" gap="3">
                            {newStatus === 'investigating' && (
                                <TextArea placeholder="Root cause analysis..." value={rootCause} onChange={e => setRootCause(e.target.value)} rows={3} />
                            )}
                            {(newStatus === 'corrective_action' || newStatus === 'closed') && (
                                <TextArea placeholder="Corrective actions taken/planned..." value={correctiveAction} onChange={e => setCorrectiveAction(e.target.value)} rows={3} />
                            )}
                        </Flex>
                        <Flex justify="end" gap="2" mt="3">
                            <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                            <Button color="blue" onClick={handleStatusUpdate}>Update Status</Button>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
