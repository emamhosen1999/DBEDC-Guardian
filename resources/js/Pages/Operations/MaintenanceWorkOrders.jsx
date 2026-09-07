import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, TextField, Dialog, Select, Separator, TextArea, Tabs } from '@radix-ui/themes';
import {
    WrenchScrewdriverIcon,
    PlusIcon,
    CheckCircleIcon,
    ClockIcon,
    ShieldCheckIcon,
    DocumentCheckIcon,
    PlayIcon,
    EyeIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function MaintenanceWorkOrders({ auth, workOrders, stats, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [openCreateModal, setOpenCreateModal] = useState(false);
    const [selectedWo, setSelectedWo] = useState(null);
    const [openQcModal, setOpenQcModal] = useState(null);
    const [qcNotes, setQcNotes] = useState('');

    // Create Modal Form States
    const [title, setTitle] = useState('');
    const [workType, setWorkType] = useState('routine_corrective');
    const [category, setCategory] = useState('pavement');
    const [location, setLocation] = useState('Ch 14+250');
    const [priority, setPriority] = useState('medium');
    const [assignedTo, setAssignedTo] = useState('Roadside Maintenance Crew Alpha');
    const [contractorName, setContractorName] = useState('Expressway Routine Maintenance Ltd.');
    const [estimatedCost, setEstimatedCost] = useState('45000');
    const [description, setDescription] = useState('');
    const [requiresLaneClosure, setRequiresLaneClosure] = useState(true);

    const woList = workOrders?.data || [];

    const handleCreateSubmit = (e) => {
        e.preventDefault();
        router.post('/om/work-orders', {
            title,
            work_type: workType,
            category,
            location,
            priority,
            assigned_to: assignedTo,
            contractor_name: contractorName,
            estimated_cost: Number(estimatedCost),
            description,
            requires_lane_closure: requiresLaneClosure,
        }, {
            onSuccess: () => {
                setOpenCreateModal(false);
                setTitle('');
                setDescription('');
            },
            onError: showOperationMutationErrors,
        });
    };

    const handleApprove = (workOrder) => {
        router.post(`/om/work-orders/${workOrder.id}/approve`, {
            lock_version: workOrder.lock_version,
        }, {
            onSuccess: () => setSelectedWo(null),
            onError: showOperationMutationErrors,
        });
    };

    const handleStart = (workOrder) => {
        router.post(`/om/work-orders/${workOrder.id}/start`, {
            lock_version: workOrder.lock_version,
        }, {
            onSuccess: () => setSelectedWo(null),
            onError: showOperationMutationErrors,
        });
    };

    const handleComplete = (workOrder) => {
        router.post(`/om/work-orders/${workOrder.id}/complete`, {
            lock_version: workOrder.lock_version,
        }, {
            onSuccess: () => setSelectedWo(null),
            onError: showOperationMutationErrors,
        });
    };

    const handleVerifyQc = (e) => {
        e.preventDefault();
        if (!openQcModal) return;

        router.post(`/om/work-orders/${openQcModal.id}/verify`, {
            qc_notes: qcNotes,
            lock_version: openQcModal.lock_version,
        }, {
            onSuccess: () => {
                setOpenQcModal(null);
                setSelectedWo(null);
                setQcNotes('');
            },
            onError: showOperationMutationErrors,
        });
    };

    const statItems = [
        { key: 'total', title: 'Total Work Orders', value: stats?.total_work_orders || woList.length, color: 'blue' },
        { key: 'in_progress', title: 'In Progress / Active Zone', value: stats?.in_progress || 0, color: 'amber', icon: <PlayIcon /> },
        { key: 'pending_qc', title: 'Pending QC Signoff', value: stats?.completed_pending_qc || 0, color: 'purple', icon: <DocumentCheckIcon /> },
        { key: 'verified', title: 'Verified & Closed', value: stats?.verified_closed || 0, color: 'green', icon: <CheckCircleIcon /> },
    ];

    const getStatusBadge = (st) => {
        const map = {
            pending: { color: 'amber', label: 'Pending Approval' },
            assigned: { color: 'blue', label: 'Assigned / Dispatched' },
            in_progress: { color: 'purple', label: 'Work Zone Active' },
            completed: { color: 'indigo', label: 'Completed (Pending QC)' },
            verified: { color: 'green', label: 'Verified & Closed' },
        };
        const item = map[st] || { color: 'gray', label: st };
        return <Badge color={item.color} variant="surface" style={{ borderRadius: 999 }}>{item.label}</Badge>;
    };

    const getPriorityBadge = (p) => {
        const map = { emergency: 'red', high: 'orange', medium: 'amber', low: 'gray' };
        return <Badge color={map[p] || 'gray'} variant="soft" style={{ borderRadius: 999 }}>{p.toUpperCase()}</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="Maintenance Work Orders & Safety Work Zones" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <WrenchScrewdriverIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Routine & Preventive Maintenance Work Orders
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Full Lifecycle Management: Bill of Quantities (BOQ), Work Zone Safety Permits & Joint QC Verification
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && <Button color="blue" onClick={() => setOpenCreateModal(true)} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                    <PlusIcon width={16} height={16} /> Issue Work Order
                                </Button>}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '4' }} mb="4" />

                        {/* Work Orders Table */}
                        <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Table.Root size="2" style={{ minWidth: 980, width: '100%' }}>
                                <Table.Header style={{
                                    position: 'sticky',
                                    top: 0,
                                    zIndex: 2,
                                    background: 'var(--aero-surface, var(--color-background))',
                                    backdropFilter: 'blur(8px)',
                                    boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                }}>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>WO #</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 260 }}>Task / Scope of Work</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>Category</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Location</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 110 }}>Priority</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 160 }}>Assigned Contractor</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 100 }}>Action</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {woList.map((wo) => (
                                        <Table.Row key={wo.id} align="center">
                                            <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                                {wo.work_order_number}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text weight="bold" style={{ display: 'block', maxWidth: 280, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {wo.title}
                                                </Text>
                                                <Text size="1" color="gray">{wo.work_type?.replace(/_/g, ' ').toUpperCase() || 'ROUTINE'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color="indigo" variant="soft" style={{ borderRadius: 999 }}>{wo.category.toUpperCase()}</Badge>
                                            </Table.Cell>
                                            <Table.Cell><Text size="2" style={{ whiteSpace: 'nowrap' }}>{wo.location}</Text></Table.Cell>
                                            <Table.Cell>
                                                {getPriorityBadge(wo.priority)}
                                            </Table.Cell>
                                            <Table.Cell>
                                                {getStatusBadge(wo.status)}
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'right' }}>
                                                <Text size="2" style={{ whiteSpace: 'nowrap' }}>{wo.contractor_name || wo.assigned_to}</Text>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'right' }}>
                                                <Button size="1" variant="soft" color="blue" onClick={() => setSelectedWo(wo)}>
                                                    <EyeIcon width={14} height={14} /> Detail
                                                </Button>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            {/* Work Order Detail & Lifecycle Action Modal */}
            <Dialog.Root open={!!selectedWo} onOpenChange={(open) => !open && setSelectedWo(null)}>
                <Dialog.Content style={{ maxWidth: 650 }}>
                    <Dialog.Title>Work Order: {selectedWo?.work_order_number}</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        {selectedWo?.title} · Location: {selectedWo?.location}
                    </Dialog.Description>

                    <Separator size="4" mb="3" />

                    <Flex direction="column" gap="3">
                        <Grid columns="2" gap="2">
                            <Box>
                                <Text size="1" color="gray">Category & Work Type</Text>
                                <Text size="2" weight="bold">{selectedWo?.category?.toUpperCase()} ({selectedWo?.work_type?.replace(/_/g, ' ')})</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Priority & Status</Text>
                                <Flex gap="2" align="center" mt="1">
                                    {selectedWo && getPriorityBadge(selectedWo.priority)}
                                    {selectedWo && getStatusBadge(selectedWo.status)}
                                </Flex>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Assigned Crew / Contractor</Text>
                                <Text size="2" weight="bold">{selectedWo?.contractor_name || selectedWo?.assigned_to || 'Unassigned'}</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Estimated Cost</Text>
                                <Text size="2" weight="bold" color="green">৳ {Number(selectedWo?.estimated_cost || 0).toLocaleString()}</Text>
                            </Box>
                        </Grid>

                        {/* Bill of Quantities (BOQ) Materials */}
                        {selectedWo?.materials && selectedWo.materials.length > 0 && (
                            <Box mt="2" p="3" style={{ background: 'var(--gray-a2)', borderRadius: 10 }}>
                                <Heading size="2" mb="2">BOQ Materials Consumed</Heading>
                                <Table.Root size="1">
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>Material</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Planned</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Used</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Unit Cost</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'right' }}>Total</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {selectedWo.materials.map((m) => (
                                            <Table.Row key={m.id}>
                                                <Table.Cell><Text size="1">{m.item_name}</Text></Table.Cell>
                                                <Table.Cell><Text size="1">{m.quantity_planned} {m.unit}</Text></Table.Cell>
                                                <Table.Cell><Text size="1" weight="bold">{m.quantity_used} {m.unit}</Text></Table.Cell>
                                                <Table.Cell><Text size="1">৳{m.unit_cost}</Text></Table.Cell>
                                                <Table.Cell style={{ textAlign: 'right' }}><Text size="1" weight="bold">৳{Number(m.total_cost).toLocaleString()}</Text></Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                        )}

                        {/* Lane Closure Permit Status */}
                        {selectedWo?.lane_closure_permit && (
                            <Box p="3" style={{ background: 'var(--amber-a2)', borderRadius: 10, border: '1px solid var(--amber-a4)' }}>
                                <Flex justify="between" align="center">
                                    <Text size="2" weight="bold" color="amber">Safety Permit: {selectedWo.lane_closure_permit.permit_number}</Text>
                                    <Badge color="amber" variant="solid">{selectedWo.lane_closure_permit.status.toUpperCase()}</Badge>
                                </Flex>
                                <Text size="1" color="gray" mt="1">
                                    Lanes Closed: {selectedWo.lane_closure_permit.lanes_closed.replace(/_/g, ' ')} · {selectedWo.lane_closure_permit.safety_cones_deployed} Cones Deployed
                                </Text>
                            </Box>
                        )}

                        {/* Action Buttons based on lifecycle status */}
                        <Flex justify="end" gap="2" mt="3">
                            <Button variant="soft" color="gray" onClick={() => setSelectedWo(null)}>Close</Button>

                            {canManage && selectedWo?.status === 'pending' && (
                                <Button color="green" onClick={() => handleApprove(selectedWo)}>
                                    <CheckCircleIcon width={16} height={16} /> Approve & Dispatch
                                </Button>
                            )}

                            {canManage && selectedWo?.status === 'assigned' && (
                                <Button color="purple" onClick={() => handleStart(selectedWo)}>
                                    <PlayIcon width={16} height={16} /> Activate Work Zone
                                </Button>
                            )}

                            {canManage && selectedWo?.status === 'in_progress' && (
                                <Button color="indigo" onClick={() => handleComplete(selectedWo)}>
                                    <DocumentCheckIcon width={16} height={16} /> Mark Completed
                                </Button>
                            )}

                            {canManage && selectedWo?.status === 'completed' && (
                                <Button color="green" onClick={() => setOpenQcModal(selectedWo)}>
                                    <ShieldCheckIcon width={16} height={16} /> QC Joint Verification
                                </Button>
                            )}
                        </Flex>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* QC Joint Verification Modal */}
            <Dialog.Root open={canManage && !!openQcModal} onOpenChange={(open) => !open && setOpenQcModal(null)}>
                <Dialog.Content style={{ maxWidth: 480 }}>
                    <Dialog.Title>Joint QA/QC Inspection Sign-off</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        Verify and certify that maintenance work order {openQcModal?.work_order_number} adheres to expressway quality standards.
                    </Dialog.Description>
                    <form onSubmit={handleVerifyQc}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">QA/QC Inspection Remarks</Text>
                                <TextArea placeholder="Confirm surface smoothness, compaction, guardrail torque, or electrical tests pass..." value={qcNotes} onChange={(e) => setQcNotes(e.target.value)} required />
                            </label>
                            <Flex justify="end" gap="2" mt="2">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenQcModal(null)}>Cancel</Button>
                                <Button type="submit" color="green">Verify & Close Work Order</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            {/* Create Work Order Modal */}
            <Dialog.Root open={canManage && openCreateModal} onOpenChange={setOpenCreateModal}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Create Maintenance Work Order</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Issue a new routine, corrective, or preventive highway maintenance ticket.
                    </Dialog.Description>
                    <form onSubmit={handleCreateSubmit}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Task / Scope</Text>
                                <TextField.Root placeholder="e.g. Guardrail Replacement at Kanchan" value={title} onChange={(e) => setTitle(e.target.value)} required />
                            </label>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Work Type</Text>
                                    <Select.Root value={workType} onValueChange={setWorkType}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="routine_corrective">Routine Corrective</Select.Item>
                                            <Select.Item value="preventive_scheduled">Preventive Scheduled</Select.Item>
                                            <Select.Item value="emergency_repair">Emergency Repair</Select.Item>
                                            <Select.Item value="periodic_rehabilitation">Periodic Rehabilitation</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Category</Text>
                                    <Select.Root value={category} onValueChange={setCategory}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="pavement">Pavement Care</Select.Item>
                                            <Select.Item value="guardrail">Guardrails & Safety</Select.Item>
                                            <Select.Item value="lighting">Lighting & Electrical</Select.Item>
                                            <Select.Item value="drainage">Drainage & Culverts</Select.Item>
                                            <Select.Item value="bridge">Bridges & Expansion Joints</Select.Item>
                                            <Select.Item value="signage">Signages & Markings</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Location (Chainage)</Text>
                                    <TextField.Root placeholder="e.g. Ch 18+270" value={location} onChange={(e) => setLocation(e.target.value)} required />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Priority</Text>
                                    <Select.Root value={priority} onValueChange={setPriority}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="low">Low</Select.Item>
                                            <Select.Item value="medium">Medium</Select.Item>
                                            <Select.Item value="high">High</Select.Item>
                                            <Select.Item value="emergency">Emergency</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Assigned Contractor / Internal Crew</Text>
                                <TextField.Root value={assignedTo} onChange={(e) => setAssignedTo(e.target.value)} required />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Estimated Cost (৳)</Text>
                                <TextField.Root type="number" value={estimatedCost} onChange={(e) => setEstimatedCost(e.target.value)} />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Description & Instructions</Text>
                                <TextArea placeholder="Enter specific repair steps, safety precautions..." value={description} onChange={(e) => setDescription(e.target.value)} />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenCreateModal(false)}>Cancel</Button>
                                <Button type="submit" color="blue">Create Work Order</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
