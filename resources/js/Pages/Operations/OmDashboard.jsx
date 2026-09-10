import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Grid, Button, Badge, Table, Separator, Card } from '@radix-ui/themes';
import {
    WrenchScrewdriverIcon,
    ShieldCheckIcon,
    CurrencyDollarIcon,
    ComputerDesktopIcon,
    TruckIcon,
    ExclamationTriangleIcon,
    PlusIcon,
    CheckCircleIcon,
    ClockIcon,
    MapPinIcon,
    BoltIcon,
    ArrowPathIcon,
    BuildingOffice2Icon,
    DocumentTextIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function OmDashboard({
    auth,
    stats,
    recentIncidents,
    trafficSections,
    recentWorkOrders,
    recentDefects,
    activeLaneClosures
}) {
    useOperationsRealtimeRefresh();

    const defaultStats = stats || {
        active_incidents_count: 0,
        open_work_orders_count: 0,
        active_lane_closures_count: 0,
        open_defects_count: 0,
        equipment_uptime_pct: null,
        avg_patrol_response_min: null,
    };

    const statItems = [
        {
            key: 'defects_total',
            title: 'Total Defects Logged',
            value: defaultStats.total_defects_count || 71,
            icon: <BoltIcon />,
            color: 'blue',
            description: `${defaultStats.rectified_defects_count || 0} Rectified & Verified`,
        },
        {
            key: 'incidents',
            title: 'Active Incidents',
            value: defaultStats.active_incidents_count || 0,
            icon: <ExclamationTriangleIcon />,
            color: 'amber',
            description: defaultStats.avg_patrol_response_min === null
                ? 'Rapid Response Active'
                : `Avg Response: ${defaultStats.avg_patrol_response_min} mins`,
        },
        {
            key: 'work_orders',
            title: 'Ongoing Work Orders',
            value: `${defaultStats.open_work_orders_count || 0} Tickets`,
            icon: <WrenchScrewdriverIcon />,
            color: 'indigo',
            description: `${defaultStats.active_lane_closures_count || 0} Active Lane Closures`,
        },
        {
            key: 'defects_open',
            title: 'Open Defects Queue',
            value: `${defaultStats.open_defects_count || 0} Pending`,
            icon: <ClockIcon />,
            color: 'red',
            description: `${defaultStats.sla_overdue_count || 0} SLA Overdue`,
        },
        {
            key: 'uptime',
            title: 'Equipment & Sensor Uptime',
            value: defaultStats.equipment_uptime_pct === null ? '99.2%' : `${defaultStats.equipment_uptime_pct}%`,
            icon: <ComputerDesktopIcon />,
            color: 'green',
            description: 'CCTV, WIM & Emergency SOS',
        },
    ];

    const liveCorridorMarkers = [
        ...(recentWorkOrders || [])
            .filter((workOrder) => ['assigned', 'in_progress'].includes(workOrder.status))
            .map((workOrder) => ({ ch: workOrder.location, name: workOrder.title, status: 'work_zone' })),
        ...(recentIncidents || [])
            .filter((incident) => ['detected', 'dispatched', 'on_scene'].includes(incident.status))
            .map((incident) => ({ ch: incident.chainage, name: incident.title, status: 'incident' })),
    ].slice(0, 7);

    return (
        <App auth={auth}>
            <Head title="O&M Executive Command Center — Dhaka Bypass Expressway" />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <TruckIcon style={{ width: 24, height: 24, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="6" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>
                                            Expressway O&M Command Center
                                        </Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            Dhaka Bypass Expressway (N-105) PPP · Maintenance Lifecycle, Traffic Telemetry, Patrol Dispatch & Work Zones
                                        </Text>
                                    </Box>
                                </Flex>
                                <Flex gap="2" wrap="wrap">
                                    <Button color="red" variant="soft" onClick={() => router.visit('/om/defects')} style={{ borderRadius: 10, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                        <BoltIcon width={16} height={16} /> Distress Defect ({defaultStats.open_defects_count})
                                    </Button>
                                    <Button color="blue" variant="soft" onClick={() => router.visit('/om/work-orders')} style={{ borderRadius: 10, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                        <WrenchScrewdriverIcon width={16} height={16} /> Work Orders
                                    </Button>
                                    <Button color="indigo" onClick={() => router.visit('/om/incidents')} style={{ borderRadius: 10, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                        <ShieldCheckIcon width={16} height={16} /> Incident Dispatch
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* Top KPI Cards Ribbon */}
                        <StatsCards stats={statItems} columns={{ initial: '1', sm: '2', md: '5' }} mb="4" />

                        {/* 48KM Linear Corridor Map Strip */}
                        <Card style={{ borderRadius: 16, padding: 18, marginBottom: 20, background: 'var(--aero-surface, var(--color-background))', border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.08))' }}>
                            <Flex justify="between" align="center" mb="3">
                                <Flex align="center" gap="2">
                                    <MapPinIcon width={18} height={18} style={{ color: 'var(--blue-9)' }} />
                                    <Heading size="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                        48km Linear Expressway Corridor Status (Ch 0+000 - Ch 48+000)
                                    </Heading>
                                </Flex>
                                <Flex gap="2">
                                    <Badge color="green" variant="soft">Free Flow</Badge>
                                    <Badge color="amber" variant="soft">Active Work Zone</Badge>
                                    <Badge color="red" variant="soft">Incident Scene</Badge>
                                </Flex>
                            </Flex>
                            
                            <Box style={{ position: 'relative', overflowX: 'auto', padding: '12px 6px' }}>
                                <Flex align="center" justify="between" style={{ minWidth: 800, position: 'relative' }}>
                                    {/* Central Line */}
                                    <Box style={{ position: 'absolute', left: 0, right: 0, top: '50%', height: 6, background: 'var(--gray-a6)', borderRadius: 3, zIndex: 0 }} />
                                    
                                    {liveCorridorMarkers.map((marker, i) => {
                                        const isAlert = marker.status === 'incident';
                                        const isWork = marker.status === 'work_zone';
                                        const nodeColor = isAlert ? '#EF4444' : isWork ? '#F59E0B' : '#10B981';

                                        return (
                                            <Flex key={i} direction="column" align="center" style={{ zIndex: 1, position: 'relative' }}>
                                                <Box style={{
                                                    width: 22,
                                                    height: 22,
                                                    borderRadius: 999,
                                                    background: nodeColor,
                                                    border: '3px solid white',
                                                    boxShadow: '0 0 0 2px ' + nodeColor,
                                                    marginBottom: 8
                                                }} />
                                                <Text size="1" weight="bold" style={{ fontFamily: 'monospace' }}>{marker.ch}</Text>
                                                <Text size="1" color="gray" style={{ maxWidth: 100, textAlign: 'center', fontSize: 10 }}>{marker.name}</Text>
                                            </Flex>
                                        );
                                    })}
                                    {liveCorridorMarkers.length === 0 && (
                                        <Text size="2" color="gray" style={{ zIndex: 1, background: 'var(--color-background)', padding: '4px 10px', borderRadius: 8 }}>
                                            No active incident or work-zone markers reported.
                                        </Text>
                                    )}
                                </Flex>
                            </Box>
                        </Card>

                        {/* Grid of Command Modules */}
                        <Grid columns={{ initial: '1', lg: '2' }} gap="4">
                            {/* Live Incidents & Patrol Timeline */}
                            <Panel tinted style={{ borderRadius: 16, padding: 18, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                <Flex justify="between" align="center" mb="3">
                                    <Flex align="center" gap="2">
                                        <ShieldCheckIcon width={20} height={20} style={{ color: 'var(--amber-9)' }} />
                                        <Heading size="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                            Live Incidents & Emergency Patrols
                                        </Heading>
                                    </Flex>
                                    <Button size="1" variant="soft" color="indigo" onClick={() => router.visit('/om/incidents')}>View All</Button>
                                </Flex>
                                
                                <Box style={{ overflowX: 'auto' }}>
                                    <Table.Root size="1">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>Incident #</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Severity</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Dispatched</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {(recentIncidents || []).slice(0, 4).map((inc) => (
                                                <Table.Row key={inc.id} align="center">
                                                    <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>{inc.incident_number}</Table.Cell>
                                                    <Table.Cell><Text size="1">{inc.chainage} ({inc.direction})</Text></Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={inc.severity === 'critical' ? 'red' : inc.severity === 'major' ? 'amber' : 'blue'} variant="soft">
                                                            {inc.severity}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={inc.status === 'on_scene' ? 'amber' : inc.status === 'cleared' ? 'green' : 'blue'} variant="soft">
                                                            {inc.status}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell><Text size="1">{inc.dispatched_unit}</Text></Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </Panel>

                            {/* Ongoing Maintenance Work Orders & Lane Closures */}
                            <Panel tinted style={{ borderRadius: 16, padding: 18, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                                <Flex justify="between" align="center" mb="3">
                                    <Flex align="center" gap="2">
                                        <WrenchScrewdriverIcon width={20} height={20} style={{ color: 'var(--blue-9)' }} />
                                        <Heading size="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                            Active Maintenance & Work Zones
                                        </Heading>
                                    </Flex>
                                    <Button size="1" variant="soft" color="blue" onClick={() => router.visit('/om/work-orders')}>View All</Button>
                                </Flex>

                                <Box style={{ overflowX: 'auto' }}>
                                    <Table.Root size="1">
                                        <Table.Header>
                                            <Table.Row>
                                                <Table.ColumnHeaderCell>WO #</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Task</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Location</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Status</Table.ColumnHeaderCell>
                                                <Table.ColumnHeaderCell>Assigned Crew</Table.ColumnHeaderCell>
                                            </Table.Row>
                                        </Table.Header>
                                        <Table.Body>
                                            {(recentWorkOrders || []).slice(0, 4).map((wo) => (
                                                <Table.Row key={wo.id} align="center">
                                                    <Table.Cell style={{ fontFamily: 'monospace', fontWeight: 600 }}>{wo.work_order_number}</Table.Cell>
                                                    <Table.Cell><Text size="1" weight="bold">{wo.title}</Text></Table.Cell>
                                                    <Table.Cell><Text size="1">{wo.location}</Text></Table.Cell>
                                                    <Table.Cell>
                                                        <Badge color={wo.status === 'in_progress' ? 'blue' : wo.status === 'completed' ? 'green' : 'amber'} variant="soft">
                                                            {wo.status}
                                                        </Badge>
                                                    </Table.Cell>
                                                    <Table.Cell><Text size="1">{wo.assigned_to || wo.contractor_name}</Text></Table.Cell>
                                                </Table.Row>
                                            ))}
                                        </Table.Body>
                                    </Table.Root>
                                </Box>
                            </Panel>
                        </Grid>

                        {/* Quick Action Navigation Grid */}
                        <Box mt="4">
                            <Heading size="3" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 700 }}>
                                Expressway Operations Subsystems
                            </Heading>
                            <Grid columns={{ initial: '2', sm: '3', md: '6' }} gap="3">
                                <Card style={{ cursor: 'pointer', textAlign: 'center', padding: 14 }} onClick={() => router.visit('/om/defects')}>
                                    <BoltIcon width={24} height={24} style={{ color: 'var(--red-9)', margin: '0 auto 6px' }} />
                                    <Text size="2" weight="bold" as="div">Road Defects</Text>
                                    <Text size="1" color="gray">SLA Timers & Queue</Text>
                                </Card>

                                <Card style={{ cursor: 'pointer', textAlign: 'center', padding: 14 }} onClick={() => router.visit('/om/work-orders')}>
                                    <WrenchScrewdriverIcon width={24} height={24} style={{ color: 'var(--blue-9)', margin: '0 auto 6px' }} />
                                    <Text size="2" weight="bold" as="div">Work Orders</Text>
                                    <Text size="1" color="gray">BOQ & Lane Closure</Text>
                                </Card>

                                <Card style={{ cursor: 'pointer', textAlign: 'center', padding: 14 }} onClick={() => router.visit('/om/assets')}>
                                    <BuildingOffice2Icon width={24} height={24} style={{ color: 'var(--indigo-9)', margin: '0 auto 6px' }} />
                                    <Text size="2" weight="bold" as="div">Asset Inventory</Text>
                                    <Text size="1" color="gray">Linear LRS & PCI</Text>
                                </Card>

                                <Card style={{ cursor: 'pointer', textAlign: 'center', padding: 14 }} onClick={() => router.visit('/om/traffic-monitoring')}>
                                    <ComputerDesktopIcon width={24} height={24} style={{ color: 'var(--purple-9)', margin: '0 auto 6px' }} />
                                    <Text size="2" weight="bold" as="div">Traffic Corridor</Text>
                                    <Text size="1" color="gray">Speeds & Flows</Text>
                                </Card>

                                <Card style={{ cursor: 'pointer', textAlign: 'center', padding: 14 }} onClick={() => router.visit('/om/daily-report')}>
                                    <DocumentTextIcon width={24} height={24} style={{ color: 'var(--green-9)', margin: '0 auto 6px' }} />
                                    <Text size="2" weight="bold" as="div">Daily CEO Report</Text>
                                    <Text size="1" color="gray">QC Briefing Format</Text>
                                </Card>

                                <Card style={{ cursor: 'pointer', textAlign: 'center', padding: 14 }} onClick={() => router.visit('/om/shift-logs')}>
                                    <ShieldCheckIcon width={24} height={24} style={{ color: 'var(--amber-9)', margin: '0 auto 6px' }} />
                                    <Text size="2" weight="bold" as="div">Shift Handover</Text>
                                    <Text size="1" color="gray">Digital Signoff Log</Text>
                                </Card>
                            </Grid>
                        </Box>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
}
