import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, Card, Progress, Callout } from '@radix-ui/themes';
import {
    CalculatorIcon,
    ArrowTrendingDownIcon,
    CurrencyDollarIcon,
    CheckBadgeIcon,
    ExclamationTriangleIcon,
    SparklesIcon,
    InformationCircleIcon
} from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function PavementDeteriorationForecast({ auth, forecast }) {
    useOperationsRealtimeRefresh();

    const statsData = [
        {
            title: 'Current Mean PCI',
            value: `${forecast?.pci_current_avg ?? 84.2} / 100`,
            icon: <CheckBadgeIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: 'ASTM D6433 Pavement Condition Index',
        },
        {
            title: '5-Yr Unattended PCI',
            value: `${forecast?.five_year_projection?.[4]?.projected_pci ?? 59.5} / 100`,
            icon: <ArrowTrendingDownIcon style={{ width: 22, height: 22 }} />,
            color: 'red',
            description: 'Deteriorates to "Poor" without intervention',
        },
        {
            title: 'Optimal LCCA Intervention',
            value: `৳${(((forecast?.lcca_optimizer?.preventive_intervention_cost_bdt ?? 85000000)) / 1000000).toFixed(1)}M`,
            icon: <CalculatorIcon style={{ width: 22, height: 22 }} />,
            color: 'indigo',
            description: 'Preventive Thin Overlay at PCI 70 trigger',
        },
        {
            title: 'Lifecycle Budget Savings',
            value: `${forecast?.lcca_optimizer?.savings_percentage ?? 65}%`,
            icon: <CurrencyDollarIcon style={{ width: 22, height: 22 }} />,
            color: 'green',
            description: `৳${(((forecast?.lcca_optimizer?.cost_savings_bdt ?? 160000000)) / 1000000).toFixed(1)}M avoided major reconstruction`,
        },
    ];

    const matrix = forecast?.transition_matrix || {
        good: { good: 0.88, fair: 0.10, poor: 0.02, critical: 0.00 },
        fair: { good: 0.00, fair: 0.72, poor: 0.22, critical: 0.06 },
        poor: { good: 0.00, fair: 0.00, poor: 0.60, critical: 0.40 },
        critical: { good: 0.00, fair: 0.00, poor: 0.00, critical: 1.00 },
    };

    const projections = forecast?.five_year_projection || [
        { year: 1, projected_pci: 82.4, state_distribution: { good: 88, fair: 10, poor: 2, critical: 0 }, recommended_action: 'Crack sealing & localized patching' },
        { year: 2, projected_pci: 78.6, state_distribution: { good: 77, fair: 18, poor: 4, critical: 1 }, recommended_action: 'Micro-surfacing in heavy freight lanes' },
        { year: 3, projected_pci: 73.1, state_distribution: { good: 65, fair: 24, poor: 9, critical: 2 }, recommended_action: 'OPTIMAL WINDOW: Thin Asphalt Overlay (25mm)' },
        { year: 4, projected_pci: 66.5, state_distribution: { good: 52, fair: 28, poor: 15, critical: 5 }, recommended_action: 'Mill and replace wearing course' },
        { year: 5, projected_pci: 58.8, state_distribution: { good: 38, fair: 31, poor: 21, critical: 10 }, recommended_action: 'Deep structural rehab & base stabilization' },
    ];

    const criticalSegments = forecast?.critical_segments || [
        { chainage: 'Ch 11+400 to 12+200', current_pci: 68, decay_rate: 'High (WIM heavy lane)', proposed_work: 'Apply polymer-modified bitumen slurry seal' },
        { chainage: 'Ch 28+100 to 29+000', current_pci: 64, decay_rate: 'High (Bypass Junction)', proposed_work: 'Mill 50mm and repave with high-modulus asphalt' },
        { chainage: 'Ch 37+500 to 38+100', current_pci: 70, decay_rate: 'Moderate', proposed_work: 'Preventive chip seal before monsoon season' },
    ];

    return (
        <App auth={auth}>
            <Head title="Pavement Deterioration Forecaster - O&M" />
            <Box p={{ initial: '3', md: '6' }} style={{ maxWidth: 1400, margin: '0 auto' }}>
                {/* Header */}
                <Flex justify="between" align={{ initial: 'start', sm: 'center' }} direction={{ initial: 'column', sm: 'row' }} gap="4" mb="5">
                    <Box>
                        <Flex align="center" gap="2" mb="1">
                            <Heading size="6" weight="bold">Markov Chain Pavement Deterioration & LCCA Optimizer</Heading>
                            <Badge color="violet" variant="soft">Stochastic Asset Modeling</Badge>
                        </Flex>
                        <Text size="2" color="gray">
                            Predicts multi-year pavement condition transitions. Demonstrates the 65% lifecycle cost savings achieved by performing preventive overlays at PCI 70 rather than reactive reconstruction.
                        </Text>
                    </Box>
                    <Button size="3" variant="solid" color="indigo" onClick={() => router.visit(route('om.work-orders.calendar'))}>
                        View Maintenance Calendar
                    </Button>
                </Flex>

                {/* KPI Metrics */}
                <Box mb="5">
                    <StatsCards stats={statsData} />
                </Box>

                {/* Research LCCA Savings Benchmark Comparison */}
                <Panel mb="5">
                    <Flex justify="between" align="center" mb="3">
                        <Box>
                            <Heading size="4" weight="bold">Life-Cycle Cost Analysis (LCCA): 65% Budget Economy</Heading>
                            <Text size="2" color="gray">
                                Comparative financial exposure over a 5-year operating horizon on the 48-km Dhaka Bypass Expressway.
                            </Text>
                        </Box>
                        <Badge color="green" size="2" variant="solid">
                            <SparklesIcon style={{ width: 16, height: 16, marginRight: 4 }} />
                            Saves ৳{(((forecast?.lcca_optimizer?.cost_savings_bdt ?? 160000000)) / 1000000).toFixed(1)}M BDT
                        </Badge>
                    </Flex>

                    <Box style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(320px, 1fr))', gap: 16 }}>
                        {/* Preventive Strategy Card */}
                        <Card style={{ border: '2px solid var(--green-8)', background: 'var(--green-2)' }}>
                            <Flex justify="between" align="start" mb="2">
                                <Heading size="3" weight="bold" color="green">Proactive / Preventive Strategy (Optimal)</Heading>
                                <Badge color="green">RECOMMENDED</Badge>
                            </Flex>
                            <Text size="2" color="gray" mb="3">
                                Intervene when PCI reaches <strong>70 (Fair)</strong> using thin asphalt overlay, joint sealing, and micro-surfacing. Restores pavement back to PCI 95+.
                            </Text>
                            <Box my="2">
                                <Text size="1" color="gray">5-Year Estimated Investment:</Text>
                                <Heading size="5" weight="bold" color="green">
                                    ৳{(((forecast?.lcca_optimizer?.preventive_intervention_cost_bdt ?? 85000000)) / 1000000).toFixed(1)} Million BDT
                                </Heading>
                            </Box>
                            <Text size="1" color="gray">Pavement ride quality remains superior (IRI &lt; 2.0 m/km), preventing severe structural rutting.</Text>
                        </Card>

                        {/* Reactive Strategy Card */}
                        <Card style={{ border: '2px solid var(--red-8)', background: 'var(--red-2)' }}>
                            <Flex justify="between" align="start" mb="2">
                                <Heading size="3" weight="bold" color="red">Reactive / Deferral Strategy (Worst Practice)</Heading>
                                <Badge color="red">HIGH COST RISK</Badge>
                            </Flex>
                            <Text size="2" color="gray" mb="3">
                                Defer maintenance until PCI falls below <strong>40 (Very Poor)</strong>. Requires emergency full-depth reconstruction and base stabilization.
                            </Text>
                            <Box my="2">
                                <Text size="1" color="gray">5-Year Emergency Cost:</Text>
                                <Heading size="5" weight="bold" color="red">
                                    ৳{(((forecast?.lcca_optimizer?.do_nothing_5yr_cost_bdt ?? 245000000)) / 1000000).toFixed(1)} Million BDT
                                </Heading>
                            </Box>
                            <Text size="1" color="gray">Causes severe traffic disruption, high vehicle operating costs, and toll revenue loss.</Text>
                        </Card>
                    </Box>
                </Panel>

                {/* 5-Year Markov Chain Condition Forecast */}
                <Panel mb="5">
                    <Heading size="4" weight="bold" mb="1">5-Year Condition Projection (PCI & Degradation States)</Heading>
                    <Text size="2" color="gray" mb="4">
                        Year-over-year condition state probabilities modeled from current traffic ESALs and environmental exposure.
                    </Text>

                    <Box style={{ overflowX: 'auto' }}>
                        <Table.Root variant="surface">
                            <Table.Header>
                                <Table.Row>
                                    <Table.ColumnHeaderCell>Forecast Horizon</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Projected Mean PCI</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>State Distribution (Good / Fair / Poor / Critical)</Table.ColumnHeaderCell>
                                    <Table.ColumnHeaderCell>Recommended Engineering Intervention</Table.ColumnHeaderCell>
                                </Table.Row>
                            </Table.Header>
                            <Table.Body>
                                {projections.map((p) => (
                                    <Table.Row key={p.year}>
                                        <Table.Cell>
                                            <Text weight="bold" size="2">Year {p.year} ({2026 + p.year})</Text>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Flex align="center" gap="2">
                                                <Text weight="bold" size="3" color={p.projected_pci >= 75 ? 'green' : p.projected_pci >= 65 ? 'amber' : 'red'}>
                                                    {p.projected_pci}
                                                </Text>
                                                <Badge color={p.projected_pci >= 75 ? 'green' : p.projected_pci >= 65 ? 'amber' : 'red'}>
                                                    {p.projected_pci >= 75 ? 'Good' : p.projected_pci >= 65 ? 'Fair' : 'Poor'}
                                                </Badge>
                                            </Flex>
                                        </Table.Cell>
                                        <Table.Cell style={{ minWidth: 260 }}>
                                            <Flex direction="column" gap="1">
                                                <Flex gap="1" style={{ height: 16, borderRadius: 4, overflow: 'hidden' }}>
                                                    <Box style={{ width: `${p.state_distribution.good}%`, background: '#10b981' }} title={`Good: ${p.state_distribution.good}%`} />
                                                    <Box style={{ width: `${p.state_distribution.fair}%`, background: '#f59e0b' }} title={`Fair: ${p.state_distribution.fair}%`} />
                                                    <Box style={{ width: `${p.state_distribution.poor}%`, background: '#ef4444' }} title={`Poor: ${p.state_distribution.poor}%`} />
                                                    <Box style={{ width: `${p.state_distribution.critical}%`, background: '#7f1d1d' }} title={`Critical: ${p.state_distribution.critical}%`} />
                                                </Flex>
                                                <Flex justify="between">
                                                    <Text size="1" color="gray">G: {p.state_distribution.good}%</Text>
                                                    <Text size="1" color="gray">F: {p.state_distribution.fair}%</Text>
                                                    <Text size="1" color="gray">P: {p.state_distribution.poor}%</Text>
                                                    <Text size="1" color="gray">C: {p.state_distribution.critical}%</Text>
                                                </Flex>
                                            </Flex>
                                        </Table.Cell>
                                        <Table.Cell>
                                            <Text size="2" weight={p.year === 3 ? 'bold' : 'normal'} color={p.year === 3 ? 'indigo' : undefined}>
                                                {p.recommended_action}
                                            </Text>
                                        </Table.Cell>
                                    </Table.Row>
                                ))}
                            </Table.Body>
                        </Table.Root>
                    </Box>
                </Panel>

                {/* Markov Transition Matrix & Critical Chainages */}
                <Flex gap="4" direction={{ initial: 'column', md: 'row' }}>
                    {/* Matrix */}
                    <Box style={{ flex: 1 }}>
                        <Panel>
                            <Heading size="3" weight="bold" mb="1">Markov State Transition Matrix P</Heading>
                            <Text size="1" color="gray" mb="3">
                                Annual probabilities P(State_t+1 | State_t) calibrated for N-105 traffic and weather conditions:
                            </Text>
                            <Table.Root variant="surface">
                                <Table.Header>
                                    <Table.Row>
                                        <Table.ColumnHeaderCell>From \ To</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Good</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Fair</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Poor</Table.ColumnHeaderCell>
                                        <Table.ColumnHeaderCell>Critical</Table.ColumnHeaderCell>
                                    </Table.Row>
                                </Table.Header>
                                <Table.Body>
                                    <Table.Row>
                                        <Table.Cell><Text weight="bold" size="2">Good (PCI 85-100)</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.good.good}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.good.fair}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.good.poor}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.good.critical}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell><Text weight="bold" size="2">Fair (PCI 70-84)</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.fair.good}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.fair.fair}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.fair.poor}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.fair.critical}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell><Text weight="bold" size="2">Poor (PCI 55-69)</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.poor.good}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.poor.fair}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.poor.poor}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.poor.critical}</Text></Table.Cell>
                                    </Table.Row>
                                    <Table.Row>
                                        <Table.Cell><Text weight="bold" size="2">Critical (&lt;55)</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.critical.good}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.critical.fair}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.critical.poor}</Text></Table.Cell>
                                        <Table.Cell><Text size="2">{matrix.critical.critical}</Text></Table.Cell>
                                    </Table.Row>
                                </Table.Body>
                            </Table.Root>
                        </Panel>
                    </Box>

                    {/* Critical Segments */}
                    <Box style={{ flex: 1 }}>
                        <Panel>
                            <Heading size="3" weight="bold" mb="1">Priority Remediation Segments</Heading>
                            <Text size="1" color="gray" mb="3">
                                Expressway sections approaching the PCI 70 threshold requiring near-term maintenance:
                            </Text>
                            <Flex direction="column" gap="2">
                                {criticalSegments.map((seg, idx) => (
                                    <Card key={idx}>
                                        <Flex justify="between" align="start">
                                            <Box>
                                                <Text size="2" weight="bold">{seg.chainage}</Text>
                                                <Text size="1" color="gray">{seg.decay_rate}</Text>
                                            </Box>
                                            <Badge color="amber">PCI {seg.current_pci}</Badge>
                                        </Flex>
                                        <Text size="2" color="gray" mt="2">{seg.proposed_work}</Text>
                                    </Card>
                                ))}
                            </Flex>
                        </Panel>
                    </Box>
                </Flex>
            </Box>
        </App>
    );
}
