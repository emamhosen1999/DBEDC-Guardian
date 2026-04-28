import { Head } from '@inertiajs/react';
import React, { useState, useEffect, useCallback } from 'react';
import { motion } from 'framer-motion';
import App from "@/Layouts/App.jsx";
import PunchStatusCard from '@/Components/PunchStatusCard.jsx';
import EmployeeWelcomeWidget from '@/Components/Employee/EmployeeWelcomeWidget.jsx';
import DailyWorksStatsCard from '@/Components/Employee/EmployeeStatsCards/DailyWorksStatsCard.jsx';
import LeaveBalanceCard from '@/Components/Employee/EmployeeStatsCards/LeaveBalanceCard.jsx';
import UpcomingHolidayCard from '@/Components/Employee/EmployeeStatsCards/UpcomingEventCard.jsx';
import DepartmentCard from '@/Components/Employee/EmployeeStatsCards/DepartmentCard.jsx';
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
            // Calculate employee-specific daily works stats from recent daily works
            const response = await axios.get(route('dailyWorks.paginate'), {
                params: { 
                    page: 1,
                    perPage: 1000,
                    search: '',
                    assigned: auth.user?.id,
                    incharge: auth.user?.id
                }
            });
            const data = response.data?.data || [];
            const works = Array.isArray(data) ? data : [];
            
            const total = works.length;
            const completed = works.filter(w => w.status === 'completed').length;
            const pending = works.filter(w => w.status === 'pending').length;
            const inProgress = works.filter(w => w.status === 'in-progress').length;
            
            setDailyWorksStats({
                total,
                completed,
                pending,
                inProgress,
                completionRate: total > 0 ? Math.round((completed / total) * 100) : 0
            });
        } catch (error) {
            console.error('Error fetching daily works stats:', error);
            setDailyWorksStats({});
        }
    }, [auth.user?.id]);

    const fetchLeaveBalance = useCallback(async () => {
        try {
            // Use the same endpoint as the My Leaves page for data consistency
            const response = await axios.get(route('leaves.paginate'), {
                params: {
                    page: 1,
                    perPage: 10,
                    year: new Date().getFullYear(),
                }
            });
            const leavesData = response.data?.leavesData || {};
            const leaveTypes = leavesData.leaveTypes || [];
            const userLeaveCounts = leavesData.leaveCountsByUser?.[auth.user?.id] || [];
            setLeaveBalance({ leaveTypes, userLeaveCounts });
        } catch (error) {
            console.error('Error fetching leave balance:', error);
            setLeaveBalance({ leaveTypes: [], userLeaveCounts: [] });
        }
    }, [auth.user?.id]);

    const fetchUpdates = useCallback(async () => {
        try {
            const response = await axios.get(route('updates'));
            setUpcomingHoliday(response.data?.upcomingHoliday || null);
            // Filter leaves by current user and deduplicate by ID
            const leaves = response.data?.upcomingLeaves || [];
            const userLeaves = leaves.filter((leave) => leave.user_id === auth.user?.id);
            const uniqueLeaves = userLeaves.filter((leave, index, self) =>
                index === self.findIndex((l) => l.id === leave.id)
            );
            setUpcomingLeaves(uniqueLeaves);
        } catch (error) {
            console.error('Error fetching updates:', error);
        }
    }, [auth.user?.id]);

    const fetchRecentDailyWorks = useCallback(async () => {
        try {
            const response = await axios.get(route('dailyWorks.paginate'), {
                params: { 
                    page: 1,
                    perPage: 5,
                    search: '',
                    // Filter for current user's works (either assigned or incharge)
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
                className="w-full p-4"
            >
                <div className="w-full space-y-6">
                    {/* Welcome Widget */}
                    <motion.div variants={itemVariants}>
                        <EmployeeWelcomeWidget auth={auth} status={getAttendanceStatus()} />
                    </motion.div>

                    {/* Punch Status and Stats Row */}
                    <motion.div variants={itemVariants}>
                        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                            {/* Left Column - Punch Status Card */}
                            <div className="w-full">
                                <PunchStatusCard handlePunchSuccess={handlePunchSuccess} />
                            </div>

                            {/* Right Column - Stats Cards */}
                            <div className="grid grid-cols-2 gap-4">
                                <DepartmentCard auth={auth} />
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
