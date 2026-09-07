import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Badge, Select } from '@radix-ui/themes';
import { ChartBarIcon, ClockIcon, WrenchScrewdriverIcon, BoltIcon, ShieldCheckIcon, ExclamationTriangleIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';

export default function OmAnalytics({ auth, analytics, filters }) {
    useOperationsRealtimeRefresh();

    const a = analytics || {};
    const kpis = a.kpis || {};

    const handlePeriodChange = (period) => {
        router.get('/om/analytics', { period }, { preserveState: true, preserveScroll: true });
    };

    return (
        <App auth={auth}>
            <Head title="O&M Analytics & Reports" />
            <Box p="5">
                <Flex justify="between" align="center" mb="4">
                    <Box>
                        <Heading size="6" weight="bold">
                            <ChartBarIcon className="inline h-6 w-6 mr-2" />
                            O&M Analytics & Performance Reports
                        </Heading>
                        <Text size="2" color="gray">{a.period_label || 'Last 30 Days'} — MTTR, SLA compliance, trend analysis</Text>
                    </Box>
                    <Select.Root value={filters?.period || 'month'} onValueChange={handlePeriodChange}>
                        <Select.Trigger placeholder="Time Period" />
                        <Select.Content>
                            <Select.Item value="week">Last 7 Days</Select.Item>
                            <Select.Item value="month">Last 30 Days</Select.Item>
                            <Select.Item value="quarter">Last 3 Months</Select.Item>
                            <Select.Item value="year">Last 12 Months</Select.Item>
                        </Select.Content>
                    </Select.Root>
                </Flex>

                {/* Core KPIs */}
                <StatsCards stats={[
                    { label: 'MTTR (Hours)', value: kpis.mttr_hours ?? '—', icon: ClockIcon, color: 'blue' },
                    { label: 'WO Completion', value: `${kpis.wo_completion_rate ?? 0}%`, icon: WrenchScrewdriverIcon, color: 'green' },
                    { label: 'Defect Resolution', value: `${kpis.defect_resolution_rate ?? 0}%`, icon: BoltIcon, color: 'indigo' },
                    { label: 'Avg Response (min)', value: kpis.avg_incident_response_min ?? '—', icon: ExclamationTriangleIcon, color: 'amber' },
                    { label: 'SLA Compliance', value: `${kpis.sla_compliance_rate ?? 100}%`, icon: ShieldCheckIcon, color: kpis.sla_compliance_rate >= 90 ? 'green' : 'red' },
                    { label: 'Inspection Pass Rate', value: `${kpis.inspection_pass_rate ?? 0}%`, icon: ChartBarIcon, color: 'cyan' },
                ]} />

                {/* Volume Summary */}
                <Flex gap="4" mt="4" wrap="wrap">
                    <Panel style={{flex: 1, minWidth: 200}}>
                        <Text size="2" color="gray">Total Work Orders</Text>
                        <Heading size="7">{kpis.total_work_orders ?? 0}</Heading>
                    </Panel>
                    <Panel style={{flex: 1, minWidth: 200}}>
                        <Text size="2" color="gray">Total Defects</Text>
                        <Heading size="7">{kpis.total_defects ?? 0}</Heading>
                    </Panel>
                    <Panel style={{flex: 1, minWidth: 200}}>
                        <Text size="2" color="gray">Total Incidents</Text>
                        <Heading size="7">{kpis.total_incidents ?? 0}</Heading>
                    </Panel>
                    <Panel style={{flex: 1, minWidth: 200}}>
                        <Text size="2" color="gray">Total Inspections</Text>
                        <Heading size="7">{kpis.total_inspections ?? 0}</Heading>
                    </Panel>
                </Flex>

                {/* Defect Trends */}
                {a.defect_trends && a.defect_trends.length > 0 && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">Defect Trends</Heading>
                        <Flex gap="2" wrap="wrap">
                            {a.defect_trends.slice(-14).map((d, i) => (
                                <Box key={i} style={{textAlign: 'center', minWidth: 60}}>
                                    <Box style={{
                                        height: Math.max(d.total * 8, 4),
                                        width: 24,
                                        backgroundColor: d.critical > 0 ? 'var(--red-9)' : 'var(--blue-9)',
                                        borderRadius: 4,
                                        margin: '0 auto',
                                    }} />
                                    <Text size="1" color="gray" as="div" mt="1">{d.total}</Text>
                                    <Text size="1" color="gray" as="div">{d.date?.split('-').slice(1).join('/')}</Text>
                                </Box>
                            ))}
                        </Flex>
                    </Panel>
                )}

                {/* Category Breakdown */}
                {a.category_breakdown && a.category_breakdown.length > 0 && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">Work Orders by Category</Heading>
                        <Flex gap="3" wrap="wrap">
                            {a.category_breakdown.map((c, i) => (
                                <Box key={i} style={{border: '1px solid var(--gray-6)', borderRadius: 8, padding: 12, minWidth: 140}}>
                                    <Badge variant="soft" size="1">{c.category?.replace(/_/g, ' ').toUpperCase()}</Badge>
                                    <Text size="4" weight="bold" as="div" mt="1">{c.count}</Text>
                                    <Text size="1" color="gray" as="div">৳{Number(c.total_cost || 0).toLocaleString()}</Text>
                                </Box>
                            ))}
                        </Flex>
                    </Panel>
                )}

                {/* Top Defect Types */}
                {a.top_defect_types && a.top_defect_types.length > 0 && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">Top Defect Types</Heading>
                        {a.top_defect_types.map((dt, i) => (
                            <Flex key={i} justify="between" align="center" py="1" style={{borderBottom: '1px solid var(--gray-4)'}}>
                                <Text size="2">{dt.distress_type?.replace(/_/g, ' ')}</Text>
                                <Badge variant="outline" size="1">{dt.count}</Badge>
                            </Flex>
                        ))}
                    </Panel>
                )}

                {/* SLA Compliance Trends */}
                {a.sla_compliance && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">SLA Breach Summary</Heading>
                        <Flex gap="4">
                            <Box>
                                <Text size="2" color="gray">Total Breaches</Text>
                                <Text size="5" weight="bold" color="red" as="div">{a.sla_compliance.total_breaches}</Text>
                            </Box>
                            <Box>
                                <Text size="2" color="gray">Acknowledged</Text>
                                <Text size="5" weight="bold" color="green" as="div">{a.sla_compliance.acknowledged}</Text>
                            </Box>
                            <Box>
                                <Text size="2" color="gray">Unacknowledged</Text>
                                <Text size="5" weight="bold" color="orange" as="div">{a.sla_compliance.unacknowledged}</Text>
                            </Box>
                        </Flex>
                    </Panel>
                )}

                {/* Safety Summary */}
                {a.safety_summary && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">Safety Summary</Heading>
                        <Flex gap="4">
                            <Box>
                                <Text size="2" color="gray">Total Safety Incidents</Text>
                                <Text size="5" weight="bold" as="div">{a.safety_summary.total}</Text>
                            </Box>
                            <Box>
                                <Text size="2" color="gray">Near Misses</Text>
                                <Text size="5" weight="bold" color="amber" as="div">{a.safety_summary.near_misses}</Text>
                            </Box>
                            <Box>
                                <Text size="2" color="gray">Injuries</Text>
                                <Text size="5" weight="bold" color="red" as="div">{a.safety_summary.injuries}</Text>
                            </Box>
                            <Box>
                                <Text size="2" color="gray">Lost Time (hrs)</Text>
                                <Text size="5" weight="bold" color="orange" as="div">{a.safety_summary.lost_time_hours}</Text>
                            </Box>
                        </Flex>
                    </Panel>
                )}
            </Box>
        </App>
    );
}
