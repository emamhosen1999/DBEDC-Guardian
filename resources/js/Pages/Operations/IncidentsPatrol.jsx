import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, TextField, Dialog, Select, Separator, TextArea } from '@radix-ui/themes';
import {
    ShieldCheckIcon,
    PlusIcon,
    ExclamationTriangleIcon,
    TruckIcon,
    DocumentTextIcon,
    ClockIcon,
    WrenchScrewdriverIcon,
    EyeIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function IncidentsPatrol({ auth, metrics, incidents, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.incidents.manage') || auth?.roles?.includes('Super Administrator');
    const [openModal, setOpenModal] = useState(false);
    const [selectedIncident, setSelectedIncident] = useState(null);
    const [openTppdModal, setOpenTppdModal] = useState(null);

    // New Incident Form State
    const [title, setTitle] = useState('');
    const [incidentType, setIncidentType] = useState('vehicle_breakdown');
    const [chainage, setChainage] = useState('Ch 24+500');
    const [direction, setDirection] = useState('southbound');
    const [severity, setSeverity] = useState('minor');
    const [unit, setUnit] = useState('Patrol Unit 1');
    const [casualtiesInjured, setCasualtiesInjured] = useState('0');
    const [hasAssetDamage, setHasAssetDamage] = useState(false);
    const [assetDamageEst, setAssetDamageEst] = useState('0');
    const [description, setDescription] = useState('');

    const incidentList = incidents?.data || [];

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post('/om/incidents', {
            title,
            incident_type: incidentType,
            chainage,
            direction,
            severity,
            dispatched_unit: unit,
            casualties_injured: Number(casualtiesInjured),
            has_asset_damage: hasAssetDamage,
            asset_damage_cost_est: Number(assetDamageEst),
            description,
        }, {
            onSuccess: () => {
                setOpenModal(false);
                setTitle('');
                setDescription('');
            },
            onError: showOperationMutationErrors,
        });
    };

    const handleUpdateStatus = (incident, newStatus) => {
        router.post(`/om/incidents/${incident.id}/status`, {
            status: newStatus,
            lock_version: incident.lock_version,
        }, {
            onSuccess: () => setSelectedIncident(null),
            onError: showOperationMutationErrors,
        });
    };

    const handleCreateDamageWo = (incident) => {
        router.post(`/om/incidents/${incident.id}/create-damage-wo`, {
            lock_version: incident.lock_version,
        }, {
            onSuccess: () => setSelectedIncident(null),
            onError: showOperationMutationErrors,
        });
    };

    const statItems = [
        { key: 'active', title: 'Active Incidents', value: metrics?.active_incidents || 0, color: 'amber', icon: <ExclamationTriangleIcon /> },
        { key: 'cleared', title: 'Cleared Today', value: metrics?.cleared_today || 0, color: 'green', icon: <ShieldCheckIcon /> },
        { key: 'sla', title: 'Avg SLA Response Time', value: `${metrics?.avg_response_time_min || '11.8'} mins`, color: 'blue', icon: <ClockIcon /> },
        { key: 'tppd', title: 'TPPD Asset Damage Claims', value: `৳ ${Number(metrics?.total_tppd_damage_claims || 0).toLocaleString()}`, color: 'red', icon: <DocumentTextIcon /> },
    ];

    const getSeverityBadge = (sev) => {
        const colors = { critical: 'red', major: 'amber', minor: 'blue' };
        return <Badge color={colors[sev] || 'gray'} variant="soft" style={{ borderRadius: 999 }}>{sev?.toUpperCase()}</Badge>;
    };

    const getStatusBadge = (st) => {
        const colors = { detected: 'blue', dispatched: 'purple', on_scene: 'amber', cleared: 'green', closed: 'gray' };
        return <Badge color={colors[st] || 'gray'} variant="surface" style={{ borderRadius: 999 }}>{st?.replace(/_/g, ' ').toUpperCase()}</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="Incidents Command & Emergency Patrol" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--amber-a3)', borderRadius: 12, border: '1px solid var(--amber-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ShieldCheckIcon style={{ width: 22, height: 22, color: 'var(--amber-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Incident Command & Emergency Roadside Patrol
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Highway Emergency SLAs, Crash Timelines & Third-Party Property Damage (TPPD) Asset Cost Recovery
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && <Button color="amber" onClick={() => setOpenModal(true)} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                    <PlusIcon width={16} height={16} /> Report & Dispatch Patrol
                                </Button>}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '4' }} mb="4" />

                        {/* Incident Table */}
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
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Incident #</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 240 }}>Title & Type</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Location</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 100 }}>Severity</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 110 }}>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 150 }}>Dispatched Unit</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>TPPD Claim</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 100 }}>Action</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {incidentList.map((inc) => (
                                        <Table.Row key={inc.id} align="center">
                                            <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                                {inc.incident_number}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text weight="bold" style={{ display: 'block', maxWidth: 240, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {inc.title}
                                                </Text>
                                                <Text size="1" color="gray">{inc.incident_type?.replace(/_/g, ' ').toUpperCase() || 'BREAKDOWN'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" style={{ whiteSpace: 'nowrap' }}>{inc.chainage} ({inc.direction})</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {getSeverityBadge(inc.severity)}
                                            </Table.Cell>
                                            <Table.Cell>
                                                {getStatusBadge(inc.status)}
                                            </Table.Cell>
                                            <Table.Cell><Text size="2" style={{ whiteSpace: 'nowrap' }}>{inc.dispatched_unit}</Text></Table.Cell>
                                            <Table.Cell>
                                                {inc.has_asset_damage ? (
                                                    <Badge color="red" variant="soft">৳{Number(inc.asset_damage_cost_est).toLocaleString()}</Badge>
                                                ) : (
                                                    <Text size="1" color="gray">None</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'right' }}>
                                                <Button size="1" variant="soft" color="blue" onClick={() => setSelectedIncident(inc)}>
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

            {/* Incident Detail Modal */}
            <Dialog.Root open={!!selectedIncident} onOpenChange={(open) => !open && setSelectedIncident(null)}>
                <Dialog.Content style={{ maxWidth: 640 }}>
                    <Dialog.Title>Incident: {selectedIncident?.incident_number}</Dialog.Title>
                    <Dialog.Description size="2" mb="3">
                        {selectedIncident?.title} · Location: {selectedIncident?.chainage} ({selectedIncident?.direction})
                    </Dialog.Description>

                    <Separator size="4" mb="3" />

                    <Flex direction="column" gap="3">
                        <Grid columns="2" gap="2">
                            <Box>
                                <Text size="1" color="gray">Incident Type & Source</Text>
                                <Text size="2" weight="bold">{selectedIncident?.incident_type?.replace(/_/g, ' ')} ({selectedIncident?.detection_source})</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Severity & Status</Text>
                                <Flex gap="2" align="center" mt="1">
                                    {selectedIncident && getSeverityBadge(selectedIncident.severity)}
                                    {selectedIncident && getStatusBadge(selectedIncident.status)}
                                </Flex>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Dispatched Patrol / Wrecker</Text>
                                <Text size="2" weight="bold">{selectedIncident?.dispatched_unit}</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Casualties</Text>
                                <Text size="2" weight="bold">{selectedIncident?.casualties_fatalities} Fatalities, {selectedIncident?.casualties_injured} Injured</Text>
                            </Box>
                        </Grid>

                        {/* Involved Vehicles & TPPD Damages */}
                        {selectedIncident?.vehicles && selectedIncident.vehicles.length > 0 && (
                            <Box mt="2" p="3" style={{ background: 'var(--gray-a2)', borderRadius: 10 }}>
                                <Heading size="2" mb="2">Involved Vehicles & Third-Party Damage</Heading>
                                {selectedIncident.vehicles.map((v) => (
                                    <Box key={v.id} mb="2" pb="2" style={{ borderBottom: '1px solid var(--dl-border-color, rgba(0,0,0,0.06))' }}>
                                        <Flex justify="between">
                                            <Text size="2" weight="bold">{v.vehicle_reg_number} ({v.vehicle_type})</Text>
                                            <Badge color="red" variant="soft">Damage Est: ৳{Number(v.estimated_asset_repair_cost).toLocaleString()}</Badge>
                                        </Flex>
                                        <Text size="1" color="gray">Driver: {v.driver_name || 'N/A'} · Insurance: {v.insurance_company || 'N/A'}</Text>
                                        <Text size="1" mt="1">Asset Damage: {v.damage_to_expressway_asset || 'Guardrail deformation'}</Text>
                                    </Box>
                                ))}
                            </Box>
                        )}

                        <Flex justify="end" gap="2" mt="3">
                            <Button variant="soft" color="gray" onClick={() => setSelectedIncident(null)}>Close</Button>

                            {canManage && selectedIncident?.status === 'detected' && (
                                <Button color="blue" onClick={() => handleUpdateStatus(selectedIncident, 'dispatched')}>
                                    Dispatch Patrol Unit
                                </Button>
                            )}

                            {canManage && selectedIncident?.status === 'dispatched' && (
                                <Button color="amber" onClick={() => handleUpdateStatus(selectedIncident, 'on_scene')}>
                                    Arrived On-Scene
                                </Button>
                            )}

                            {canManage && selectedIncident?.status === 'on_scene' && (
                                <Button color="green" onClick={() => handleUpdateStatus(selectedIncident, 'cleared')}>
                                    Scene Cleared & Lane Reopened
                                </Button>
                            )}

                            {canManage && selectedIncident?.has_asset_damage && (
                                <Button color="red" variant="soft" onClick={() => handleCreateDamageWo(selectedIncident)}>
                                    <WrenchScrewdriverIcon width={16} height={16} /> Spawn TPPD Repair WO
                                </Button>
                            )}
                        </Flex>
                    </Flex>
                </Dialog.Content>
            </Dialog.Root>

            {/* Report New Incident Modal */}
            <Dialog.Root open={canManage && openModal} onOpenChange={setOpenModal}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Report Incident & Dispatch Emergency Units</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Dispatch motorway patrol units, heavy wreckers, or ambulances to the incident scene.
                    </Dialog.Description>
                    <form onSubmit={handleSubmit}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Incident Title</Text>
                                <TextField.Root placeholder="e.g. Heavy Truck Tire Blowout" value={title} onChange={(e) => setTitle(e.target.value)} required />
                            </label>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Type</Text>
                                    <Select.Root value={incidentType} onValueChange={setIncidentType}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="vehicle_breakdown">Vehicle Breakdown</Select.Item>
                                            <Select.Item value="road_traffic_collision">Traffic Collision</Select.Item>
                                            <Select.Item value="vehicle_fire">Vehicle Fire</Select.Item>
                                            <Select.Item value="cargo_spill_hazard">Cargo Spill / Hazard</Select.Item>
                                            <Select.Item value="infrastructure_strike">Infrastructure Strike</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Severity</Text>
                                    <Select.Root value={severity} onValueChange={setSeverity}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="minor">Minor</Select.Item>
                                            <Select.Item value="major">Major (Lane Obstructed)</Select.Item>
                                            <Select.Item value="critical">Critical (Casualties / Fire)</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Location (Chainage)</Text>
                                    <TextField.Root placeholder="e.g. Ch 24+500" value={chainage} onChange={(e) => setChainage(e.target.value)} required />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Direction</Text>
                                    <Select.Root value={direction} onValueChange={setDirection}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="northbound">Northbound</Select.Item>
                                            <Select.Item value="southbound">Southbound</Select.Item>
                                            <Select.Item value="both">Both Carriageways</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Dispatched Response Unit</Text>
                                <TextField.Root value={unit} onChange={(e) => setUnit(e.target.value)} required />
                            </label>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Incident Description & Triage</Text>
                                <TextArea placeholder="Describe vehicle status, lane obstruction, driver condition..." value={description} onChange={(e) => setDescription(e.target.value)} />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenModal(false)}>Cancel</Button>
                                <Button type="submit" color="amber">Dispatch Emergency Response</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
