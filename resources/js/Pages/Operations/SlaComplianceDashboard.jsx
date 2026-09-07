import React from 'react';
import { Head, router } from '@inertiajs/react';
import { Box, Flex, Text, Heading, Button, Badge, Table, Separator, Callout } from '@radix-ui/themes';
import { ShieldCheckIcon, ClockIcon, ExclamationTriangleIcon, CheckCircleIcon, BoltIcon } from '@heroicons/react/24/outline';
import App from '@/Layouts/App.jsx';
import { Panel } from '@/Components/ui/Panel';
import StatsCards from '@/Components/StatsCards';
import { useOperationsRealtimeRefresh } from '@/Hooks/useOperationsRealtimeRefresh';
import { showOperationMutationErrors } from './mutationFeedback';

export default function SlaComplianceDashboard({ auth, dashboard }) {
    useOperationsRealtimeRefresh();

    const canManage = auth?.permissions?.includes('om.maintenance.manage') || auth?.roles?.includes('Super Administrator');
    const d = dashboard || {};

    const handleAcknowledge = (id) => {
        router.post(`/om/sla-breaches/${id}/acknowledge`, { notes: 'Acknowledged via dashboard' }, {
            onError: showOperationMutationErrors,
        });
    };

    const complianceColor = (rate) => rate >= 90 ? 'green' : rate >= 70 ? 'amber' : 'red';
    const escalationColor = (level) => ({
        warning: 'amber', breach: 'orange', critical_breach: 'red',
    }[level] || 'gray');

    return (
        <App auth={auth}>
            <Head title="SLA Compliance Dashboard" />
            <Box p="5">
                <Flex justify="between" align="center" mb="4">
                    <Box>
                        <Heading size="6" weight="bold">
                            <ShieldCheckIcon className="inline h-6 w-6 mr-2" />
                            SLA Compliance Dashboard
                        </Heading>
                        <Text size="2" color="gray">Real-time SLA tracking, breach registry, compliance metrics</Text>
                    </Box>
                </Flex>

                {/* Compliance KPIs */}
                <StatsCards stats={[
                    { label: 'SLA Compliance', value: `${d.compliance_rate ?? 100}%`, icon: ShieldCheckIcon, color: complianceColor(d.compliance_rate ?? 100) },
                    { label: 'Currently Breached', value: d.currently_breached ?? 0, icon: ExclamationTriangleIcon, color: d.currently_breached > 0 ? 'red' : 'green' },
                    { label: 'At Risk (< 4h)', value: d.at_risk ?? 0, icon: ClockIcon, color: d.at_risk > 0 ? 'amber' : 'green' },
                    { label: 'Resolved on Time', value: d.resolved_on_time ?? 0, icon: CheckCircleIcon, color: 'green' },
                    { label: 'Total w/ SLA', value: d.total_with_sla ?? 0, icon: BoltIcon, color: 'blue' },
                ]} />

                {/* At-Risk & Breach Warnings */}
                {(d.currently_breached > 0 || d.at_risk > 0) && (
                    <Callout.Root color="red" mt="4">
                        <Callout.Icon><ExclamationTriangleIcon className="h-4 w-4" /></Callout.Icon>
                        <Callout.Text>
                            <strong>{d.currently_breached}</strong> defect{d.currently_breached !== 1 ? 's' : ''} have breached SLA.
                            {d.at_risk > 0 && <> <strong>{d.at_risk}</strong> more at risk of breaching within 4 hours.</>}
                        </Callout.Text>
                    </Callout.Root>
                )}

                {/* Breach Severity Breakdown */}
                {d.breaches_by_severity && Object.keys(d.breaches_by_severity).length > 0 && (
                    <Panel mt="4">
                        <Heading size="4" mb="3">Breach Severity Breakdown</Heading>
                        <Flex gap="4">
                            {Object.entries(d.breaches_by_severity).map(([level, count]) => (
                                <Box key={level} style={{textAlign: 'center'}}>
                                    <Badge color={escalationColor(level)} size="2">{level.replace(/_/g, ' ').toUpperCase()}</Badge>
                                    <Text size="5" weight="bold" as="div" mt="1">{count}</Text>
                                </Box>
                            ))}
                        </Flex>
                    </Panel>
                )}

                {/* Active Breaches Table */}
                <Panel mt="4">
                    <Heading size="4" mb="3">Unacknowledged SLA Breaches</Heading>
                    <Table.Root>
                        <Table.Header>
                            <Table.Row>
                                <Table.ColumnHeaderCell>Entity</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>SLA Target</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Due At</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Overdue</Table.ColumnHeaderCell>
                                <Table.ColumnHeaderCell>Escalation</Table.ColumnHeaderCell>
                                {canManage && <Table.ColumnHeaderCell>Action</Table.ColumnHeaderCell>}
                            </Table.Row>
                        </Table.Header>
                        <Table.Body>
                            {(d.active_breaches || []).map(breach => (
                                <Table.Row key={breach.id}>
                                    <Table.Cell>
                                        <Text weight="bold" size="2" color="blue">{breach.entity_number}</Text>
                                        <Text size="1" color="gray" as="div">{breach.entity_type}</Text>
                                    </Table.Cell>
                                    <Table.Cell><Text size="2">{breach.sla_hours}h</Text></Table.Cell>
                                    <Table.Cell><Text size="2">{breach.sla_due_at ? new Date(breach.sla_due_at).toLocaleString() : '—'}</Text></Table.Cell>
                                    <Table.Cell><Text size="2" color="red" weight="bold">{breach.overdue_hours}h overdue</Text></Table.Cell>
                                    <Table.Cell>
                                        <Badge color={escalationColor(breach.escalation_level)} size="1">
                                            {breach.escalation_level?.replace(/_/g, ' ').toUpperCase()}
                                        </Badge>
                                    </Table.Cell>
                                    {canManage && (
                                        <Table.Cell>
                                            <Button size="1" variant="soft" color="blue" onClick={() => handleAcknowledge(breach.id)}>
                                                Acknowledge
                                            </Button>
                                        </Table.Cell>
                                    )}
                                </Table.Row>
                            ))}
                            {(d.active_breaches || []).length === 0 && (
                                <Table.Row>
                                    <Table.Cell colSpan={6}>
                                        <Flex align="center" justify="center" py="5" gap="2">
                                            <CheckCircleIcon className="h-5 w-5 text-green-500" style={{color: 'var(--green-9)'}} />
                                            <Text color="green" size="2">All SLA commitments on track. No unacknowledged breaches.</Text>
                                        </Flex>
                                    </Table.Cell>
                                </Table.Row>
                            )}
                        </Table.Body>
                    </Table.Root>
                </Panel>
            </Box>
        </App>
    );
}
