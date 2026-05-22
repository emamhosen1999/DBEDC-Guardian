import React, { useState, useEffect, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import {
    Badge,
    Box,
    Button,
    Card,
    Flex,
    Grid,
    Heading,
    ScrollArea,
    Select,
    Separator,
    Spinner,
    Switch,
    Table,
    Tabs,
    Text,
} from '@radix-ui/themes';
import {
    ArrowPathIcon,
    ChartBarIcon,
    CircleStackIcon,
    ComputerDesktopIcon,
    DocumentArrowDownIcon,
    DocumentTextIcon,
    ShieldCheckIcon,
} from '@heroicons/react/24/outline';
import * as useSystemMonitoringQuery from '@/api/queries/useSystemMonitoringQuery';
import App from '@/Layouts/App.jsx';
import GlassCard from '@/Components/GlassCard';
import PageHeader from '@/Components/PageHeader';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import QueryState from '@/Components/Common/QueryState';

const statusColor = (status) => {
    if (['healthy', 'compliant', 'good', 'success'].includes(status)) return 'green';
    if (['warning', 'partial'].includes(status)) return 'amber';
    if (['critical', 'unhealthy', 'non_compliant', 'error'].includes(status)) return 'red';
    return 'gray';
};

const JsonPanel = ({ data, empty = 'No data available.' }) => (
    <Card>
        <Box p="4">
            {data && Object.keys(data).length > 0 ? (
                <ScrollArea scrollbars="horizontal" style={{ maxHeight: 480 }}>
                    <pre style={{ fontSize: 12, margin: 0, whiteSpace: 'pre-wrap' }}>
                        {JSON.stringify(data, null, 2)}
                    </pre>
                </ScrollArea>
            ) : (
                <Text size="2" color="gray">{empty}</Text>
            )}
        </Box>
    </Card>
);

const SystemMonitoringEnhanced = ({ title, initialData }) => {
    const { app } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 768px)');

    const [autoRefresh, setAutoRefresh] = useState(true);
    const [refreshInterval, setRefreshInterval] = useState('30');
    const [selectedTab, setSelectedTab] = useState('overview');
    const [timePeriod, setTimePeriod] = useState('24h');

    // React Query hooks
    const { data: monitoringData, isLoading, isError, error, refetch } = useSystemMonitoringQuery.useSystemMonitoringMetrics({ type: selectedTab, period: timePeriod });
    const exportSystemReport = useSystemMonitoringQuery.useExportSystemReport();
    const isMutating = exportSystemReport.isPending;

    const [data, setData] = useState(initialData || {});

    // Update local state when React Query data changes
    useEffect(() => {
        if (monitoringData) {
            setData(monitoringData);
        }
    }, [monitoringData]);

    const refreshData = useCallback(async () => {
        await refetch();
    }, [refetch]);

    useEffect(() => {
        if (!autoRefresh) return;
        const ms = parseInt(refreshInterval, 10) * 1000;
        const id = setInterval(refreshData, ms);
        return () => clearInterval(id);
    }, [autoRefresh, refreshInterval, refreshData]);

    const handleExportReport = async () => {
        try {
            const response = await exportSystemReport.mutateAsync();
            const url = window.URL.createObjectURL(new Blob([response]));
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', `system-report-${new Date().toISOString().split('T')[0]}.pdf`);
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
            showToast.success('System report exported successfully');
        } catch {
            showToast.error('Failed to export system report');
        }
    };

    const overviewStats = [
        {
            title: 'System health',
            value: data.system_health?.overall_status || 'Unknown',
            color: statusColor(data.system_health?.overall_status),
        },
        {
            title: 'Avg response',
            value: `${Math.round(data.performance_summary?.avg_response_time || 0)} ms`,
            color: (data.performance_summary?.avg_response_time || 0) < 500 ? 'green' : 'amber',
        },
        {
            title: 'Active users',
            value: String(data.user_activity?.active_users ?? 0),
            color: 'blue',
        },
        {
            title: 'Errors (24h)',
            value: String(data.error_summary?.total_errors ?? 0),
            color: (data.error_summary?.total_errors || 0) < 10 ? 'green' : 'red',
        },
    ];

    const tables = data.database_stats?.table_analysis?.tables || [];

    const renderTabContent = () => {
        switch (selectedTab) {
            case 'database':
                return (
                    <Card>
                        <Box p="4">
                            <Heading size="4" mb="3">Table analysis</Heading>
                            <ScrollArea scrollbars="horizontal">
                                <Table.Root variant="surface" style={{ minWidth: 640 }}>
                                    <Table.Header>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>Table</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell align="right">Rows</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell align="right">Size (MB)</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Engine</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {tables.slice(0, 50).map((table) => (
                                            <Table.Row key={table.TABLE_NAME}>
                                                <Table.Cell>{table.TABLE_NAME}</Table.Cell>
                                                <Table.Cell align="right">{table.TABLE_ROWS?.toLocaleString?.() ?? table.TABLE_ROWS ?? 0}</Table.Cell>
                                                <Table.Cell align="right">{table.size_mb ?? 0}</Table.Cell>
                                                <Table.Cell>{table.ENGINE}</Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </ScrollArea>
                            {tables.length > 50 && (
                                <Text size="1" color="gray" mt="2">Showing first 50 of {tables.length} tables.</Text>
                            )}
                        </Box>
                    </Card>
                );
            case 'performance':
                return <JsonPanel data={data.performance_summary} empty="No performance metrics." />;
            case 'security':
                return <JsonPanel data={data.security_summary} empty="No security metrics." />;
            case 'compliance':
                return <JsonPanel data={data.compliance_summary} empty="No compliance metrics." />;
            default:
                return (
                    <Grid columns={{ initial: '1', sm: '2', lg: '4' }} gap="4">
                        {overviewStats.map((stat) => (
                            <Card key={stat.title}>
                                <Box p="4">
                                    <Text size="2" color="gray" mb="1">{stat.title}</Text>
                                    <Heading size="6">
                                        <Badge color={stat.color} variant="soft">{stat.value}</Badge>
                                    </Heading>
                                </Box>
                            </Card>
                        ))}
                        {data.system_health?.checks && (
                            <Box style={{ gridColumn: '1 / -1' }}>
                                <Card>
                                    <Box p="4">
                                        <Heading size="4" mb="3">Health checks</Heading>
                                        <Flex direction="column" gap="2">
                                            {Object.entries(data.system_health.checks).map(([key, check]) => (
                                                <Flex key={key} justify="between" align="center" p="3" style={{ border: '1px solid var(--gray-a4)', borderRadius: 'var(--radius-2)' }}>
                                                    <Box>
                                                        <Text size="2" weight="medium">{key.replace(/_/g, ' ')}</Text>
                                                        <Text size="1" color="gray">{check.message}</Text>
                                                    </Box>
                                                    <Badge color={statusColor(check.status)} variant="soft">{check.status}</Badge>
                                                </Flex>
                                            ))}
                                        </Flex>
                                    </Box>
                                </Card>
                            </Box>
                        )}
                    </Grid>
                );
        }
    };

    return (
        <>
            <Head title={title} />
            <Box p={{ initial: '3', md: '5' }}>
                <GlassCard>
                    <PageHeader
                        title="Enterprise System Monitoring"
                        subtitle="System health, database, performance, security, and compliance"
                        icon={<ComputerDesktopIcon style={{ width: 32, height: 32 }} />}
                        actionButtons={[
                            {
                                label: 'Export report',
                                icon: <DocumentArrowDownIcon style={{ width: 16, height: 16 }} />,
                                onPress: handleExportReport,
                            },
                            {
                                label: 'Refresh',
                                icon: <ArrowPathIcon style={{ width: 16, height: 16 }} />,
                                onPress: refreshData,
                                isLoading,
                            },
                        ]}
                    >
                        <Box p="4">
                            <Card mb="4">
                                <Flex direction={{ initial: 'column', lg: 'row' }} gap="4" p="4" align={{ lg: 'center' }} justify="between">
                                    <Flex align="center" gap="3" wrap="wrap">
                                        <Flex align="center" gap="2">
                                            <Switch checked={autoRefresh} onCheckedChange={setAutoRefresh} />
                                            <Text size="2">Auto refresh</Text>
                                        </Flex>
                                        <Select.Root
                                            value={refreshInterval}
                                            onValueChange={setRefreshInterval}
                                            disabled={!autoRefresh}
                                        >
                                            <Select.Trigger style={{ width: 96 }} />
                                            <Select.Content>
                                                <Select.Item value="30">30s</Select.Item>
                                                <Select.Item value="60">1m</Select.Item>
                                                <Select.Item value="300">5m</Select.Item>
                                            </Select.Content>
                                        </Select.Root>
                                    </Flex>
                                    <Select.Root value={timePeriod} onValueChange={setTimePeriod}>
                                        <Select.Trigger style={{ width: isMobile ? '100%' : 140 }} />
                                        <Select.Content>
                                            <Select.Item value="1h">Last hour</Select.Item>
                                            <Select.Item value="24h">Last 24h</Select.Item>
                                            <Select.Item value="7d">Last 7 days</Select.Item>
                                        </Select.Content>
                                    </Select.Root>
                                </Flex>
                            </Card>

                            <Tabs.Root value={selectedTab} onValueChange={setSelectedTab}>
                                <Tabs.List mb="4" wrap="wrap">
                                    <Tabs.Trigger value="overview">
                                        <Flex align="center" gap="1"><ComputerDesktopIcon style={{ width: 14, height: 14 }} /> Overview</Flex>
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="database">
                                        <Flex align="center" gap="1"><CircleStackIcon style={{ width: 14, height: 14 }} /> Database</Flex>
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="performance">
                                        <Flex align="center" gap="1"><ChartBarIcon style={{ width: 14, height: 14 }} /> Performance</Flex>
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="security">
                                        <Flex align="center" gap="1"><ShieldCheckIcon style={{ width: 14, height: 14 }} /> Security</Flex>
                                    </Tabs.Trigger>
                                    <Tabs.Trigger value="compliance">
                                        <Flex align="center" gap="1"><DocumentTextIcon style={{ width: 14, height: 14 }} /> Compliance</Flex>
                                    </Tabs.Trigger>
                                </Tabs.List>
                            </Tabs.Root>

                            <QueryState
                                isLoading={isLoading && !Object.keys(data).length}
                                isError={isError}
                                error={error}
                                isEmpty={false}
                                onRetry={() => refetch()}
                                minHeight={400}
                            >
                                {renderTabContent()}
                            </QueryState>

                            <Separator size="4" my="4" />
                            <Grid columns={{ initial: '1', md: '4' }} gap="3">
                                <Box>
                                    <Text size="1" color="gray">Last updated</Text>
                                    <Text size="2">{data.system_health?.last_check ? new Date(data.system_health.last_check).toLocaleString() : new Date().toLocaleString()}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray">Application</Text>
                                    <Text size="2">{app?.name || 'ERP'}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray">Version</Text>
                                    <Text size="2">v{app?.version || '2.0'}</Text>
                                </Box>
                                <Box>
                                    <Text size="1" color="gray">Compliance</Text>
                                    <Badge color="green" variant="soft">Monitoring active</Badge>
                                </Box>
                            </Grid>
                        </Box>
                    </PageHeader>
                </GlassCard>
            </Box>
        </>
    );
};

SystemMonitoringEnhanced.layout = (page) => <App>{page}</App>;
export default SystemMonitoringEnhanced;

