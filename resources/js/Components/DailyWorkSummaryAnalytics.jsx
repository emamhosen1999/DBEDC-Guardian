import React, { useEffect, useMemo, useState, useRef, forwardRef, useImperativeHandle } from "react";
import {
    Card,
    CardBody,
    CardHeader,
    Chip,
    Skeleton,
    Tooltip,
} from "@heroui/react";
import {
    ArrowTrendingUpIcon,
    ArrowTrendingDownIcon,
    ChartBarIcon,
    CheckCircleIcon,
    ClockIcon,
    DocumentTextIcon,
    UserIcon,
    SparklesIcon,
    CalendarDaysIcon,
    ArrowPathIcon,
} from "@heroicons/react/24/outline";
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
const ChartCard = ({ id, title, subtitle, icon, children, className = "" }) => (
    <Card
        className={`bg-content2/50 backdrop-blur-sm ${className}`}
        style={{ borderRadius: "var(--borderRadius, 12px)" }}
        data-chart-id={id}
    >
        <CardHeader className="pb-1">
            <div className="flex items-center gap-2 w-full">
                {icon && (
                    <div className="p-1.5 rounded-md bg-primary/10">{icon}</div>
                )}
                <div className="flex-1 min-w-0">
                    <h3 className="text-sm font-semibold text-foreground truncate">
                        {title}
                    </h3>
                    {subtitle && (
                        <p className="text-xs text-default-500 truncate">{subtitle}</p>
                    )}
                </div>
            </div>
        </CardHeader>
        <CardBody className="pt-2">{children}</CardBody>
    </Card>
);

const KpiCard = ({ label, value, sub, icon, color = "primary", trend = null }) => {
    const colorMap = {
        primary: "text-primary",
        success: "text-success",
        warning: "text-warning",
        danger: "text-danger",
        secondary: "text-secondary",
    };

    return (
        <Card
            className="bg-content2/40 hover:bg-content2/60 transition-colors"
            style={{ borderRadius: "var(--borderRadius, 12px)" }}
        >
            <CardBody className="p-3 sm:p-4">
                <div className="flex items-start justify-between gap-2">
                    <div className="min-w-0 flex-1">
                        <p className="text-[11px] uppercase tracking-wide text-default-500 font-medium truncate">
                            {label}
                        </p>
                        <p className={`text-2xl font-bold ${colorMap[color]} mt-1`}>
                            {value}
                        </p>
                        {sub && (
                            <p className="text-xs text-default-500 mt-1 truncate">{sub}</p>
                        )}
                        {trend !== null && trend !== 0 && (
                            <div className="flex items-center gap-1 mt-1">
                                {trend > 0 ? (
                                    <ArrowTrendingUpIcon className="w-3 h-3 text-success" />
                                ) : (
                                    <ArrowTrendingDownIcon className="w-3 h-3 text-danger" />
                                )}
                                <span
                                    className={`text-xs font-semibold ${
                                        trend > 0 ? "text-success" : "text-danger"
                                    }`}
                                >
                                    {trend > 0 ? "+" : ""}
                                    {trend}%
                                </span>
                            </div>
                        )}
                    </div>
                    <div className={`p-2 rounded-lg bg-${color}/10 flex-shrink-0`}>
                        {React.cloneElement(icon, {
                            className: `w-5 h-5 ${colorMap[color]}`,
                        })}
                    </div>
                </div>
            </CardBody>
        </Card>
    );
};

const HighlightTile = ({ label, value, sub, color = "primary" }) => {
    const colorMap = {
        primary: "from-primary/10 to-primary/5 text-primary",
        success: "from-success/10 to-success/5 text-success",
        warning: "from-warning/10 to-warning/5 text-warning",
        danger: "from-danger/10 to-danger/5 text-danger",
    };

    return (
        <div
            className={`bg-gradient-to-br ${colorMap[color]} p-3 rounded-lg border border-divider/30`}
            style={{ borderRadius: "var(--borderRadius, 8px)" }}
        >
            <p className="text-[11px] uppercase tracking-wide text-default-500 font-medium">
                {label}
            </p>
            <p className={`text-base font-bold mt-1 ${colorMap[color].split(" ").pop()}`}>
                {value}
            </p>
            {sub && <p className="text-xs text-default-500 mt-0.5">{sub}</p>}
        </div>
    );
};

const TooltipContent = ({ active, payload, label, formatter }) => {
    if (!active || !payload || !payload.length) return null;
    return (
        <div className="bg-content1 border border-divider rounded-lg shadow-lg p-2 text-xs">
            {label && <p className="font-semibold mb-1">{label}</p>}
            {payload.map((entry, idx) => (
                <div key={idx} className="flex items-center gap-1.5">
                    <span
                        className="w-2 h-2 rounded-full"
                        style={{ background: entry.color }}
                    />
                    <span className="text-default-600">
                        {entry.name}: <strong>{formatter ? formatter(entry.value) : entry.value}</strong>
                    </span>
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
            <div className="space-y-4">
                <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
                    {Array.from({ length: 4 }).map((_, i) => (
                        <Skeleton key={i} className="h-24 rounded-lg" />
                    ))}
                </div>
                <Skeleton className="h-72 rounded-lg" />
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                    <Skeleton className="h-72 rounded-lg" />
                    <Skeleton className="h-72 rounded-lg" />
                </div>
            </div>
        );
    }

    if (!analytics || kpi.totalWorks === 0) {
        return (
            <Card className="bg-content2/50" style={{ borderRadius: "var(--borderRadius, 12px)" }}>
                <CardBody className="p-12 text-center">
                    <ChartBarIcon className="w-12 h-12 text-default-300 mx-auto mb-3" />
                    <p className="text-base font-medium text-default-600">
                        No analytics data available
                    </p>
                    <p className="text-sm text-default-500 mt-1">
                        Adjust your filters or import daily works to see analytics
                    </p>
                </CardBody>
            </Card>
        );
    }

    return (
        <div ref={containerRef} className="space-y-4">
            {/* KPI Cards Row */}
            <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                <KpiCard
                    label="Total Works"
                    value={kpi.totalWorks?.toLocaleString() || 0}
                    sub="In selected period"
                    icon={<DocumentTextIcon />}
                    color="primary"
                />
                <KpiCard
                    label="Completed"
                    value={kpi.completed?.toLocaleString() || 0}
                    sub={`${kpi.completionRate || 0}% completion rate`}
                    icon={<CheckCircleIcon />}
                    color="success"
                    trend={kpi.trendDirection}
                />
                <KpiCard
                    label="Pending"
                    value={kpi.pending?.toLocaleString() || 0}
                    sub="In progress / new"
                    icon={<ClockIcon />}
                    color="warning"
                />
                <KpiCard
                    label="RFI Submissions"
                    value={kpi.rfiSubmissions?.toLocaleString() || 0}
                    sub={`${kpi.rfiRate || 0}% RFI rate`}
                    icon={<DocumentTextIcon />}
                    color="primary"
                />
                <KpiCard
                    label="Avg Daily Works"
                    value={kpi.avgDailyWorks || 0}
                    sub="Throughput per day"
                    icon={<ChartBarIcon />}
                    color="secondary"
                />
                <KpiCard
                    label="Resubmissions"
                    value={kpi.totalResubmissions?.toLocaleString() || 0}
                    sub={`${kpi.worksWithResubmissions || 0} works affected`}
                    icon={<ArrowPathIcon />}
                    color="danger"
                />
            </div>

            {/* Highlights Row */}
            {(highlights.bestDay || highlights.busiestDay || highlights.topIncharge) && (
                <Card
                    className="bg-content2/40"
                    style={{ borderRadius: "var(--borderRadius, 12px)" }}
                >
                    <CardHeader className="pb-1">
                        <div className="flex items-center gap-2">
                            <SparklesIcon className="w-4 h-4 text-warning" />
                            <h3 className="text-sm font-semibold">Highlights</h3>
                        </div>
                    </CardHeader>
                    <CardBody className="pt-2">
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            {highlights.bestDay && (
                                <HighlightTile
                                    label="Best Completion Day"
                                    value={dayjs(highlights.bestDay.date).format("MMM D, YYYY")}
                                    sub={`${highlights.bestDay.completionRate}% (${highlights.bestDay.completed}/${highlights.bestDay.total})`}
                                    color="success"
                                />
                            )}
                            {highlights.busiestDay && (
                                <HighlightTile
                                    label="Busiest Day"
                                    value={dayjs(highlights.busiestDay.date).format("MMM D, YYYY")}
                                    sub={`${highlights.busiestDay.total} works logged`}
                                    color="primary"
                                />
                            )}
                            {highlights.topIncharge && (
                                <HighlightTile
                                    label="Top Incharge"
                                    value={highlights.topIncharge.incharge}
                                    sub={`${highlights.topIncharge.completionRate}% completion`}
                                    color="warning"
                                />
                            )}
                            {highlights.mostCommonType && (
                                <HighlightTile
                                    label="Most Common Type"
                                    value={highlights.mostCommonType.name}
                                    sub={`${highlights.mostCommonType.value} works`}
                                    color="primary"
                                />
                            )}
                        </div>
                    </CardBody>
                </Card>
            )}

            {/* Chart 1: Daily Trend (full width) */}
            <ChartCard
                id="daily_trend"
                title="Daily Work Trend"
                subtitle="Total works vs completed vs pending over time"
                icon={<CalendarDaysIcon className="w-4 h-4 text-primary" />}
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
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                <ChartCard
                    id="work_type"
                    title="Work Type Distribution"
                    subtitle="Embankment / Structure / Pavement"
                    icon={<ChartBarIcon className="w-4 h-4 text-secondary" />}
                >
                    {typeBreakdown.filter((t) => t.value > 0).length > 0 ? (
                        <ResponsiveContainer width="100%" height={260}>
                            <PieChart>
                                <Pie
                                    data={typeBreakdown.filter((t) => t.value > 0)}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={50}
                                    outerRadius={90}
                                    paddingAngle={2}
                                    dataKey="value"
                                    label={({ name, percent }) =>
                                        `${name} ${(percent * 100).toFixed(0)}%`
                                    }
                                    labelLine={false}
                                    fontSize={11}
                                >
                                    {typeBreakdown
                                        .filter((t) => t.value > 0)
                                        .map((entry, index) => (
                                            <Cell key={`cell-${index}`} fill={entry.color} />
                                        ))}
                                </Pie>
                                <RTooltip content={<TooltipContent />} />
                            </PieChart>
                        </ResponsiveContainer>
                    ) : (
                        <div className="h-[260px] flex items-center justify-center text-default-400">
                            No type data
                        </div>
                    )}
                </ChartCard>

                <ChartCard
                    id="status"
                    title="Status Distribution"
                    subtitle="Completed / Pending / In Progress / Other"
                    icon={<CheckCircleIcon className="w-4 h-4 text-success" />}
                >
                    {statusBreakdown.length > 0 ? (
                        <ResponsiveContainer width="100%" height={260}>
                            <PieChart>
                                <Pie
                                    data={statusBreakdown}
                                    cx="50%"
                                    cy="50%"
                                    innerRadius={50}
                                    outerRadius={90}
                                    paddingAngle={2}
                                    dataKey="value"
                                    label={({ name, percent }) =>
                                        `${name} ${(percent * 100).toFixed(0)}%`
                                    }
                                    labelLine={false}
                                    fontSize={11}
                                >
                                    {statusBreakdown.map((entry, index) => (
                                        <Cell key={`cell-${index}`} fill={entry.color} />
                                    ))}
                                </Pie>
                                <RTooltip content={<TooltipContent />} />
                            </PieChart>
                        </ResponsiveContainer>
                    ) : (
                        <div className="h-[260px] flex items-center justify-center text-default-400">
                            No status data
                        </div>
                    )}
                </ChartCard>
            </div>

            {/* Chart 4: Completion Rate Over Time */}
            <ChartCard
                id="completion_rate_trend"
                title="Completion Rate Trend"
                subtitle="Daily completion percentage over the period"
                icon={<ArrowTrendingUpIcon className="w-4 h-4 text-success" />}
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
                icon={<DocumentTextIcon className="w-4 h-4 text-primary" />}
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
                    icon={<UserIcon className="w-4 h-4 text-warning" />}
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
                icon={<ArrowPathIcon className="w-4 h-4 text-danger" />}
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
        </div>
    );
});

export default DailyWorkSummaryAnalytics;
