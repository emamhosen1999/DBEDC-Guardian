import { Panel } from '@/Components/ui/Panel';
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
import { Badge, Box, Flex, Grid, IconButton, Select, Separator, Text, Skeleton, ScrollArea, Table } from '@radix-ui/themes';
import {
    BarChartIcon, HomeIcon, CalendarIcon,
    CheckCircledIcon, ClockIcon, CrossCircledIcon, ReloadIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

/* ── Stacked Bar Chart (Pure SVG) ── */
function BarChart({ data, height = 180, loading = false }) {
    if (loading) return <Skeleton height={`${height + 36}px`} width="100%" />;
    if (!data?.length) return <Flex height={`${height}px`} align="center" justify="center"><Text color="gray">No data</Text></Flex>;
    
    // Find the max value (sum of approved, pending, rejected)
    const max = Math.max(...data.map(d => (d.approved || 0) + (d.pending || 0) + (d.rejected || 0)), 1);
    
    // Grid settings
    const barW = Math.max(16, Math.floor(400 / data.length) - 8);
    const totalW = data.length * (barW + 12) + 24;
    
    // Background Grid Y-Coordinates
    const gridLines = [0, 0.25, 0.5, 0.75, 1];

    return (
        <ScrollArea type="auto" scrollbars="horizontal">
            <Box style={{ overflowX: 'auto', paddingBottom: '8px' }}>
                <svg viewBox={`0 0 ${totalW} ${height + 36}`} style={{ width: '100%', minWidth: totalW }}>
                    <defs>
                        <linearGradient id="apprGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="var(--green-9)" />
                            <stop offset="100%" stopColor="var(--green-7)" stopOpacity={0.8} />
                        </linearGradient>
                        <linearGradient id="pendGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="var(--amber-9)" />
                            <stop offset="100%" stopColor="var(--amber-7)" stopOpacity={0.8} />
                        </linearGradient>
                        <linearGradient id="rejGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stopColor="var(--red-9)" />
                            <stop offset="100%" stopColor="var(--red-7)" stopOpacity={0.8} />
                        </linearGradient>
                    </defs>

                    {/* Grid Lines */}
                    {gridLines.map((ratio, idx) => {
                        const y = height - ratio * height + 10;
                        const label = Math.round(ratio * max);
                        return (
                            <g key={idx} opacity="0.15">
                                <line x1="24" y1={y} x2={totalW} y2={y} stroke="var(--gray-12)" strokeWidth="1" strokeDasharray="3 3" />
                                <text x="18" y={y + 3} textAnchor="end" fontSize="8" fontWeight="600" fill="var(--gray-12)">{label}</text>
                            </g>
                        );
                    })}

                    {data.map((d, i) => {
                        const apprVal = d.approved || 0;
                        const pendVal = d.pending || 0;
                        const rejVal = d.rejected || 0;
                        const totalVal = apprVal + pendVal + rejVal;

                        // Bar Heights
                        const apprH = Math.round((apprVal / max) * height);
                        const pendH = Math.round((pendVal / max) * height);
                        const rejH = Math.round((rejVal / max) * height);

                        const x = i * (barW + 12) + 30;

                        // Y position offsets for stacked blocks
                        const apprY = height - apprH + 10;
                        const pendY = apprY - pendH;
                        const rejY = pendY - rejH;

                        return (
                            <g key={i} style={{ transition: 'all 0.3s ease' }}>
                                {/* Approved Segment */}
                                {apprH > 0 && (
                                    <rect x={x} y={apprY} width={barW} height={apprH} fill="url(#apprGrad)" rx={pendH === 0 && rejH === 0 ? "4" : "0"} />
                                )}
                                {/* Pending Segment */}
                                {pendH > 0 && (
                                    <rect x={x} y={pendY} width={barW} height={pendH} fill="url(#pendGrad)" rx={rejH === 0 ? "4" : "0"} />
                                )}
                                {/* Rejected Segment */}
                                {rejH > 0 && (
                                    <rect x={x} y={rejY} width={barW} height={rejH} fill="url(#rejGrad)" rx="4" />
                                )}

                                {/* X-axis labels */}
                                <text x={x + barW / 2} y={height + 26} textAnchor="middle" fontSize="9" fontWeight="600" fill="var(--gray-10)">{d.label}</text>
                                
                                {/* Total Label above bar */}
                                {totalVal > 0 && (
                                    <text x={x + barW / 2} y={rejY - 4} textAnchor="middle" fontSize="9" fontWeight="800" fill="var(--gray-12)">{totalVal}</text>
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
                <Text size="2" color="gray" weight="semibold">{label}</Text>
                <Flex align="center" gap="2">
                    {badge && <Badge size="1" variant="soft" color={badge} style={{ fontWeight: 700 }}>{value}</Badge>}
                    {!badge && <Text size="2" weight="bold">{value}</Text>}
                    <Text size="1" color="gray" style={{ width: '32px', textAlign: 'right', fontWeight: 500 }}>{pct}%</Text>
                </Flex>
            </Flex>
            <Box style={{ height: 8, background: 'var(--gray-a4)', borderRadius: 'var(--radius-full)', overflow: 'hidden' }}>
                <Box style={{ 
                    height: '100%', 
                    width: `${pct}%`, 
                    background: color, 
                    borderRadius: 'var(--radius-full)', 
                    transition: 'width 0.6s cubic-bezier(0.4, 0, 0.2, 1)',
                    boxShadow: `0 0 8px ${color}80`
                }} />
            </Box>
        </Flex>
    );
}

/* ── Stat Card ── */
function StatCard({ label, value, sub, color = 'blue', icon: Icon, loading = false }) {
    return (
        <Panel size="2" style={{
            background: `linear-gradient(135deg, var(--${color}-a2) 0%, var(--color-surface) 100%)`,
            border: `1px solid var(--${color}-a4)`,
            boxShadow: 'var(--shadow-2)',
            borderRadius: 'var(--radius-3)'
        }}>
            <Flex align="center" gap="3">
                <Box p="3" style={{ background: `var(--${color}-a3)`, borderRadius: 'var(--radius-3)', display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                    <Icon style={{ width: 20, height: 20, color: `var(--${color}-9)` }} />
                </Box>
                <Box>
                    <Skeleton loading={loading}>
                        <Text size="6" weight="bold" as="div" style={{ lineHeight: 1.1, color: 'var(--gray-12)' }}>{value ?? 0}</Text>
                    </Skeleton>
                    <Text size="2" weight="bold" as="div" mt="1" color="gray" style={{ opacity: 0.8 }}>{label}</Text>
                    {sub && <Text size="1" color="gray" style={{ display: 'block', mt: 1 }}>{sub}</Text>}
                </Box>
            </Flex>
        </Panel>
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
        const total = source.reduce((s, t) => s + (Number(t.count) || Number(t.total) || Number(t.days) || 0), 0) || 1;
        return source.map(t => {
            const val = Number(t.count) || Number(t.total) || Number(t.days) || 0;
            return {
                label:      t.type ?? t.name ?? '—',
                value:      val,
                percentage: Math.round((val / total) * 100),
            };
        });
    }, [analytics]);

    const departmentData = useMemo(() => {
        const source = analytics?.department_comparison ?? analytics?.by_department ?? [];
        if (!source.length) return [];
        const max = Math.max(...source.map(d => Number(d.average_days) || Number(d.total) || Number(d.count) || 0), 1);
        return source.map(d => ({
            label: d.department ?? d.name ?? '—',
            value: Number(d.average_days) || Number(d.total) || Number(d.count) || 0,
            max,
        }));
    }, [analytics]);

    const topStats = analytics?.summary ?? analytics?.stats ?? analytics?.overview ?? { total: 0, approved: 0, pending: 0, rejected: 0 };
    const absenteeismRate = Number(analytics?.absenteeism_rate) || 0;
    const peakPeriods = analytics?.peak_periods || [];

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

                    {/* ── Visual Analytics Grid ── */}
                    <Grid columns={{ initial: '1', md: '3' }} gap="4">
                        {/* Monthly Trend Bar Chart */}
                        <Panel variant="surface">
                            <Text size="3" weight="bold" as="div" mb="1">Monthly Leave Trends — {year}</Text>
                            <Text size="1" color="gray" mb="3">Monthly request volume and status splits</Text>
                            <BarChart
                                data={monthlyData}
                                height={120}
                                loading={loading}
                            />
                            {!loading && monthlyData.length > 0 && (
                                <Flex gap="3" mt="3" justify="center" wrap="wrap">
                                    {[
                                        { label: 'Approved', color: 'green' },
                                        { label: 'Pending',  color: 'amber' },
                                        { label: 'Rejected', color: 'red'   },
                                    ].map(({ label, color }) => (
                                        <Flex key={label} align="center" gap="1">
                                            <Box style={{ width: 8, height: 8, borderRadius: '50%', background: `var(--${color}-9)` }} />
                                            <Text size="1" color="gray" weight="medium">{label}</Text>
                                        </Flex>
                                    ))}
                                </Flex>
                            )}
                        </Panel>

                        {/* Absenteeism Gauge Card */}
                        <Panel variant="surface">
                            <Text size="3" weight="bold" as="div" mb="1">Absenteeism Rate</Text>
                            <Text size="1" color="gray" mb="3">Ratio of leave days to total working days</Text>
                            <Flex align="center" justify="between" gap="4" py="2" style={{ height: 120 }}>
                                <Flex direction="column" gap="1">
                                    <Text size="6" weight="bold" color={absenteeismRate > 2 ? 'red' : 'green'}>
                                        {absenteeismRate.toFixed(2)}%
                                    </Text>
                                    <Text size="2" color="gray">Healthy range: &lt; 2.0%</Text>
                                </Flex>
                                <Box style={{ position: 'relative', width: 75, height: 75, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                                    <svg viewBox="0 0 36 36" style={{ width: '100%', height: '100%', transform: 'rotate(-90deg)' }}>
                                        <path
                                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                            fill="none"
                                            stroke="var(--gray-a4)"
                                            strokeWidth="3.5"
                                        />
                                        <path
                                            d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"
                                            fill="none"
                                            stroke={absenteeismRate > 2 ? 'var(--red-9)' : 'var(--green-9)'}
                                            strokeWidth="3.5"
                                            strokeDasharray={`${Math.min(100, absenteeismRate * 10)}, 100`}
                                            style={{ strokeLinecap: 'round', transition: 'stroke-dasharray 0.6s ease' }}
                                        />
                                    </svg>
                                    <Box style={{ position: 'absolute' }}>
                                        <BarChartIcon style={{ width: 22, height: 22, color: 'var(--gray-9)' }} />
                                    </Box>
                                </Box>
                            </Flex>
                        </Panel>

                        {/* Peak Periods & Status Distribution List Card */}
                        <Panel variant="surface">
                            <Text size="3" weight="bold" as="div" mb="1">Peak Periods & Split</Text>
                            <Text size="1" color="gray" mb="3">Months with highest approved volume</Text>
                            
                            <Flex direction="column" gap="2" style={{ maxHeight: 110 }}>
                                {loading ? (
                                    <Skeleton height="40px" />
                                ) : peakPeriods.length > 0 ? (
                                    peakPeriods.slice(0, 2).map((p, idx) => (
                                        <Flex key={idx} align="center" justify="between" p="1" px="2" style={{ backgroundColor: 'var(--gray-a2)', borderRadius: 'var(--radius-3)' }}>
                                            <Flex align="center" gap="2">
                                                <Badge size="1" color="indigo" radius="full" style={{ width: 16, height: 16, justifyContent: 'center' }}>
                                                    {idx + 1}
                                                </Badge>
                                                <Text size="1" weight="bold">{p.period}</Text>
                                            </Flex>
                                            <Badge size="1" color="blue" variant="soft">
                                                {p.count} requests
                                            </Badge>
                                        </Flex>
                                    ))
                                ) : (
                                    <Text size="1" color="gray" style={{ fontStyle: 'italic' }}>No peak period data.</Text>
                                )}
                            </Flex>

                            {/* Status distribution progress bar */}
                            {(() => {
                                const total = Number(topStats.total) || 0;
                                const apprPct = total > 0 ? Math.round((Number(topStats.approved) / total) * 100) : 0;
                                const pendPct = total > 0 ? Math.round((Number(topStats.pending) / total) * 100) : 0;
                                const rejPct = total > 0 ? Math.round((Number(topStats.rejected) / total) * 100) : 0;
                                return (
                                    <Flex direction="column" gap="1" mt="3">
                                        <Box style={{ height: 8, background: 'var(--gray-a4)', borderRadius: 'var(--radius-full)', overflow: 'hidden', display: 'flex' }}>
                                            {apprPct > 0 && <Box style={{ width: `${apprPct}%`, background: 'var(--green-9)' }} />}
                                            {pendPct > 0 && <Box style={{ width: `${pendPct}%`, background: 'var(--amber-9)' }} />}
                                            {rejPct > 0 && <Box style={{ width: `${rejPct}%`, background: 'var(--red-9)' }} />}
                                        </Box>
                                        <Flex justify="between" style={{ fontSize: 9 }}>
                                            <Text color="green" weight="bold">{apprPct}% Apprv</Text>
                                            <Text color="amber" weight="bold">{pendPct}% Pend</Text>
                                            <Text color="red" weight="bold">{rejPct}% Rej</Text>
                                        </Flex>
                                    </Flex>
                                );
                            })()}
                        </Panel>
                    </Grid>

                    {/* ── Leave Type & Department Breakdown ── */}
                    <Grid columns={{ initial: '1', lg: '2' }} gap="4">
                        <Panel variant="surface">
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
                        </Panel>

                        <Panel variant="surface">
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
                        </Panel>
                    </Grid>

                    {/* ── Top Leave Takers Table ── */}
                    <Panel variant="surface" p="0" style={{ overflow: 'hidden' }}>
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
                    </Panel>

                </Flex>
            )}
        </Box>
    );
}