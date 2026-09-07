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

export default function ItsRcmReliability({ auth, rcm }) {
    useOperationsRealtimeRefresh();

    const subsystems = rcm?.subsystems || [
        {
            id: 1,
            name: 'Optical Fiber Backbone (48 km Ring)',
            category: 'Communications',
            mtbf_hours: 4200,
            mttr_minutes: 35,
            availability_percentage: 99.92,
            failure_modes: 'Fiber cut due to third-party excavation, SFP module optical degradation',
            weibull_phase: 'random_failures',
            maintenance_strategy: 'condition_based',
            next_rcm_action: 'OTDR optical attenuation testing at Kanchan splice enclosure',
        },
        {
            id: 2,
            name: 'High-Speed WIM Quartz Piezo Sensors',
            category: 'Weight Enforcement',
            mtbf_hours: 850,
            mttr_minutes: 55,
            availability_percentage: 98.70,
            failure_modes: 'Epoxy debonding in heavy lane, lead wire fatigue, temperature drift',
            weibull_phase: 'wear_out',
            maintenance_strategy: 'condition_based',
            next_rcm_action: 'Resin re-grouting and static load calibration at Plaza 2',
        },
        {
            id: 3,
            name: 'Overhead Variable Message Signs (VMS)',
            category: 'Signage',
            mtbf_hours: 1950,
            mttr_minutes: 40,
            availability_percentage: 99.45,
            failure_modes: 'Power supply capacitor dry-out, LED pixel cluster failure',
            weibull_phase: 'random_failures',
            maintenance_strategy: 'scheduled_overhaul',
            next_rcm_action: 'Replace auxiliary 24V PSU fans before summer heat peaks',
        },
        {
            id: 4,
            name: 'CCTV Surveillance & ANPR Cameras (48 units)',
            category: 'Traffic & Video',
            mtbf_hours: 1400,
            mttr_minutes: 30,
            availability_percentage: 99.60,
            failure_modes: 'Lightning induced surge on PoE port, wiper motor mechanical lock',
            weibull_phase: 'random_failures',
            maintenance_strategy: 'condition_based',
            next_rcm_action: 'Inspect surge protection devices (SPD) along Ch 20-30',
        },
        {
            id: 5,
            name: 'Electronic Toll Collection (ETC) Barrier Lanes',
            category: 'Revenue Systems',
            mtbf_hours: 620,
            mttr_minutes: 18,
            availability_percentage: 99.85,
            failure_modes: 'Torque spring fatigue in barrier arm, optical lane detector obstruction',
            weibull_phase: 'wear_out',
            maintenance_strategy: 'condition_based',
            next_rcm_action: 'Replace high-cycle mechanical barrier springs at Plaza 1 & 3',
        },
        {
            id: 6,
            name: 'Plaza Solar Hybrid & Diesel Generator Sets',
            category: 'Power Grid',
            mtbf_hours: 2800,
            mttr_minutes: 45,
            availability_percentage: 99.90,
            failure_modes: 'Starting battery voltage drop, automatic transfer switch (ATS) relay lag',
            weibull_phase: 'random_failures',
            maintenance_strategy: 'failure_finding',
            next_rcm_action: 'Perform monthly full-load black-start drill and fuel testing',
        },
    ];

    const statsData = [
        {
            title: 'Monitored ITS Subsystems',
            value: rcm?.total_monitored_subsystems ?? subsystems.length,
            icon: <CpuChipIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'Critical intelligent transportation systems',
        },
        {
            title: 'Expressway ITS Availability',
            value: `${rcm?.overall_availability_percentage ?? 99.42}%`,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'Exceeds 99.0% concession SLA target',
        },
        {
            title: 'Mean MTBF (Uptime)',
            value: '1,970 hrs',
            icon: <ClockIcon style={{ width: 22, height: 22 }} />,
            color: 'blue',
            description: 'Mean Time Between Failures across fleet',
        },
        {
            title: 'Mean MTTR (Repair Speed)',
            value: '37 mins',
            icon: <WrenchScrewdriverIcon style={{ width: 22, height: 22 }} />,
            color: 'amber',
            description: 'Mean Time To Repair SLA compliance',
        },
    ];

    const getWeibullBadge = (phase) => {
        if (phase === 'infant_mortality') return <Badge color="purple">Infant Mortality (Burn-In)</Badge>;
        if (phase === 'wear_out') return <Badge color="red" variant="solid">Wear-Out Phase (High Risk)</Badge>;
        return <Badge color="green" variant="surface">Random / Useful Life (Stable)</Badge>;
    };

    const getStrategyBadge = (strat) => {
        if (strat === 'condition_based') return <Badge color="blue">Condition-Based (CBM)</Badge>;
        if (strat === 'scheduled_overhaul') return <Badge color="indigo">Scheduled Overhaul</Badge>;
        if (strat === 'failure_finding') return <Badge color="amber">Failure-Finding Task</Badge>;
        return <Badge color="gray">Run-to-Failure</Badge>;
    };

    return (
        <App auth={auth}>
            <Head title="ITS Equipment Reliability (RCM) - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                {/* Header */}
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">ITS Reliability-Centered Maintenance (SAE JA1011 RCM)</Heading>
                            <Badge color="cyan" variant="soft">ISO 55000 Asset Reliability</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            FMEA failure modes, MTBF and MTTR metrics, and Weibull bath-tub wear-out curves for Dhaka Bypass cameras, VMS, WIM, and toll plazas.
                        </Text>
                    </Box>
                    <Button size="3" variant="solid" color="indigo" onClick={() => router.visit(route('om.pm'))}>
                        Configure PM Schedules
                    </Button>
                </Flex>

                {/* KPI Metrics */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* SAE JA1011 Standard Callout */}
                <Box mb="5">
                    <Callout.Root color="indigo" size="2">
                        <Callout.Icon>
                            <InformationCircleIcon style={{ width: 22, height: 22 }} />
                        </Callout.Icon>
                        <Callout.Text>
                            <strong>Reliability-Centered Maintenance (RCM) Standard:</strong> Governed by SAE JA1011 criteria. Replaces arbitrary calendar maintenance with failure mode identification (FMEA), Weibull bathtub lifecycle hazard rate analysis, and targeted Condition-Based Maintenance (CBM) to eliminate unexpected expressway equipment downtime.
                        </Callout.Text>
                    </Callout.Root>
                </Box>

                {/* Subsystem Reliability & FMEA Matrix */}
                <Panel mb="5">
                    <Heading size="4" weight="bold" mb="1">Subsystem Reliability, MTBF & Failure Modes (FMEA)</Heading>
                    <Text size="2" color="gray" mb="4">
                        Detailed operational telemetry, repair metrics, and wear-out hazard classifications.
                    </Text>

                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>ITS Subsystem</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Category</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>MTBF (Hours)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>MTTR (Minutes)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Availability</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Weibull Phase</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>RCM Maintenance Strategy</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Priority Action</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {subsystems.map((sub) => (
                                    <Table.Row key={sub.id}>
                                        <Table.Cell>
                                            <Flex direction="column">
                                                <Text weight="bold" size="2">{sub.name}</Text>
                                                <Text size="1" color="gray" style={{ maxWidth: 280 }}>{sub.failure_modes}</Text>
                                            </Flex>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Badge color="gray" variant="surface">{sub.category}</Badge>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text weight="bold" size="2">{sub.mtbf_hours.toLocaleString()} hrs</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text weight="bold" size="2" color={sub.mttr_minutes <= 45 ? 'green' : 'amber'}>
                                                {sub.mttr_minutes} min
                                            </Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text weight="bold" size="2" color="green">{sub.availability_percentage}%</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            {getWeibullBadge(sub.weibull_phase)}
                                        </Table.Cell>
                                        <Table.Cell>
                                            {getStrategyBadge(sub.maintenance_strategy)}
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="1" color="indigo" weight="medium" style={{ maxWidth: 220 }}>
                                                {sub.next_rcm_action}
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Panel>

                {/* Weibull Hazard Curve & Active Action List */}
                <Flex gap="4" direction={{ initial: 'column', md: 'row' }}>
                    <Box style={{ flex: 1 }}>
                        <Panel>
                            <Heading size="3" weight="bold" mb="2">Weibull Bathtub Hazard Curve Distribution</Heading>
                            <Text size="2" color="gray" mb="3">
                                Classifies electronic and mechanical road assets by lifecycle failure rate distribution:
                            </Text>
                            <Flex direction="column" gap="3">
                                <Card style={{ borderLeft: '4px solid var(--purple-9)' }}>
                                    <Flex justify="between" align="center">
                                        <Text size="2" weight="bold">Infant Mortality (Beta &lt; 1.0)</Text>
                                        <Badge color="purple">0 Subsystems</Badge>
                                    </Flex>
                                    <Text size="1" color="gray" mt="1">Early manufacturing and installation defects. All Dhaka Bypass ITS passed burn-in commissioning.</Text>
                                </Card>

                                <Card style={{ borderLeft: '4px solid var(--green-9)' }}>
                                    <Flex justify="between" align="center">
                                        <Text size="2" weight="bold">Useful Life / Constant Random (Beta = 1.0)</Text>
                                        <Badge color="green">4 Subsystems</Badge>
                                    </Flex>
                                    <Text size="1" color="gray" mt="1">CCTV cameras, optical fiber, generators, and VMS experiencing normal stress-induced random events.</Text>
                                </Card>

                                <Card style={{ borderLeft: '4px solid var(--red-9)' }}>
                                    <Flex justify="between" align="center">
                                        <Text size="2" weight="bold">Wear-Out Phase (Beta &gt; 1.0)</Text>
                                        <Badge color="red">2 Subsystems (High Priority)</Badge>
                                    </Flex>
                                    <Text size="1" color="gray" mt="1">WIM piezo quartz sensors and toll barrier torque springs entering mechanical fatigue.</Text>
                                </Card>
                            </Flex>
                        </Panel>
                    </Box>

                    <Box style={{ flex: 1 }}>
                        <Panel>
                            <Heading size="3" weight="bold" mb="2">Immediate CBM Work Recommendations</Heading>
                            <Text size="2" color="gray" mb="3">
                                Condition-based proactive maintenance orders scheduled to avert unscheduled failures:
                            </Text>
                            <Flex direction="column" gap="2">
                                <Card>
                                    <Flex justify="between" align="start">
                                        <Text size="2" weight="bold">Re-grout WIM Piezo Epoxy at Plaza 2</Text>
                                        <Badge color="red">Wear-Out Risk</Badge>
                                    </Flex>
                                    <Text size="1" color="gray" mt="1">Prevent quartz crystal sensor dislodgement during peak monsoon freight loading.</Text>
                                    <Flex justify="end" mt="2">
                                        <Button size="1" variant="soft" color="indigo" onClick={() => router.visit(route('om.work-orders'))}>
                                            Create Work Order
                                        </Button>
                                    </Flex>
                                </Card>

                                <Card>
                                    <Flex justify="between" align="start">
                                        <Text size="2" weight="bold">Optical Fiber OTDR Attenuation Scan</Text>
                                        <Badge color="blue">Condition CBM</Badge>
                                    </Flex>
                                    <Text size="1" color="gray" mt="1">Measure decibel signal loss at Kanchan bridge expansion joint splice tray.</Text>
                                    <Flex justify="end" mt="2">
                                        <Button size="1" variant="soft" color="indigo" onClick={() => router.visit(route('om.work-orders'))}>
                                            Create Work Order
                                        </Button>
                                    </Flex>
                                </Card>
                            </Flex>
                        </Panel>
                    </Box>
                </Flex>
            </Box>
        </App>
    );
}
