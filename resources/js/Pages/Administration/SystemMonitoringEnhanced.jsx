import { Panel } from '@/Components/ui/Panel';
import React, { useState, useEffect, useCallback } from 'react';
import { Head, usePage } from '@inertiajs/react';
import { Badge, Box, Button, Flex, Grid, Heading, ScrollArea, Select, Separator, Spinner, Switch, Table, Tabs, Text } from '@radix-ui/themes';
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
import { useQueryFilters } from '@/Hooks/useQueryFilters';
import { usePersistentPageState } from '@/Hooks/usePersistentPageState';
import { showToast } from '@/utils/toastUtils';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import QueryState from '@/Components/Common/QueryState';
import StatsCards from '@/Components/StatsCards';

const statusColor = (status) => {
    if (['healthy', 'compliant', 'good', 'success'].includes(status)) return 'green';
    if (['warning', 'partial'].includes(status)) return 'amber';
    if (['critical', 'unhealthy', 'non_compliant', 'error'].includes(status)) return 'red';
    return 'gray';
};

const JsonPanel = ({ data, empty = 'No data available.' }) => (
    <Panel tinted style={{ borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: 16 }}>
        {data && Object.keys(data).length > 0 ? (
            <ScrollArea scrollbars="horizontal" style={{ maxHeight: 480 }}>
                <pre style={{ fontSize: 12, margin: 0, whiteSpace: 'pre-wrap', fontFamily: 'monospace' }}>
                    {JSON.stringify(data, null, 2)}
                </pre>
            </ScrollArea>
        ) : (
            <Text size="2" color="gray">{empty}</Text>
        )}
    </Panel>
);

const SystemMonitoringEnhanced = ({ title, initialData }) => {
    const { app } = usePage().props;
    const isMobile = useMediaQuery('(max-width: 768px)');

    /* Which panel and which window are what the server is asked for, so they
       live in the URL and an on-call engineer can share the exact view. */
    const f = useQueryFilters({
        mode: 'client',
        defaults: { tab: 'overview', period: '24h' },
        debounceKeys: [],
    });
    const MONITORING_TABS = ['overview', 'database', 'performance', 'security', 'compliance'];
    const selectedTab = MONITORING_TABS.includes(f.values.tab) ? f.values.tab : 'overview';
    const setSelectedTab = (value) => f.set('tab', value);
    const timePeriod = f.values.period;
    const setTimePeriod = (value) => f.set('period', value);

    /* How often to poll is this operator's standing preference, not part of the
       view being shared, so it is remembered rather than put in the URL. */
    const [ui, setUi] = usePersistentPageState('Administration/SystemMonitoring', {
        autoRefresh: true,
        refreshInterval: '30',
    });
    const autoRefresh = ui.autoRefresh;
    const setAutoRefresh = (value) => setUi({ autoRefresh: value });
    const refreshInterval = ui.refreshInterval;
    const setRefreshInterval = (value) => setUi({ refreshInterval: value });

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
                    <Panel tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: 20 }}>
                        <Box>
                            <Heading size="4" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>Table Analysis</Heading>
                            <Box style={{ overflowX: 'auto', WebkitOverflowScrolling: 'touch', borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', background: 'var(--aero-surface, var(--color-background))' }}>
                                <Table.Root size="2" style={{ minWidth: 640, width: '100%' }}>
                                    <Table.Header style={{
                                        position: 'sticky',
                                        top: 0,
                                        zIndex: 2,
                                        background: 'var(--aero-surface, var(--color-background))',
                                        backdropFilter: 'blur(8px)',
                                        boxShadow: '0 1px 0 var(--dl-border-color, rgba(0,0,0,0.06))'
                                    }}>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell style={{ minWidth: 200, background: 'inherit' }}>Table</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell align="right" style={{ minWidth: 100, background: 'inherit' }}>Rows</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell align="right" style={{ minWidth: 120, background: 'inherit' }}>Size (MB)</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ minWidth: 100, background: 'inherit' }}>Engine</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {tables.slice(0, 50).map((table) => (
                                            <Table.Row key={table.TABLE_NAME} align="center">
                                                <Table.Cell><Text weight="bold" style={{ fontFamily: 'monospace' }}>{table.TABLE_NAME}</Text></Table.Cell>
                                                <Table.Cell align="right" style={{ fontVariantNumeric: 'tabular-nums' }}>{table.TABLE_ROWS?.toLocaleString?.() ?? table.TABLE_ROWS ?? 0}</Table.Cell>
                                                <Table.Cell align="right" style={{ fontVariantNumeric: 'tabular-nums' }}>{table.size_mb ?? 0}</Table.Cell>
                                                <Table.Cell><Badge size="1" color="gray" variant="soft">{table.ENGINE}</Badge></Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </Box>
                            {tables.length > 50 && (
                                <Text size="1" color="gray" mt="2">Showing first 50 of {tables.length} tables.</Text>
                            )}
                        </Box>
                    </Panel>
                );
            case 'performance':
                return <JsonPanel data={data.performance_summary} empty="No performance metrics." />;
            case 'security':
                return <JsonPanel data={data.security_summary} empty="No security metrics." />;
            case 'compliance':
                return <JsonPanel data={data.compliance_summary} empty="No compliance metrics." />;
            default:
                return (
                    <Box>
                        <StatsCards
                            stats={overviewStats.map((stat, i) => ({
                                key: `stat-${i}`,
                                title: stat.title,
                                value: stat.value,
                                color: stat.color,
                            }))}
                            columns={{ initial: '1', sm: '2', lg: '4' }}
                            mb="4"
                        />
                        {data.system_health?.checks && (
                            <Box>
                                <Panel tinted style={{ borderRadius: 16, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))', padding: 20 }}>
                                    <Heading size="4" mb="3" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif` }}>Health Checks</Heading>
                                    <Flex direction="column" gap="2">
                                        {Object.entries(data.system_health.checks).map(([key, check]) => (
                                            <Flex key={key} justify="between" align="center" p="3" style={{ border: '1px solid var(--dl-border-color, rgba(0,0,0,0.06))', borderRadius: 12, background: 'var(--aero-surface, var(--color-background))' }}>
                                                <Box>
                                                    <Text size="2" weight="medium">{key.replace(/_/g, ' ')}</Text>
                                                    <Text size="1" color="gray" as="div">{check.message}</Text>
                                                </Box>
                                                <Badge color={statusColor(check.status)} variant="soft" style={{ borderRadius: 999 }}>{check.status}</Badge>
                                            </Flex>
                                        ))}
                                    </Flex>
                                </Panel>
                            </Box>
                        )}
                    </Box>
                );
        }
    };

    return (
        <App>
            <Head title={title || 'Enterprise System Monitoring'} />
            <Flex justify="center" p="4">
                <Box style={{ width: '100%', maxWidth: 2000 }}>
                    <Panel>
                        {/* ── Page Header ── */}
                        <Box mb="4">
                            <Flex direction={{ initial: 'column', sm: 'row' }} align={{ initial: 'start', sm: 'center' }} justify="between" gap="4">
                                <Flex align="center" gap="3">
                                    <Box p="3" style={{ background: 'var(--blue-a3)', borderRadius: 12, border: '1px solid var(--blue-a5)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                        <ComputerDesktopIcon style={{ width: 22, height: 22, color: 'var(--blue-9)' }} />
                                    </Box>
                                    <Box>
                                        <Heading size="5" style={{ fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 800, letterSpacing: '-0.02em' }}>Enterprise System Monitoring</Heading>
                                        <Text size="2" style={{ color: 'var(--aero-color-subtle, var(--gray-9))' }}>
                                            System health, database, performance, security, and compliance telemetry
                                        </Text>
                                    </Box>
                                </Flex>
                                <Flex gap="2" align="center" wrap="wrap">
                                    <Button variant="soft" color="green" onClick={handleExportReport} style={{ borderRadius: 10, fontFamily: `'Space Grotesk', system-ui, sans-serif`, fontWeight: 600 }}>
                                        <DocumentArrowDownIcon style={{ width: 16, height: 16 }} />
                                        {!isMobile && 'Export report'}
                                    </Button>
                                    <Button variant="soft" color="gray" onClick={refreshData} disabled={isLoading} style={{ borderRadius: 10 }}>
                                        <ArrowPathIcon style={{ width: 16, height: 16 }} />
                                        {!isMobile && 'Refresh'}
                                    </Button>
                                </Flex>
                            </Flex>
                        </Box>

                        <Separator size="4" mb="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        {/* Controls Panel */}
                        <Box mb="4" p="3" style={{ background: 'var(--aero-surface, var(--color-background))', borderRadius: 14, border: '1px solid var(--aero-surface-border, rgba(0,0,0,0.06))' }}>
                            <Flex direction={{ initial: 'column', lg: 'row' }} gap="4" align={{ lg: 'center' }} justify="between">
                                <Flex align="center" gap="3" wrap="wrap">
                                    <Flex align="center" gap="2">
                                        <Switch checked={autoRefresh} onCheckedChange={setAutoRefresh} />
                                        <Text size="2" weight="medium">Auto refresh</Text>
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
                        </Box>

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
                            minHeight={300}
                        >
                            {renderTabContent()}
                        </QueryState>

                        <Separator size="4" my="4" style={{ background: 'var(--dl-border-color, rgba(0,0,0,0.06))' }} />

                        <Grid columns={{ initial: '1', sm: '2', md: '4' }} gap="3">
                            <Box>
                                <Text size="1" color="gray">Last updated</Text>
                                <Text size="2" weight="medium" style={{ fontVariantNumeric: 'tabular-nums' }}>{data.system_health?.last_check ? new Date(data.system_health.last_check).toLocaleString() : new Date().toLocaleString()}</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Application</Text>
                                <Text size="2" weight="medium">{app?.name || 'ERP'}</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Version</Text>
                                <Text size="2" weight="medium">v{app?.version || '2.0'}</Text>
                            </Box>
                            <Box>
                                <Text size="1" color="gray">Compliance</Text>
                                <Badge color="green" variant="soft" style={{ borderRadius: 999 }}>Monitoring active</Badge>
                            </Box>
                        </Grid>
                    </Panel>
                </Box>
            </Flex>
        </App>
    );
};

export default SystemMonitoringEnhanced;
