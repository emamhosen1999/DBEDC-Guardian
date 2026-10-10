import React from 'react';
import { Head } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Badge, Table, Card, Progress, Callout } from '@radix-ui/themes';
import {
    ScaleIcon,
    ExclamationTriangleIcon,
    CurrencyDollarIcon,
    TruckIcon,
    FireIcon,
    InformationCircleIcon,
    ChartBarSquareIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function WimOverloadAnalytics({ auth, analytics }) {
    useOperationsRealtimeRefresh();

    const available = analytics?.available === true;
    const dash = '—';
    const num = (v) => (v == null ? dash : Number(v).toLocaleString());

    const statsData = [
        {
            title: 'Weighed Vehicles Recorded',
            value: available ? num(analytics.total_heavy_vehicles) : dash,
            icon: <TruckIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: available ? `WIM logs ${analytics.first_log_date} to ${analytics.last_log_date}` : 'No WIM readings recorded yet',
        },
        {
            title: 'Overload Violation Rate',
            value: available ? `${analytics.overload_percentage}%` : dash,
            icon: <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />,
            color: 'red',
            description: 'Share of weighed vehicles above their statutory limit',
        },
        {
            title: 'Accumulated ESAL',
            value: available ? num(analytics.total_esal_accumulated) : dash,
            icon: <FireIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Equivalent 18-kip Standard Axle Loads (AASHTO)',
        },
        {
            title: 'Recorded Fatigue Cost (BDT)',
            value: available ? `৳${num(analytics.structural_damage_cost_bdt)}` : dash,
            icon: <CurrencyDollarIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'Sum of the estimated damage cost stored with each WIM log',
        },
    ];

    const axleClasses = analytics?.axle_class_breakdown || [];
    const laneDist = analytics?.lane_distribution || [];

    return (
        <App auth={auth}>
            <Head title="WIM Overload & Pavement Fatigue - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                {/* Header */}
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">Weigh-In-Motion (WIM) Overload & Fatigue Analytics</Heading>
                            <Badge color="orange" variant="soft">AASHTO 4th Power Law</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            Bridge and asphalt fatigue calculation using AASHTO Equivalent Single Axle Load (ESAL) power-law models to measure asset wear from heavy freight.
                        </Text>
                    </Box>
                </Flex>

                {/* KPI Metrics */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Research Theory Scientific Callout */}
                <Box mb="5">
                    <Callout.Root color="amber" size="2">
                        <Callout.Icon>
                            <InformationCircleIcon style={{ width: 22, height: 22 }} />
                        </Callout.Icon>
                        <Callout.Text>
                            <strong>AASHTO Fourth Power Law Principle:</strong> Relative pavement damage increases with the <strong>4th power</strong> of axle load: <code>Damage Factor = (Actual Axle Load / 8.2 Tonnes)^4.2</code>. A mere <strong>20% axle overload causes a 115% increase</strong> in structural fatigue, rapidly exhausting expressway asphalt design life.
                        </Callout.Text>
                    </Callout.Root>
                </Box>

                {/* Axle Class Breakdown Panel */}
                <Panel mb="5">
                    <Heading size="4" weight="bold" mb="3">Axle Classification & Equivalent Damage Factors</Heading>
                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Vehicle & Axle Configuration</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Weighed Vehicles</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Overloaded Violations</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Mean Damage Factor</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Accumulated ESALs</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell align="right">Structural Fatigue Cost (BDT)</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {axleClasses.length === 0 && (
                                    <Table.Row>
                                        <Table.Cell colSpan={6}>
                                            <Text size="2" color="gray">{analytics?.reason || 'No weigh-in-motion readings have been recorded yet.'}</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                )}
                                {axleClasses.map((cls, idx) => (
                                    <Table.Row key={idx}>
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                <ScaleIcon style={{ width: 18, height: 18, color: 'var(--indigo-9)' }} />
                                                <Text weight="bold" size="2">{cls.axle_class}</Text>
                                            </Flex>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="2">{cls.vehicle_count.toLocaleString()}</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                <Badge color={cls.overload_rate > 25 ? 'red' : cls.overload_rate > 15 ? 'amber' : 'green'}>
                                                    {cls.overloaded_count.toLocaleString()} ({cls.overload_rate}%)
                                                </Badge>
                                            </Flex>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="2" weight="bold" color={cls.avg_damage_factor > 3.0 ? 'red' : 'gray'}>
                                                {cls.avg_damage_factor}x standard axle
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="2" weight="bold">{cls.total_esal.toLocaleString()}</Text>
                                        </Table.Cell>
                                        <Table.Cell align="right">
                                            <Text size="2" weight="bold" color="crimson">৳{cls.damage_cost_bdt.toLocaleString()}</Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Panel>

                {/* Lane Distribution & Fatigue Allocation */}
                <Panel>
                    <Heading size="4" weight="bold" mb="1">Lane-Specific Fatigue Allocation (Channelization)</Heading>
                    <Text size="2" color="gray" mb="4">
                        Share of accumulated ESAL and overload rate by lane, from recorded WIM logs.
                    </Text>

                    {laneDist.length === 0 && (
                        <Text size="2" color="gray">No lane-level WIM readings recorded yet.</Text>
                    )}
                    <Box style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16 }}>
                        {laneDist.map((lane, idx) => (
                            <Card key={idx} style={{ border: idx === 0 ? '2px solid var(--amber-8)' : undefined }}>
                                <Flex justify="between" align="start" mb="2">
                                    <Heading size="3" weight="bold">{lane.lane_name}</Heading>
                                    <Badge color={idx === 0 ? 'red' : idx === 1 ? 'amber' : 'green'} size="2">
                                        {lane.esal_percentage}% Fatigue Load
                                    </Badge>
                                </Flex>

                                <Box my="3">
                                    <Flex justify="between" mb="1">
                                        <Text size="1" color="gray">ESAL Share</Text>
                                        <Text size="1" weight="bold">{lane.esal_count.toLocaleString()} ESALs</Text>
                                    </Flex>
                                    <Progress value={lane.esal_percentage} color={idx === 0 ? 'red' : idx === 1 ? 'amber' : 'green'} />
                                </Box>

                                <Flex justify="between" align="center" pt="2" style={{ borderTop: '1px solid var(--gray-4)' }}>
                                    <Text size="1" color="gray">Lane Overload Rate:</Text>
                                    <Text size="2" weight="bold" color={lane.overload_rate > 20 ? 'red' : 'gray'}>
                                        {lane.overload_rate}%
                                    </Text>
                                </Flex>
                            </Card>
                        ))}
                    </Box>
                </Panel>
            </Box>
        </App>
    );
}
