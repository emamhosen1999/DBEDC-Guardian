import React, { useEffect, useState, useCallback } from 'react';
import { motion } from 'framer-motion';
import axios from 'axios';
import { route } from 'ziggy-js';
import { showToast } from '@/utils/toastUtils';
import {
    Card,
    CardHeader,
    CardBody,
    Button,
    Select,
    SelectItem,
    Spinner,
    Chip
} from "@heroui/react";
import {
    ChartBarIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    ArrowPathIcon,
    CalendarIcon
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

const AnalyticsOverviewTab = ({ auth }) => {
    const [dashboardData, setDashboardData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [timeframe, setTimeframe] = useState('30');

    const fetchDashboard = useCallback(async (days = timeframe) => {
        setLoading(true);
        try {
            const response = await axios.get(route('daily-works-analytics.dashboard'), {
                params: { days }
            });
            setDashboardData(response.data.data);
        } catch (error) {
            console.error('Error fetching dashboard data:', error);
            showToast.error('Failed to load dashboard data');
        } finally {
            setLoading(false);
        }
    }, [timeframe]);

    useEffect(() => {
        fetchDashboard();
    }, [fetchDashboard]);

    const handleTimeframeChange = (value) => {
        setTimeframe(value);
        fetchDashboard(value);
    };

    const handleRefresh = () => {
        fetchDashboard(timeframe);
    };

    if (loading) {
        return (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Card 
                        key={i}
                        radius={getThemeRadius()}
                        style={getCardStyle()}
                    >
                        <CardBody>
                            <div className="animate-pulse">
                                <div className="h-4 bg-default-200 rounded w-3/4 mb-2"></div>
                                <div className="h-8 bg-default-200 rounded w-1/2"></div>
                            </div>
                        </CardBody>
                    </Card>
                ))}
            </div>
        );
    }

    if (!dashboardData) {
        return (
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardBody className="text-center p-8">
                    <p style={{ color: 'var(--theme-foreground-400, #A1A1AA)', fontFamily: `var(--fontFamily, "Inter")` }}>
                        No dashboard data available
                    </p>
                </CardBody>
            </Card>
        );
    }

    const { summary, urgent_items, recent_activity } = dashboardData;

    const statsCards = [
        {
            title: 'Total Works',
            value: summary.total_work_items?.toLocaleString() || '0',
            icon: <ChartBarIcon className="w-5 h-5" />,
            color: 'var(--theme-primary, #006FEE)',
            description: `Last ${timeframe} days`
        },
        {
            title: 'Completed',
            value: summary.completed_items?.toLocaleString() || '0',
            icon: <CheckCircleIcon className="w-5 h-5" />,
            color: 'var(--theme-success, #17C964)',
            description: `${summary.completion_rate_percentage || 0}% completion rate`
        },
        {
            title: 'In Progress',
            value: summary.in_progress_items?.toLocaleString() || '0',
            icon: <ClockIcon className="w-5 h-5" />,
            color: 'var(--theme-warning, #F5A524)',
            description: 'Currently active'
        },
        {
            title: 'Overdue',
            value: summary.overdue_items?.toLocaleString() || '0',
            icon: <ExclamationTriangleIcon className="w-5 h-5" />,
            color: 'var(--theme-danger, #F31260)',
            description: 'Requires attention'
        }
    ];

    return (
        <div className="space-y-6">
            {/* Header with timeframe selector */}
            <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
                <div className="flex items-center gap-2">
                    <CalendarIcon className="w-5 h-5" style={{ color: 'var(--theme-primary, #006FEE)' }} />
                    <span className="text-sm font-medium" style={{ color: 'var(--theme-foreground, #11181C)', fontFamily: `var(--fontFamily, "Inter")` }}>
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

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {statsCards.map((stat, index) => (
                    <motion.div
                        key={stat.title}
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3, delay: index * 0.1 }}
                    >
                        <Card 
                            className="cursor-pointer"
                            radius={getThemeRadius()}
                            style={getCardStyle()}
                            onMouseEnter={(e) => {
                                e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, ${stat.color} 50%, transparent)`;
                                e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                                e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
                            }}
                            onMouseLeave={(e) => {
                                e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                                e.currentTarget.style.transform = `scale(var(--scale, 1))`;
                            }}
                        >
                            <CardBody className="gap-3">
                                <div className="flex items-start justify-between">
                                    <div 
                                        className="p-2 rounded-lg"
                                        style={{ 
                                            background: `color-mix(in srgb, ${stat.color} 15%, transparent)`,
                                            borderRadius: `var(--borderRadius, 8px)`,
                                        }}
                                    >
                                        <div style={{ color: stat.color }}>{stat.icon}</div>
                                    </div>
                                </div>
                                <div>
                                    <p 
                                        className="text-sm" 
                                        style={{ 
                                            color: 'var(--theme-foreground-400, #A1A1AA)',
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                        }}
                                    >
                                        {stat.title}
                                    </p>
                                    <p 
                                        className="text-2xl font-bold" 
                                        style={{ 
                                            color: 'var(--theme-foreground, #11181C)',
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                        }}
                                    >
                                        {stat.value}
                                    </p>
                                    <p 
                                        className="text-xs" 
                                        style={{ 
                                            color: 'var(--theme-foreground-400, #A1A1AA)',
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                        }}
                                    >
                                        {stat.description}
                                    </p>
                                </div>
                            </CardBody>
                        </Card>
                    </motion.div>
                ))}
            </div>

            {/* Urgent Items */}
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <h3 
                        className="text-lg font-bold" 
                        style={{ 
                            color: 'var(--theme-foreground, #11181C)',
                            fontFamily: `var(--fontFamily, "Inter")`,
                        }}
                    >
                        Urgent Items
                    </h3>
                </CardHeader>
                <CardBody className="pt-0">
                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div 
                            className="p-4 rounded-lg"
                            style={{ 
                                background: `color-mix(in srgb, var(--theme-danger, #F31260) 10%, transparent)`,
                                borderRadius: `var(--borderRadius, 8px)`,
                            }}
                        >
                            <p 
                                className="text-sm font-medium" 
                                style={{ 
                                    color: 'var(--theme-danger, #F31260)',
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                Overdue Works
                            </p>
                            <p 
                                className="text-2xl font-bold" 
                                style={{ 
                                    color: 'var(--theme-danger, #F31260)',
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                {urgent_items?.overdue_count || 0}
                            </p>
                        </div>
                        <div 
                            className="p-4 rounded-lg"
                            style={{ 
                                background: `color-mix(in srgb, var(--theme-warning, #F5A524) 10%, transparent)`,
                                borderRadius: `var(--borderRadius, 8px)`,
                            }}
                        >
                            <p 
                                className="text-sm font-medium" 
                                style={{ 
                                    color: 'var(--theme-warning, #F5A524)',
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                Blocked by Objections
                            </p>
                            <p 
                                className="text-2xl font-bold" 
                                style={{ 
                                    color: 'var(--theme-warning, #F5A524)',
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                {urgent_items?.blocked_by_objections || 0}
                            </p>
                        </div>
                        <div 
                            className="p-4 rounded-lg"
                            style={{ 
                                background: `color-mix(in srgb, var(--theme-secondary, #9353d3) 10%, transparent)`,
                                borderRadius: `var(--borderRadius, 8px)`,
                            }}
                        >
                            <p 
                                className="text-sm font-medium" 
                                style={{ 
                                    color: 'var(--theme-secondary, #9353d3)',
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                Escalated
                            </p>
                            <p 
                                className="text-2xl font-bold" 
                                style={{ 
                                    color: 'var(--theme-secondary, #9353d3)',
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                {urgent_items?.escalated_count || 0}
                            </p>
                        </div>
                    </div>
                </CardBody>
            </Card>

            {/* Recent Activity */}
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <h3 
                        className="text-lg font-bold" 
                        style={{ 
                            color: 'var(--theme-foreground, #11181C)',
                            fontFamily: `var(--fontFamily, "Inter")`,
                        }}
                    >
                        Recent Activity
                    </h3>
                </CardHeader>
                <CardBody className="pt-0">
                    {recent_activity && recent_activity.length > 0 ? (
                        <div className="space-y-3">
                            {recent_activity.map((activity, index) => (
                                <motion.div
                                    key={activity.id}
                                    initial={{ opacity: 0, x: -20 }}
                                    animate={{ opacity: 1, x: 0 }}
                                    transition={{ duration: 0.3, delay: index * 0.05 }}
                                    className="flex items-center justify-between p-3 rounded-lg border"
                                    style={{ 
                                        backgroundColor: `var(--theme-background, #FFFFFF)`,
                                        borderColor: `var(--theme-divider, #E4E4E7)`,
                                        borderRadius: `var(--borderRadius, 8px)`,
                                    }}
                                >
                                    <div className="flex items-center gap-3">
                                        <div 
                                            className="w-2 h-2 rounded-full"
                                            style={{ 
                                                backgroundColor: activity.status === 'completed' ? 'var(--theme-success, #17C964)' : 
                                                                 activity.status === 'in_progress' ? 'var(--theme-warning, #F5A524)' : 
                                                                 'var(--theme-danger, #F31260)'
                                            }}
                                        />
                                        <div>
                                            <p 
                                                className="text-sm font-medium" 
                                                style={{ 
                                                    color: 'var(--theme-foreground, #11181C)',
                                                    fontFamily: `var(--fontFamily, "Inter")`,
                                                }}
                                            >
                                                {activity.number || activity.type}
                                            </p>
                                            <p 
                                                className="text-xs" 
                                                style={{ 
                                                    color: 'var(--theme-foreground-400, #A1A1AA)',
                                                    fontFamily: `var(--fontFamily, "Inter")`,
                                                }}
                                            >
                                                {activity.location || activity.incharge}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="text-right">
                                        <Chip
                                            size="sm"
                                            variant="flat"
                                            color={activity.status === 'completed' ? 'success' : 
                                                   activity.status === 'in_progress' ? 'warning' : 'danger'}
                                            style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                        >
                                            {activity.status}
                                        </Chip>
                                        <p 
                                            className="text-xs mt-1" 
                                            style={{ 
                                                color: 'var(--theme-foreground-400, #A1A1AA)',
                                                fontFamily: `var(--fontFamily, "Inter")`,
                                            }}
                                        >
                                            {activity.last_updated}
                                        </p>
                                    </div>
                                </motion.div>
                            ))}
                        </div>
                    ) : (
                        <p 
                            className="text-center text-sm" 
                            style={{ 
                                color: 'var(--theme-foreground-400, #A1A1AA)',
                                fontFamily: `var(--fontFamily, "Inter")`,
                            }}
                        >
                            No recent activity
                        </p>
                    )}
                </CardBody>
            </Card>
        </div>
    );
};

export default AnalyticsOverviewTab;
