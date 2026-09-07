import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, TextField, Dialog, Select, TextArea, Card } from '@radix-ui/themes';
import {
    CloudIcon,
    PlusIcon,
    CheckBadgeIcon,
    ExclamationTriangleIcon,
    ShieldExclamationIcon,
    MapPinIcon,
    MagnifyingGlassIcon,
    SunIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function EnvironmentalMonitoring({ auth, logs, stats, filters = {} }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const [showModal, setShowModal] = useState(false);
    const [selectedLog, setSelectedLog] = useState(null);

    // Filters
    const [search, setSearch] = useState(filters?.search || '');
    const [typeFilter, setTypeFilter] = useState(filters?.monitoring_type || 'all');
    const [statusFilter, setStatusFilter] = useState(filters?.compliance_status || 'all');

    // Form
    const [logDate, setLogDate] = useState(new Date().toISOString().substring(0, 10));
    const [monitoringType, setMonitoringType] = useState('air_quality');
    const [location, setLocation] = useState('');
    const [chainage, setChainage] = useState('');
    const [direction, setDirection] = useState('both');
    const [measuredValue, setMeasuredValue] = useState('');
    const [measuredUnit, setMeasuredUnit] = useState('ppm');
    const [threshold, setThreshold] = useState('');
    const [complianceStatus, setComplianceStatus] = useState('compliant');
    const [weather, setWeather] = useState('Sunny');
    const [description, setDescription] = useState('');
    const [correctiveAction, setCorrectiveAction] = useState('');

    const logList = logs?.data || logs || [];

    const statsData = [
        {
            title: 'Environmental Logs',
            value: stats?.total_logs ?? logList.length,
            icon: <CloudIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Environmental checkpoints recorded',
        },
        {
            title: 'Compliance Rate',
            value: `${stats?.compliance_rate ?? 100}%`,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Readings within regulatory limits',
        },
        {
            title: 'Minor Exceedances',
            value: stats?.minor_exceedance_count ?? 0,
            icon: <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Advisory warnings / mitigation required',
        },
        {
            title: 'Critical Violations',
            value: stats?.critical_violation_count ?? 0,
            icon: <ShieldExclamationIcon style={{ width: 22, height: 22 }} />,
            color: 'red',
            description: 'Exceeding statutory environmental caps',
        },
    ];

    const handleSave = (e) => {
        e.preventDefault();
        router.post('/om/environmental', {
            log_date: logDate,
            monitoring_type: monitoringType,
            location: location || null,
            chainage: chainage || null,
            direction: direction || null,
            measured_value: measuredValue ? parseFloat(measuredValue) : null,
            measured_unit: measuredUnit || null,
            regulatory_threshold: threshold ? parseFloat(threshold) : null,
            compliance_status: complianceStatus,
            weather_condition: weather || null,
            description: description || null,
            corrective_action_taken: correctiveAction || null,
        }, {
            onSuccess: () => {
                setShowModal(false);
                setDescription('');
                setCorrectiveAction('');
            },
            onError: showOperationMutationErrors,
        });
    };

    const handleFilter = () => {
        router.get('/om/environmental', {
            search: search || undefined,
            monitoring_type: typeFilter !== 'all' ? typeFilter : undefined,
            compliance_status: statusFilter !== 'all' ? statusFilter : undefined,
        }, { preserveState: true });
    };

    const statusBadgeColor = (s) => ({
        compliant: 'green',
        minor_exceedance: 'amber',
        critical_violation: 'red',
    }[s] || 'gray');

    const typeUnitDefaults = {
        air_quality: 'µg/m³ (PM2.5)',
        noise: 'dBA',
        water_runoff: 'pH',
        waste_discharge: 'mg/L',
        soil_erosion: 'severity',
        flora_fauna: 'index',
    };

    const handleTypeChange = (val) => {
        setMonitoringType(val);
        setMeasuredUnit(typeUnitDefaults[val] || '');
    };

    return (
        <App>
            <Head title="Environmental & Weather Monitoring - DBEDC O&M" />

            <Box p="6">
                {/* Header */}
                <Flex justify="between" align="center" mb="5" wrap="wrap" gap="3">
                    <Box>
                        <Flex align="center" gap="2">
                            <CloudIcon style={{ width: 28, height: 28, color: '#3b82f6' }} />
                            <Heading size="6">Environmental & Weather Monitoring</Heading>
                        </Flex>
                        <Text size="2" color="gray">
                            DOE regulatory compliance, roadside emissions, noise levels, drainage runoff & weather alerts
                        </Text>
                    </Box>

                    {canManage && (
                        <Button color="blue" onClick={() => setShowModal(true)}>
                            <PlusIcon style={{ width: 16, height: 16 }} />
                            Record Reading
                        </Button>
                    )}
                </Flex>

                {/* Stats */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Filters */}
                <Panel mb="4">
                    <Flex gap="3" align="center" wrap="wrap">
                        <Box style={{ flex: 1, minWidth: '220px' }}>
                            <TextField.Root
                                placeholder="Search location or log code..."
                                value={search}
                                onChange={e => setSearch(e.target.value)}
                                onKeyDown={e => e.key === 'Enter' && handleFilter()}
                            >
                                <TextField.Slot>
                                    <MagnifyingGlassIcon style={{ width: 16, height: 16 }} />
                                </TextField.Slot>
                            </TextField.Root>
                        </Box>

                        <Select.Root value={typeFilter} onValueChange={setTypeFilter}>
                            <Select.Trigger placeholder="Monitoring Type" />
                            <Select.Content>
                                <Select.Item value="all">All Types</Select.Item>
                                <Select.Item value="air_quality">Air Quality (PM2.5 / PM10)</Select.Item>
                                <Select.Item value="noise">Acoustic Noise (dBA)</Select.Item>
                                <Select.Item value="water_runoff">Drainage / Runoff Quality</Select.Item>
                                <Select.Item value="waste_discharge">Waste Discharge</Select.Item>
                                <Select.Item value="soil_erosion">Soil Erosion & Embankment</Select.Item>
                            </Select.Content>
                        </Select.Root>

                        <Select.Root value={statusFilter} onValueChange={setStatusFilter}>
                            <Select.Trigger placeholder="Compliance" />
                            <Select.Content>
                                <Select.Item value="all">All Statuses</Select.Item>
                                <Select.Item value="compliant">Compliant</Select.Item>
                                <Select.Item value="minor_exceedance">Minor Exceedance</Select.Item>
                                <Select.Item value="critical_violation">Critical Violation</Select.Item>
                            </Select.Content>
                        </Select.Root>

                        <Button variant="soft" color="gray" onClick={handleFilter}>
                            Filter
                        </Button>
                    </Flex>
                </Panel>

                {/* Table */}
                <Panel>
                    <Table.Root variant="surface">
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Log Code</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Date</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Type</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Location / Chainage</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell align="right">Reading</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell align="right">Threshold</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Compliance</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Weather</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell align="right">Details</Table.ColumnHeaderCell>
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {logList.map(log => (
                                <Table.Row key={log.id}>
                                    <Table.Cell>
                                        <Text weight="bold" size="2">{log.log_code}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{log.log_date?.substring(0, 10)}</Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge size="1" color="blue" variant="soft">
                                            {log.monitoring_type?.replace(/_/g, ' ')}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Text size="2">{log.location || 'Expressway Mainline'}</Text>
                                        {log.chainage && (
                                            <Flex align="center" gap="1" mt="1">
                                                <MapPinIcon style={{ width: 12, height: 12, color: 'var(--gray-9)' }} />
                                                <Text size="1" color="gray">KM {log.chainage} ({log.direction || 'both'})</Text>
                                            </Flex>
                                        )}
                                    </Table.Cell>
                                    <Table.Cell align="right">
                                        <Text weight="bold" size="2">
                                            {log.measured_value ?? '—'} {log.measured_unit}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell align="right">
                                        <Text size="2" color="gray">
                                            {log.regulatory_threshold ?? '—'} {log.measured_unit}
                                        </Text>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Badge color={statusBadgeColor(log.compliance_status)}>
                                            {log.compliance_status?.replace(/_/g, ' ')?.toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    <Table.Cell>
                                        <Flex align="center" gap="1">
                                            <SunIcon style={{ width: 14, height: 14, color: 'var(--amber-9)' }} />
                                            <Text size="1">{log.weather_condition || 'Normal'}</Text>
                                        </Flex>
                                    </Table.Cell>
                                    <Table.Cell align="right">
                                        <Button size="1" variant="ghost" color="gray" onClick={() => setSelectedLog(log)}>
                                            View
                                        </Button>
                                    </Table.Cell>
                                </Table.Row>
                            ))}
                            {logList.length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={9}>
                                        <Text align="center" color="gray" size="2" style={{ display: 'block', padding: '24px' }}>
                                            No environmental monitoring logs recorded yet.
                                        </Text>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>

                {/* Create Modal */}
                <Dialog.Root open={showModal} onOpenChange={setShowModal}>
                    <Dialog.Content maxWidth="550px">
                        <Dialog.Title>Record Environmental Reading</Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Log environmental parameters and verify DOE compliance limits
                        </Dialog.Description>

                        <form onSubmit={handleSave}>
                            <Flex direction="column" gap="3">
                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Log Date *</Text>
                                        <TextField.Root
                                            type="date"
                                            required
                                            value={logDate}
                                            onChange={e => setLogDate(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Monitoring Type *</Text>
                                        <Select.Root value={monitoringType} onValueChange={handleTypeChange}>
                                            <Select.Trigger style={{ width: '100%', marginTop: 4 }} />
                                            <Select.Content>
                                                <Select.Item value="air_quality">Air Quality (PM2.5)</Select.Item>
                                                <Select.Item value="noise">Noise (dBA)</Select.Item>
                                                <Select.Item value="water_runoff">Water Runoff / pH</Select.Item>
                                                <Select.Item value="waste_discharge">Waste Discharge</Select.Item>
                                                <Select.Item value="soil_erosion">Soil Erosion</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 2 }}>
                                        <Text size="2" weight="bold">Location</Text>
                                        <TextField.Root
                                            placeholder="e.g., Kanchan Bridge Toll Plaza"
                                            value={location}
                                            onChange={e => setLocation(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Chainage (KM)</Text>
                                        <TextField.Root
                                            placeholder="12+400"
                                            value={chainage}
                                            onChange={e => setChainage(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Measured Value</Text>
                                        <TextField.Root
                                            type="number"
                                            step="0.01"
                                            placeholder="e.g., 68.5"
                                            value={measuredValue}
                                            onChange={e => setMeasuredValue(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Unit</Text>
                                        <TextField.Root
                                            placeholder="e.g., dBA or µg/m³"
                                            value={measuredUnit}
                                            onChange={e => setMeasuredUnit(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Threshold Limit</Text>
                                        <TextField.Root
                                            type="number"
                                            step="0.01"
                                            placeholder="e.g., 70"
                                            value={threshold}
                                            onChange={e => setThreshold(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                <Flex gap="3">
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Compliance Status *</Text>
                                        <Select.Root value={complianceStatus} onValueChange={setComplianceStatus}>
                                            <Select.Trigger style={{ width: '100%', marginTop: 4 }} />
                                            <Select.Content>
                                                <Select.Item value="compliant">Compliant</Select.Item>
                                                <Select.Item value="minor_exceedance">Minor Exceedance</Select.Item>
                                                <Select.Item value="critical_violation">Critical Violation</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Box>
                                    <Box style={{ flex: 1 }}>
                                        <Text size="2" weight="bold">Weather Condition</Text>
                                        <TextField.Root
                                            placeholder="Sunny / Heavy Rain / Foggy"
                                            value={weather}
                                            onChange={e => setWeather(e.target.value)}
                                            mt="1"
                                        />
                                    </Box>
                                </Flex>

                                <Box>
                                    <Text size="2" weight="bold">Description / Observations</Text>
                                    <TextArea
                                        placeholder="Monitoring conditions, ambient traffic levels, or equipment used..."
                                        value={description}
                                        onChange={e => setDescription(e.target.value)}
                                        rows={2}
                                        mt="1"
                                    />
                                </Box>

                                {complianceStatus !== 'compliant' && (
                                    <Box>
                                        <Text size="2" weight="bold" color="amber">Immediate Corrective Action</Text>
                                        <TextArea
                                            placeholder="Action taken to mitigate exceedance (e.g., water spraying, acoustic baffle check)..."
                                            value={correctiveAction}
                                            onChange={e => setCorrectiveAction(e.target.value)}
                                            rows={2}
                                            mt="1"
                                        />
                                    </Box>
                                )}
                            </Flex>

                            <Flex justify="end" gap="2" mt="4">
                                <Dialog.Close>
                                    <Button variant="soft" color="gray" type="button">Cancel</Button>
                                </Dialog.Close>
                                <Button color="blue" type="submit">
                                    Save Reading
                                </Button>
                            </Flex>
                        </form>
                    </Dialog.Content>
                </Dialog.Root>

                {/* Detail View Modal */}
                <Dialog.Root open={!!selectedLog} onOpenChange={(open) => !open && setSelectedLog(null)}>
                    <Dialog.Content maxWidth="500px">
                        <Dialog.Title>
                            <Flex justify="between" align="center">
                                <span>{selectedLog?.log_code}</span>
                                <Badge color={statusBadgeColor(selectedLog?.compliance_status)}>
                                    {selectedLog?.compliance_status?.replace(/_/g, ' ')?.toUpperCase()}
                                </Badge>
                            </Flex>
                        </Dialog.Title>
                        <Dialog.Description size="2" color="gray" mb="3">
                            Recorded on {selectedLog?.log_date?.substring(0, 10)}
                        </Dialog.Description>

                        <Flex direction="column" gap="3">
                            <Box style={{ background: 'var(--gray-3)', padding: 12, borderRadius: 8 }}>
                                <Text size="1" color="gray">Parameter & Reading</Text>
                                <Heading size="4" mt="1">
                                    {selectedLog?.measured_value} {selectedLog?.measured_unit}
                                </Heading>
                                <Text size="2" color="gray">
                                    Threshold Cap: {selectedLog?.regulatory_threshold ?? 'N/A'} {selectedLog?.measured_unit}
                                </Text>
                            </Box>

                            <Box>
                                <Text size="1" color="gray">Location & Chainage</Text>
                                <Text size="2" weight="medium">
                                    {selectedLog?.location || 'Expressway Corridor'} (KM {selectedLog?.chainage || 'N/A'})
                                </Text>
                            </Box>

                            <Box>
                                <Text size="1" color="gray">Weather Condition</Text>
                                <Text size="2">{selectedLog?.weather_condition || 'Standard'}</Text>
                            </Box>

                            {selectedLog?.description && (
                                <Box>
                                    <Text size="1" color="gray">Findings</Text>
                                    <Text size="2">{selectedLog.description}</Text>
                                </Box>
                            )}

                            {selectedLog?.corrective_action_taken && (
                                <Box style={{ background: 'var(--amber-2)', border: '1px solid var(--amber-6)', padding: 10, borderRadius: 6 }}>
                                    <Text size="1" weight="bold" color="amber">Mitigation Action Taken:</Text>
                                    <Text size="2" color="amber">{selectedLog.corrective_action_taken}</Text>
                                </Box>
                            )}
                        </Flex>

                        <Flex justify="end" mt="4">
                            <Dialog.Close>
                                <Button variant="soft" color="gray">Close</Button>
                            </Dialog.Close>
                        </Flex>
                    </Dialog.Content>
                </Dialog.Root>
            </Box>
        </App>
    );
}
