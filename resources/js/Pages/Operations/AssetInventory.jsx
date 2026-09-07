import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, TextField, Dialog, Select, Separator } from '@radix-ui/themes';
import {
    BuildingOffice2Icon,
    PlusIcon,
    CpuChipIcon,
    CheckCircleIcon,
    ExclamationTriangleIcon,
    CurrencyDollarIcon,
    WrenchScrewdriverIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function AssetInventory({ auth, assets, stats, filters }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.equipment.manage') || auth?.roles?.includes('Super Administrator');
    const [openModal, setOpenModal] = useState(false);
    const [name, setName] = useState('');
    const [category, setCategory] = useState('pavement_civil');
    const [startChainage, setStartChainage] = useState('Ch 0+000');
    const [endChainage, setEndChainage] = useState('Ch 12+000');
    const [direction, setDirection] = useState('both');
    const [locationDescription, setLocationDescription] = useState('');
    const [purchaseCost, setPurchaseCost] = useState('');
    const [expectedLifespan, setExpectedLifespan] = useState('15');
    const [conditionScore, setConditionScore] = useState('90');

    const assetList = assets?.data || [];

    const handleSubmit = (e) => {
        e.preventDefault();
        router.post('/om/assets', {
            name,
            category,
            start_chainage: startChainage,
            end_chainage: endChainage,
            direction,
            location_description: locationDescription,
            purchase_cost: Number(purchaseCost) || 0,
            expected_lifespan_years: Number(expectedLifespan),
            condition_score: Number(conditionScore),
        }, {
            onSuccess: () => {
                setOpenModal(false);
                setName('');
            }
        });
    };

    const statItems = [
        { key: 'total', title: 'Total Registered Assets', value: stats?.total_assets || assetList.length, color: 'blue' },
        { key: 'active', title: 'Active In Service', value: stats?.active_assets || 0, color: 'green', icon: <CheckCircleIcon /> },
        { key: 'critical', title: 'Critical Attention', value: stats?.critical_attention || 0, color: 'red', icon: <ExclamationTriangleIcon /> },
        { key: 'pci', title: 'Avg Expressway PCI', value: `${stats?.avg_condition_pci || 88.5}/100`, color: 'indigo' },
        { key: 'valuation', title: 'Asset Valuation', value: `৳ ${(Number(stats?.total_asset_valuation || 0) / 10000000).toFixed(1)} Cr`, color: 'amber', icon: <CurrencyDollarIcon /> },
    ];

    const getConditionBadge = (grade, score) => {
        const colors = { excellent: 'green', good: 'blue', fair: 'amber', poor: 'orange', critical: 'red' };
        return (
            <Badge color={colors[grade] || 'gray'} variant="soft" style={{ borderRadius: 999 }}>
                {grade ? grade.toUpperCase() : 'GOOD'} ({score}%)
            </Badge>
        );
    };

    return (
        <App auth={auth}>
            <Head title="Linear Asset Inventory & Infrastructure Health" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--indigo-a3)', borderRadius: 12, border: '1px solid var(--indigo-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <BuildingOffice2Icon style={{ width: 22, height: 22, color: 'var(--indigo-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Expressway Linear Asset Inventory (LRS Ch 0+000 - Ch 48+000)
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Civil Pavement, Bridges, Guardrails, High-Mast Lighting, ITS Hardware & Toll Plaza Asset Lifecycle
                                        </Text>
                                    </Box>
                                </Flex>
                                {canManage && <Button color="indigo" onClick={() => setOpenModal(true)} style={{ borderRadius: 12, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                    <PlusIcon width={16} height={16} /> Register Asset
                                </Button>}
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '2', md: '5' }} mb="4" />

                        {/* Assets Table */}
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
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Asset Code</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 260 }}>Asset Name</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Category</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 160 }}>Chainage Bounds</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 140 }}>Condition (PCI)</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ minWidth: 110 }}>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell style={{ textAlign: 'right', minWidth: 140 }}>Book Valuation</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {assetList.map((ast) => (
                                        <Table.Row key={ast.id} align="center">
                                            <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>
                                                {ast.asset_code}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text weight="bold" style={{ display: 'block', maxWidth: 260, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>
                                                    {ast.name}
                                                </Text>
                                                <Text size="1" color="gray">{ast.location_description || 'Main Carriageway'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color="purple" variant="soft" style={{ borderRadius: 999 }}>{ast.category?.replace(/_/g, ' ').toUpperCase()}</Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="2" style={{ whiteSpace: 'nowrap' }}>
                                                    {ast.start_chainage} {ast.end_chainage ? `- ${ast.end_chainage}` : ''} ({ast.direction})
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                {getConditionBadge(ast.condition_grade, ast.condition_score)}
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color={ast.operational_status === 'active' ? 'green' : 'amber'} variant="surface" style={{ borderRadius: 999 }}>
                                                    {ast.operational_status?.toUpperCase()}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell style={{ textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 600 }}>
                                                ৳ {Number(ast.purchase_cost || 0).toLocaleString()}
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    </Panel>
                </Box>
            </Flex>

            {/* Register Asset Modal */}
            <Dialog.Root open={canManage && openModal} onOpenChange={setOpenModal}>
                <Dialog.Content style={{ maxWidth: 520 }}>
                    <Dialog.Title>Register Highway Asset</Dialog.Title>
                    <Dialog.Description size="2" mb="4">
                        Add a linear carriageway segment, bridge structure, safety barrier, or ITS equipment.
                    </Dialog.Description>
                    <form onSubmit={handleSubmit}>
                        <Flex direction="column" gap="3">
                            <label>
                                <Text as="div" size="2" mb="1" weight="bold">Asset Name</Text>
                                <TextField.Root placeholder="e.g. W-Beam Galvanized Guardrail Section 3" value={name} onChange={(e) => setName(e.target.value)} required />
                            </label>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Category</Text>
                                    <Select.Root value={category} onValueChange={setCategory}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="pavement_civil">Pavement & Civil</Select.Item>
                                            <Select.Item value="bridge_structure">Bridges & Structures</Select.Item>
                                            <Select.Item value="guardrail_safety">Guardrails & Safety</Select.Item>
                                            <Select.Item value="signage_marking">Signages & Markings</Select.Item>
                                            <Select.Item value="drainage_slope">Drainage & Slopes</Select.Item>
                                            <Select.Item value="lighting_electrical">Lighting & Power</Select.Item>
                                            <Select.Item value="its_telecom">ITS & Telecom</Select.Item>
                                            <Select.Item value="toll_equipment">Toll Hardware</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Direction</Text>
                                    <Select.Root value={direction} onValueChange={setDirection}>
                                        <Select.Trigger style={{ width: '100%' }} />
                                        <Select.Content>
                                            <Select.Item value="northbound">Northbound</Select.Item>
                                            <Select.Item value="southbound">Southbound</Select.Item>
                                            <Select.Item value="both">Both Directions</Select.Item>
                                            <Select.Item value="median">Median</Select.Item>
                                            <Select.Item value="interchange">Interchange</Select.Item>
                                            <Select.Item value="toll_plaza">Toll Plaza</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Start Chainage</Text>
                                    <TextField.Root placeholder="e.g. Ch 0+000" value={startChainage} onChange={(e) => setStartChainage(e.target.value)} required />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">End Chainage</Text>
                                    <TextField.Root placeholder="e.g. Ch 12+000" value={endChainage} onChange={(e) => setEndChainage(e.target.value)} />
                                </label>
                            </Grid>

                            <Grid columns="2" gap="3">
                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Purchase / Valuation (৳)</Text>
                                    <TextField.Root type="number" placeholder="5000000" value={purchaseCost} onChange={(e) => setPurchaseCost(e.target.value)} />
                                </label>

                                <label>
                                    <Text as="div" size="2" mb="1" weight="bold">Expected Lifespan (Yrs)</Text>
                                    <TextField.Root type="number" value={expectedLifespan} onChange={(e) => setExpectedLifespan(e.target.value)} />
                                </label>
                            </Grid>

                            <Flex justify="end" gap="3" mt="3">
                                <Button type="button" variant="soft" color="gray" onClick={() => setOpenModal(false)}>Cancel</Button>
                                <Button type="submit" color="indigo">Register Asset</Button>
                            </Flex>
                        </Flex>
                    </form>
                </Dialog.Content>
            </Dialog.Root>
        </App>
    );
}
