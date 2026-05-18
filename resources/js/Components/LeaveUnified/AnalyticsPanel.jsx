/**
 * AnalyticsPanel.jsx
 * "Analytics" tab — monthly trends, leave-type breakdown, department bars.
 * Rebuilt from LeaveAnalytics.jsx (was HeroUI) → pure Radix UI.
 * Charts are rendered as inline SVG progress bars / bar charts (no external chart lib needed).
 */
import React, { useState, useEffect, useCallback, useMemo } from 'react';
import axios from 'axios';
import {
    Badge, Box, Button, Card, Flex, Grid,
    IconButton, Select, Separator, Spinner, Text,
} from '@radix-ui/themes';
import {
    BarChartIcon, BuildingOfficeIcon, CalendarIcon,
    CheckCircledIcon, ClockIcon, CrossCircledIcon, ReloadIcon,
} from '@radix-ui/react-icons';
import { showToast } from '@/utils/toastUtils';

/* ── mini bar chart (pure SVG) ── */
function BarChart({ data, valueKey = 'value', labelKey = 'label', color = 'var(--accent-9)', height = 140 }) {
    if (!data?.length) return null;
    const max    = Math.max(...data.map(d => d[valueKey] || 0), 1);
    const barW   = Math.max(12, Math.floor(320 / data.length) - 4);
    const totalW = data.length * (barW + 4);
    return (
        <Box style={{ overflowX: 'auto' }}>
            <svg viewBox={`0 0 ${totalW} ${height + 24}`} style={{ width: '100%', minWidth: totalW }}>
                {data.map((d, i) => {
                    const val  = d[valueKey] || 0;
                    const barH = Math.max(2, Math.round((val / max) * height));
                    const x    = i * (barW + 4);
                    const y    = height - barH;
                    return (
                        <g key={i}>
                            <rect x={x} y={y} width={barW} height={barH}
                                fill={color} rx="3" opacity="0.85" />
                            <text x={x + barW / 2} y={height + 14}
                                textAnchor="middle" fontSize="9"
                                fill="var(--gray-10)">{d[labelKey]}</text>
                            {val > 0 && (
                                <text x={x + barW / 2} y={y - 4}
                                    textAnchor="middle" fontSize="9" fontWeight="600"
                                    fill="var(--gray-11)">{val}</text>
                            )}
                        </g>
                    );
                })}
            </svg>
        </Box>
    );
}

/* ── horizontal progress row ── */
function ProgressRow({ label, value, max, color = 'var(--accent-9)', badge }) {
    const pct = max > 0 ? Math.round((value / max) * 100) : 0;
    return (
        <Flex direction="column" gap="1">
            <Flex justify="between" align="center">
                <Text size="2" color="gray">{label}</Text>
                <Flex align="center" gap="2">
                    {badge && <Badge size="1" variant="soft" color={badge}>{value}</Badge>}
                    {!badge && <Text size="2" weight="medium">{value}</Text>}
                    <Text size="1" color="gray">{pct}%</Text>
                </Flex>
            </Flex>
            <Box style={{
                height: 6, background: 'var(--gray-a4)',
                borderRadius: 'var(--radius-1)', overflow: 'hidden',
            }}>
                <Box style={{
                    height: '100%', width: `${pct}%`,
                    background: color, borderRadius: 'var(--radius-1)',
                    transition: 'width 0.4s ease',
                }} />
            </Box>
        </Flex>
    );
}

/* ── stat card ── */
function StatCard({ label, value, sub, color = 'blue', icon: Icon }) {
    return (
        <Card size="2" variant="surface">
            <Flex align="start" gap="3">
                <Box p="2" style={{
                    background: `var(--${color}-a3)`,
                    borderRadius: 'var(--radius-2)',
                    display: 'flex', alignItems: 'center', justifyContent: 'center',
                }}>
                    <Icon style={{ width: 16, height: 16, color: `var(--${color}-9)` }} />
                </Box>
                <Box>
                    <Text size="5" weight="bold" as="div">{value ?? 0}</Text>
                    <Text size="2" weight="medium" as="div">{label}</Text>
                    {sub && <Text size="1" color="gray">{sub}</Text>}
                </Box>
            </Flex>
        </Card>
    );
}

/* ── main ── */
export default function AnalyticsPanel({ isMobile, isActive, onSetHeaderActions }) {
    const [loading,    setLoading]    = useState(false);
    const [analytics,  setAnalytics]  = useState(null);
    const [year,       setYear]       = useState(new Date().getFullYear());
    const [deptId,     setDeptId]     = useState('');
    const [departments, setDepartments] = useState([]);

    const years = useMemo(() => {
        const cur = new Date().getFullYear();
        return Array.from({ length: 5 }, (_, i) => cur - i);
    }, []);

    const MONTH_LABELS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    const fetch = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get(route('leaves.analytics'), {
                params: { year, department_id: deptId || undefined },
            });
            if (data.success) setAnalytics(data.analytics);
            // pull departments from first load if present
            if (data.departments) setDepartments(data.departments);
        } catch {
            showToast.error('Failed to load analytics.');
        } finally {
            setLoading(false);
        }
    }, [year, deptId]);

    useEffect(() => { if (isActive) fetch(); }, [fetch, isActive]);

    /* ── header actions ── */
    useEffect(() => {
        if (!isActive) return;
        onSetHeaderActions?.(
            <IconButton size="2" variant="soft" color="gray" onClick={fetch} aria-label="Refresh">
                <ReloadIcon />
            </IconButton>
        );
    }, [isActive, fetch]);

    /* ── derived chart data ── */
    const monthlyData = useMemo(() => {
        if (!analytics?.monthly_trends) return [];
        return analytics.monthly_trends.map((m, i) => ({
            label: MONTH_LABELS[i] ?? `M${i + 1}`,
            value: m.total ?? m.count ?? 0,
            approved: m.approved ?? 0,
            pending:  m.pending  ?? 0,
            rejected: m.rejected ?? 0,
        }));
    }, [analytics]);

    const leaveTypeData = useMemo(() => {
        if (!analytics?.by_leave_type) return [];
        const total = analytics.by_leave_type.reduce((s, t) => s + (t.count ?? 0), 0) || 1;
        return analytics.by_leave_type.map(t => ({
            label:      t.type ?? t.name ?? '—',
            value:      t.count ?? 0,
            percentage: Math.round(((t.count ?? 0) / total) * 100),
        }));
    }, [analytics]);

    const departmentData = useMemo(() => {
        if (!analytics?.by_department) return [];
        const max = Math.max(...analytics.by_department.map(d => d.total ?? d.count ?? 0), 1);
        return analytics.by_department.map(d => ({
            label: d.department ?? d.name ?? '—',
            value: d.total ?? d.count ?? 0,
            max,
        }));
    }, [analytics]);

    const topStats = analytics?.summary ?? analytics?.stats ?? null;

    /* ── render ── */
    return (
        <Box>
            {/* ── Filter row ── */}
            <Flex gap="3" align="center" mb="5" wrap="wrap">
                <Select.Root size="2" value={String(year)} onValueChange={v => setYear(Number(v))}>
                    <Select.Trigger style={{ minWidth: 100 }} />
                    <Select.Content>
                        {years.map(y => <Select.Item key={y} value={String(y)}>{y}</Select.Item>)}
                    </Select.Content>
                </Select.Root>

                {departments.length > 0 && (
                    <Select.Root size="2" value={deptId || 'all'} onValueChange={v => setDeptId(v === 'all' ? '' : v)}>
                        <Select.Trigger style={{ minWidth: 180 }} placeholder="All Departments" />
                        <Select.Content>
                            <Select.Item value="all">All Departments</Select.Item>
                            {departments.map(d => (
                                <Select.Item key={d.id} value={String(d.id)}>{d.name}</Select.Item>
                            ))}
                        </Select.Content>
                    </Select.Root>
                )}
            </Flex>

            {loading ? (
                <Flex direction="column" align="center" py="9" gap="3">
                    <Spinner size="3" />
                    <Text color="gray" size="2">Loading analytics…</Text>
                </Flex>
            ) : !analytics ? (
                <Flex direction="column" align="center" py="9" gap="2">
                    <BarChartIcon style={{ width: 40, height: 40, color: 'var(--gray-9)' }} />
                    <Text size="3" weight="medium">No Analytics Data</Text>
                    <Text size="2" color="gray">No leave data available for {year}.</Text>
                </Flex>
            ) : (
                <Flex direction="column" gap="5">

                    {/* ── Summary stat cards ── */}
                    {topStats && (
                        <Grid columns={{ initial: '2', sm: '4' }} gap="3">
                            <StatCard label="Total Leaves" value={topStats.total}    color="blue"   icon={CalendarIcon}      />
                            <StatCard label="Approved"     value={topStats.approved} color="green"  icon={CheckCircledIcon}  />
                            <StatCard label="Pending"      value={topStats.pending}  color="amber"  icon={ClockIcon}         />
                            <StatCard label="Rejected"     value={topStats.rejected} color="red"    icon={CrossCircledIcon}  />
                        </Grid>
                    )}

                    {/* ── Monthly trend bar chart ── */}
                    {monthlyData.length > 0 && (
                        <Card variant="surface">
                            <Text size="3" weight="bold" as="div" mb="3">Monthly Leave Trends — {year}</Text>
                            <BarChart
                                data={monthlyData}
                                valueKey="value"
                                labelKey="label"
                                color="var(--accent-9)"
                                height={130}
                            />
                            {/* Approved vs Pending mini legend */}
                            <Flex gap="4" mt="3" wrap="wrap">
                                {[
                                    { label: 'Approved', color: 'green' },
                                    { label: 'Pending',  color: 'amber' },
                                    { label: 'Rejected', color: 'red'   },
                                ].map(({ label, color }) => (
                                    <Flex key={label} align="center" gap="2">
                                        <Box style={{
                                            width: 10, height: 10, borderRadius: '50%',
                                            background: `var(--${color}-9)`,
                                        }} />
                                        <Text size="1" color="gray">{label}</Text>
                                    </Flex>
                                ))}
                            </Flex>

                            {/* Approved / Pending / Rejected stacked per month detail */}
                            <Separator size="4" mt="3" mb="3" />
                            <Flex direction="column" gap="2">
                                {monthlyData.filter(m => m.value > 0).slice(0, 6).map((m, i) => (
                                    <Flex key={i} align="center" justify="between">
                                        <Text size="2" style={{ width: 36 }}>{m.label}</Text>
                                        <Flex gap="2" align="center" style={{ flex: 1 }}>
                                            {m.approved > 0 && <Badge size="1" color="green"  variant="soft">{m.approved} approved</Badge>}
                                            {m.pending  > 0 && <Badge size="1" color="amber"  variant="soft">{m.pending} pending</Badge>}
                                            {m.rejected > 0 && <Badge size="1" color="red"    variant="soft">{m.rejected} rejected</Badge>}
                                        </Flex>
                                        <Badge size="1" color="blue" variant="soft">{m.value} total</Badge>
                                    </Flex>
                                ))}
                            </Flex>
                        </Card>
                    )}

                    {/* ── Leave type breakdown + Department bars side by side ── */}
                    <Grid columns={{ initial: '1', lg: '2' }} gap="4">

                        {/* Leave type */}
                        {leaveTypeData.length > 0 && (
                            <Card variant="surface">
                                <Text size="3" weight="bold" as="div" mb="4">By Leave Type</Text>
                                <Flex direction="column" gap="3">
                                    {leaveTypeData.map((t, i) => (
                                        <ProgressRow
                                            key={i}
                                            label={t.label}
                                            value={t.value}
                                            max={leaveTypeData.reduce((s, x) => s + x.value, 0) || 1}
                                            color="var(--accent-9)"
                                            badge="blue"
                                        />
                                    ))}
                                </Flex>
                            </Card>
                        )}

                        {/* Department */}
                        {departmentData.length > 0 && (
                            <Card variant="surface">
                                <Text size="3" weight="bold" as="div" mb="4">By Department</Text>
                                <Flex direction="column" gap="3">
                                    {departmentData.map((d, i) => (
                                        <ProgressRow
                                            key={i}
                                            label={d.label}
                                            value={d.value}
                                            max={d.max}
                                            color="var(--violet-9)"
                                        />
                                    ))}
                                </Flex>
                            </Card>
                        )}
                    </Grid>

                    {/* ── Top leave takers table ── */}
                    {analytics?.top_leave_takers?.length > 0 && (
                        <Card variant="surface">
                            <Text size="3" weight="bold" as="div" mb="3">Top Leave Takers — {year}</Text>
                            <Box style={{ overflowX: 'auto' }}>
                                <table style={{ width: '100%', borderCollapse: 'collapse' }}>
                                    <thead>
                                        <tr style={{ borderBottom: '1px solid var(--gray-a4)' }}>
                                            {['#', 'Employee', 'Department', 'Total Days', 'Approved', 'Pending'].map(h => (
                                                <th key={h} style={{
                                                    padding: '6px 10px', textAlign: 'left',
                                                    fontSize: 11, fontWeight: 600,
                                                    color: 'var(--gray-10)', whiteSpace: 'nowrap',
                                                }}>{h}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {analytics.top_leave_takers.slice(0, 10).map((emp, i) => (
                                            <tr key={i} style={{ borderBottom: '1px solid var(--gray-a3)' }}>
                                                <td style={{ padding: '7px 10px' }}>
                                                    <Text size="1" color="gray">{i + 1}</Text>
                                                </td>
                                                <td style={{ padding: '7px 10px' }}>
                                                    <Text size="2" weight="medium">{emp.name ?? emp.employee_name ?? '—'}</Text>
                                                </td>
                                                <td style={{ padding: '7px 10px' }}>
                                                    <Text size="2" color="gray">{emp.department ?? '—'}</Text>
                                                </td>
                                                <td style={{ padding: '7px 10px', textAlign: 'center' }}>
                                                    <Badge size="1" color="blue" variant="solid">{emp.total_days ?? emp.total ?? 0}</Badge>
                                                </td>
                                                <td style={{ padding: '7px 10px', textAlign: 'center' }}>
                                                    <Badge size="1" color="green" variant="soft">{emp.approved_days ?? emp.approved ?? 0}</Badge>
                                                </td>
                                                <td style={{ padding: '7px 10px', textAlign: 'center' }}>
                                                    <Badge size="1" color="amber" variant="soft">{emp.pending_days ?? emp.pending ?? 0}</Badge>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </Box>
                        </Card>
                    )}

                </Flex>
            )}
        </Box>
    );
}
