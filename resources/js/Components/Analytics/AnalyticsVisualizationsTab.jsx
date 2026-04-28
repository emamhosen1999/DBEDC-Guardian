import React, { useEffect, useState, useCallback } from 'react';
import { motion } from 'framer-motion';
import axios from 'axios';
import { route } from 'ziggy-js';
import { showToast } from '@/utils/toastUtils';
import { BarChart, Bar, LineChart, Line, XAxis, YAxis, CartesianGrid, Tooltip, Legend, ResponsiveContainer, PieChart, Pie, Cell } from 'recharts';
import {
    Card,
    CardHeader,
    CardBody,
    Button,
    Select,
    SelectItem,
    Spinner
} from "@heroui/react";
import {
    ChartBarIcon,
    ArrowPathIcon,
    CalendarIcon,
    PresentationChartLineIcon
} from "@heroicons/react/24/outline";

// Helper function to get theme radius
const getThemeRadius = () => {
    if (typeof window === 'undefined') return 'lg';
    const rootStyles = getComputedStyle(document.documentElement);
    const borderRadius = rootStyles.getPropertyValue('--borderRadius')?.trim() || '12px';
    const radiusValue = parseInt(borderRadius);
    if (radiusValue === 0) return 'none';
    if (radiusValue <= 4) return 'sm';
    if (radiusValue <= 8) return 'md';
    if (radiusValue <= 16) return 'lg';
    return 'full';
};

// Get theme-aware card style
const getCardStyle = () => ({
    background: `linear-gradient(135deg, 
        var(--theme-content1, #FAFAFA) 20%, 
        var(--theme-content2, #F4F4F5) 10%, 
        var(--theme-content3, #F1F3F4) 20%)`,
    borderColor: `transparent`,
    borderWidth: `var(--borderWidth, 2px)`,
    borderRadius: `var(--borderRadius, 12px)`,
    fontFamily: `var(--fontFamily, "Inter")`,
    transform: `scale(var(--scale, 1))`,
    transition: 'all 0.2s ease-in-out',
    boxShadow: '0 2px 8px rgba(0, 0, 0, 0.1)',
});

const AnalyticsVisualizationsTab = ({ auth }) => {
    const [analyticsData, setAnalyticsData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [timeframe, setTimeframe] = useState('30');
    const [themeColors, setThemeColors] = useState({
        primary: '#006FEE',
        success: '#17C964',
        warning: '#F5A524',
        danger: '#F31260',
        secondary: '#9353d3',
        divider: '#E4E4E7',
        foreground400: '#A1A1AA'
    });

    useEffect(() => {
        if (typeof window !== 'undefined') {
            const rootStyles = getComputedStyle(document.documentElement);
            setThemeColors({
                primary: rootStyles.getPropertyValue('--theme-primary')?.trim() || '#006FEE',
                success: rootStyles.getPropertyValue('--theme-success')?.trim() || '#17C964',
                warning: rootStyles.getPropertyValue('--theme-warning')?.trim() || '#F5A524',
                danger: rootStyles.getPropertyValue('--theme-danger')?.trim() || '#F31260',
                secondary: rootStyles.getPropertyValue('--theme-secondary')?.trim() || '#9353d3',
                divider: rootStyles.getPropertyValue('--theme-divider')?.trim() || '#E4E4E7',
                foreground400: rootStyles.getPropertyValue('--theme-foreground-400')?.trim() || '#A1A1AA',
            });
        }
    }, []);

    const fetchAnalytics = useCallback(async (days = timeframe) => {
        setLoading(true);
        try {
            const response = await axios.get(route('daily-works-analytics.analytics'), {
                params: { days }
            });
            setAnalyticsData(response.data.data);
        } catch (error) {
            console.error('Error fetching analytics data:', error);
            showToast.error('Failed to load analytics data');
        } finally {
            setLoading(false);
        }
    }, [timeframe]);

    useEffect(() => {
        fetchAnalytics();
    }, [fetchAnalytics]);

    const handleTimeframeChange = (value) => {
        setTimeframe(value);
        fetchAnalytics(value);
    };

    const handleRefresh = () => {
        fetchAnalytics(timeframe);
    };

    if (loading) {
        return (
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Card 
                        key={i}
                        radius={getThemeRadius()}
                        style={getCardStyle()}
                    >
                        <CardBody>
                            <div className="animate-pulse">
                                <div className="h-4 bg-default-200 rounded w-3/4 mb-4"></div>
                                <div className="h-48 bg-default-200 rounded"></div>
                            </div>
                        </CardBody>
                    </Card>
                ))}
            </div>
        );
    }

    if (!analyticsData) {
        return (
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardBody className="text-center p-8">
                    <p style={{ color: 'var(--theme-foreground-400, #A1A1AA)', fontFamily: `var(--fontFamily, "Inter")` }}>
                        No analytics data available
                    </p>
                </CardBody>
            </Card>
        );
    }

    const { completion_rates, bottlenecks, trends } = analyticsData;

    // Prepare chart data
    const completionChartData = [
        { name: 'Total', value: completion_rates?.total_work_items || 0, fill: themeColors.primary },
        { name: 'Completed', value: completion_rates?.completed_items || 0, fill: themeColors.success },
        { name: 'In Progress', value: completion_rates?.in_progress_items || 0, fill: themeColors.warning },
    ];

    const statCardStyle = (color) => ({
        background: `color-mix(in srgb, ${color} 10%, transparent)`,
        borderRadius: `var(--borderRadius, 8px)`,
    });

    return (
        <div className="space-y-6">
            {/* Header with timeframe selector */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div className="flex items-center gap-2">
                    <CalendarIcon className="w-5 h-5" style={{ color: 'var(--theme-primary, #006FEE)' }} />
                    <span 
                        className="text-sm font-medium" 
                        style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                    >
                        Timeframe:
                    </span>
                </div>
                <div className="flex items-center gap-3">
                    <Select
                        size="sm"
                        variant="bordered"
                        radius={getThemeRadius()}
                        className="w-40"
                        selectedKeys={[timeframe]}
                        onSelectionChange={(keys) => handleTimeframeChange(Array.from(keys)[0])}
                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                    >
                        <SelectItem key="7" value="7">Last 7 Days</SelectItem>
                        <SelectItem key="30" value="30">Last 30 Days</SelectItem>
                        <SelectItem key="90" value="90">Last 90 Days</SelectItem>
                    </Select>
                    <Button
                        size="sm"
                        variant="bordered"
                        radius={getThemeRadius()}
                        isIconOnly
                        onPress={handleRefresh}
                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                    >
                        <ArrowPathIcon className="w-4 h-4" />
                    </Button>
                </div>
            </div>

            {/* Completion Rates Chart */}
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3 }}
            >
                <Card 
                    radius={getThemeRadius()}
                    style={getCardStyle()}
                >
                    <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                        <h3 
                            className="text-lg font-bold flex items-center gap-2" 
                            style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                        >
                            <ChartBarIcon className="w-5 h-5" style={{ color: 'var(--theme-primary, #006FEE)' }} />
                            Completion Rate Analysis
                        </h3>
                    </CardHeader>
                    <CardBody className="pt-0">
                        <div className="space-y-4">
                            <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                                <div className="p-4" style={statCardStyle(themeColors.primary)}>
                                    <p className="text-sm" style={{ color: themeColors.primary, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        Total Works
                                    </p>
                                    <p className="text-2xl font-bold" style={{ color: themeColors.primary, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {completion_rates?.total_work_items?.toLocaleString() || '0'}
                                    </p>
                                </div>
                                <div className="p-4" style={statCardStyle(themeColors.success)}>
                                    <p className="text-sm" style={{ color: themeColors.success, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        Completed
                                    </p>
                                    <p className="text-2xl font-bold" style={{ color: themeColors.success, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {completion_rates?.completed_items?.toLocaleString() || '0'}
                                    </p>
                                </div>
                                <div className="p-4" style={statCardStyle(themeColors.warning)}>
                                    <p className="text-sm" style={{ color: themeColors.warning, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        In Progress
                                    </p>
                                    <p className="text-2xl font-bold" style={{ color: themeColors.warning, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {completion_rates?.in_progress_items?.toLocaleString() || '0'}
                                    </p>
                                </div>
                                <div className="p-4" style={statCardStyle(themeColors.secondary)}>
                                    <p className="text-sm" style={{ color: themeColors.secondary, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        Rate
                                    </p>
                                    <p className="text-2xl font-bold" style={{ color: themeColors.secondary, fontFamily: `var(--fontFamily, "Inter")` }}>
                                        {completion_rates?.completion_rate_percentage || '0'}%
                                    </p>
                                </div>
                            </div>
                            <div className="w-full h-64">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={completionChartData}>
                                        <CartesianGrid strokeDasharray="3 3" stroke={themeColors.divider} />
                                        <XAxis dataKey="name" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                        <YAxis tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                        <Tooltip 
                                            contentStyle={{
                                                backgroundColor: 'var(--theme-background, #FFFFFF)',
                                                borderColor: themeColors.divider,
                                                borderRadius: '8px',
                                                fontFamily: `var(--fontFamily, "Inter")`
                                            }}
                                        />
                                        <Bar dataKey="value" radius={[4, 4, 0, 0]}>
                                            {completionChartData.map((entry, index) => (
                                                <Cell key={`cell-${index}`} fill={entry.fill} />
                                            ))}
                                        </Bar>
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            </motion.div>

            {/* Bottleneck Analysis */}
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, delay: 0.1 }}
            >
                <Card 
                    radius={getThemeRadius()}
                    style={getCardStyle()}
                >
                    <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                        <h3 
                            className="text-lg font-bold" 
                            style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                        >
                            Bottleneck Analysis
                        </h3>
                    </CardHeader>
                    <CardBody className="pt-0">
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {bottlenecks?.by_status && bottlenecks.by_status.length > 0 && (
                                <div>
                                    <p 
                                        className="text-sm font-medium mb-3" 
                                        style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        By Status
                                    </p>
                                    <div className="w-full h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={bottlenecks.by_status} layout="vertical">
                                                <CartesianGrid strokeDasharray="3 3" stroke={themeColors.divider} />
                                                <XAxis type="number" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <YAxis dataKey="status" type="category" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <Tooltip 
                                                    contentStyle={{
                                                        backgroundColor: 'var(--theme-background, #FFFFFF)',
                                                        borderColor: themeColors.divider,
                                                        borderRadius: '8px',
                                                        fontFamily: `var(--fontFamily, "Inter")`
                                                    }}
                                                />
                                                <Bar dataKey="count" fill={themeColors.primary} radius={[0, 4, 4, 0]} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            )}
                            {bottlenecks?.by_type && bottlenecks.by_type.length > 0 && (
                                <div>
                                    <p 
                                        className="text-sm font-medium mb-3" 
                                        style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        By Type
                                    </p>
                                    <div className="w-full h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={bottlenecks.by_type.slice(0, 5)} layout="vertical">
                                                <CartesianGrid strokeDasharray="3 3" stroke={themeColors.divider} />
                                                <XAxis type="number" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <YAxis dataKey="type" type="category" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <Tooltip 
                                                    contentStyle={{
                                                        backgroundColor: 'var(--theme-background, #FFFFFF)',
                                                        borderColor: themeColors.divider,
                                                        borderRadius: '8px',
                                                        fontFamily: `var(--fontFamily, "Inter")`
                                                    }}
                                                />
                                                <Bar dataKey="count" fill={themeColors.secondary} radius={[0, 4, 4, 0]} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            )}
                        </div>
                    </CardBody>
                </Card>
            </motion.div>

            {/* Trends */}
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.3, delay: 0.2 }}
            >
                <Card 
                    radius={getThemeRadius()}
                    style={getCardStyle()}
                >
                    <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                        <h3 
                            className="text-lg font-bold flex items-center gap-2" 
                            style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                        >
                            <PresentationChartLineIcon className="w-5 h-5" style={{ color: 'var(--theme-primary, #006FEE)' }} />
                            Trend Analysis
                        </h3>
                    </CardHeader>
                    <CardBody className="pt-0">
                        <div className="space-y-6">
                            {trends?.completion_trend && trends.completion_trend.length > 0 && (
                                <div>
                                    <p 
                                        className="text-sm font-medium mb-3" 
                                        style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        Completion Trend
                                    </p>
                                    <div className="w-full h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <LineChart data={trends.completion_trend}>
                                                <CartesianGrid strokeDasharray="3 3" stroke={themeColors.divider} />
                                                <XAxis dataKey="date" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <YAxis tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <Tooltip 
                                                    contentStyle={{
                                                        backgroundColor: 'var(--theme-background, #FFFFFF)',
                                                        borderColor: themeColors.divider,
                                                        borderRadius: '8px',
                                                        fontFamily: `var(--fontFamily, "Inter")`
                                                    }}
                                                />
                                                <Legend />
                                                <Line type="monotone" dataKey="count" stroke={themeColors.success} strokeWidth={2} dot={{ r: 4 }} />
                                            </LineChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            )}
                            {trends?.volume_trend && trends.volume_trend.length > 0 && (
                                <div>
                                    <p 
                                        className="text-sm font-medium mb-3" 
                                        style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}
                                    >
                                        Volume Trend
                                    </p>
                                    <div className="w-full h-64">
                                        <ResponsiveContainer width="100%" height="100%">
                                            <BarChart data={trends.volume_trend}>
                                                <CartesianGrid strokeDasharray="3 3" stroke={themeColors.divider} />
                                                <XAxis dataKey="date" tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <YAxis tick={{ fill: themeColors.foreground400, fontSize: 12 }} />
                                                <Tooltip 
                                                    contentStyle={{
                                                        backgroundColor: 'var(--theme-background, #FFFFFF)',
                                                        borderColor: themeColors.divider,
                                                        borderRadius: '8px',
                                                        fontFamily: `var(--fontFamily, "Inter")`
                                                    }}
                                                />
                                                <Legend />
                                                <Bar dataKey="count" fill={themeColors.primary} radius={[4, 4, 0, 0]} />
                                            </BarChart>
                                        </ResponsiveContainer>
                                    </div>
                                </div>
                            )}
                            {(!trends?.completion_trend?.length && !trends?.volume_trend?.length) && (
                                <p 
                                    className="text-center text-sm" 
                                    style={{ color: 'var(--theme-foreground-400, #A1A1AA)', fontFamily: `var(--fontFamily, "Inter")` }}
                                >
                                    No trend data available for the selected period
                                </p>
                            )}
                        </div>
                    </CardBody>
                </Card>
            </motion.div>
        </div>
    );
};

export default AnalyticsVisualizationsTab;
