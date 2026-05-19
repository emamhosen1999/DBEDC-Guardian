/**
 * AnalyticsPanel.jsx
 * "Analytics" tab — monthly trends, leave-type breakdown, department bars.
 * * UX Improvements added:
 * - Layout-Preserving Skeletons: No more layout jumps when changing years or filters.
 * - Upgraded Data Table: Replaced raw HTML table with Radix UI <Table> wrapped in a <ScrollArea> for mobile.
 * - Unified Card Layout: Stats and charts now share exact shadow/border radius values with the rest of the app.
 */
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import axios from 'axios';
import {
    Badge, Box, Card, Flex, Grid,
    IconButton, Select, Separator, Text,
    Skeleton, ScrollArea, Table
} from '@radix-ui/themes';
import {
    BarChartIcon, HomeIcon, CalendarIcon,
    CheckCircledIcon, ClockIcon, CrossCircledIcon, ReloadIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

/* ── Mini Bar Chart (Pure SVG) ── */
function BarChart({ data, valueKey = 'value', labelKey = 'label', color = 'var(--accent-9)', height = 140, loading = false }) {
    if (loading) return <Skeleton height={`${height + 24}px`} width="100%" />;
    if (!data?.length) return <Flex height={`${height}px`} align="center" justify="center"><Text color="gray">No data</Text></Flex>;
    
    const max = Math.max(...data.map(d => d[valueKey] || 0), 1);
    const barW = Math.max(12, Math.floor(320 / data.length) - 4);
    const totalW = data.length * (barW + 4);
    
    return (
        <ScrollArea type="auto" scrollbars="horizontal">
            <Box style={{ overflowX: 'auto', paddingBottom: '8px' }}>
                <svg viewBox={`0 0 ${totalW} ${height + 24}`} style={{ width: '100%', minWidth: totalW }}>
                    {data.map((d, i) => {
                        const val = d[valueKey] || 0;
                        const barH = Math.max(2, Math.round((val / max) * height));
                        const x = i * (barW + 4);
                        const y = height - barH;
                        return (
                            <g key={i} style={{ transition: 'all 0.3s ease' }}>
                                <rect x={x} y={y} width={barW} height={barH} fill={color} rx="3" opacity="0.85" />
                                <text x={x + barW / 2} y={height + 14} textAnchor="middle" fontSize="9" fill="var(--gray-10)">{d[labelKey]}</text>
                                {val > 0 && (
                                    <text x={x + barW / 2} y={y - 4} textAnchor="middle" fontSize="9" fontWeight="600" fill="var(--gray-11)">{val}</text>
                                )}
                            </g>
                        );
                    })}
                </svg>
            </Box>
        </ScrollArea>
    );
}

/* ── Horizontal Progress Row ── */
function ProgressRow({ label, value, max, color = 'var(--accent-9)', badge, loading = false }) {
    if (loading) return <Skeleton height="28px" mb="2" />;
    
    const pct = max > 0 ? Math.round((value / max) * 100) : 0;
    return (
        <Flex direction="column" gap="1">
            <Flex justify="between" align="center">
                <Text size="2" color="gray" weight="medium">{label}</Text>
                <Flex align="center" gap="2">
                    {badge && <Badge size="1" variant="soft" color={badge}>{value}</Badge>}
                    {!badge && <Text size="2" weight="medium">{value}</Text>}
                    <Text size="1" color="gray" style={{ width: '30px', textAlign: 'right' }}>{pct}%</Text>
                </Flex>
            </Flex>
            <Box style={{ height: 6, background: 'var(--gray-a4)', borderRadius: 'var(--radius-1)', overflow: 'hidden' }}>
                <Box style={{ height: '100%', width: `${pct}%`, background: color, borderRadius: 'var(--radius-1)', transition: 'width 0.6s cubic-bezier(0.4, 0, 0.2, 1)' }} />
            </Box>
        </Flex>
    );
}

/* ── Stat Card ── */
function StatCard({ label, value, sub, color = 'blue', icon: Icon, loading = false }) {
    return (
        <Card size="2" variant="surface">
            <Flex align="start" gap="3">
                <Box p="2" style={{ background: `var(--${color}-a3)`, borderRadius: 'var(--radius-2)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    <Icon style={{ width: 18, height: 18, color: `var(--${color}-9)` }} />
                </Box>
                <Box>
                    <Skeleton loading={loading}>
                        <Text size="6" weight="bold" as="div" style={{ lineHeight: 1.1 }}>{value ?? 0}</Text>
                    </Skeleton>
                    <Text size="2" weight="medium" as="div" mt="1">{label}</Text>
                    {sub && <Text size="1" color="gray">{sub}</Text>}
                </Box>
            </Flex>
        </Card>
    );
}

/* ── Main Component ── */
const MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

export default function AnalyticsPanel({ isMobile, isActive, onSetHeaderActions }) {
    const [loading,    setLoading]    = useState(true);
    const [analytics,  setAnalytics]  = useState(null);
    const [year,       setYear]       = useState(new Date().getFullYear());
    const [deptId,     setDeptId]     = useState('');
    const [departments, setDepartments] = useState([]);

    const years = useMemo(() => {
        const cur = new Date().getFullYear();
        return Array.from({ length: 5 }, (_, i) => cur - i);
    }, []);

    const fetchAnalytics = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('leaves.analytics'), {
                params: { year, department_id: deptId || undefined },
            });
            if (data.success) setAnalytics(data.analytics);
            if (data.departments) setDepartments(data.departments);
        } catch {
            showToast.error('Failed to load analytics.');
        } finally {
            setLoading(false);
        }
    }, [year, deptId]);

    useEffect(() => { if (isActive) fetchAnalytics(); }, [fetchAnalytics, isActive]);

    /* ── Header Actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <IconButton size="2" variant="soft" color="gray" onClick={fetchAnalytics} aria-label="Refresh Analytics" disabled={loading}>
                <ReloadIcon />
            </IconButton>
        );
    }, [isActive, fetchAnalytics, loading]);

    /* ── Derived Chart Data ── */
    const monthlyData = useMemo(() => {
        if (!analytics?.monthly_trends) return [];
        return analytics.monthly_trends.map((m, i) => ({
            label: MONTH_LABELS[i] ?? `M${i + 1}`,
            value: m.leaves_taken ?? m.total ?? m.count ?? 0,
            approved: m.leaves_approved ?? m.approved ?? 0,
            pending:  m.pending  ?? 0,
            rejected: m.rejected ?? 0,
        }));
    }, [analytics]);

    const leaveTypeData = useMemo(() => {
        const source = analytics?.leave_type_distribution ?? analytics?.by_leave_type ?? [];
        if (!source.length) return [];
        const total = source.reduce((s, t) => s + (t.count ?? 0), 0) || 1;
        return source.map(t => ({
            label:      t.type ?? t.name ?? '—',
            value:      t.count ?? 0,
            percentage: Math.round(((t.count ?? 0) / total) * 100),
        }));
    }, [analytics]);

    const departmentData = useMemo(() => {
        const source = analytics?.department_comparison ?? analytics?.by_department ?? [];
        if (!source.length) return [];
        const max = Math.max(...source.map(d => d.average_days ?? d.total ?? d.count ?? 0), 1);
        return source.map(d => ({
            label: d.department ?? d.name ?? '—',
            value: d.average_days ?? d.total ?? d.count ?? 0,
            max,
        }));
    }, [analytics]);

    const topStats = analytics?.summary ?? analytics?.stats ?? analytics?.overview ?? { total: 0, approved: 0, pending: 0, rejected: 0 };

    /* ── Render ── */
    return (
        <Box>
            {/* ── Filter Row ── */}
            <Flex gap="3" align="center" mb="5" wrap="wrap">
                <Select.Root size="2" value={String(year)} onValueChange={v => setYear(Number(v))}>
                    <Select.Trigger style={{ minWidth: 120 }} />
                    <Select.Content>
                        {years.map(y => <Select.Item key={y} value={String(y)}>{y}</Select.Item>)}
                    </Select.Content>
                </Select.Root>

                {departments.length > 0 && (
                    <Select.Root size="2" value={deptId || 'all'} onValueChange={v => setDeptId(v === 'all' ? '' : v)}>
                        <Select.Trigger style={{ minWidth: 200 }} placeholder="All Departments" />
                        <Select.Content>
                            <Select.Item value="all">All Departments</Select.Item>
                            {departments.map(d => (
                                <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                )}
            </Flex>

            {!loading && !analytics ? (
                <Flex direction="column" align="center" py="9" gap="3" style={{ border: '1px dashed var(--gray-a6)', borderRadius: 'var(--radius-3)' }}>
                    <BarChartIcon style={{ width: 48, height: 48, color: 'var(--gray-8)' }} />
                    <Text size="4" weight="bold">No Analytics Data</Text>
                    <Text size="2" color="gray">No leave data available for {year}.</Text>
                </Flex>
            ) : (
                <Flex direction="column" gap="5">

                    {/* ── Summary Stat Cards ── */}
                    <Grid columns={{ initial: '2', md: '4' }} gap="4">
                        <StatCard label="Total Leaves" value={topStats.total}    color="blue"   icon={CalendarIcon}     loading={loading} />
                        <StatCard label="Approved"     value={topStats.approved} color="green"  icon={CheckCircledIcon} loading={loading} />
                        <StatCard label="Pending"      value={topStats.pending}  color="amber"  icon={ClockIcon}        loading={loading} />
                        <StatCard label="Rejected"     value={topStats.rejected} color="red"    icon={CrossCircledIcon} loading={loading} />
                    </Grid>

                    {/* ── Monthly Trend Bar Chart ── */}
                    <Card variant="surface">
                        <Text size="3" weight="bold" as="div" mb="4">Monthly Leave Trends — {year}</Text>
                        <BarChart
                            data={monthlyData}
                            valueKey="value"
                            labelKey="label"
                            color="var(--accent-9)"
                            height={160}
                            loading={loading}
                        />
                        
                        {!loading && monthlyData.length > 0 && (
                            <>
                                <Flex gap="4" mt="4" wrap="wrap">
                                    {[
                                        { label: 'Approved', color: 'green' },
                                        { label: 'Pending',  color: 'amber' },
                                        { label: 'Rejected', color: 'red'   },
                                    ].map(({ label, color }) => (
                                        <Flex key={label} align="center" gap="2">
                                            <Box style={{ width: 10, height: 10, borderRadius: '50%', background: `var(--${color}-9)` }} />
                                            <Text size="1" color="gray" weight="medium">{label}</Text>
                                        </Flex>
                                    ))}
                                </Flex>

                                <Separator size="4" my="4" />
                                
                                <Grid columns={{ initial: '1', sm: '2', md: '3' }} gap="3">
                                    {monthlyData.filter(m => m.value > 0).slice(0, 6).map((m, i) => (
                                        <Flex key={i} align="center" justify="between" p="2" style={{ backgroundColor: 'var(--gray-a2)', borderRadius: 'var(--radius-2)' }}>
                                            <Text size="2" weight="bold" style={{ width: 40 }}>{m.label}</Text>
                                            <Flex gap="2" align="center">
                                                {m.approved > 0 && <Badge size="1" color="green" variant="soft">{m.approved} Apprv</Badge>}
                                                {m.pending  > 0 && <Badge size="1" color="amber" variant="soft">{m.pending} Pend</Badge>}
                                                {m.rejected > 0 && <Badge size="1" color="red"   variant="soft">{m.rejected} Rej</Badge>}
                                            </Flex>
                                        </Flex>
                                    ))}
                                </Grid>
                            </>
                        )}
                    </Card>

                    {/* ── Leave Type & Department Breakdown ── */}
                    <Grid columns={{ initial: '1', lg: '2' }} gap="4">
                        <Card variant="surface">
                            <Text size="3" weight="bold" as="div" mb="4">By Leave Type</Text>
                            <Flex direction="column" gap="3">
                                {loading ? Array.from({ length: 4 }).map((_, i) => <ProgressRow key={i} loading={true} />) 
                                : leaveTypeData.length > 0 ? leaveTypeData.map((t, i) => (
                                    <ProgressRow
                                        key={i}
                                        label={t.label}
                                        value={t.value}
                                        max={leaveTypeData.reduce((s, x) => s + x.value, 0) || 1}
                                        color="var(--blue-9)"
                                        badge="blue"
                                    />
                                )) : <Text size="2" color="gray">No leave types recorded.</Text>}
                            </Flex>
                        </Card>

                        <Card variant="surface">
                            <Text size="3" weight="bold" as="div" mb="4">By Department</Text>
                            <Flex direction="column" gap="3">
                                {loading ? Array.from({ length: 4 }).map((_, i) => <ProgressRow key={i} loading={true} />)
                                : departmentData.length > 0 ? departmentData.map((d, i) => (
                                    <ProgressRow
                                        key={i}
                                        label={d.label}
                                        value={d.value}
                                        max={d.max}
                                        color="var(--violet-9)"
                                    />
                                )) : <Text size="2" color="gray">No department data available.</Text>}
                            </Flex>
                        </Card>
                    </Grid>

                    {/* ── Top Leave Takers Table ── */}
                    <Card variant="surface" p="0" style={{ overflow: 'hidden' }}>
                        <Box p="4" pb="2">
                            <Text size="3" weight="bold" as="div">Top Leave Takers — {year}</Text>
                        </Box>
                        
                        {loading ? (
                            <Box p="4">
                                <Skeleton height="30px" mb="2" />
                                <Skeleton height="30px" mb="2" />
                                <Skeleton height="30px" />
                            </Box>
                        ) : analytics?.top_leave_takers?.length > 0 ? (
                            <ScrollArea type="auto" scrollbars="both" style={{ width: '100%' }}>
                                <Table.Root size="2" variant="ghost">
                                    <Table.Header style={{ backgroundColor: 'var(--gray-a2)' }}>
                                        <Table.Row>
                                            <Table.ColumnHeaderCell>#</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Employee</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell>Department</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Total Days</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Approved</Table.ColumnHeaderCell>
                                            <Table.ColumnHeaderCell style={{ textAlign: 'center' }}>Pending</Table.ColumnHeaderCell>
                                        </Table.Row>
                                    </Table.Header>
                                    <Table.Body>
                                        {analytics.top_leave_takers.slice(0, 10).map((emp, i) => (
                                            <Table.Row key={i}>
                                                <Table.Cell><Text size="2" color="gray" weight="bold">{i + 1}</Text></Table.Cell>
                                                <Table.Cell><Text size="2" weight="medium">{emp.name ?? emp.employee_name ?? '—'}</Text></Table.Cell>
                                                <Table.Cell><Text size="2" color="gray">{emp.department ?? '—'}</Text></Table.Cell>
                                                <Table.Cell style={{ textAlign: 'center' }}><Badge size="2" color="blue" variant="solid">{emp.total_days ?? emp.total ?? 0}</Badge></Table.Cell>
                                                <Table.Cell style={{ textAlign: 'center' }}><Badge size="2" color="green" variant="soft">{emp.approved_days ?? emp.approved ?? 0}</Badge></Table.Cell>
                                                <Table.Cell style={{ textAlign: 'center' }}><Badge size="2" color="amber" variant="soft">{emp.pending_days ?? emp.pending ?? 0}</Badge></Table.Cell>
                                            </Table.Row>
                                        ))}
                                    </Table.Body>
                                </Table.Root>
                            </ScrollArea>
                        ) : (
                            <Box p="4"><Text size="2" color="gray">No leave takers found.</Text></Box>
                        )}
                    </Card>

                </Flex>
            )}
        </Box>
    );
}