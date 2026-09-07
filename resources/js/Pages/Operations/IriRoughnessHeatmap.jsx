import React, { useState } from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, Card, Tooltip, Progress, SegmentedControl } from '@radix-ui/themes';
import {
    ArrowTrendingUpIcon,
    ExclamationTriangleIcon,
    CheckBadgeIcon,
    MapPinIcon,
    ClockIcon,
    ArrowPathIcon,
    InformationCircleIcon,
    SignalIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function IriRoughnessHeatmap({ auth, profile, direction = 'northbound' }) {
    useOperationsRealtimeRefresh();

    const [selectedDirection, setSelectedDirection] = useState(direction);
    const [selectedSegment, setSelectedSegment] = useState(null);
    const [conditionFilter, setConditionFilter] = useState('all');

    const handleDirectionChange = (val) => {
        setSelectedDirection(val);
        router.get(route('om.iri'), { direction: val }, { preserveState: true, replace: true });
    };

    const segments = profile?.segments || [];
    const alerts = profile?.deterioration_alerts || [];

    const statsData = [
        {
            title: 'Expressway Mean IRI',
            value: `${profile?.average_iri ?? 2.1} m/km`,
            icon: <ArrowTrendingUpIcon style={{ width: 22, height: 22 }} />,
            color: (profile?.average_iri || 2.1) < 2.5 ? 'green' : 'amber',
            description: 'World Bank HDM-4 ride quality standard',
        },
        {
            title: 'Smooth Pavement (IRI < 2.0)',
            value: `${profile?.smooth_percentage ?? 75}%`,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Superior expressway comfort index',
        },
        {
            title: 'Fair Condition (2.0 - 3.5)',
            value: `${profile?.fair_percentage ?? 20}%`,
            icon: <InformationCircleIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Routine maintenance candidate zones',
        },
        {
            title: 'Severe Roughness (> 3.5)',
            value: `${profile?.rough_percentage ?? 5}%`,
            icon: <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />,
            color: 'red',
            description: 'Critical mill-and-overlay required',
        },
    ];

    const getConditionColor = (cond) => {
        if (cond === 'good' || cond === 'smooth') return '#10b981'; // emerald green
        if (cond === 'fair') return '#f59e0b'; // amber
        return '#ef4444'; // red
    };

    const filteredSegments = segments.filter(seg => {
        if (conditionFilter === 'all') return true;
        return seg.condition === conditionFilter;
    });

    return (
        <App auth={auth}>
            <Head title="Pavement Roughness IRI Heatmap - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                {/* Page Title & Direction Selector */}
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">Pavement Roughness (IRI) Heatmap</Heading>
                            <Badge color="blue" variant="soft">ASTM E1926 Class 3 Profiling</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            Continuous 48-km linear international roughness index (IRI) aggregated from highway patrol vehicle accelerometer telemetry.
                        </Text>
                    </Box>
                    <SegmentedControl.Root value={selectedDirection} onValueChange={handleDirectionChange} size="3">
                        <SegmentedControl.Item value="northbound">Northbound (Joydebpur)</SegmentedControl.Item>
                        <SegmentedControl.Item value="southbound">Southbound (Madanpur)</SegmentedControl.Item>
                    </SegmentedControl.Root>
                </Flex>

                {/* KPI Cards */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* 48-km Linear Expressway Heatmap Visualization Bar */}
                <Panel mb="5">
                    <Flex justify="between" align="center" mb="3">
                        <Box>
                            <Heading size="4" weight="bold">Continuous Linear 48-km Expressway Profiler</Heading>
                            <Text size="2" color="gray">
                                Click on any 500m segment to inspect local IRI readings, roughness class, and deterioration trends.
                            </Text>
                        </Box>
                        <Flex gap="3" align="center">
                            <Flex align="center" gap="1">
                                <Box style={{ width: 12, height: 12, backgroundColor: '#10b981', borderRadius: 2 }} />
                                <Text size="1" color="gray">Smooth (&lt;2.0)</Text>
                            </Flex>
                            <Flex align="center" gap="1">
                                <Box style={{ width: 12, height: 12, backgroundColor: '#f59e0b', borderRadius: 2 }} />
                                <Text size="1" color="gray">Fair (2.0-3.5)</Text>
                            </Flex>
                            <Flex align="center" gap="1">
                                <Box style={{ width: 12, height: 12, backgroundColor: '#ef4444', borderRadius: 2 }} />
                                <Text size="1" color="gray">Rough (&gt;3.5)</Text>
                            </Flex>
                        </Flex>
                    </Flex>

                    {/* Linear Strip Grid */}
                    <Box style={{ background: 'var(--gray-3)', padding: 12, borderRadius: 8 }}>
                        <Flex gap="1" style={{ overflowX: 'auto', paddingBottom: 6 }}>
                            {segments.map((seg, idx) => {
                                const isSelected = selectedSegment?.chainage_km === seg.chainage_km;
                                const barColor = getConditionColor(seg.condition);
                                return (
                                    <Tooltip key={idx} content={`${seg.chainage_label}: IRI ${seg.iri_value} m/km (${seg.condition.toUpperCase()})`}>
                                        <Box
                                            onClick={() => setSelectedSegment(seg)}
                                            style={{
                                                flex: '1 0 14px',
                                                height: 48,
                                                backgroundColor: barColor,
                                                borderRadius: 3,
                                                cursor: 'pointer',
                                                transition: 'transform 0.15s ease, outline 0.15s ease',
                                                outline: isSelected ? '3px solid var(--indigo-9)' : 'none',
                                                transform: isSelected ? 'scaleY(1.15)' : 'scaleY(1)',
                                            }}
                                        />
                                    </Tooltip>
                                );
                            })}
                        </Flex>
                        <Flex justify="between" mt="2">
                            <Text size="1" weight="medium" color="gray">Km 0+000 ({selectedDirection === 'northbound' ? 'Madanpur Origin' : 'Joydebpur Origin'})</Text>
                            <Text size="1" weight="medium" color="gray">Km 24+000 (Kanchan Interchange)</Text>
                            <Text size="1" weight="medium" color="gray">Km 48+000 (Expressway Terminus)</Text>
                        </Flex>
                    </Box>

                    {/* Selected Segment Inspector Card */}
                    {selectedSegment && (
                        <Card mt="3" style={{ background: 'var(--indigo-2)', border: '1px solid var(--indigo-6)' }}>
                            <Flex justify="between" align="center" wrap="wrap" gap="3">
                                <Flex align="center" gap="3">
                                    <Box style={{
                                        width: 14,
                                        height: 14,
                                        borderRadius: '50%',
                                        backgroundColor: getConditionColor(selectedSegment.condition)
                                    }} />
                                    <Box>
                                        <Heading size="3" weight="bold">Selected: {selectedSegment.chainage_label}</Heading>
                                        <Text size="1" color="gray">Chainage {selectedSegment.chainage_km} km | Direction: {selectedDirection.toUpperCase()}</Text>
                                    </Box>
                                </Flex>
                                <Flex gap="4" align="center">
                                    <Box>
                                        <Text size="1" color="gray">Roughness Index</Text>
                                        <Text size="3" weight="bold">{selectedSegment.iri_value} m/km</Text>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Classification</Text>
                                        <Badge color={selectedSegment.condition === 'good' ? 'green' : selectedSegment.condition === 'fair' ? 'amber' : 'red'}>
                                            {selectedSegment.condition.toUpperCase()}
                                        </Badge>
                                    </Box>
                                    <Box>
                                        <Text size="1" color="gray">Patrol Speed</Text>
                                        <Text size="2" weight="bold">{selectedSegment.avg_speed_kmh || 65} km/h</Text>
                                    </Box>
                                    <Button size="1" variant="soft" color="gray" onClick={() => setSelectedSegment(null)}>
                                        Clear Selection
                                    </Button>
                                </Flex>
                            </Flex>
                        </Card>
                    )}
                </Panel>

                {/* Deterioration Velocity Alerts */}
                {alerts.length > 0 && (
                    <Panel mb="5">
                        <Flex align="center" gap="2" mb="3">
                            <ExclamationTriangleIcon style={{ width: 20, height: 20, color: '#f59e0b' }} />
                            <Heading size="4" weight="bold">Deterioration Velocity Alerts (&gt;0.3 m/km/year growth)</Heading>
                        </Flex>
                        <Box style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 12 }}>
                            {alerts.map((alert, idx) => (
                                <Card key={idx} style={{ borderLeft: '4px solid var(--amber-9)' }}>
                                    <Flex justify="between" align="start" mb="2">
                                        <Box>
                                            <Text size="2" weight="bold">{alert.chainage}</Text>
                                            <Text size="1" color="gray">Current IRI: {alert.iri} m/km</Text>
                                        </Box>
                                        <Badge color="amber" variant="solid">+{alert.delta_per_year} m/km/yr</Badge>
                                    </Flex>
                                    <Text size="2" color="gray" mb="2">{alert.recommendation}</Text>
                                    <Flex justify="between" align="center">
                                        <Text size="1" color="gray">Surveyed via Mobile Patrol</Text>
                                        <Button size="1" variant="soft" color="indigo" onClick={() => router.visit(route('om.work-orders'))}>
                                            Create Work Order
                                        </Button>
                                    </Flex>
                                </Card>
                            ))}
                        </Box>
                    </Panel>
                )}

                {/* Detailed Segments Table */}
                <Panel>
                    <Flex justify="between" align="center" mb="4" wrap="wrap" gap="3">
                        <Heading size="4" weight="bold">Linear Segment Condition Breakdown (0.5 km Intervals)</Heading>
                        <Flex gap="2">
                            <Button size="1" variant={conditionFilter === 'all' ? 'solid' : 'soft'} color="gray" onClick={() => setConditionFilter('all')}>
                                All ({segments.length})
                            </Button>
                            <Button size="1" variant={conditionFilter === 'good' ? 'solid' : 'soft'} color="green" onClick={() => setConditionFilter('good')}>
                                Smooth
                            </Button>
                            <Button size="1" variant={conditionFilter === 'fair' ? 'solid' : 'soft'} color="amber" onClick={() => setConditionFilter('fair')}>
                                Fair
                            </Button>
                            <Button size="1" variant={conditionFilter === 'poor' ? 'solid' : 'soft'} color="red" onClick={() => setConditionFilter('poor')}>
                                Rough
                            </Button>
                        </Flex>
                    </Flex>

                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Chainage Section</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Roughness (IRI)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Ride Quality Category</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Patrol Speed</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Samples Logged</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Last Telemetry Sync</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {filteredSegments.slice(0, 30).map((seg, idx) => (
                                    <Table.Row key={idx} style={{ backgroundColor: selectedSegment?.chainage_km === seg.chainage_km ? 'var(--indigo-2)' : undefined }}>
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                <MapPinIcon style={{ width: 16, height: 16, color: 'gray' }} />
                                                <Text weight="bold" size="2">{seg.chainage_label}</Text>
                                            </Flex>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text weight="bold" size="2">{seg.iri_value} m/km</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge color={seg.condition === 'good' ? 'green' : seg.condition === 'fair' ? 'amber' : 'red'}>
                                                {seg.condition.toUpperCase()}
                                            </Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="2">{seg.avg_speed_kmh || 60} km/h</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="2">{seg.survey_count || 12} accelerometer batches</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="1" color="gray">{seg.last_surveyed_at || 'Recent patrol'}</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Panel>
            </Box>
        </App>
    );
}
