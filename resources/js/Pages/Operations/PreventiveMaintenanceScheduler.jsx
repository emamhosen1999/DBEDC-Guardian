import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, Separator, TextArea, Switch } from '@radix-ui/themes';
import { CalendarDaysIcon, PlusIcon, ClockIcon, WrenchScrewdriverIcon, ArrowPathIcon, BoltIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function PreventiveMaintenanceScheduler({ auth, schedules, stats, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [showCreate, setShowCreate] = useState(false);
    const [generating, setGenerating] = useState(false);

    // Form states
    const [title, setTitle] = useState('');
    const [description, setDescription] = useState('');
    const [assetCategory, setAssetCategory] = useState('pavement_civil');
    const [frequencyType, setFrequencyType] = useState('monthly');
    const [priority, setPriority] = useState('medium');
    const [assignedTo, setAssignedTo] = useState('');
    const [estimatedCost, setEstimatedCost] = useState('');
    const [estimatedHours, setEstimatedHours] = useState('2');
    const [chainageFrom, setChainageFrom] = useState('');
    const [chainageTo, setChainageTo] = useState('');
    const [requiresLaneClosure, setRequiresLaneClosure] = useState(false);

    const scheduleList = schedules?.data || [];

    const handleCreate = (e) => {
        e.preventDefault();
        router.post('/om/preventive-maintenance', {
            title, description, asset_category: assetCategory,
            frequency_type: frequencyType, priority,
            assigned_to: assignedTo || null,
            estimated_cost: estimatedCost || 0,
            estimated_duration_hours: estimatedHours || 2,
            chainage_from: chainageFrom || null,
            chainage_to: chainageTo || null,
            requires_lane_closure: requiresLaneClosure,
        }, {
            onSuccess: () => { setShowCreate(false); setTitle(''); setDescription(''); },
            onError: showOperationMutationErrors,
        });
    };

    const handleGenerate = () => {
        setGenerating(true);
        router.post('/om/preventive-maintenance/generate', {}, {
            onSuccess: () => setGenerating(false),
            onError: (e) => { setGenerating(false); showOperationMutationErrors(e); },
        });
    };

    const handleToggle = (id) => {
        router.post(`/om/preventive-maintenance/${id}/toggle`);
    };

    const frequencyLabel = (f) => ({
        daily: 'Daily', weekly: 'Weekly', biweekly: 'Bi-Weekly', monthly: 'Monthly',
        quarterly: 'Quarterly', semi_annual: 'Semi-Annual', annual: 'Annual',
        condition_based: 'Condition-Based',
    }[f] || f);

    const categoryLabel = (c) => ({
        pavement_civil: 'Pavement/Civil', bridge_structure: 'Bridge/Structure',
        guardrail_safety: 'Guardrail/Safety', signage_marking: 'Signage/Marking',
        drainage_slope: 'Drainage/Slope', lighting_electrical: 'Lighting/Electrical',
        its_telecom: 'ITS/Telecom', toll_equipment: 'Toll Equipment',
        building_facility: 'Building/Facility',
    }[c] || c);

    return (
        <App auth={auth}>
            <Head title="Preventive Maintenance Scheduler" />
            <Box p="5">
                <Flex justify="between" align="center" mb="4">
                    <Box>
                        <Heading size="6" weight="bold">
                            <CalendarDaysIcon className="inline h-6 w-6 mr-2" />
                            Preventive Maintenance Scheduler
                        </Heading>
                        <Text size="2" color="gray">Recurring PM tasks, auto-generated work orders, compliance tracking</Text>
                    </Box>
                    <Flex gap="2">
                        {canManage && (
                            <>
                                <Button color="indigo" onClick={handleGenerate} disabled={generating}>
                                    <ArrowPathIcon className={`h-4 w-4 ${generating ? 'animate-spin' : ''}`} />
                                    {generating ? 'Generating...' : 'Generate Due WOs'}
                                </Button>
                                <Dialog.Root open={showCreate} onOpenChange={setShowCreate}>
                                    <Dialog.Trigger>
                                        <Button><PlusIcon className="h-4 w-4" /> New PM Schedule</Button>
                                    </Dialog.Trigger>
                                    <Dialog.Content maxWidth="550px">
                                        <Dialog.Title>Create Preventive Maintenance Schedule</Dialog.Title>
                                        <Dialog.Description size="2" color="gray" mb="3">
                                            Define a recurring maintenance task that auto-generates work orders
                                        </Dialog.Description>
                                        <form onSubmit={handleCreate}>
                                            <Flex direction="column" gap="3">
                                                <TextField.Root placeholder="e.g. Monthly Drainage Clearing Ch 0-48" value={title} onChange={e => setTitle(e.target.value)} required />
                                                <TextArea placeholder="Description of PM task scope..." value={description} onChange={e => setDescription(e.target.value)} rows={2} />
                                                <Flex gap="3">
                                                    <Select.Root value={assetCategory} onValueChange={setAssetCategory}>
                                                        <Select.Trigger placeholder="Asset Category" />
                                                        <Select.Content>
                                                            <Select.Item value="pavement_civil">Pavement/Civil</Select.Item>
                                                            <Select.Item value="bridge_structure">Bridge/Structure</Select.Item>
                                                            <Select.Item value="guardrail_safety">Guardrail/Safety</Select.Item>
                                                            <Select.Item value="signage_marking">Signage/Marking</Select.Item>
                                                            <Select.Item value="drainage_slope">Drainage/Slope</Select.Item>
                                                            <Select.Item value="lighting_electrical">Lighting/Electrical</Select.Item>
                                                            <Select.Item value="its_telecom">ITS/Telecom</Select.Item>
                                                            <Select.Item value="toll_equipment">Toll Equipment</Select.Item>
                                                        </Select.Content>
                                                    </Select.Root>
                                                    <Select.Root value={frequencyType} onValueChange={setFrequencyType}>
                                                        <Select.Trigger placeholder="Frequency" />
                                                        <Select.Content>
                                                            <Select.Item value="daily">Daily</Select.Item>
                                                            <Select.Item value="weekly">Weekly</Select.Item>
                                                            <Select.Item value="biweekly">Bi-Weekly</Select.Item>
                                                            <Select.Item value="monthly">Monthly</Select.Item>
                                                            <Select.Item value="quarterly">Quarterly</Select.Item>
                                                            <Select.Item value="semi_annual">Semi-Annual</Select.Item>
                                                            <Select.Item value="annual">Annual</Select.Item>
                                                        </Select.Content>
                                                    </Select.Root>
                                                </Flex>
                                                <Flex gap="3">
                                                    <Select.Root value={priority} onValueChange={setPriority}>
                                                        <Select.Trigger placeholder="Priority" />
                                                        <Select.Content>
                                                            <Select.Item value="low">Low</Select.Item>
                                                            <Select.Item value="medium">Medium</Select.Item>
                                                            <Select.Item value="high">High</Select.Item>
                                                            <Select.Item value="critical">Critical</Select.Item>
                                                        </Select.Content>
                                                    </Select.Root>
                                                    <TextField.Root placeholder="Assigned Crew/Team" value={assignedTo} onChange={e => setAssignedTo(e.target.value)} style={{flex: 1}} />
                                                </Flex>
                                                <Flex gap="3">
                                                    <TextField.Root placeholder="Est. Cost (৳)" type="number" value={estimatedCost} onChange={e => setEstimatedCost(e.target.value)} />
                                                    <TextField.Root placeholder="Est. Hours" type="number" value={estimatedHours} onChange={e => setEstimatedHours(e.target.value)} />
                                                </Flex>
                                                <Flex gap="3">
                                                    <TextField.Root placeholder="From Chainage" value={chainageFrom} onChange={e => setChainageFrom(e.target.value)} />
                                                    <TextField.Root placeholder="To Chainage" value={chainageTo} onChange={e => setChainageTo(e.target.value)} />
                                                </Flex>
                                                <Flex align="center" gap="2">
                                                    <Switch checked={requiresLaneClosure} onCheckedChange={setRequiresLaneClosure} />
                                                    <Text size="2">Requires Lane Closure</Text>
                                                </Flex>
                                            </Flex>
                                            <Flex justify="end" gap="2" mt="4">
                                                <Dialog.Close><Button variant="soft" color="gray">Cancel</Button></Dialog.Close>
                                                <Button type="submit">Create PM Schedule</Button>
                                            </Flex>
                                        </form>
                                    </Dialog.Content>
                                </Dialog.Root>
                            </>
                        )}
                    </Flex>
                </Flex>

                {/* KPI Cards */}
                <StatsCards stats={[
                    { label: 'Active Schedules', value: stats?.active_schedules ?? 0, icon: CalendarDaysIcon, color: 'blue' },
                    { label: 'Overdue', value: stats?.overdue ?? 0, icon: BoltIcon, color: stats?.overdue > 0 ? 'red' : 'green' },
                    { label: 'Due This Week', value: stats?.due_this_week ?? 0, icon: ClockIcon, color: 'amber' },
                    { label: 'PM Completion Rate', value: `${stats?.pm_completion_rate ?? 0}%`, icon: WrenchScrewdriverIcon, color: 'green' },
                ]} />

                {/* Schedule Table */}
                <Panel mt="4">
                    <Table.Root>
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Schedule Code</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Title</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Frequency</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Priority</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Next Due</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                {canManage && <Table.ColumnHeaderCell>Actions</Table.ColumnHeaderCell>}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {scheduleList.map(s => (
                                <Table.Row key={s.id}>
                                    <Table.Cell><Text weight="bold" size="2" color="blue">{s.schedule_code}</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{s.title}</Text></Table.Cell>
                                    <Table.Cell><Badge variant="soft" size="1">{categoryLabel(s.asset_category)}</Badge></Table.Cell>
                                    <Table.Cell><Badge variant="outline" size="1">{frequencyLabel(s.frequency_type)}</Badge></Table.Cell>
                                    <Table.Cell>
                                        <Badge color={s.priority === 'critical' ? 'red' : s.priority === 'high' ? 'orange' : s.priority === 'medium' ? 'amber' : 'gray'} size="1">
                                            {s.priority.toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2" color={s.next_due_at && new Date(s.next_due_at) < new Date() ? 'red' : 'gray'}>
                                            {s.next_due_at ? new Date(s.next_due_at).toLocaleDateString() : '—'}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={s.is_active ? 'green' : 'gray'} size="1">{s.is_active ? 'ACTIVE' : 'INACTIVE'}</Badge>
                                    </Table.Cell>
                                    {canManage && (
                                        <Table.Cell>
                                            <Button size="1" variant="soft" color={s.is_active ? 'gray' : 'green'} onClick={() => handleToggle(s.id)}>
                                                {s.is_active ? 'Deactivate' : 'Activate'}
                                            </Button>
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            ))}
                            {scheduleList.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={8}>
                                        <Text align="center" color="gray" size="2" style={{display:'block', padding:'24px'}}>
                                            No preventive maintenance schedules yet. Create one to auto-generate recurring work orders.
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>
            </Box>
        </App>
    );
}
