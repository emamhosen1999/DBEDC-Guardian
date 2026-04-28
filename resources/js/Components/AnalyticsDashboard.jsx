import React, { useEffect, useState, useCallback } from 'react';
import { motion, AnimatePresence } from 'framer-motion';
import axios from 'axios';
import { showToast } from '@/utils/toastUtils';
import {
    ChartBarIcon,
    CheckCircleIcon,
    ClockIcon,
    ExclamationTriangleIcon,
    ArrowUpTrendingIcon,
    ArrowDownTrendingIcon,
    MinusIcon,
    ArrowPathIcon,
    CalendarIcon,
    UserIcon,
    MapPinIcon,
    DocumentTextIcon
} from "@heroicons/react/24/outline";
import { Card, CardHeader, CardBody, Button, Select, SelectItem, Spinner } from "@heroui/react";

const AnalyticsDashboard = ({ auth }) => {
    const [analyticsData, setAnalyticsData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [selectedTimeframe, setSelectedTimeframe] = useState('30');
    const [filters, setFilters] = useState({});

    // Fetch analytics data
    const fetchAnalytics = useCallback(async (timeframe = selectedTimeframe, filterParams = filters) => {
        setLoading(true);
        try {
            const params = {
                days: timeframe,
                ...filterParams
            };

            const response = await axios.get('/api/v1/analytics/daily-works/dashboard', { params });
            setAnalyticsData(response.data.data);
        } catch (error) {
            console.error('Error fetching analytics:', error);
            showToast.error('Failed to load analytics data');
        } finally {
            setLoading(false);
        }
    }, [selectedTimeframe, filters]);

    useEffect(() => {
        fetchAnalytics();
    }, [fetchAnalytics]);

    const handleTimeframeChange = (timeframe) => {
        setSelectedTimeframe(timeframe);
        fetchAnalytics(timeframe, filters);
    };

    const handleRefresh = () => {
        fetchAnalytics(selectedTimeframe, filters);
    };

    if (loading) {
        return (
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {Array.from({ length: 4 }).map((_, i) => (
                    <Card key={i} className="p-6">
                        <div className="animate-pulse">
                            <div className="h-4 bg-gray-200 rounded w-3/4 mb-2"></div>
                            <div className="h-8 bg-gray-200 rounded w-1/2"></div>
                        </div>
                    </Card>
                ))}
            </div>
        );
    }

    if (!analyticsData) {
        return (
            <Card className="p-8 text-center">
                <p className="text-gray-500">No analytics data available</p>
            </Card>
        );
    }

    const { summary, urgent_items, recent_activity, performance_indicators } = analyticsData;

    // Enhanced stats calculation
    const enhancedStats = [
        {
            title: 'Total Works',
            value: summary.total_work_items?.toLocaleString() || '0',
            icon: <ChartBarIcon className="w-5 h-5" />,
            color: 'text-blue-600',
            bgColor: 'bg-blue-50 dark:bg-blue-900/20',
            description: `Last ${selectedTimeframe} days`,
            trend: summary.total_work_items > 0 ? 'up' : 'neutral'
        },
        {
            title: 'Completion Rate',
            value: `${summary.completion_rate_percentage || 0}%`,
            icon: <CheckCircleIcon className="w-5 h-5" />,
            color: (summary.completion_rate_percentage || 0) >= 80 ? 'text-green-600' :
                   (summary.completion_rate_percentage || 0) >= 50 ? 'text-yellow-600' : 'text-red-600',
            bgColor: (summary.completion_rate_percentage || 0) >= 80 ? 'bg-green-50 dark:bg-green-900/20' :
                     (summary.completion_rate_percentage || 0) >= 50 ? 'bg-yellow-50 dark:bg-yellow-900/20' : 'bg-red-50 dark:bg-red-900/20',
            description: `${summary.completed_items || 0} of ${summary.total_work_items || 0} completed`,
            trend: (summary.completion_rate_percentage || 0) >= 80 ? 'up' :
                   (summary.completion_rate_percentage || 0) >= 50 ? 'neutral' : 'down'
        },
        {
            title: 'Pending Review',
            value: (urgent_items?.overdue_count || 0) + (urgent_items?.blocked_by_objections || 0),
            icon: <ClockIcon className="w-5 h-5" />,
            color: ((urgent_items?.overdue_count || 0) + (urgent_items?.blocked_by_objections || 0)) > 10 ? 'text-orange-600' : 'text-amber-600',
            bgColor: 'bg-orange-50 dark:bg-orange-900/20',
            description: `${urgent_items?.overdue_count || 0} overdue, ${urgent_items?.blocked_by_objections || 0} blocked`,
            trend: ((urgent_items?.overdue_count || 0) + (urgent_items?.blocked_by_objections || 0)) > 10 ? 'down' : 'neutral'
        },
        {
            title: 'Avg Completion Time',
            value: summary.average_completion_time_days ?
                `${summary.average_completion_time_days.toFixed(1)} hrs` : 'N/A',
            icon: <ArrowTrendingUpIcon className="w-5 h-5" />,
            color: 'text-purple-600',
            bgColor: 'bg-purple-50 dark:bg-purple-900/20',
            description: 'Average time to complete work',
            trend: summary.average_completion_time_days ?
                (summary.average_completion_time_days < 8 ? 'up' : 'neutral') : 'neutral'
        }
    ];

    const getTrendIcon = (trend) => {
        switch (trend) {
            case 'up': return <ArrowTrendingUpIcon className="w-4 h-4 text-green-500" />;
            case 'down': return <ArrowTrendingDownIcon className="w-4 h-4 text-red-500" />;
            default: return <MinusIcon className="w-4 h-4 text-gray-500" />;
        }
    };

    return (
        <div className="space-y-6">
            {/* Header */}
            <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                <div>
                    <h2 className="text-2xl font-bold text-foreground">Analytics Dashboard</h2>
                    <p className="text-default-500">Work progress insights and performance metrics</p>
                </div>

                <div className="flex items-center gap-3">
                    <Select
                        label="Timeframe"
                        placeholder="Select timeframe"
                        selectedKeys={[selectedTimeframe]}
                        onSelectionChange={(keys) => handleTimeframeChange(Array.from(keys)[0])}
                        size="sm"
                        className="w-40"
                    >
                        <SelectItem key="7" value="7">Last 7 days</SelectItem>
                        <SelectItem key="30" value="30">Last 30 days</SelectItem>
                        <SelectItem key="90" value="90">Last 90 days</SelectItem>
                    </Select>

                    <Button
                        isIconOnly
                        variant="flat"
                        onPress={handleRefresh}
                        isLoading={loading}
                        size="sm"
                    >
                        <ArrowPathIcon className="w-4 h-4" />
                    </Button>
                </div>
            </div>

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
                {enhancedStats.map((stat, index) => (
                    <motion.div
                        key={stat.title}
                        initial={{ opacity: 0, y: 20 }}
                        animate={{ opacity: 1, y: 0 }}
                        transition={{ duration: 0.3, delay: index * 0.1 }}
                    >
                        <Card className="p-6 hover:shadow-lg transition-shadow">
                            <CardBody className="p-0">
                                <div className="flex items-center justify-between">
                                    <div className="flex items-center gap-3">
                                        <div className={`p-2 rounded-lg ${stat.bgColor}`}>
                                            <div className={stat.color}>
                                                {stat.icon}
                                            </div>
                                        </div>
                                        <div>
                                            <p className="text-sm text-default-500">{stat.title}</p>
                                            <p className="text-2xl font-bold text-foreground">{stat.value}</p>
                                        </div>
                                    </div>
                                    <div className="flex items-center">
                                        {getTrendIcon(stat.trend)}
                                    </div>
                                </div>
                                <p className="text-xs text-default-400 mt-2">{stat.description}</p>
                            </CardBody>
                        </Card>
                    </motion.div>
                ))}
            </div>

            {/* Charts and Additional Insights */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                {/* Recent Activity */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <DocumentTextIcon className="w-5 h-5" />
                            <h3 className="text-lg font-semibold">Recent Activity</h3>
                        </div>
                    </CardHeader>
                    <CardBody>
                        <div className="space-y-3">
                            {recent_activity?.slice(0, 5).map((activity, index) => (
                                <div key={index} className="flex items-center gap-3 p-3 rounded-lg bg-content2/50">
                                    <div className="flex-1">
                                        <p className="text-sm font-medium">{activity.number}</p>
                                        <p className="text-xs text-default-500">{activity.location} • {activity.type}</p>
                                    </div>
                                    <div className="text-right">
                                        <span className={`text-xs px-2 py-1 rounded-full ${
                                            activity.status === 'completed' ? 'bg-green-100 text-green-700' :
                                            activity.status === 'in-progress' ? 'bg-blue-100 text-blue-700' :
                                            'bg-gray-100 text-gray-700'
                                        }`}>
                                            {activity.status}
                                        </span>
                                        <p className="text-xs text-default-400 mt-1">{activity.last_updated}</p>
                                    </div>
                                </div>
                            )) || (
                                <p className="text-default-500 text-sm">No recent activity</p>
                            )}
                        </div>
                    </CardBody>
                </Card>

                {/* Performance Indicators */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center gap-2">
                            <ChartBarIcon className="w-5 h-5" />
                            <h3 className="text-lg font-semibold">Performance Indicators</h3>
                        </div>
                    </CardHeader>
                    <CardBody>
                        <div className="space-y-4">
                            <div className="flex justify-between items-center">
                                <span className="text-sm">On-time Completion Rate</span>
                                <span className="font-semibold text-green-600">
                                    {performance_indicators?.on_time_completion_rate?.toFixed(1) || 0}%
                                </span>
                            </div>
                            <div className="flex justify-between items-center">
                                <span className="text-sm">Blockage Rate</span>
                                <span className="font-semibold text-orange-600">
                                    {performance_indicators?.blockage_rate?.toFixed(1) || 0}%
                                </span>
                            </div>
                            <div className="flex justify-between items-center">
                                <span className="text-sm">Efficiency Score</span>
                                <span className="font-semibold text-blue-600">
                                    {performance_indicators?.efficiency_score?.toFixed(1) || 0}/100
                                </span>
                            </div>
                        </div>
                    </CardBody>
                </Card>
            </div>

            {/* Urgent Items Alert */}
            {(urgent_items?.overdue_count > 0 || urgent_items?.blocked_by_objections > 0 || urgent_items?.escalated_count > 0) && (
                <motion.div
                    initial={{ opacity: 0, scale: 0.95 }}
                    animate={{ opacity: 1, scale: 1 }}
                    transition={{ duration: 0.3 }}
                >
                    <Card className="border-orange-200 bg-orange-50 dark:bg-orange-900/10">
                        <CardHeader>
                            <div className="flex items-center gap-2">
                                <ExclamationTriangleIcon className="w-5 h-5 text-orange-600" />
                                <h3 className="text-lg font-semibold text-orange-800">Attention Required</h3>
                            </div>
                        </CardHeader>
                        <CardBody>
                            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                {urgent_items?.overdue_count > 0 && (
                                    <div className="text-center">
                                        <p className="text-2xl font-bold text-orange-600">{urgent_items.overdue_count}</p>
                                        <p className="text-sm text-orange-700">Overdue Works</p>
                                    </div>
                                )}
                                {urgent_items?.blocked_by_objections > 0 && (
                                    <div className="text-center">
                                        <p className="text-2xl font-bold text-red-600">{urgent_items.blocked_by_objections}</p>
                                        <p className="text-sm text-red-700">Blocked by Objections</p>
                                    </div>
                                )}
                                {urgent_items?.escalated_count > 0 && (
                                    <div className="text-center">
                                        <p className="text-2xl font-bold text-red-800">{urgent_items.escalated_count}</p>
                                        <p className="text-sm text-red-800">Escalated Items</p>
                                    </div>
                                )}
                            </div>
                        </CardBody>
                    </Card>
                </motion.div>
            )}
        </div>
    );
};

export default AnalyticsDashboard;