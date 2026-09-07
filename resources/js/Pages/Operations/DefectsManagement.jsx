import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, Separator, TextArea, Grid } from '@radix-ui/themes';
import { BoltIcon, PlusIcon, ClockIcon, WrenchScrewdriverIcon, CheckCircleIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function DefectsManagement({ auth, defects, stats, filters, lookups }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [openLogModal, setOpenLogModal] = useState(false);
    const [openConvertModal, setOpenConvertModal] = useState(null);
    const [title, setTitle] = useState('');
    const [distressType, setDistressType] = useState('pothole');
    const [chainage, setChainage] = useState('Ch 14+250');
    const [direction, setDirection] = useState('northbound');
    const [locationCarriageway, setLocationCarriageway] = useState('Main Carriageway - L');
    const [severity, setSeverity] = useState('medium');
    const [responsibleParty, setResponsibleParty] = useState('O&M Contractor');
    const [recommendedAction, setRecommendedAction] = useState('Rectification Required');
    const [description, setDescription] = useState('');

    // Convert to Work Order Form States
    const [woTitle, setWoTitle] = useState('');
    const [woCategory, setWoCategory] = useState('pavement');
    const [woAssignedTo, setWoAssignedTo] = useState('Roadside Maintenance Crew Alpha');
    const [woContractor, setWoContractor] = useState('Expressway Routine Maintenance Ltd.');
    const [woEstCost, setWoEstCost] = useState('45000');
    const [woLaneClosure, setWoLaneClosure] = useState(true);

    const defectList = defects?.data || [];

    const handleLogSubmit = (e) => {
        e.preventDefault();
        router.post('/om/defects', {
            title,
            distress_type: distressType,
            chainage,
            direction,
            location_carriageway: locationCarriageway,
            severity,
            responsible_party: responsibleParty,
            recommended_action: recommendedAction,
            description,
        }, {
            onSuccess: () => {
                setOpenLogModal(false);
                setTitle('');
                setDescription('');
            },
            onError: showOperationMutationErrors,
        });
    };

    const handleConvertSubmit = (e) => {
        e.preventDefault();
        if (!openConvertModal) return;

        router.post(`/om/defects/${openConvertModal.id}/convert-to-wo`, {
            title: woTitle || `Rectification: ${openConvertModal.title}`,
            category: woCategory,
            assigned_to: woAssignedTo,
            contractor_name: woContractor,
            estimated_cost: Number(woEstCost),
            requires_lane_closure: woLaneClosure,
            lock_version: openConvertModal.lock_version,
        }, {
            onSuccess: () => {
                setOpenConvertModal(null);
            },
            onError: showOperationMutationErrors,
        });
    };

    const statItems = [
        { key: 'total', title: 'Total Logged Defects', value: stats?.total_defects || defectList.length, color: 'blue' },
        { key: 'open', title: 'Open & In-Repair', value: stats?.open_defects || 0, color: 'amber', icon: <ClockIcon /> },
        { key: 'overdue', title: 'SLA Overdue', value: stats?.sla_overdue_count || 0, color: 'red', icon: <ExclamationTriangleIcon /> },
        { key: 'rectified', title: 'Rectified Today', value: stats?.rectified_today || 0, color: 'green', icon: <CheckCircleIcon /> },
    ];

    const getSeverityBadge = (sev) => {
        const colors = { critical: 'red', high: 'orange', medium: 'amber', low: 'blue' };
        return <Badge color={colors[sev] || 'gray'} variant="soft" style={{ borderRadius: 999 }}>{sev.toUpperCase()}</Badge>;
    };

    const getStatusBadge = (st) => {
        const colors = { reported: 'amber', investigating: 'blue', work_order_created: 'indigo', in_repair: 'purple', rectified: 'green', verified_closed: 'green' };
        return <Badge color={colors[st] || 'gray'} variant="surface" style={{ borderRadius: 999 }}>{st.replace(/_/g, ' ').toUpperCase()}</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="Roadway Distress & Defects Management" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--red-a3)', borderRadius: 12, border: '1px solid var(--red-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <BoltIcon style={{ width: 22, height: 22, color: 'var(--red-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Roadway Distress & Defects Catalog
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Automated SLA Countdown Timers, Distress Priority Scoring & One-Click Work Order Conversion
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && <Button color="red" onClick={() => setOpenLogModal(true)} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                    <PlusIcon width={16} height={16} /> Log Road Distress
                                </Button>}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '4' }} mb="4" />

                        {/* Defect Table */}
                        <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                            <Table.Root size="2" style={{ minWidth: 960, width: '100%' }}>
                                <Table.Header style={{
                                    position: 'sticky',
                                    top: 0,
                                    zIndex: 2,
                                    background: 'var(--aero-surface, var(--color-background))',
                                    backdropFilter: 'blur(8px)',
                                    boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                }}>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Defect #</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 240 }}>Distress & Description</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Location</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 110 }}>Severity</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>SLA Target</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 120 }}>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 140 }}>Action</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {defectList.map((def) => {
                                        const isOverdue = def.sla_due_at && new Date(def.sla_due_at) < new Date() && !['rectified', 'verified_closed'].includes(def.status);

                                        return (
                                            <Table.Row key={def.id} align="center">
                                                <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                                    {def.defect_number}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text weight="bold" style={{ display: 'block', maxWidth: 280, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                        {def.title}
                                                    </Text>
                                                    <Flex gap="2" align="center" mt="1">
                                                        <Text size="1" color="gray">{def.distress_type}</Text>
                                                        {def.photo_reference && (
                                                            <Badge size="1" color="indigo" variant="soft">{def.photo_reference}</Badge>
                                                        )}
                                                        {def.responsible_party && (
                                                            <Text size="1" color="gray">• {def.responsible_party}</Text>
                                                        )}
                                                    </Flex>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text size="2" weight="medium" style={{ whiteSpace: 'nowrap' }}>{def.chainage}</Text>
                                                    <Text size="1" color="gray" style={{ display: 'block' }}>{def.location_carriageway || def.direction}</Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {getSeverityBadge(def.severity)}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {isOverdue ? (
                                                        <Badge color="red" variant="solid" style={{ borderRadius: 999 }}>OVERDUE</Badge>
                                                    ) : (
                                                        <Box>
                                                            <Text size="2" weight="medium" color="gray">{def.sla_hours}h SLA</Text>
                                                            {def.target_repair_date && (
                                                                <Text size="1" color="gray" style={{ display: 'block' }}>Due: {def.target_repair_date.substring(0, 10)}</Text>
                                                            )}
                                                        </Box>
                                                    )}
                                                </Table.Cell>
                                                <Table.Cell>
                                                    {getStatusBadge(def.status)}
                                                </Table.Cell>
                                                <Table.Cell style={{ textAlign: 'right' }}>
                                                    {canManage && def.status === 'reported' ? (
                                                        <Button size="1" color="blue" onClick={() => {
                                                            setOpenConvertModal(def);
                                                            setWoTitle(`Rectification: ${def.title}`);
                                                        }}>
                                                            <WrenchScrewdriverIcon width={14} height={14} /> Create WO
                                                        </Button>
                                                    ) : (
                                                        <Badge color="gray" variant="soft">WO Active</Badge>
                                                    )}
                                                </Table.Cell>
                                            </Table.Row>
                                        );
                                    })}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            {/* Log Roadway Defect Modal */}
            <Dialog.Root open={canManage && openLogModal} onOpenChange={setOpenLogModal}>
                <Dialog.Content style={{ maxWidth: 500 }}>
                    <Dialog.Title>Log Highway Roadway Distress</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Record a new road surface defect, guardrail impact, or lighting outage with SLA tracking.
                    </Dialog.Description>
                    <form onSubmit={handleLogSubmit}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Distress Title</Text>
                                <TextField.Root placeholder="e.g. Deep Pothole on Driving Lane" value={title} onChange={(e) => setTitle(e.target.value)} required />
                            </label>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Distress Category</Text>
                                    <Select.Root value={distressType} onValueChange={setDistressType}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {(lookups?.defect_categories?.length ? lookups.defect_categories : [
                                                { key: 'pothole', label: 'Pothole (Pavement)', sla_hours: 4 },
                                                { key: 'isolation_barrier', label: 'Isolation Barrier Damage/Cut', sla_hours: 24 },
                                                { key: 'guardrail_crash_damage', label: 'Guardrail Crash Barrier Damage', sla_hours: 24 },
                                                { key: 'anti_glare_panel', label: 'Anti-Glare Panel Board Damage/Missing', sla_hours: 48 },
                                                { key: 'median_delineator', label: 'Central Median Delineator Missing', sla_hours: 48 },
                                                { key: 'drainage_clogged', label: 'Drainage Pipe Clogged', sla_hours: 12 },
                                                { key: 'road_marking_faded', label: 'Faded Road Marking', sla_hours: 72 },
                                                { key: 'vegetation_overgrowth', label: 'Bush/Plant/Grass Sightline', sla_hours: 24 },
                                                { key: 'debris_illegal_dumping', label: 'Debris on Roadway', sla_hours: 1 },
                                                { key: 'other', label: 'Other Highway Distress', sla_hours: 48 },
                                            ]).map((cat) => (
                                                <Select.Item key={cat.id || cat.key} value={cat.label || cat.key}>
                                                    {cat.label} {cat.sla_hours ? `(${cat.sla_hours}h SLA)` : ''}
                                                </Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Severity Level</Text>
                                    <Select.Root value={severity} onValueChange={setSeverity}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {(lookups?.severities?.length ? lookups.severities : [
                                                { key: 'minor', label: 'Minor (72h SLA)' },
                                                { key: 'moderate', label: 'Moderate (48h SLA)' },
                                                { key: 'major', label: 'Major (24h SLA)' },
                                                { key: 'critical', label: 'Critical - Safety Hazard (4h SLA)' },
                                            ]).map((s) => (
                                                <Select.Item key={s.id || s.key} value={s.key}>{s.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Chainage (Ch)</Text>
                                    <TextField.Root placeholder="e.g. K4+120 or Ch 18+400" value={chainage} onChange={(e) => setChainage(e.target.value)} required />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Location / Carriageway</Text>
                                    <Select.Root value={locationCarriageway} onValueChange={setLocationCarriageway}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            {(lookups?.carriageway_locations?.length ? lookups.carriageway_locations : [
                                                { key: 'Main Carriageway - L', label: 'Main Carriageway - L' },
                                                { key: 'Main Carriageway - R', label: 'Main Carriageway - R' },
                                                { key: 'Median', label: 'Central Median' },
                                                { key: 'Guardrail/Barrier', label: 'Guardrail / Outer Barrier' },
                                                { key: 'Service Road - Left', label: 'Service Road - Left' },
                                                { key: 'Service Road - Right', label: 'Service Road - Right' },
                                                { key: 'Toll Plaza / Ramp', label: 'Toll Plaza / Ramp' },
                                            ]).map((loc) => (
                                                <Select.Item key={loc.id || loc.key} value={loc.label || loc.key}>{loc.label}</Select.Item>
                                            ))}
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Responsible Party</Text>
                                    <TextField.Root placeholder="e.g. O&M Contractor" value={responsibleParty} onChange={(e) => setResponsibleParty(e.target.value)} />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Recommended Action</Text>
                                    <TextField.Root placeholder="e.g. Rectification Required" value={recommendedAction} onChange={(e) => setRecommendedAction(e.target.value)} />
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Description & Site Extent</Text>
                                <TextArea placeholder="Describe defect size, lane affected, barrier damage, and immediate hazard..." value={description} onChange={(e) => setDescription(e.target.value)} />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenLogModal(false)}>Cancel</Button>
                                <Button type="submit" color="red">Submit & Start SLA Timer</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>

            {/* Convert to Work Order Modal */}
            <Dialog.Root open={canManage && !!openConvertModal} onOpenChange={(open) => !open && setOpenConvertModal(null)}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Convert Defect to Maintenance Work Order</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Dispatch a maintenance crew or contractor to rectify defect {openConvertModal?.defect_number}.
                    </Dialog.Description>
                    <form onSubmit={handleConvertSubmit}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Work Order Title</Text>
                                <TextField.Root value={woTitle} onChange={(e) => setWoTitle(e.target.value)} required />
                            </label>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Category</Text>
                                    <Select.Root value={woCategory} onValueChange={setWoCategory}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="pavement">Pavement Care</Select.Item>
                                            <Select.Item value="guardrail">Guardrail Repair</Select.Item>
                                            <Select.Item value="lighting">Lighting & Electrical</Select.Item>
                                            <Select.Item value="drainage">Drainage & Culvert</Select.Item>
                                            <Select.Item value="bridge">Bridge Joint / Bearing</Select.Item>
                                            <Select.Item value="signage">Signage & Marking</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Estimated Cost (৳)</Text>
                                    <TextField.Root type="number" value={woEstCost} onChange={(e) => setWoEstCost(e.target.value)} />
                                </label>
                            </Grid>

                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Assigned Maintenance Crew / Contractor</Text>
                                <TextField.Root value={woAssignedTo} onChange={(e) => setWoAssignedTo(e.target.value)} required />
                            </label>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenConvertModal(null)}>Cancel</Button>
                                <Button type="submit" color="blue">Issue Work Order & Dispatch</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
