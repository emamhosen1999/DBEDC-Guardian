import {
    Box, Flex, Grid, Text, Heading, Button, IconButton, Card, Separator,
    Dialog, AlertDialog, Select, TextField, TextArea, Checkbox, Switch,
    RadioGroup, Radio, Badge, Spinner, Skeleton, ScrollArea, Table,
    Tabs, Tooltip, DropdownMenu, Progress, Callout, Inset,
} from '@radix-ui/themes';
import React, { useState, useCallback, useEffect } from 'react';
import { useMediaQuery } from '@/Hooks/useMediaQuery.js';
import { Head, usePage } from '@inertiajs/react';
import axios from 'axios';

import App from "@/Layouts/App.jsx";
import StatsCards from '@/Components/StatsCards.jsx';
import AttendanceEmployeeTable from "@/Tables/AttendanceEmployeeTable.jsx";
import {
    ClockIcon,
    CalendarIcon,
    BarChartIcon,
    CheckCircledIcon,
    CrossCircledIcon,
    ExclamationTriangleIcon,
    DashboardIcon,
    PersonIcon,
} from '@radix-ui/react-icons';
import ErrorBoundary from '@/Components/ErrorBoundary/ErrorBoundary';
import { useAttendanceStore } from '@/store/attendanceStore';
import * as useAttendanceQuery from '@/api/queries/useAttendanceQuery';

const AttendanceEmployee = React.memo(({ title, totalWorkingDays, presentDays, absentDays, lateArrivals }) => {
    const { auth } = usePage().props;
    
    const isLargeScreen  = useMediaQuery('(min-width: 1025px)');
    const isMediumScreen  = useMediaQuery('(min-width: 641px) and (max-width: 1024px)');
    
    // Helper function for radius
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
    
    const [selectedDate, setSelectedDate] = useState(new Date());
    const [updateTimeSheet, setUpdateTimeSheet] = useState(false);
    
    const [filterData, setFilterData] = useState({
        currentMonth: new Date().toISOString().slice(0, 7), // YYYY-MM format
    });

    // React Query hook for monthly stats
    const yearNum = new Date(filterData.currentMonth).getFullYear();
    const monthNum = String(new Date(filterData.currentMonth).getMonth() + 1).padStart(2, '0');
    
    const { data: monthlyStatsResponse, isLoading, refetch } = useAttendanceQuery.useMyMonthlyStats({
        currentMonth: parseInt(monthNum),
        currentYear: yearNum,
    });

    // Derived state from React Query data
    const stats = monthlyStatsResponse?.stats || monthlyStatsResponse?.data || {
        meta: { month: '', workingDays: 0, holidays: 0, weekends: 0 },
        attendance: { present: 0, absent: 0, leaves: 0, lateArrivals: 0, percentage: 0 },
        hours: { totalWork: 0, averageDaily: 0, overtime: 0 }
    };

    const handleDateChange = (event) => {
        const newDate = event.target.value;
        // Ensure we create the date correctly from the input string to avoid timezone shifts
        if (newDate) {
            setSelectedDate(new Date(newDate));
        }
    };

    const handleFilterChange = useCallback((key, value) => {
        setFilterData(prevState => ({
            ...prevState,
            [key]: value,
        }));
    }, []);

    // Auto-refetch when month changes
    useEffect(() => {
        refetch();
    }, [filterData.currentMonth, refetch]); 

    // const allStatsData = [
    //     { title: "Working Days", value: attendanceStats.totalWorkingDays, icon: <CalendarDaysIcon />, color: "text-primary", iconBg: "bg-primary/20", description: `Total for ${attendanceStats.month || 'this month'}` },
    //     { title: "Present Days", value: attendanceStats.presentDays, icon: <CheckCircleIcon />, color: "text-success", iconBg: "bg-success/20", description: "Days attended" },
    //     { title: "Absent Days", value: attendanceStats.absentDays, icon: <XCircleIcon />, color: "text-danger", iconBg: "bg-danger/20", description: "Days missed" },
    //     { title: "Late Arrivals", value: attendanceStats.lateArrivals, icon: <ExclamationTriangleIcon />, color: "text-warning", iconBg: "bg-warning/20", description: "Times late" },
    //     { title: "Attendance Rate", value: `${attendanceStats.attendancePercentage}%`, icon: <ChartBarIcon />, color: "text-success", iconBg: "bg-success/20", description: "Monthly performance" },
    //     { title: "Avg Work Hours", value: `${attendanceStats.averageWorkHours}h`, icon: <ClockIcon />, color: "text-primary", iconBg: "bg-primary/20", description: "Daily average" },
    //     { title: "Overtime", value: `${attendanceStats.overtimeHours}h`, icon: <ClockIcon />, color: "text-secondary", iconBg: "bg-secondary/20", description: "Extra hours" },
    //     { title: "Leave Days", value: attendanceStats.totalLeaveDays, icon: <UserIcon />, color: "text-warning", iconBg: "bg-warning/20", description: "Leaves taken" }
    // ];

    const allStatsData = [
        { 
            title: "Working Days", 
            value: stats.meta.workingDays, 
            icon: <CalendarIcon />, 
            color: "text-default-600", 
            iconBg: "bg-default-100", 
            description: `Calendar: ${stats.meta.month}` 
        },
        { 
            title: "Present", 
            value: stats.attendance.present, 
            icon: <CheckCircledIcon />, 
            color: "text-success", 
            iconBg: "bg-success/20", 
            description: `${stats.attendance.percentage}% Attendance Rate` 
        },
        { 
            title: "Absent", 
            value: stats.attendance.absent, 
            icon: <CrossCircledIcon />, 
            color: "text-danger", 
            iconBg: "bg-danger/20", 
            description: "Unexcused absences" 
        },
        { 
            title: "On Leave", 
            value: stats.attendance.leaves, 
            icon: <PersonIcon />, 
            color: "text-warning", 
            iconBg: "bg-warning/20", 
            description: "Approved leaves" 
        },
        { 
            title: "Late Arrivals", 
            value: stats.attendance.lateArrivals, 
            icon: <ExclamationTriangleIcon />, 
            color: "text-orange-500", 
            iconBg: "bg-orange-100", 
            description: "After grace period" 
        },
        { 
            title: "Total Hours", 
            value: `${stats.hours.totalWork}h`, 
            icon: <ClockIcon />, 
            color: "text-primary", 
            iconBg: "bg-primary/20", 
            description: "Total production time" 
        },
        { 
            title: "Daily Avg", 
            value: `${stats.hours.averageDaily}h`, 
            icon: <DashboardIcon />, 
            color: "text-secondary", 
            iconBg: "bg-secondary/20", 
            description: "Target: 8.0h" 
        },
        { 
            title: "Overtime", 
            value: `${stats.hours.overtime}h`, 
            icon: <BarChartIcon />, 
            color: "text-success-600", 
            iconBg: "bg-success-100", 
            description: "Extra hours logged" 
        }
    ];



    return (
        <>
            <Head title={title || "My Attendance"} />
            <div className="flex flex-col w-full h-full p-4" role="main" aria-label="My Attendance Management">
                <div className="space-y-4">
                    <div className="w-full">
                        <div>
                            <Card
                                className="transition-all duration-200"
                                style={{
                                    borderRadius: `var(--borderRadius, 12px)`,
                                    fontFamily: `var(--fontFamily, "Inter")`,
                                }}
                            >
                                <Box 
                                    className="border-b p-0"
                                    style={{
                                        borderColor: `var(--theme-divider, #E4E4E7)`,
                                        background: `linear-gradient(135deg, color-mix(in srgb, var(--theme-content1) 50%, transparent) 20%, color-mix(in srgb, var(--theme-content2) 30%, transparent) 10%)`,
                                    }}
                                >
                                    <div className={`${isLargeScreen ? 'p-6' : isMediumScreen ? 'p-4' : 'p-3'} w-full`}>
                                        <div className="flex flex-col space-y-4">
                                            <div className="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
                                                <div className="flex items-center gap-3 lg:gap-4">
                                                    <div 
                                                        className={`${isLargeScreen ? 'p-3' : isMediumScreen ? 'p-2.5' : 'p-2'} rounded-xl flex items-center justify-center`}
                                                        style={{
                                                            background: `color-mix(in srgb, var(--theme-primary) 15%, transparent)`,
                                                            borderColor: `color-mix(in srgb, var(--theme-primary) 25%, transparent)`,
                                                            borderWidth: `var(--borderWidth, 2px)`,
                                                            borderRadius: `var(--borderRadius, 12px)`,
                                                        }}
                                                    >
                                                        <DashboardIcon className={`${isLargeScreen ? 'w-8 h-8' : isMediumScreen ? 'w-6 h-6' : 'w-5 h-5'}`} style={{ color: 'var(--theme-primary)' }} />
                                                    </div>
                                                    <div className="min-w-0 flex-1">
                                                        <h4 className={`${isLargeScreen ? 'text-2xl' : isMediumScreen ? 'text-xl' : 'text-lg'} font-bold text-foreground ${!isLargeScreen ? 'truncate' : ''}`} style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                                                            My Attendance
                                                        </h4>
                                                        <p className={`${isLargeScreen ? 'text-sm' : 'text-xs'} text-default-500 ${!isLargeScreen ? 'truncate' : ''}`} style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                                                            View your attendance records and timesheet details
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </Box>

                                <Box className="p-6">
                                    <ErrorBoundary>
                                        <StatsCards stats={allStatsData} className="mb-6" />
                                    </ErrorBoundary>
                                    
                                    <div className="mb-6">
                                        <div className="flex flex-col sm:flex-row gap-4 items-start sm:items-end">
                                            <div className="w-full sm:w-auto sm:min-w-[200px]">
                                                <TextField.Root
                                                    type="month"
                                                    value={filterData.currentMonth}
                                                    onChange={(e) => handleFilterChange('currentMonth', e.target.value)}
                                                    size="2"
                                                    style={{ fontFamily: `var(--fontFamily, "Inter")`, width: 200 }}
                                                    aria-label="Select month and year for attendance"
                                                >
                                                    <TextField.Slot>
                                                        <CalendarIcon className="w-4 h-4 text-default-400" />
                                                    </TextField.Slot>
                                                </TextField.Root>
                                            </div>
                                        </div>
                                    </div>

                                    <Card 
                                        className="transition-all duration-200"
                                        style={{
                                            border: `var(--borderWidth, 2px) solid transparent`,
                                            borderRadius: `var(--borderRadius, 12px)`,
                                            fontFamily: `var(--fontFamily, "Inter")`,
                                            background: `linear-gradient(135deg, var(--theme-content1, #FAFAFA) 20%, var(--theme-content2, #F4F4F5) 10%, var(--theme-content3, #F1F3F4) 20%)`,
                                        }}
                                    >
                                        <Box className="border-b pb-2" style={{ borderColor: `var(--theme-divider, #E4E4E7)` }}>
                                            <div className="flex items-center gap-3">
                                                <div 
                                                    className="p-2 rounded-lg flex items-center justify-center"
                                                    style={{
                                                        background: `color-mix(in srgb, var(--theme-primary) 15%, transparent)`,
                                                        borderColor: `color-mix(in srgb, var(--theme-primary) 25%, transparent)`,
                                                    }}
                                                >
                                                    <ClockIcon style={{ width: 24, height: 24, color: 'var(--theme-primary)' }} />
                                                </div>
                                                <h1 className="text-xl sm:text-2xl md:text-3xl font-semibold text-foreground" style={{ fontFamily: `var(--fontFamily, "Inter")` }}>
                                                    My Attendance Records
                                                </h1>
                                            </div>
                                        </Box>
                                        <Box>
                                            <div className="max-h-[84vh] overflow-y-auto">
                                                <ErrorBoundary>
                                                    <AttendanceEmployeeTable
                                                        selectedDate={selectedDate}
                                                        handleDateChange={handleDateChange}
                                                        updateTimeSheet={updateTimeSheet}
                                                        externalFilterData={filterData}
                                                        // REMOVED KEY PROP to allow internal useEffects to work
                                                    />
                                                </ErrorBoundary>
                                            </div>
                                        </Box>
                                    </Card>
                                </Box>
                            </Card>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
});
AttendanceEmployee.layout = (page) => <App>{page}</App>;
export default AttendanceEmployee;