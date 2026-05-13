import React, { useEffect, useMemo, useState, useRef, forwardRef, useImperativeHandle } from "react";
import { Card, Box, Flex, Text } from "@radix-ui/themes";
import {
    TriangleUpIcon,
    TriangleDownIcon,
    BarChartIcon,
    CheckCircledIcon,
    TimerIcon,
    FileTextIcon,
    PersonIcon,
    StarIcon,
    CalendarIcon,
    ReloadIcon,
} from "@radix-ui/react-icons";

import {
    AreaChart,
    Area,
    BarChart,
    Bar,
    PieChart,
    Pie,
    Cell,
    LineChart,
    Line,
    XAxis,
    YAxis,
    CartesianGrid,
    Tooltip as RTooltip,
    Legend,
    ResponsiveContainer,
} from "recharts";
import axios from "axios";
import { route } from "ziggy-js";
import dayjs from "dayjs";
import { showToast } from "@/utils/toastUtils";

const SkeletonBox = ({ height = 96 }) => (
    <div style={{ height, background: 'var(--gray-a4)', borderRadius: 'var(--radius-2)' }} />
);

const PALETTE = {
    primary: "#0070F0",
    success: "#17C964",
    warning: "#F5A524",
    danger: "#F31260",
    secondary: "#9333EA",
    embankment: "#F59E0B",
    structure: "#3B82F6",
    pavement: "#8B5CF6",
};

/**
 * Lightweight wrapper that lets the parent capture a chart container as PNG
 * for inclusion in the PDF export.
 */
const ChartCard = ({ id, title, subtitle, icon, children }) => (
    <Card data-chart-id={id}>
        <Box px="3" pt="3" pb="1">
            <Flex align="center" gap="2">
                {icon && <Box p="1" style={{ borderRadius: 'var(--radius-1)', background: 'var(--accent-a3)' }}>{icon}</Box>}
                <Box style={{ flex: 1, minWidth: 0 }}>
                    <Text size="2" weight="bold" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{title}</Text>
                    {subtitle && <Text size="1" color="gray" style={{ display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{subtitle}</Text>}
                </Box>
            </Flex>
        </Box>
        <Box px="3" pb="3" pt="2">{children}</Box>
    </Card>
);

const radixColorMap = { primary: 'indigo', success: 'green', warning: 'orange', danger: 'red', secondary: 'violet' };

const KpiCard = ({ label, value, sub, icon, color = 'primary', trend = null }) => {
    const rc = radixColorMap[color] || 'indigo';
    return (
        <Card>
            <Box p="3">
                <Flex align="start" justify="between" gap="2">
                    <Box style={{ flex: 1, minWidth: 0 }}>
                        <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em', display: 'block', overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{label}</Text>
                        <Text size="6" weight="bold" color={rc} as="p" mt="1">{value}</Text>
                        {sub && <Text size="1" color="gray" as="p" mt="1" style={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{sub}</Text>}
                        {trend !== null && trend !== 0 && (
                            <Flex align="center" gap="1" mt="1">
                                {trend > 0 ? <TriangleUpIcon style={{ color: 'var(--green-9)', width: 12, height: 12 }} /> : <TriangleDownIcon style={{ color: 'var(--red-9)', width: 12, height: 12 }} />}
                                <Text size="1" weight="bold" color={trend > 0 ? 'green' : 'red'}>{trend > 0 ? '+' : ''}{trend}%</Text>
                            </Flex>
                        )}
                    </Box>
                    <Box p="2" style={{ borderRadius: 'var(--radius-2)', background: `var(--${rc}-a3)`, flexShrink: 0 }}>
                        {React.cloneElement(icon, { style: { width: 18, height: 18, color: `var(--${rc}-9)` } })}
                    </Box>
                </Flex>
            </Box>
        </Card>
    );
};

const HighlightTile = ({ label, value, sub, color = 'primary' }) => {
    const rc = radixColorMap[color] || 'indigo';
    return (
        <Box p="3" style={{ background: `var(--${rc}-a3)`, border: `1px solid var(--${rc}-a6)`, borderRadius: 'var(--radius-2)' }}>
            <Text size="1" color="gray" style={{ textTransform: 'uppercase', letterSpacing: '0.05em', display: 'block' }}>{label}</Text>
            <Text size="3" weight="bold" color={rc} as="p" mt="1">{value}</Text>
            {sub && <Text size="1" color="gray" as="p">{sub}</Text>}
        </Box>
    );
};

const TooltipContent = ({ active, payload, label, formatter }) => {
    if (!active || !payload || !payload.length) return null;
    return (
        <div style={{ background: 'var(--color-panel-solid)', border: '1px solid var(--gray-a6)', borderRadius: 'var(--radius-2)', boxShadow: 'var(--shadow-3)', padding: '6px 10px', fontSize: 12 }}>
            {label && <div style={{ fontWeight: 600, marginBottom: 4 }}>{label}</div>}
            {payload.map((entry, idx) => (
                <div key={idx} style={{ display: 'flex', alignItems: 'center', gap: 6 }}>
                    <span style={{ width: 8, height: 8, borderRadius: '50%', background: entry.color, flexShrink: 0 }} />
                    <span style={{ color: 'var(--gray-11)' }}>{entry.name}: <strong>{formatter ? formatter(entry.value) : entry.value}</strong></span>
                </div>
            ))}
        </div>
    );
};

const DailyWorkSummaryAnalytics = forwardRef(function DailyWorkSummaryAnalytics(
    { filters, isVisible },
    ref,
) {
    const [analytics, setAnalytics] = useState(null);
    const [loading, setLoading] = useState(false);
    const containerRef = useRef(null);

    const fetchAnalytics = async () => {
        setLoading(true);
        try {
            const payload = {};
            if (filters?.startDate) payload.startDate = filters.startDate;
            if (filters?.endDate) payload.endDate = filters.endDate;
            if (filters?.status && filters.status !== "all") payload.status = filters.status;
            if (filters?.type && filters.type !== "all") payload.type = filters.type;
            if (filters?.search) payload.search = filters.search;
            if (filters?.incharge?.length) payload.incharge = filters.incharge;
            if (filters?.jurisdiction?.length) payload.jurisdiction = filters.jurisdiction;

            const response = await axios.post(
                route("daily-works-summary.analytics"),
                payload,
            );
            setAnalytics(response.data);
        } catch (err) {
            console.error("Failed to load analytics:", err);
            showToast.error(
                err.response?.data?.error || "Failed to load analytics data",
            );
        } finally {
            setLoading(false);
        }
    };

    // Refetch when filters change AND tab is visible
    useEffect(() => {
        if (isVisible) {
            fetchAnalytics();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [
        isVisible,
        filters?.startDate,
        filters?.endDate,
        filters?.status,
        filters?.type,
        filters?.search,
        JSON.stringify(filters?.incharge || []),
        JSON.stringify(filters?.jurisdiction || []),
    ]);

    /**
     * Capture all chart cards as base64 PNG images for PDF export.
     * Returns: { [chart_id]: dataUri }
     */
    useImperativeHandle(ref, () => ({
        captureCharts: async () => {
            if (!containerRef.current) return {};
            const html2canvas = (await import("html2canvas")).default;
            const cards = containerRef.current.querySelectorAll("[data-chart-id]");
            const result = {};
            for (const card of cards) {
                const id = card.getAttribute("data-chart-id");
                try {
                    const canvas = await html2canvas(card, {
                        backgroundColor: "#ffffff",
                        scale: 1.5,
                        logging: false,
                        useCORS: true,
                    });
                    result[id] = canvas.toDataURL("image/png");
                } catch (e) {
                    console.error(`Failed to capture chart ${id}:`, e);
                }
            }
            return result;
        },
        getAnalytics: () => analytics,
    }));

    const kpi = analytics?.kpi || {};
    const highlights = analytics?.highlights || {};
    const dailyTrend = analytics?.dailyTrend || [];
    const typeBreakdown = analytics?.typeBreakdown || [];
    const statusBreakdown = analytics?.statusBreakdown || [];
    const inchargePerformance = analytics?.inchargePerformance || [];

    // Format daily trend dates for display
    const formattedTrend = useMemo(
        () =>
            dailyTrend.map((d) => ({
                ...d,
                dateLabel: dayjs(d.date).format("MMM D"),
            })),
        [dailyTrend],
    );

    // Resubmissions vs new chart data
    const resubmissionsData = useMemo(
        () =>
            formattedTrend.map((d) => ({
                date: d.dateLabel,
                "New Works": Math.max(0, d.total - d.resubmissions),
                Resubmissions: d.resubmissions,
            })),
        [formattedTrend],
    );

    if (loading && !analytics) {
        return (
            <Flex direction="column" gap="4">
                <Flex gap="3">{Array.from({ length: 4 }).map((_, i) => <Box key={i} style={{ flex: 1 }}><SkeletonBox height={96} /></Box>)}</Flex>
                <SkeletonBox height={288} />
                <Flex gap="3">
                    <Box style={{ flex: 1 }}><SkeletonBox height={288} /></Box>
                    <Box style={{ flex: 1 }}><SkeletonBox height={288} /></Box>
                </Flex>
            </Flex>
        );
    }

    if (!analytics || kpi.totalWorks === 0) {
        return (
            <Card>
                <Flex direction="column" align="center" py="9" gap="2" style={{ textAlign: 'center' }}>
                    <BarChartIcon style={{ width: 48, height: 48, color: 'var(--gray-8)' }} />
                    <Text size="3" weight="medium" color="gray" as="p">No analytics data available</Text>
                    <Text size="2" color="gray" as="p">Adjust your filters or import daily works to see analytics</Text>
                </Flex>
            </Card>
        );
    }

    return (
        <Flex ref={containerRef} direction="column" gap="4">
            {/* KPI Cards Row */}
            <Flex gap="3" wrap="wrap">
                {[
                    { label: 'Total Works',    value: kpi.totalWorks?.toLocaleString() || 0,         sub: 'In selected period',                     icon: <FileTextIcon />,      color: 'primary' },
                    { label: 'Completed',      value: kpi.completed?.toLocaleString() || 0,           sub: `${kpi.completionRate || 0}% rate`,        icon: <CheckCircledIcon />,  color: 'success', trend: kpi.trendDirection },
                    { label: 'Pending',        value: kpi.pending?.toLocaleString() || 0,             sub: 'In progress / new',                      icon: <TimerIcon />,         color: 'warning' },
                    { label: 'RFI Submissions',value: kpi.rfiSubmissions?.toLocaleString() || 0,      sub: `${kpi.rfiRate || 0}% RFI rate`,           icon: <FileTextIcon />,      color: 'primary' },
                    { label: 'Avg Daily',      value: kpi.avgDailyWorks || 0,                        sub: 'Throughput per day',                     icon: <BarChartIcon />,      color: 'secondary' },
                    { label: 'Resubmissions',  value: kpi.totalResubmissions?.toLocaleString() || 0, sub: `${kpi.worksWithResubmissions || 0} works`, icon: <ReloadIcon />,        color: 'danger' },
                ].map((kp, i) => (
                    <Box key={i} style={{ flex: '1 1 140px', minWidth: 130 }}>
                        <KpiCard {...kp} />
                    </Box>
                ))}
            </Flex>

            {/* Highlights Row */}
            {(highlights.bestDay || highlights.busiestDay || highlights.topIncharge) && (
                <Card>
                    <Box px="3" pt="3" pb="1">
                        <Flex align="center" gap="2">
                            <StarIcon style={{ width: 14, height: 14, color: 'var(--orange-9)' }} />
                            <Text size="2" weight="bold">Highlights</Text>
                        </Flex>
                    </Box>
                    <Box px="3" pb="3" pt="2">
                        <Flex gap="3" wrap="wrap">
                            {highlights.bestDay && <Box style={{ flex: '1 1 160px' }}><HighlightTile label="Best Completion Day" value={dayjs(highlights.bestDay.date).format('MMM D, YYYY')} sub={`${highlights.bestDay.completionRate}% (${highlights.bestDay.completed}/${highlights.bestDay.total})`} color="success" /></Box>}
                            {highlights.busiestDay && <Box style={{ flex: '1 1 160px' }}><HighlightTile label="Busiest Day" value={dayjs(highlights.busiestDay.date).format('MMM D, YYYY')} sub={`${highlights.busiestDay.total} works logged`} color="primary" /></Box>}
                            {highlights.topIncharge && <Box style={{ flex: '1 1 160px' }}><HighlightTile label="Top Incharge" value={highlights.topIncharge.incharge} sub={`${highlights.topIncharge.completionRate}% completion`} color="warning" /></Box>}
                            {highlights.mostCommonType && <Box style={{ flex: '1 1 160px' }}><HighlightTile label="Most Common Type" value={highlights.mostCommonType.name} sub={`${highlights.mostCommonType.value} works`} color="primary" /></Box>}
                        </Flex>
                    </Box>
                </Card>
            )}

            {/* Chart 1: Daily Trend (full width) */}
            <ChartCard
                id="daily_trend"
                title="Daily Work Trend"
                subtitle="Total works vs completed vs pending over time"
                icon={<CalendarIcon style={{ width: 14, height: 14, color: 'var(--accent-9)' }} />}
            >
                <ResponsiveContainer width="100%" height={280}>
                    <AreaChart data={formattedTrend} margin={{ top: 5, right: 16, left: 0, bottom: 0 }}>
                        <defs>
                            <linearGradient id="colorTotal" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor={PALETTE.primary} stopOpacity={0.3} />
                                <stop offset="95%" stopColor={PALETTE.primary} stopOpacity={0} />
                            </linearGradient>
                            <linearGradient id="colorCompleted" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="5%" stopColor={PALETTE.success} stopOpacity={0.3} />
                                <stop offset="95%" stopColor={PALETTE.success} stopOpacity={0} />
                            </linearGradient>
                        </defs>
                        <CartesianGrid strokeDasharray="3 3" stroke="#E4E4E7" opacity={0.4} />
                        <XAxis dataKey="dateLabel" stroke="#71717A" fontSize={11} />
                        <YAxis stroke="#71717A" fontSize={11} />
                        <RTooltip content={<TooltipContent />} />
                        <Legend wrapperStyle={{ fontSize: "12px" }} />
                        <Area
                            type="monotone"
                            dataKey="total"
                            name="Total"
                            stroke={PALETTE.primary}
                            strokeWidth={2}
                            fill="url(#colorTotal)"
                        />
                        <Area
                            type="monotone"
                            dataKey="completed"
                            name="Completed"
                            stroke={PALETTE.success}
                            strokeWidth={2}
                            fill="url(#colorCompleted)"
                        />
                        <Area
                            type="monotone"
                            dataKey="pending"
                            name="Pending"
                            stroke={PALETTE.warning}
                            strokeWidth={2}
                            fill="transparent"
                        />
                    </AreaChart>
                </ResponsiveContainer>
            </ChartCard>

            {/* Chart 2 + 3: Type and Status side-by-side */}
            <Flex gap="3" wrap="wrap">
                <Box style={{ flex: '1 1 300px' }}>
                    <ChartCard id="work_type" title="Work Type Distribution" subtitle="Embankment / Structure / Pavement" icon={<BarChartIcon style={{ width: 14, height: 14, color: 'var(--violet-9)' }} />}>
                        {typeBreakdown.filter((t) => t.value > 0).length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <PieChart>
                                    <Pie data={typeBreakdown.filter((t) => t.value > 0)} cx="50%" cy="50%" innerRadius={50} outerRadius={90} paddingAngle={2} dataKey="value" label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`} labelLine={false} fontSize={11}>
                                        {typeBreakdown.filter((t) => t.value > 0).map((entry, index) => (<Cell key={`cell-${index}`} fill={entry.color} />))}
                                    </Pie>
                                    <RTooltip content={<TooltipContent />} />
                                </PieChart>
                            </ResponsiveContainer>
                        ) : (
                            <Flex align="center" justify="center" style={{ height: 260 }}><Text size="2" color="gray">No type data</Text></Flex>
                        )}
                    </ChartCard>
                </Box>
                <Box style={{ flex: '1 1 300px' }}>
                    <ChartCard id="status" title="Status Distribution" subtitle="Completed / Pending / In Progress / Other" icon={<CheckCircledIcon style={{ width: 14, height: 14, color: 'var(--green-9)' }} />}>
                        {statusBreakdown.length > 0 ? (
                            <ResponsiveContainer width="100%" height={260}>
                                <PieChart>
                                    <Pie data={statusBreakdown} cx="50%" cy="50%" innerRadius={50} outerRadius={90} paddingAngle={2} dataKey="value" label={({ name, percent }) => `${name} ${(percent * 100).toFixed(0)}%`} labelLine={false} fontSize={11}>
                                        {statusBreakdown.map((entry, index) => (<Cell key={`cell-${index}`} fill={entry.color} />))}
                                    </Pie>
                                    <RTooltip content={<TooltipContent />} />
                                </PieChart>
                            </ResponsiveContainer>
                        ) : (
                            <Flex align="center" justify="center" style={{ height: 260 }}><Text size="2" color="gray">No status data</Text></Flex>
                        )}
                    </ChartCard>
                </Box>
            </Flex>

            {/* Chart 4: Completion Rate Over Time */}
            <ChartCard
                id="completion_rate_trend"
                title="Completion Rate Trend"
                subtitle="Daily completion percentage over the period"
                icon={<TriangleUpIcon style={{ width: 14, height: 14, color: 'var(--green-9)' }} />}
            >
                <ResponsiveContainer width="100%" height={240}>
                    <LineChart data={formattedTrend} margin={{ top: 5, right: 16, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#E4E4E7" opacity={0.4} />
                        <XAxis dataKey="dateLabel" stroke="#71717A" fontSize={11} />
                        <YAxis stroke="#71717A" fontSize={11} domain={[0, 100]} unit="%" />
                        <RTooltip content={<TooltipContent formatter={(v) => `${v}%`} />} />
                        <Line
                            type="monotone"
                            dataKey="completionRate"
                            name="Completion %"
                            stroke={PALETTE.success}
                            strokeWidth={2.5}
                            dot={{ fill: PALETTE.success, r: 3 }}
                            activeDot={{ r: 5 }}
                        />
                    </LineChart>
                </ResponsiveContainer>
            </ChartCard>

            {/* Chart 5: RFI Submissions vs Completed */}
            <ChartCard
                id="rfi_trend"
                title="RFI Submissions vs Completed"
                subtitle="Daily RFI activity"
                icon={<FileTextIcon style={{ width: 14, height: 14, color: 'var(--accent-9)' }} />}
            >
                <ResponsiveContainer width="100%" height={240}>
                    <BarChart data={formattedTrend} margin={{ top: 5, right: 16, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#E4E4E7" opacity={0.4} />
                        <XAxis dataKey="dateLabel" stroke="#71717A" fontSize={11} />
                        <YAxis stroke="#71717A" fontSize={11} />
                        <RTooltip content={<TooltipContent />} />
                        <Legend wrapperStyle={{ fontSize: "12px" }} />
                        <Bar dataKey="completed" name="Completed" fill={PALETTE.success} radius={[4, 4, 0, 0]} />
                        <Bar dataKey="rfiSubmissions" name="RFI Submitted" fill={PALETTE.primary} radius={[4, 4, 0, 0]} />
                    </BarChart>
                </ResponsiveContainer>
            </ChartCard>

            {/* Chart 6: Per-Incharge Performance (horizontal bar) */}
            {inchargePerformance.length > 0 && (
                <ChartCard
                    id="incharge_performance"
                    title="Per-Incharge Performance"
                    subtitle="Total works and completion rate by incharge"
                    icon={<PersonIcon style={{ width: 14, height: 14, color: 'var(--orange-9)' }} />}
                >
                    <ResponsiveContainer
                        width="100%"
                        height={Math.max(240, inchargePerformance.length * 36 + 40)}
                    >
                        <BarChart
                            data={inchargePerformance}
                            layout="vertical"
                            margin={{ top: 5, right: 16, left: 0, bottom: 0 }}
                        >
                            <CartesianGrid strokeDasharray="3 3" stroke="#E4E4E7" opacity={0.4} />
                            <XAxis type="number" stroke="#71717A" fontSize={11} />
                            <YAxis
                                type="category"
                                dataKey="incharge"
                                stroke="#71717A"
                                fontSize={11}
                                width={140}
                            />
                            <RTooltip content={<TooltipContent />} />
                            <Legend wrapperStyle={{ fontSize: "12px" }} />
                            <Bar dataKey="completed" name="Completed" stackId="a" fill={PALETTE.success} />
                            <Bar dataKey="pending" name="Pending" stackId="a" fill={PALETTE.warning} />
                        </BarChart>
                    </ResponsiveContainer>
                </ChartCard>
            )}

            {/* Chart 7: Resubmissions vs New Works */}
            <ChartCard
                id="resubmissions"
                title="New Works vs Resubmissions"
                subtitle="Daily breakdown of new logs vs resubmitted works"
                icon={<ReloadIcon style={{ width: 14, height: 14, color: 'var(--red-9)' }} />}
            >
                <ResponsiveContainer width="100%" height={240}>
                    <BarChart data={resubmissionsData} margin={{ top: 5, right: 16, left: 0, bottom: 0 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke="#E4E4E7" opacity={0.4} />
                        <XAxis dataKey="date" stroke="#71717A" fontSize={11} />
                        <YAxis stroke="#71717A" fontSize={11} />
                        <RTooltip content={<TooltipContent />} />
                        <Legend wrapperStyle={{ fontSize: "12px" }} />
                        <Bar dataKey="New Works" stackId="a" fill={PALETTE.primary} />
                        <Bar dataKey="Resubmissions" stackId="a" fill={PALETTE.danger} />
                    </BarChart>
                </ResponsiveContainer>
            </ChartCard>
        </Flex>
    );
});

export default DailyWorkSummaryAnalytics;
