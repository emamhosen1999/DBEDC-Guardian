import { Head } from '@inertiajs/react';
import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import App from "@/Layouts/App.jsx";
import PunchStatusCard from '@/Components/PunchStatusCard.jsx';
import EmployeeWelcomeWidget from '@/Components/Employee/EmployeeWelcomeWidget.jsx';
import AttendanceStatusCard from '@/Components/Employee/EmployeeStatsCards/AttendanceStatusCard.jsx';
import DailyWorksStatsCard from '@/Components/Employee/EmployeeStatsCards/DailyWorksStatsCard.jsx';
import LeaveBalanceCard from '@/Components/Employee/EmployeeStatsCards/LeaveBalanceCard.jsx';
import UpcomingHolidayCard from '@/Components/Employee/EmployeeStatsCards/UpcomingEventCard.jsx';
import RecentDailyWorksCard from '@/Components/Employee/RecentDailyWorksCard.jsx';
import AttendanceSummaryChart from '@/Components/Employee/AttendanceSummaryChart.jsx';
import QuickActionsCard from '@/Components/Employee/QuickActionsCard.jsx';
import UpcomingLeavesCard from '@/Components/Employee/UpcomingLeavesCard.jsx';
import EmployeeRecentDailyWorksTable from '@/Components/Employee/EmployeeRecentDailyWorksTable.jsx';
import axios from 'axios';
import { route } from 'ziggy-js';

export default function MemberDashboard({ auth }) {
    const [updateMap, setUpdateMap] = useState(false);
    const [updateTimeSheet, setUpdateTimeSheet] = useState(false);
    const [punchData, setPunchData] = useState({});
    const [dailyWorksStats, setDailyWorksStats] = useState({});
    const [leaveBalance, setLeaveBalance] = useState({});
    const [upcomingHoliday, setUpcomingHoliday] = useState(null);
    const [recentDailyWorks, setRecentDailyWorks] = useState([]);
    const [upcomingLeaves, setUpcomingLeaves] = useState([]);
    const [loading, setLoading] = useState(true);

    const containerVariants = {
        hidden: { opacity: 0 },
        visible: {
            opacity: 1,
            transition: {
                duration: 0.6,
                staggerChildren: 0.1,
                ease: "easeOut"
            }
        }
    };

    const itemVariants = {
        hidden: { 
            opacity: 0, 
            y: 20, 
            scale: 0.95 
        },
        visible: { 
            opacity: 1, 
            y: 0, 
            scale: 1,
            transition: {
                duration: 0.5,
                ease: "easeOut"
            }
        }
    };

    const handlePunchSuccess = () => {
        setUpdateMap(prev => !prev);
        setUpdateTimeSheet(prev => !prev);
        fetchPunchData();
    };

    const fetchPunchData = useCallback(async () => {
        try {
            const response = await axios.get(route('attendance.current-user-punch'));
            setPunchData(response.data || {});
        } catch (error) {
            console.error('Error fetching punch data:', error);
        }
    }, []);

    const fetchDailyWorksStats = useCallback(async () => {
        try {
            const response = await axios.get(route('admin.dashboard.stats'));
            setDailyWorksStats(response.data?.statistics || {});
        } catch (error) {
            console.error('Error fetching daily works stats:', error);
        }
    }, []);

    const fetchLeaveBalance = useCallback(async () => {
        try {
            const response = await axios.get(route('leaves.stats'));
            setLeaveBalance(response.data || {});
        } catch (error) {
            console.error('Error fetching leave balance:', error);
        }
    }, []);

    const fetchUpdates = useCallback(async () => {
        try {
            const response = await axios.get(route('updates'));
            setUpcomingHoliday(response.data?.upcomingHoliday || null);
            // Deduplicate leaves by ID to prevent repeated data
            const leaves = response.data?.upcomingLeaves || [];
            const uniqueLeaves = leaves.filter((leave, index, self) =>
                index === self.findIndex((l) => l.id === leave.id)
            );
            setUpcomingLeaves(uniqueLeaves);
        } catch (error) {
            console.error('Error fetching updates:', error);
        }
    }, []);

    const fetchRecentDailyWorks = useCallback(async () => {
        try {
            const response = await axios.get(route('dailyWorks.paginate'), {
                params: { 
                    page: 1,
                    perPage: 5,
                    search: '',
                    assigned: auth.user?.id,
                    incharge: auth.user?.id
                }
            });
            const data = response.data?.data || [];
            setRecentDailyWorks(Array.isArray(data) ? data : []);
        } catch (error) {
            console.error('Error fetching recent daily works:', error);
        }
    }, [auth.user?.id]);

    useEffect(() => {
        const fetchData = async () => {
            setLoading(true);
            await Promise.all([
                fetchPunchData(),
                fetchDailyWorksStats(),
                fetchLeaveBalance(),
                fetchUpdates(),
                fetchRecentDailyWorks()
            ]);
            setLoading(false);
        };

        fetchData();
    }, [fetchPunchData, fetchDailyWorksStats, fetchLeaveBalance, fetchUpdates, fetchRecentDailyWorks]);

    const getAttendanceStatus = () => {
        if (punchData?.isUserOnLeave) return 'On Leave';
        if (punchData?.punches && punchData.punches.length > 0) {
            const latestPunch = punchData.punches[punchData.punches.length - 1];
            if (latestPunch.punchout_time) return 'Present';
            return 'Punched In';
        }
        return 'Not Punched In';
    };

    return ( 
        <>
            <Head title="Employee Dashboard" />
            <motion.div
                variants={containerVariants}
                initial="hidden"
                animate="visible"
                className="w-full p-8"
            >
                <div className="w-full space-y-6">
                    {/* Welcome Widget */}
                    <motion.div variants={itemVariants}>
                        <EmployeeWelcomeWidget auth={auth} status={getAttendanceStatus()} />
                    </motion.div>

                    {/* Punch Status and Stats Row */}
                    <motion.div variants={itemVariants}>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
                            {/* Left Column - Punch Status Card */}
                            <PunchStatusCard handlePunchSuccess={handlePunchSuccess} />

                            {/* Right Column - Stats Cards */}
                            <div className="grid grid-cols-2 gap-4">
                                <AttendanceStatusCard punchData={punchData} />
                                <DailyWorksStatsCard stats={dailyWorksStats} />
                                <LeaveBalanceCard leaveBalance={leaveBalance} />
                                <UpcomingHolidayCard upcomingHoliday={upcomingHoliday} />
                            </div>
                        </div>
                    </motion.div>

                    {/* Main Content Grid */}
                    <motion.div variants={itemVariants}>
                        <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            {/* Left Column (2/3) */}
                            <div className="lg:col-span-2 space-y-6">
                                <RecentDailyWorksCard dailyWorks={recentDailyWorks} />
                                <AttendanceSummaryChart />
                            </div>

                            {/* Right Column (1/3) */}
                            <div className="space-y-6">
                                <QuickActionsCard onPunchAction={handlePunchSuccess} />
                                <UpcomingLeavesCard leaves={upcomingLeaves} />
                            </div>
                        </div>
                    </motion.div>

                    {/* Bottom Table */}
                    <motion.div variants={itemVariants}>
                        <EmployeeRecentDailyWorksTable auth={auth} />
                    </motion.div>
                </div>
            </motion.div>
        </>
    );
}

MemberDashboard.layout = (page) => <App>{page}</App>;
