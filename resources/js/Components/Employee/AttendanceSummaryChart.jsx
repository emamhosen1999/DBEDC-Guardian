import React, { useEffect, useState } from 'react';
import { motion } from 'framer-motion';
import { Card, CardHeader, CardBody, Skeleton } from '@heroui/react';
import { 
    ChartBarIcon
} from '@heroicons/react/24/outline';
import { BarChart, Bar, XAxis, YAxis, CartesianGrid, Tooltip, ResponsiveContainer, Legend } from 'recharts';
import axios from 'axios';
import { route } from 'ziggy-js';

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

const AttendanceSummaryChart = () => {
    const [chartData, setChartData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [themeColors, setThemeColors] = useState({
        success: '#17C964',
        danger: '#F31260',
        divider: '#E4E4E7',
        foreground: '#11181C',
        foreground400: '#A1A1AA'
    });

    useEffect(() => {
        // Get theme colors from CSS variables
        if (typeof window !== 'undefined') {
            const rootStyles = getComputedStyle(document.documentElement);
            setThemeColors({
                success: rootStyles.getPropertyValue('--theme-success')?.trim() || '#17C964',
                danger: rootStyles.getPropertyValue('--theme-danger')?.trim() || '#F31260',
                divider: rootStyles.getPropertyValue('--theme-divider')?.trim() || '#E4E4E7',
                foreground: rootStyles.getPropertyValue('--theme-foreground')?.trim() || '#11181C',
                foreground400: rootStyles.getPropertyValue('--theme-foreground-400')?.trim() || '#A1A1AA'
            });
        }
    }, []);

    useEffect(() => {
        let isMounted = true;
        const controller = new AbortController();
        
        const fetchChartData = async () => {
            try {
                setLoading(true);
                setError(null);
                
                // Try admin dashboard trends endpoint first, fall back to employee attendance
                let response;
                try {
                    response = await axios.get(route('admin.dashboard.attendance-trends'), {
                        signal: controller.signal,
                        timeout: 10000
                    });
                } catch (adminErr) {
                    // If admin endpoint fails, try employee attendance endpoint
                    const today = new Date();
                    response = await axios.get(route('getCurrentUserAttendanceForDate'), {
                        signal: controller.signal,
                        params: {
                            currentMonth: today.getMonth() + 1,
                            currentYear: today.getFullYear(),
                            perPage: 7,
                            page: 1
                        }
                    });
                    
                    // Transform employee attendance data to match admin format
                    if (response.data?.attendances) {
                        const transformed = response.data.attendances.map(att => ({
                            date: att.date,
                            present: att.status === '√' || att.status === 'Present' ? 1 : 0,
                            absent: att.status === '▼' || att.status === 'Absent' ? 1 : 0
                        }));
                        response.data = { trends: transformed };
                    }
                }
                
                if (isMounted && response.data?.trends) {
                    setChartData(response.data.trends);
                }
            } catch (err) {
                if (isMounted && !controller.signal.aborted) {
                    console.error('Failed to fetch attendance chart data:', err);
                    setError(err.message);
                    setChartData([]);
                }
            } finally {
                if (isMounted) {
                    setLoading(false);
                }
            }
        };

        fetchChartData();

        return () => {
            isMounted = false;
            controller.abort();
        };
    }, []);

    const formatDate = (dateString) => {
        const date = new Date(dateString);
        return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
    };

    if (loading) {
        return (
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.5, delay: 0.3 }}
            >
                <Card 
                    className="h-full"
                    radius={getThemeRadius()}
                    style={getCardStyle()}
                >
                    <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                        <div className="flex items-center gap-3">
                            <div 
                                className="p-2 rounded-lg"
                                style={{ background: `color-mix(in srgb, var(--theme-primary, #006FEE) 10%, transparent)` }}
                            >
                                <ChartBarIcon className="w-5 h-5" style={{ color: `var(--theme-primary, #006FEE)` }} />
                            </div>
                            <div>
                                <h3 className="text-lg font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Attendance Overview
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Weekly attendance summary
                                </p>
                            </div>
                        </div>
                    </CardHeader>
                    <CardBody className="pt-0">
                        <Skeleton className="rounded-lg w-full h-48" isLoaded={false} />
                    </CardBody>
                </Card>
            </motion.div>
        );
    }

    if (error) {
        return (
            <motion.div
                initial={{ opacity: 0, y: 20 }}
                animate={{ opacity: 1, y: 0 }}
                transition={{ duration: 0.5, delay: 0.3 }}
            >
                <Card 
                    className="h-full"
                    radius={getThemeRadius()}
                    style={{
                        ...getCardStyle(),
                        borderColor: `color-mix(in srgb, var(--theme-danger) 50%, transparent)`,
                        background: `linear-gradient(135deg, 
                            color-mix(in srgb, var(--theme-danger) 5%, var(--theme-content1)) 20%, 
                            color-mix(in srgb, var(--theme-danger) 3%, var(--theme-content2)) 10%, 
                            color-mix(in srgb, var(--theme-danger) 2%, var(--theme-content3)) 20%)`,
                    }}
                >
                    <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                        <div className="flex items-center gap-3">
                            <div 
                                className="p-2 rounded-lg"
                                style={{ background: `color-mix(in srgb, var(--theme-danger, #EF4444) 10%, transparent)` }}
                            >
                                <ChartBarIcon className="w-5 h-5" style={{ color: `var(--theme-danger, #EF4444)` }} />
                            </div>
                            <div>
                                <h3 className="text-lg font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Attendance Overview
                                </h3>
                            </div>
                        </div>
                    </CardHeader>
                    <CardBody className="pt-0">
                        <p style={{ color: 'var(--theme-danger)', fontFamily: `var(--fontFamily, "Inter")` }}>
                            Failed to load attendance data: {error}
                        </p>
                    </CardBody>
                </Card>
            </motion.div>
        );
    }

    return (
        <motion.div
            initial={{ opacity: 0, y: 20 }}
            animate={{ opacity: 1, y: 0 }}
            transition={{ duration: 0.5, delay: 0.3 }}
        >
            <Card 
                radius={getThemeRadius()}
                style={getCardStyle()}
                className="h-full"
                onMouseEnter={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid color-mix(in srgb, var(--theme-primary) 50%, transparent)`;
                    e.currentTarget.style.borderRadius = `var(--borderRadius, 12px)`;
                    e.currentTarget.style.transform = `scale(calc(var(--scale, 1) * 1.02))`;
                }}
                onMouseLeave={(e) => {
                    e.currentTarget.style.border = `var(--borderWidth, 2px) solid transparent`;
                    e.currentTarget.style.transform = `scale(var(--scale, 1))`;
                }}
            >
                <CardHeader className="pb-2" style={{ background: 'transparent' }}>
                    <div className="flex items-center justify-between w-full">
                        <div className="flex items-center gap-3">
                            <div 
                                className="p-2 rounded-lg"
                                style={{ background: `color-mix(in srgb, var(--theme-primary, #006FEE) 10%, transparent)` }}
                            >
                                <ChartBarIcon className="w-5 h-5" style={{ color: `var(--theme-primary, #006FEE)` }} />
                            </div>
                            <div>
                                <h3 className="text-lg font-semibold" style={{ color: `var(--theme-foreground, #11181C)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Attendance Overview
                                </h3>
                                <p className="text-xs" style={{ color: `var(--theme-foreground-400, #A1A1AA)`, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    Weekly attendance summary
                                </p>
                            </div>
                        </div>
                        <div className="flex items-center gap-4 text-xs">
                            <div className="flex items-center gap-1">
                                <div 
                                    className="w-3 h-3 rounded-full"
                                    style={{ backgroundColor: themeColors.success }}
                                />
                                <span style={{ color: themeColors.foreground400, fontFamily: `var(--fontFamily, "Inter")` }}>Present</span>
                            </div>
                            <div className="flex items-center gap-1">
                                <div 
                                    className="w-3 h-3 rounded-full"
                                    style={{ backgroundColor: themeColors.danger }}
                                />
                                <span style={{ color: themeColors.foreground400, fontFamily: `var(--fontFamily, "Inter")` }}>Absent</span>
                            </div>
                        </div>
                    </div>
                </CardHeader>
                <CardBody className="pt-0">
                    {chartData.length === 0 ? (
                        <div className="flex items-center justify-center h-48">
                            <div className="text-center">
                                <ChartBarIcon className="w-12 h-12 mx-auto mb-2" style={{ color: themeColors.foreground400 }} />
                                <p className="text-sm" style={{ color: themeColors.foreground400, fontFamily: `var(--fontFamily, "Inter")` }}>
                                    No attendance data available
                                </p>
                            </div>
                        </div>
                    ) : (
                        <div className="w-full h-64">
                            <ResponsiveContainer width="100%" height="100%">
                                <BarChart data={chartData}>
                                    <CartesianGrid strokeDasharray="3 3" stroke={themeColors.divider} />
                                    <XAxis 
                                        dataKey="date" 
                                        tickFormatter={formatDate}
                                        tick={{ fill: themeColors.foreground400, fontSize: 12 }}
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    />
                                    <YAxis 
                                        tick={{ fill: themeColors.foreground400, fontSize: 12 }}
                                        style={{ fontFamily: `var(--fontFamily, "Inter")` }}
                                    />
                                    <Tooltip 
                                        contentStyle={{
                                            backgroundColor: 'var(--theme-background, #FFFFFF)',
                                            borderColor: themeColors.divider,
                                            borderRadius: '8px',
                                            fontFamily: `var(--fontFamily, "Inter")`
                                        }}
                                        labelFormatter={formatDate}
                                    />
                                    <Legend wrapperStyle={{ fontSize: '12px', fontFamily: `var(--fontFamily, "Inter")` }} />
                                    <Bar dataKey="present" name="Present" fill={themeColors.success} radius={[4, 4, 0, 0]} />
                                    <Bar dataKey="absent" name="Absent" fill={themeColors.danger} radius={[4, 4, 0, 0]} />
                                </BarChart>
                            </ResponsiveContainer>
                        </div>
                    )}
                </CardBody>
            </Card>
        </motion.div>
    );
};

export default AttendanceSummaryChart;
