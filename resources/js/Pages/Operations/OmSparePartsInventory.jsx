import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, Tabs, Card } from '@radix-ui/themes';
import {
    ArchiveBoxIcon,
    PlusIcon,
    ExclamationTriangleIcon,
    CurrencyDollarIcon,
    WrenchIcon,
    ArrowDownTrayIcon,
    CheckCircleIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function OmSparePartsInventory({ auth, inventory = {} }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [showLogModal, setShowLogModal] = useState(false);

    // Consumption modal form
    const [workOrderId, setWorkOrderId] = useState('');
    const [itemName, setItemName] = useState('');
    const [itemCode, setItemCode] = useState('');
    const [quantity, setQuantity] = useState('');
    const [unit, setUnit] = useState('bags');
    const [unitCost, setUnitCost] = useState('');

    const items = inventory?.items || [];
    const consumptionLogs = inventory?.recent_consumption || [];
    const stats = inventory?.stats || {};

    const statsData = [
        {
            title: 'Inventory SKUs',
            value: stats?.total_skus ?? items.length,
            icon: <ArchiveBoxIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Cataloged expressway spare parts',
        },
        {
            title: 'Low Stock Alerts',
            value: stats?.low_stock_count ?? 0,
            icon: <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />,
            color: 'red',
            description: 'Below safe reorder thresholds',
        },
        {
            title: 'Total Consumption Logs',
            value: stats?.total_consumptions ?? consumptionLogs.length,
            icon: <WrenchIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Recorded work order usages',
        },
        {
            title: 'Consumption Value',
            value: `BDT ${(stats?.total_consumption_value ?? 0).toLocaleString()}`,
            icon: <CurrencyDollarIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Aggregated maintenance material cost',
        },
    ];

    const handleLogMaterial = (e) => {
        e.preventDefault();
        router.post(`/om/work-orders/${workOrderId}/materials`, {
            item_name: itemName,
            item_code: itemCode || null,
            quantity_used: parseFloat(quantity),
            unit,
            unit_cost: unitCost ? parseFloat(unitCost) : null,
        }, {
            onSuccess: () => {
                setShowLogModal(false);
                setItemName('');
                setItemCode('');
                setQuantity('');
                setWorkOrderId('');
                setUnitCost('');
            },
            onError: showOperationMutationErrors,
        });
    };

    return (
        <App>
            <Head title="Spare Parts & Materials Inventory - DBEDC O&M" />

            <Box p="6">
                {/* Header */}
                <Flex justify="between" align="center" mb="5" wrap="wrap" gap="3">
                    <Box>
                        <Flex align="center" gap="2">
                            <ArchiveBoxIcon style={{ width: 28, height: 28, color: '#3b82f6' }} />
                            <Heading size="6">O&M Spare Parts & Materials Inventory</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            Expressway maintenance inventory, asphalt/guardrail materials, and WO consumption tracking
                        </Text>
                    </Box>

                    {canManage && (
                        <Button color="blue" onClick={() => setShowLogModal(true)}>
                            <PlusIcon style={{ width: 16, height: 16 }} />
                            Log Material Consumption
                        </Button>
                    )}
                </Flex>

                {/* Stats */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Tabs: SKUs vs Consumption */}
                <Tabs.Root defaultValue="skus">
                    <Tabs.List>
                        <Tabs.Trigger value="skus">Cataloged Spare Parts ({items.length})</Tabs.Trigger>
                        <Tabs.Trigger value="consumption">Recent Material Usages ({consumptionLogs.length})</Tabs.Trigger>
                    </Tabs.List>

                    {/* SKUs Tab */}
                    <Tabs.Content value="skus" style={{ paddingTop: 16 }}>
                        <Panel>
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>SKU / Code</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Item Description</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell align="right">Stock on Hand</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell align="right">Reorder Level</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell align="right">Unit Cost (BDT)</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Stock Status</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {items.map(it => {
                                        const isLow = it.stock_on_hand <= (it.reorder_level || 0);
                                        return (
                                            <Table.Row key={it.id || it.item_code}>
                                                <Table.Cell>
                                                    <Text weight="bold" size="2">{it.item_code || '—'}</Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Text weight="medium" size="2">{it.item_name || it.name}</Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Badge size="1" color="gray" variant="soft">
                                                        {it.category || 'General O&M'}
                                                    </Badge>
                                                </Table.Cell>
                                                <Table.Cell align="right">
                                                    <Text weight="bold" size="2">
                                                        {it.stock_on_hand} {it.unit}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell align="right">
                                                    <Text size="2" color="gray">
                                                        {it.reorder_level ?? '—'} {it.unit}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell align="right">
                                                    <Text size="2">
                                                        {it.unit_cost ? Number(it.unit_cost).toLocaleString() : '—'}
                                                    </Text>
                                                </Table.Cell>
                                                <Table.Cell>
                                                    <Badge color={isLow ? 'red' : 'green'}>
                                                        {isLow ? 'LOW STOCK' : 'IN STOCK'}
                                                    </Badge>
                                                </Table.Cell>
                                            </Table.Row>
                                        );
                                    })}
                                    {items.length === 0 && (
                                        <Table.Row>
                                            <Table.Cell colSpan={7}>
                                                <Text align="center" color="gray" size="2" style={{ display: 'block', padding: '24px' }}>
                                                    No cataloged spare parts yet.
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    )}
                                </Table.Body>
                            </Table.Root>
                        </Panel>
                    </Tabs.Content>

                    {/* Consumption Logs Tab */}
                    <Tabs.Content value="consumption" style={{ paddingTop: 16 }}>
                        <Panel>
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Work Order</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Material Item</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell align="right">Quantity Used</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell align="right">Unit Cost</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell align="right">Total Cost (BDT)</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Logged By</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {consumptionLogs.map(log => (
                                        <Table.Row key={log.id}>
                                            <Table.Cell>
                                                <Text size="1" color="gray">{log.created_at?.substring(0, 16) || '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Badge color="blue" variant="surface">
                                                    {log.work_order?.work_order_number || `WO #${log.work_order_id}`}
                                                </Badge>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text weight="bold" size="2">{log.item_name}</Text>
                                                {log.item_code && (
                                                    <Text size="1" color="gray" display="block">Code: {log.item_code}</Text>
                                                )}
                                            </Table.Cell>
                                            <Table.Cell align="right">
                                                <Text weight="bold" size="2">
                                                    {log.quantity_used} {log.unit}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell align="right">
                                                <Text size="2">{log.unit_cost ? Number(log.unit_cost).toLocaleString() : '—'}</Text>
                                            </Table.Cell>
                                            <Table.Cell align="right">
                                                <Text weight="bold" size="2" color="green">
                                                    {log.unit_cost ? (Number(log.unit_cost) * Number(log.quantity_used)).toLocaleString() : '—'}
                                                </Text>
                                            </Table.Cell>
                                            <Table.Cell>
                                                <Text size="1" color="gray">{log.logged_by_user?.name || 'Field Crew'}</Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    ))}
                                    {consumptionLogs.length === 0 && (
                                        <Table.Row>
                                            <Table.Cell colSpan={7}>
                                                <Text align="center" color="gray" size="2" style={{ display: 'block', padding: '24px' }}>
                                                    No material consumption logs recorded yet.
                                                </Text>
                                            </Table.Cell>
                                        </Table.Row>
                                    )}
                                </Table.Body>
                            </Table.Root>
                        </Panel>
                    </Tabs.Content>
                </Tabs.Root>

                {/* Log Material Consumption Modal */}
                <Dialog.Root open={showLogModal} onOpenChange={setShowLogModal}>
                    <Dialog.Content maxWidth="500px">
                        <Dialog.Title>Log Material Consumption</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Record parts or consumables expended on an active work order
                        </Dialog.Description>

                        <form onSubmit={handleLogMaterial}>
                            <Flex direction="column" gap="3">
                                <Box>
                                    <Text size="2" weight="bold">Work Order ID *</Text>
                                    <TextField.Root
                                        required
                                        type="number"
                                        placeholder="Enter work order ID (e.g., 1)"
                                        value={workOrderId}
                                        onChange={e => setWorkOrderId(e.target.value)}
                                        mt="1"
                                    />
                                </Box>

                                <Box>
                                    <Text size="2" weight="bold">Material / Item Name *</Text>
                                    <TextField.Root
                                        required
                                        placeholder="e.g., Cold Mix Asphalt / W-Beam Guardrail"
                                        value={itemName}
                                        onChange={e => setItemName(e.target.value)}
                                        mt="1"
                                    />
                                </Box>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Item Code</Text>
                                        <TextField.Root
                                            placeholder="SKU-2026-01"
                                            value={itemCode}
                                            onChange={e => setItemCode(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Unit *</Text>
                                        <Select.Root value={unit} onValueChange={setUnit}>
                                            <Select.Trigger style={{ width: '100%', marginTop: 4 }} />
                                            <Select.Content>
                                                <Select.Item value="bags">Bags</Select.Item>
                                                <Select.Item value="tonnes">Tonnes</Select.Item>
                                                <Select.Item value="litres">Litres</Select.Item>
                                                <Select.Item value="pieces">Pieces / Units</Select.Item>
                                                <Select.Item value="meters">Meters</Select.Item>
                                                <Select.Item value="sq_m">Square Meters</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Quantity Used *</Text>
                                        <TextField.Root
                                            required
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            placeholder="e.g., 15"
                                            value={quantity}
                                            onChange={e => setQuantity(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Unit Cost (BDT)</Text>
                                        <TextField.Root
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            placeholder="e.g., 1200"
                                            value={unitCost}
                                            onChange={e => setUnitCost(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>
                            </Flex>

                            <Flex justify="end" gap="2" mt="4">
                                <Dialog.Close>
                                    <Button variant="soft" color="gray" type="button">Cancel</Button>
                                </Dialog.Close>
                                <Button color="blue" type="submit">
                                    Record Consumption
                                </Button>
                            </Flex>
                        </form>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
