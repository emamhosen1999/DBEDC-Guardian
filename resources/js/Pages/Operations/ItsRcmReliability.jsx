import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, Card, Progress, Callout } from '@radix-ui/themes';
import {
    PresentationChartLineIcon,
    CpuChipIcon,
    ClockIcon,
    WrenchScrewdriverIcon,
    ExclamationTriangleIcon,
    CheckBadgeIcon,
    BoltIcon,
    InformationCircleIcon,
    SignalIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

const STATUS_COLOR = { online: 'green', degraded: 'amber', offline: 'red', maintenance: 'blue' };

export default function ItsRcmReliability({ auth, rcm }) {
    useOperationsRealtimeRefresh();

    const equipment = rcm?.equipment || [];
    const stats = rcm?.stats || {};
    const dash = '—';

    const statsData = [
        {
            title: 'Registered ITS Equipment',
            value: stats.total_monitored_assets ?? 0,
            icon: <CpuChipIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: `${stats.reporting_assets ?? 0} have reported a heartbeat`,
        },
        {
            title: 'Recorded Uptime',
            value: stats.average_recorded_uptime_pct != null ? `${stats.average_recorded_uptime_pct}%` : dash,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: stats.average_recorded_uptime_pct != null ? 'Mean of equipment that has reported' : 'No equipment has reported yet',
        },
        {
            title: 'Degraded or Offline',
            value: stats.degraded_or_offline ?? 0,
            icon: <ExclamationTriangleIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Equipment not currently online',
        },
        {
            title: 'MTBF / MTTR',
            value: dash,
            icon: <ClockIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Needs a recorded failure history',
        },
    ];

    return (
        <App auth={auth}>
            <Head title="ITS Equipment Reliability (RCM) - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">ITS Reliability-Centered Maintenance (SAE JA1011 RCM)</Heading>
                            <Badge color="cyan" variant="soft">ISO 55000 Asset Reliability</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            Reliability of registered ITS and toll equipment, from the equipment register.
                        </Text>
                    </Box>
                    <Button size="3" variant="solid" color="indigo" onClick={() => router.visit(route('om.pm'))}>
                        Configure PM Schedules
                    </Button>
                </Flex>

                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                <Box mb="5">
                    <Callout.Root color="indigo" size="2">
                        <Callout.Icon>
                            <InformationCircleIcon style={{ width: 22, height: 22 }} />
                        </Callout.Icon>
                        <Callout.Text>
                            {rcm?.reason || 'Reliability metrics appear once equipment is registered.'}
                        </Callout.Text>
                    </Callout.Root>
                </Box>

                <Panel mb="5">
                    <Heading size="4" weight="bold" mb="1">Equipment Register</Heading>
                    <Text size="2" color="gray" mb="4">Status and uptime as recorded by the equipment heartbeat.</Text>
                    {equipment.length === 0 ? (
                        <Text size="2" color="gray">No ITS equipment is registered yet.</Text>
                    ) : (
                        <Box style={{ overflowX: 'auto' }}>
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>Equipment</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Recorded Uptime</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Last Heartbeat</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    {equipment.map((e) => (
                                        <Table.Row key={e.id}>
                                            <Table.Cell>
                                                <Flex direction="column">
                                                    <Text weight="bold" size="2">{e.name}</Text>
                                                    <Text size="1" color="gray">{e.code}</Text>
                                                </Flex>
                                            </Table.Cell>
                                            <Table.Cell><Badge color="gray" variant="surface">{String(e.category).replace(/_/g, ' ').toUpperCase()}</Badge></Table.Cell>
                                            <Table.Cell><Text size="2">{e.location}</Text></Table.Cell>
                                            <Table.Cell><Badge color={STATUS_COLOR[e.status] || 'gray'}>{String(e.status).toUpperCase()}</Badge></Table.Cell>
                                            <Table.Cell><Text size="2" weight="bold">{e.uptime_pct != null ? `${e.uptime_pct}%` : dash}</Text></Table.Cell>
                                            <Table.Cell><Text size="1" color="gray">{e.last_ping_at || 'Never reported'}</Text></Table.Cell>
                                        </Table.Row>
                                    ))}
                                </Table.Body>
                            </Table.Root>
                        </Box>
                    )}
                </Panel>
            </Box>
        </App>
    );
}
